<?php

/** A read-only lookup; selecting a product never creates products or changes stock. */
function manufacturing_primary_availability(PDO $pdo, int $finalProductId, int $locationId = 0): array {
    $scope = getInventoryChannelScopeSql('i');
    $stmt = $pdo->prepare("SELECT mp.primary_product_id, p.name, COALESCE(SUM(ip.quantity), 0) AS quantity
        FROM manufacturing_primary_products mp JOIN products p ON p.id = mp.primary_product_id AND p.type = 'primary'
        LEFT JOIN inventories i ON i.system_key = 'manufacturing_primary' AND i.is_active = 1 AND i.direct_sale IS NULL
            AND i.location_id IS NOT NULL AND $scope AND (? = 0 OR i.location_id = ?)
        LEFT JOIN inventory_products ip ON ip.inventory_id = i.id AND ip.product_id = p.id
        WHERE mp.final_product_id = ? GROUP BY mp.primary_product_id, p.name");
    $stmt->execute([$locationId, $locationId, $finalProductId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['primary_product_id' => null, 'name' => null, 'quantity' => 0];
    $stmt = $pdo->prepare("SELECT i.location_id, l.name AS location_name, ip.quantity FROM inventory_products ip
        JOIN inventories i ON i.id = ip.inventory_id JOIN locations l ON l.id = i.location_id
        WHERE ip.product_id = ? AND ip.quantity > 0 AND i.system_key = 'manufacturing_primary' AND i.is_active = 1
            AND i.direct_sale IS NULL AND $scope AND (? = 0 OR i.location_id = ?) ORDER BY l.name");
    $stmt->execute([$result['primary_product_id'], $locationId, $locationId]);
    $result['locations'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return $result;
}

/** Serialize on the final product so concurrent orders cannot create duplicate primary SKUs. */
function manufacturing_ensure_primary_product(PDO $pdo, int $finalProductId): int {
    if (!$pdo->inTransaction()) throw new LogicException('Primary products require a transaction.');
    $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ? AND type = 'final' FOR UPDATE");
    $stmt->execute([$finalProductId]);
    $final = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$final) throw new DomainException('The final product is missing.');
    $stmt = $pdo->prepare('SELECT primary_product_id FROM manufacturing_primary_products WHERE final_product_id = ? FOR UPDATE');
    $stmt->execute([$finalProductId]);
    $existing = $stmt->fetchColumn();
    if ($existing) return (int)$existing;
    $pdo->prepare("INSERT INTO products (name, sku, category_id, subcategory_id, type, unit, customer_id, description)
        VALUES (?, ?, ?, ?, 'primary', 'each', ?, ?)")->execute([
            mb_substr('منتج أولي - ' . $final['name'], 0, 100), 'PRIMARY-' . $finalProductId . '-' . bin2hex(random_bytes(4)),
            $final['category_id'], $final['subcategory_id'], $final['customer_id'], 'Unpackaged output for final product #' . $finalProductId,
        ]);
    $primaryId = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO manufacturing_primary_products (final_product_id, primary_product_id) VALUES (?, ?)')->execute([$finalProductId, $primaryId]);
    return $primaryId;
}

function manufacturing_validate_primary_choice(PDO $pdo, int $productId, $input, int $bottles, int $locationId = 0): int {
    if (!is_scalar($input) || !preg_match('/^\d+$/D', (string)$input) || (float)$input > $bottles) {
        throw new DomainException('Primary stock quantity must be a whole number between zero and the ordered quantity.');
    }
    $quantity = (int)$input;
    if ($quantity > 0 && (($_SESSION['login_region'] ?? 'factory') !== 'factory'
        || $quantity > (float)manufacturing_primary_availability($pdo, $productId, $locationId)['quantity'])) {
        throw new DomainException('The requested primary stock is no longer available at this location. Review مخزن منتج اولي.');
    }
    return $quantity;
}

/** Primary stock movements and their audit rows always share the caller's transaction. */
function manufacturing_move_primary_stock(PDO $pdo, int $productId, int $change, int $orderId, string $source): void {
    if (!$pdo->inTransaction()) throw new LogicException('Primary stock movements require a transaction.');
    $location = $pdo->prepare('SELECT location_id FROM manufacturing_orders WHERE id = ?');
    $location->execute([$orderId]);
    $locationId = (int)$location->fetchColumn();
    if ($locationId <= 0) throw new DomainException('Choose the manufacturing location before posting primary stock.');
    $stmt = $pdo->prepare("SELECT id FROM inventories WHERE system_key = 'manufacturing_primary' AND location_id = ? AND is_active = 1 AND direct_sale IS NULL FOR UPDATE");
    $stmt->execute([$locationId]);
    $inventoryId = (int)$stmt->fetchColumn();
    if (!$inventoryId) throw new DomainException('The location primary inventory (مخزن منتج اولي) is missing or inactive.');
    $stmt = $pdo->prepare('SELECT quantity FROM inventory_products WHERE inventory_id = ? AND product_id = ? FOR UPDATE');
    $stmt->execute([$inventoryId, $productId]);
    $before = (float)($stmt->fetchColumn() ?: 0);
    if ($before + $change < 0) throw new DomainException('Insufficient primary stock in this location’s مخزن منتج اولي.');
    $pdo->prepare('INSERT INTO inventory_products (inventory_id, product_id, quantity) VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)')->execute([$inventoryId, $productId, $change]);
    $pdo->prepare('INSERT INTO inventory_stock_logs
        (inventory_id, product_id, change_quantity, quantity_before, quantity_after, source_type, source_id, notes, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([$inventoryId, $productId, $change, $before, $before + $change,
            $source, $orderId, 'Manufacturing order ' . $orderId . '; primary product stock', $_SESSION['user_id'] ?? null]);
}

function manufacturing_consume_primary_stock(PDO $pdo, array $order): void {
    $quantity = (int)($order['primary_stock_quantity'] ?? 0);
    if ($quantity <= 0 || !empty($order['primary_stock_deducted_at'])) return;
    if ((int)$order['location_id'] <= 0) throw new DomainException('Choose a location before consuming primary stock.');
    manufacturing_validate_primary_choice($pdo, (int)$order['product_id'], $quantity, (int)$order['number_of_bottles'], (int)$order['location_id']);
    $primary = manufacturing_primary_availability($pdo, (int)$order['product_id'], (int)$order['location_id']);
    if (!$primary['primary_product_id']) throw new DomainException('There is no matching primary product for this order.');
    manufacturing_move_primary_stock($pdo, (int)$primary['primary_product_id'], -$quantity, (int)$order['id'], 'manufacturing_primary_use');
    $pdo->prepare('UPDATE manufacturing_orders SET primary_stock_deducted_at = NOW() WHERE id = ?')->execute([$order['id']]);
}

/** Cancel both the output credit and the input consumption; all reversal markers are atomic. */
function manufacturing_reverse_primary_stock(PDO $pdo, array $order): void {
    $stmt = $pdo->prepare('SELECT * FROM manufacturing_packaging_output WHERE manufacturing_order_id = ? FOR UPDATE');
    $stmt->execute([$order['id']]);
    $output = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($output && (int)$output['primary_quantity'] > 0) {
        manufacturing_move_primary_stock($pdo, (int)$output['primary_product_id'], -(int)$output['primary_quantity'], (int)$order['id'], 'manufacturing_primary_output_return');
    }
    $usedQuantity = $output ? (int)$output['primary_used_quantity'] : (!empty($order['primary_stock_deducted_at']) ? (int)$order['primary_stock_quantity'] : 0);
    if ($usedQuantity > 0) {
        $primary = manufacturing_primary_availability($pdo, (int)$order['product_id'], (int)$order['location_id']);
        manufacturing_move_primary_stock($pdo, (int)$primary['primary_product_id'], $usedQuantity, (int)$order['id'], 'manufacturing_primary_return');
    }
    $pdo->prepare('UPDATE manufacturing_orders SET primary_stock_deducted_at = NULL WHERE id = ?')->execute([$order['id']]);
    $pdo->prepare('DELETE FROM manufacturing_packaging_output WHERE manufacturing_order_id = ?')->execute([$order['id']]);
}

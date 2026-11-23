<?php

/** These helpers run inside the transaction that saves the workflow step. */
function manufacturing_lock_stock_order(PDO $pdo, int $orderId): array {
    if (!$pdo->inTransaction()) throw new LogicException('Manufacturing stock requires a transaction.');
    $stmt = $pdo->prepare('SELECT * FROM manufacturing_orders WHERE id = ? FOR UPDATE');
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order || in_array($order['status'], ['completed', 'cancelled'], true)) {
        throw new DomainException('This manufacturing order is locked.');
    }
    return $order;
}

function manufacturing_component_stock_quantity(array $component): float {
    $quantity = convertProductUnitQuantity($component['required_quantity'], $component['unit'], $component['stock_unit']);
    if ($quantity === null || !is_finite($quantity) || $quantity < 0 || $quantity > 9999999999.99
        || abs($quantity - round($quantity, 2)) > 0.00000001
        || (normalizeProductUnit($component['stock_unit']) === 'each' && floor($quantity) !== $quantity)) {
        throw new DomainException('Material quantity cannot be represented in its stock unit. Review the material units and quantity.');
    }
    return round($quantity, 2);
}

function manufacturing_component_stock_log(PDO $pdo, int $inventoryId, array $component, float $change, float $before, string $source): void {
    $pdo->prepare('INSERT INTO inventory_stock_logs
        (inventory_id, product_id, change_quantity, quantity_before, quantity_after, source_type, source_id, notes, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
            $inventoryId, $component['product_id'], $change, $before, round($before + $change, 2),
            $source, $component['id'], 'Manufacturing order ' . $component['manufacturing_order_id'] . '; sourcing component ' . $component['id'],
            $_SESSION['user_id'] ?? null,
        ]);
}

/** Consume each component once, spreading the requirement over usable warehouses. */
function manufacturing_deduct_sourcing_stock(PDO $pdo, int $orderId): void {
    $order = manufacturing_lock_stock_order($pdo, $orderId);
    $stmt = $pdo->prepare('SELECT msc.*, p.unit AS stock_unit FROM manufacturing_sourcing_components msc
        LEFT JOIN products p ON p.id = msc.product_id
        WHERE msc.manufacturing_order_id = ? AND msc.product_id IS NOT NULL AND msc.deducted_at IS NULL
        ORDER BY msc.product_id, msc.id FOR UPDATE');
    $stmt->execute([$orderId]);
    $components = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($components as $component) {
        manufacturing_withdraw_component_stock($pdo, $order, $component, manufacturing_component_stock_quantity($component));
        $pdo->prepare('UPDATE manufacturing_sourcing_components SET deducted_at = NOW() WHERE id = ?')->execute([$component['id']]);
    }
    manufacturing_consume_primary_stock($pdo, $order);
}

/** Withdraw a native stock quantity using the same allocation rules in sourcing and packaging. */
function manufacturing_withdraw_component_stock(PDO $pdo, array $order, array $component, float $quantity): void {
    if (!$pdo->inTransaction()) throw new LogicException('Material withdrawals require a transaction.');
    $remaining = $quantity;
    $scope = getInventoryChannelScopeSql('i');
    $stock = $pdo->prepare("SELECT ip.inventory_id, ip.quantity FROM inventory_products ip
        JOIN inventories i ON i.id = ip.inventory_id
        WHERE ip.product_id = ? AND i.location_id = ? AND i.is_active = 1 AND i.system_key IS NULL
          AND $scope AND ip.quantity > 0 ORDER BY ip.inventory_id FOR UPDATE");
    $deduct = $pdo->prepare('UPDATE inventory_products SET quantity = quantity - ? WHERE inventory_id = ? AND product_id = ?');
    $stock->execute([$component['product_id'], $order['location_id']]);
    $warehouses = $stock->fetchAll(PDO::FETCH_ASSOC);
    if (round(array_sum(array_column($warehouses, 'quantity')), 2) < $remaining) {
        throw new DomainException('Insufficient usable material stock at this location.');
    }
    foreach ($warehouses as $warehouse) {
        if ($remaining <= 0) break;
        $take = min($remaining, (float)$warehouse['quantity']);
        $deduct->execute([$take, $warehouse['inventory_id'], $component['product_id']]);
        manufacturing_component_stock_log($pdo, (int)$warehouse['inventory_id'], $component, -$take,
            (float)$warehouse['quantity'], 'manufacturing_sourcing_component');
        $remaining = round($remaining - $take, 2);
    }
}

/** Restore only recorded withdrawals, to their original warehouses, once. */
function manufacturing_save_receipt_stock(PDO $pdo, int $orderId, array $statuses, array $notes): void {
    manufacturing_lock_stock_order($pdo, $orderId);
    $componentStmt = $pdo->prepare('SELECT * FROM manufacturing_sourcing_components WHERE id = ? AND manufacturing_order_id = ? FOR UPDATE');
    foreach ($statuses as $id => $status) {
        if (!in_array($status, ['pending', 'approved', 'rejected'], true) || !is_scalar($notes[$id] ?? '')) {
            throw new DomainException('Invalid material receipt status or notes.');
        }
        $componentStmt->execute([(int)$id, $orderId]);
        $component = $componentStmt->fetch(PDO::FETCH_ASSOC);
        if (!$component) throw new DomainException('Material does not belong to this order.');
        if ($status === 'approved' && $component['product_id'] && !$component['deducted_at']) {
            throw new DomainException('This material must be sourced again before receipt approval.');
        }
        if ($status === 'rejected' && $component['product_id'] && $component['deducted_at']) {
            manufacturing_return_component_stock($pdo, $component);
        }
        $pdo->prepare('UPDATE manufacturing_sourcing_components SET receipt_status = ?, receipt_notes = ? WHERE id = ?')
            ->execute([$status, $notes[$id] ?? '', $component['id']]);
    }
}

/** Return a consumed component using its allocation history, including cancellation. */
function manufacturing_return_component_stock(PDO $pdo, array $component): void {
    if (!$pdo->inTransaction()) throw new LogicException('Material returns require a transaction.');
    manufacturing_lock_stock_order($pdo, (int)$component['manufacturing_order_id']);
    $fresh = $pdo->prepare('SELECT msc.*, p.unit AS stock_unit FROM manufacturing_sourcing_components msc
        LEFT JOIN products p ON p.id = msc.product_id WHERE msc.id = ? AND msc.manufacturing_order_id = ? FOR UPDATE');
    $fresh->execute([$component['id'], $component['manufacturing_order_id']]);
    $component = $fresh->fetch(PDO::FETCH_ASSOC);
    if (!$component) throw new DomainException('Material does not belong to this order.');
    if (!$component['deducted_at']) return;
    $waste = $pdo->prepare('SELECT id FROM manufacturing_material_waste WHERE sourcing_component_id = ? LIMIT 1');
    $waste->execute([$component['id']]);
    if ($waste->fetchColumn()) throw new DomainException('Clear recorded material waste before returning this material.');
    $movements = $pdo->prepare("SELECT inventory_id, SUM(change_quantity) AS net_quantity FROM inventory_stock_logs
        WHERE product_id = ? AND source_id = ? AND source_type IN ('manufacturing_sourcing_component', 'manufacturing_return_component')
        GROUP BY inventory_id ORDER BY inventory_id");
    $movements->execute([$component['product_id'], $component['id']]);
    $withdrawals = $movements->fetchAll(PDO::FETCH_ASSOC);
    if (!$withdrawals && (float)$component['required_quantity'] !== 0.0) {
        throw new DomainException('This legacy deduction has no warehouse allocation record. Reconcile its stock history before returning the material.');
    }
    if (abs(-array_sum(array_column($withdrawals, 'net_quantity')) - manufacturing_component_stock_quantity($component)) > 0.00000001) {
        throw new DomainException('Material stock history does not match its consumed quantity. Reconcile it before returning stock.');
    }
    foreach ($withdrawals as $withdrawal) {
        $quantity = round(-(float)$withdrawal['net_quantity'], 2);
        if ($quantity < 0) throw new DomainException('Material stock history needs reconciliation.');
        if ($quantity === 0.0) continue;
        $inventoryId = (int)$withdrawal['inventory_id'];
        // Lock the warehouse as well, so deletion cannot race a stock return.
        $inventory = $pdo->prepare('SELECT id FROM inventories WHERE id = ? FOR UPDATE');
        $inventory->execute([$inventoryId]);
        if (!$inventory->fetchColumn()) throw new DomainException('The original warehouse no longer exists. Reconcile the material stock history.');
        $stock = $pdo->prepare('SELECT quantity FROM inventory_products WHERE inventory_id = ? AND product_id = ? FOR UPDATE');
        $stock->execute([$inventoryId, $component['product_id']]);
        $before = (float)($stock->fetchColumn() ?: 0);
        $pdo->prepare('INSERT INTO inventory_products (inventory_id, product_id, quantity) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)')->execute([$inventoryId, $component['product_id'], $quantity]);
        manufacturing_component_stock_log($pdo, $inventoryId, $component, $quantity, $before, 'manufacturing_return_component');
    }
    $pdo->prepare('UPDATE manufacturing_sourcing_components SET deducted_at = NULL WHERE id = ?')->execute([$component['id']]);
}

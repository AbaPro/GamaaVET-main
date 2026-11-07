<?php

// The order lock serializes edits with completion; caller owns the transaction.
function manufacturing_save_material_waste(PDO $pdo, int $orderId, array $quantities): void {
    if (!$pdo->inTransaction()) throw new LogicException('Material waste requires a transaction.');
    if (!hasPermission('manufacturing.waste.manage') || !canViewManufacturingTechnicalDetails()
        || !canViewManufacturingFormula()) throw new DomainException('Material waste permission required.');
    $stmt = $pdo->prepare('SELECT status FROM manufacturing_orders WHERE id = ? FOR UPDATE');
    $stmt->execute([$orderId]);
    if (!in_array($stmt->fetchColumn(), ['pending', 'in_progress'], true)) throw new DomainException('This order is locked.');
    $component = $pdo->prepare("SELECT msc.*, p.unit AS stock_unit FROM manufacturing_sourcing_components msc
        JOIN products p ON p.id = msc.product_id AND p.type = 'material'
        WHERE msc.id = ? AND msc.manufacturing_order_id = ? FOR UPDATE");
    $save = $pdo->prepare('INSERT INTO manufacturing_material_waste
        (manufacturing_order_id, sourcing_component_id, product_id, quantity, unit, stock_quantity, recorded_by)
        VALUES (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE quantity = VALUES(quantity),
        unit = VALUES(unit), stock_quantity = VALUES(stock_quantity), recorded_by = VALUES(recorded_by)');
    foreach ($quantities as $id => $input) {
        if (!is_scalar($input) || !preg_match('/^\d+(?:\.\d{1,4})?$/D', (string)$input)) {
            throw new DomainException('Waste must be a non-negative quantity with up to four decimal places.');
        }
        $component->execute([(int)$id, $orderId]);
        $row = $component->fetch(PDO::FETCH_ASSOC);
        if (!$row || empty($row['deducted_at'])) throw new DomainException('Waste must reference a consumed material on this order.');
        $quantity = (float)$input;
        if ($quantity > (float)$row['required_quantity']) throw new DomainException('Waste cannot exceed the consumed material quantity.');
        if ($quantity === 0.0) {
            $pdo->prepare('DELETE FROM manufacturing_material_waste WHERE manufacturing_order_id = ? AND sourcing_component_id = ? AND posted_at IS NULL')->execute([$orderId, $row['id']]);
            continue;
        }
        $stock = convertProductUnitQuantity($quantity, $row['unit'], $row['stock_unit']);
        if ($stock === null || !is_finite($stock) || $stock > 9999999999.99 || abs($stock - round($stock, 2)) > 0.00000001) {
            throw new DomainException('Waste units must match the material stock unit and its two-decimal stock precision.');
        }
        if (normalizeProductUnit($row['stock_unit']) === 'each' && floor($stock) !== $stock) {
            throw new DomainException('Waste counted in pieces must be a whole number.');
        }
        $save->execute([$orderId, $row['id'], $row['product_id'], $quantity, $row['unit'], round($stock, 2), $_SESSION['user_id'] ?? null]);
    }
}

function manufacturing_post_material_waste(PDO $pdo, array $order): void {
    if (!$pdo->inTransaction()) throw new LogicException('Waste posting requires a transaction.');
    // Caller has locked the order. Material was already consumed: do not deduct it again.
    $stmt = $pdo->prepare('SELECT * FROM manufacturing_material_waste WHERE manufacturing_order_id = ? AND posted_at IS NULL ORDER BY id FOR UPDATE');
    $stmt->execute([$order['id']]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return;
    $inventory = $pdo->query("SELECT id FROM inventories WHERE system_key = 'manufacturing_waste' AND is_active = 1 AND direct_sale IS NULL FOR UPDATE")->fetchColumn();
    if (!$inventory) throw new RuntimeException('The fixed waste inventory is missing.');
    foreach ($rows as $row) {
        // Revalidate source and conversion before posting; stale/malformed drafts must fail closed.
        $source = $pdo->prepare("SELECT msc.required_quantity, msc.unit, msc.deducted_at, p.unit AS stock_unit
            FROM manufacturing_sourcing_components msc JOIN products p ON p.id = msc.product_id AND p.type = 'material'
            WHERE msc.id = ? AND msc.manufacturing_order_id = ? AND msc.product_id = ? FOR UPDATE");
        $source->execute([$row['sourcing_component_id'], $order['id'], $row['product_id']]);
        $material = $source->fetch(PDO::FETCH_ASSOC);
        $converted = $material ? convertProductUnitQuantity($row['quantity'], $row['unit'], $material['stock_unit']) : null;
        if (!$material || !$material['deducted_at'] || $row['unit'] !== $material['unit']
            || (float)$row['quantity'] < 0 || (float)$row['quantity'] > (float)$material['required_quantity']
            || $converted === null || abs($converted - (float)$row['stock_quantity']) > 0.00000001) {
            throw new DomainException('Material waste no longer matches consumed materials. Review the waste entries.');
        }
        $before = $pdo->prepare('SELECT quantity FROM inventory_products WHERE inventory_id = ? AND product_id = ? FOR UPDATE');
        $before->execute([$inventory, $row['product_id']]);
        $previous = (float)($before->fetchColumn() ?: 0);
        $quantity = (float)$row['stock_quantity'];
        if ($quantity > 0) {
            $pdo->prepare('INSERT INTO inventory_products (inventory_id, product_id, quantity) VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)')->execute([$inventory, $row['product_id'], $quantity]);
            $pdo->prepare("INSERT INTO inventory_stock_logs
                (inventory_id, product_id, change_quantity, quantity_before, quantity_after, source_type, source_id, notes, created_by)
                VALUES (?, ?, ?, ?, ?, 'manufacturing_material_waste', ?, ?, ?)")
                ->execute([$inventory, $row['product_id'], $quantity, $previous, $previous + $quantity, $row['id'],
                    'Consumed material waste — ' . $order['order_number'] . '; ' . $row['quantity'] . ' ' . $row['unit'], $_SESSION['user_id'] ?? null]);
        }
        $pdo->prepare('UPDATE manufacturing_material_waste SET posted_at = NOW() WHERE id = ? AND posted_at IS NULL')->execute([$row['id']]);
    }
}

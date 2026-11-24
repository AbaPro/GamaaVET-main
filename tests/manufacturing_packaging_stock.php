<?php
// Rollback-only integration coverage for the actual packaging material ledger.
if (PHP_SAPI !== 'cli' || !getenv('GAMMAVET_MANUFACTURING_TEST')) throw new RuntimeException('CLI regression checks only.');
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../modules/manufacturing/lib.php';
function checkPackagingStock(bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException($label);
    echo "PASS $label\n";
}
$user = $pdo->query('SELECT id, session_version FROM users WHERE is_active = 1 LIMIT 1')->fetch(PDO::FETCH_ASSOC);
$_SESSION = ['user_id' => (int)$user['id'], 'session_version' => $user['session_version'], 'role_slug' => 'admin', 'login_region' => 'factory'];
$_SESSION['formula_unlocked'] = true;
$_SESSION['formula_password_version'] = formulaPasswordVersion();
$pdo->beginTransaction();
try {
    $source = $pdo->query('SELECT customer_id, product_id, formula_id FROM manufacturing_orders WHERE product_id IS NOT NULL LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    $category = $pdo->query('SELECT id FROM categories LIMIT 1')->fetchColumn();
    $pdo->exec("INSERT INTO locations (name) VALUES ('TEST packaging location')");
    $location = (int)$pdo->lastInsertId();
    $inventories = [];
    foreach (['first', 'second', 'excluded'] as $name) {
        $pdo->prepare('INSERT INTO inventories (name, location_id, system_key) VALUES (?, ?, ?)')
            ->execute(['TEST packaging ' . $name, $location, $name === 'excluded' ? 'test_' . bin2hex(random_bytes(4)) : null]);
        $inventories[] = (int)$pdo->lastInsertId();
    }
    $products = [];
    foreach (['each', 'each', 'kilo'] as $unit) {
        $pdo->prepare("INSERT INTO products (name, category_id, type, unit) VALUES ('TEST packaging actual material', ?, 'material', ?)")->execute([$category, $unit]);
        $products[] = (int)$pdo->lastInsertId();
    }
    $balance = function (int $inventory, int $product) use ($pdo): float {
        $stmt = $pdo->prepare('SELECT quantity FROM inventory_products WHERE inventory_id = ? AND product_id = ?');
        $stmt->execute([$inventory, $product]);
        return (float)($stmt->fetchColumn() ?: 0);
    };
    $makeOrder = function () use ($pdo, $source, $location, $products, $inventories): array {
        foreach ($products as $product) {
            foreach ($inventories as $index => $inventory) {
                $pdo->prepare('INSERT INTO inventory_products (inventory_id, product_id, quantity) VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)')->execute([$inventory, $product, $index === 0 ? 60 : ($index === 1 ? 500 : 1000)]);
            }
        }
        $pdo->prepare("INSERT INTO manufacturing_orders (order_number,customer_id,product_id,formula_id,location_id,number_of_bottles,batch_size,status)
            VALUES (?, ?, ?, ?, ?, 100, 10000, 'in_progress')")
            ->execute(['TEST-PACK-STOCK-' . bin2hex(random_bytes(5)), $source['customer_id'], $source['product_id'], $source['formula_id'], $location]);
        $order = (int)$pdo->lastInsertId();
        $ids = [];
        foreach ([['packaging', 0, 100, 'pcs'], ['packaging', 1, 200, 'each'], ['formula', 2, 500, 'g']] as [$kind, $index, $quantity, $unit]) {
            $pdo->prepare("INSERT INTO manufacturing_sourcing_components
                (manufacturing_order_id, source, formula_component_index, product_id, component_name, required_quantity, unit)
                VALUES (?, ?, ?, ?, ?, ?, ?)")->execute([$order, $kind, $index, $products[$index], 'Actual material ' . $index, $quantity, $unit]);
            $ids[] = (int)$pdo->lastInsertId();
        }
        manufacturing_deduct_sourcing_stock($pdo, $order);
        return [$order, $ids];
    };
    $load = function (int $id) use ($pdo): array|false {
        $stmt = $pdo->prepare('SELECT * FROM manufacturing_packaging_material_tracking WHERE sourcing_component_id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    };
    [$order, $ids] = $makeOrder();
    $pdo->exec('SAVEPOINT equal_packaging');
    $logsBefore = (int)$pdo->query('SELECT COUNT(*) FROM inventory_stock_logs')->fetchColumn();
    manufacturing_reconcile_finished_count($pdo, $order, 100);
    checkPackagingStock((int)$pdo->query('SELECT COUNT(*) FROM inventory_stock_logs')->fetchColumn() === $logsBefore
        && (float)$load($ids[0])['returned_quantity'] === 0.0, 'Equal output makes no additional stock movement');
    $pdo->exec('ROLLBACK TO SAVEPOINT equal_packaging');
    manufacturing_reconcile_finished_count($pdo, $order, 110);
    checkPackagingStock($balance($inventories[1], $products[0]) === 450.0 && $balance($inventories[1], $products[1]) === 340.0,
        'Excess output deducts 10 bottles and 20 labels according to their actual per-product quantities');
    checkPackagingStock($balance($inventories[0], $products[2]) === 59.5 && $balance($inventories[2], $products[0]) === 1000.0,
        'Extra output leaves raw ingredients and system warehouses unchanged');
    $plan = $pdo->query("SELECT number_of_bottles, batch_size FROM manufacturing_orders WHERE id = $order")->fetch(PDO::FETCH_ASSOC);
    checkPackagingStock((int)$plan['number_of_bottles'] === 100 && (float)$plan['batch_size'] === 10000.0, 'Extra output preserves planned bottles and formula batch size');
    $stockBefore = $balance($inventories[1], $products[0]);
    checkPackagingStock(manufacturing_reconcile_finished_count($pdo, $order, 110) === null
        && manufacturing_reconcile_finished_count($pdo, $order, 90) === null
        && $balance($inventories[1], $products[0]) === $stockBefore, 'Posted packaging cannot be duplicated or recalculated by a repeated submission');
    foreach ($ids as $id) manufacturing_return_component_stock($pdo, ['id' => $id, 'manufacturing_order_id' => $order]);
    checkPackagingStock($balance($inventories[0], $products[0]) === 60.0 && $balance($inventories[1], $products[0]) === 500.0
        && $balance($inventories[1], $products[1]) === 500.0, 'Cancellation restores original and extra allocations exactly once');

    [$order, $ids] = $makeOrder();
    manufacturing_save_material_waste($pdo, $order, [$ids[0] => '3']);
    manufacturing_reconcile_finished_count($pdo, $order, 90);
    $row = $load($ids[0]);
    checkPackagingStock((float)$row['issued_quantity'] === 100.0 && (float)$row['used_quantity'] === 90.0
        && (float)$row['returned_quantity'] === 7.0 && (float)$row['waste_quantity'] === 3.0,
        'Lower output fills راجع after subtracting actual waste: 100 = 90 + 7 + 3');
    checkPackagingStock($balance($inventories[0], $products[0]) === 7.0 && $balance($inventories[1], $products[0]) === 460.0
        && $balance($inventories[0], $products[1]) === 20.0, 'Unused packaging returns only to warehouses that supplied it');
    try {
        manufacturing_save_material_waste($pdo, $order, [$ids[0] => '4']);
        throw new LogicException('Posted packaging waste changed');
    } catch (DomainException $e) { checkPackagingStock(str_contains($e->getMessage(), 'locked'), 'Posted waste cannot invalidate the material reconciliation'); }
    manufacturing_post_material_waste($pdo, ['id' => $order, 'order_number' => 'TEST packaging']);
    $wasteId = (int)$pdo->query("SELECT id FROM inventories WHERE system_key = 'manufacturing_waste' AND location_id = $location")->fetchColumn();
    checkPackagingStock($balance($wasteId, $products[0]) === 3.0 && $balance($inventories[0], $products[0]) === 7.0,
        'Actual packaging waste posts once without deducting the already-consumed material again');
    manufacturing_save_packaging_material_notes($pdo, $order, [$ids[0] => ['returned' => 'Verified return']]);
    checkPackagingStock($load($ids[0])['returned_notes'] === 'Verified return', 'Dispatch notes reference the actual component identity');
    checkPackagingStock(str_contains(manufacturing_packaging_material_html([
        ['component_name' => 'Visible bottle', 'sku' => 'SKU-123', 'unit' => 'pcs'] + $row,
    ], false), 'Visible bottle'), 'Packaging material report displays packaging names even when formula names are hidden');

    [$order, $ids] = $makeOrder();
    $pdo->exec('SAVEPOINT shortage_packaging');
    $pdo->prepare('UPDATE inventory_products SET quantity = 0 WHERE inventory_id = ? AND product_id = ?')->execute([$inventories[1], $products[1]]);
    try {
        manufacturing_reconcile_finished_count($pdo, $order, 110);
        throw new LogicException('Packaging shortage accepted');
    } catch (DomainException $e) {
        checkPackagingStock(str_contains($e->getMessage(), 'stock'), 'Insufficient combined packaging stock blocks completion');
        $pdo->exec('ROLLBACK TO SAVEPOINT shortage_packaging');
    }
    checkPackagingStock(!$load($ids[0]) && $balance($inventories[1], $products[0]) === 460.0,
        'Failed completion rolls back partial extra deductions and tracking snapshots');
    $pdo->prepare('DELETE FROM inventory_stock_logs WHERE source_id = ? AND source_type = ?')->execute([$ids[0], 'manufacturing_sourcing_component']);
    try {
        manufacturing_reconcile_finished_count($pdo, $order, 90);
        throw new LogicException('Legacy return guessed');
    } catch (DomainException $e) { checkPackagingStock(str_contains($e->getMessage(), 'legacy'), 'Historical allocations are never guessed when returning packaging'); }

    [$order, $ids] = $makeOrder();
    manufacturing_reconcile_finished_count($pdo, $order, 0);
    checkPackagingStock((float)$load($ids[0])['returned_quantity'] === 100.0
        && $balance($inventories[0], $products[0]) === 60.0 && $balance($inventories[1], $products[0]) === 500.0,
        'Explicit zero usable output returns all packaging when no waste is recorded');
    $cartons = manufacturing_packaging_balance(['required_quantity' => 10, 'unit' => 'pcs', 'stock_unit' => 'each'], 100, 95);
    checkPackagingStock($cartons['used_quantity'] === 10.0 && $cartons['returned_quantity'] === 0.0,
        'A partially filled carton consumes a whole carton');
    [$order, $ids] = $makeOrder();
    $pdo->prepare("UPDATE manufacturing_sourcing_components SET source = 'packaging' WHERE id = ?")->execute([$ids[2]]);
    manufacturing_reconcile_finished_count($pdo, $order, 110);
    checkPackagingStock($balance($inventories[0], $products[2]) === 59.45 && (float)$load($ids[2])['used_quantity'] === 550.0,
        'Packaging measured in grams deducts its extra quantity in native kilograms');
    [$order, $ids] = $makeOrder();
    $pdo->prepare('UPDATE manufacturing_orders SET number_of_bottles = NULL WHERE id = ?')->execute([$order]);
    try {
        manufacturing_reconcile_finished_count($pdo, $order, 110);
        throw new LogicException('Batch volume used as bottle count');
    } catch (DomainException $e) { checkPackagingStock(str_contains($e->getMessage(), 'bottle count'), 'Legacy batch volume is never treated as a planned bottle count'); }
    $pdo->prepare('UPDATE manufacturing_orders SET number_of_bottles = 100 WHERE id = ?')->execute([$order]);
    $pdo->prepare('UPDATE manufacturing_sourcing_components SET deducted_at = NULL WHERE id = ?')->execute([$ids[0]]);
    try {
        manufacturing_reconcile_finished_count($pdo, $order, 110);
        throw new LogicException('Unsourced packaging accepted');
    } catch (DomainException $e) { checkPackagingStock(str_contains($e->getMessage(), 'sourcing'), 'Packaging cannot reconcile materials that were never issued'); }
} finally { $pdo->rollBack(); }
echo "All packaging stock checks passed; fixture writes rolled back.\n";

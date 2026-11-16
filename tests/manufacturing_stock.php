<?php
// Rollback-only integration checks; run with GAMMAVET_MANUFACTURING_TEST=1.
if (PHP_SAPI !== 'cli' || !getenv('GAMMAVET_MANUFACTURING_TEST')) {
    throw new RuntimeException('CLI manufacturing integration checks only.');
}
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../modules/manufacturing/lib.php';
function checkStock(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
    echo "PASS $message\n";
}
$_SESSION = ['login_region' => 'factory'];
$pdo->beginTransaction();
try {
    $source = $pdo->query('SELECT customer_id, product_id, formula_id FROM manufacturing_orders WHERE product_id IS NOT NULL LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    if (!$source) throw new RuntimeException('A baseline manufacturing order is required.');
    $category = $pdo->query('SELECT id FROM categories LIMIT 1')->fetchColumn();
    $pdo->exec("INSERT INTO locations (name) VALUES ('TEST stock allocation')");
    $location = (int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO locations (name) VALUES ('TEST other stock location')");
    $otherLocation = (int)$pdo->lastInsertId();
    $warehouses = [];
    foreach (['first', 'second', 'waste', 'inactive', 'other location', 'other channel'] as $name) {
        $pdo->prepare('INSERT INTO inventories (name, location_id, is_active, system_key, direct_sale) VALUES (?, ?, ?, ?, ?)')
            ->execute(['TEST ' . $name, $name === 'other location' ? $otherLocation : $location,
                $name === 'inactive' ? 0 : 1, $name === 'waste' ? 'test_waste_' . bin2hex(random_bytes(5)) : null,
                $name === 'other channel' ? 'curva' : null]);
        $warehouses[$name] = (int)$pdo->lastInsertId();
    }
    $makeProduct = function (string $unit) use ($pdo, $category): int {
        $pdo->prepare("INSERT INTO products (name, category_id, type, unit) VALUES ('TEST stock material', ?, 'material', ?)")->execute([$category, $unit]);
        return (int)$pdo->lastInsertId();
    };
    $product = $makeProduct('each');
    $mass = $makeProduct('kilo');
    $setStock = function (int $inventory, int $product, float $quantity) use ($pdo): void {
        $pdo->prepare('INSERT INTO inventory_products (inventory_id, product_id, quantity) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)')->execute([$inventory, $product, $quantity]);
    };
    $balance = function (int $inventory, int $product) use ($pdo): float {
        $q = $pdo->prepare('SELECT quantity FROM inventory_products WHERE inventory_id = ? AND product_id = ?');
        $q->execute([$inventory, $product]);
        return (float)($q->fetchColumn() ?: 0);
    };
    foreach ($warehouses as $name => $id) $setStock($id, $product, $name === 'first' ? 40 : ($name === 'second' ? 60 : 1000));
    $setStock($warehouses['first'], $mass, 2);
    $makeOrder = function () use ($pdo, $source, $location): int {
        $pdo->prepare("INSERT INTO manufacturing_orders (order_number,customer_id,product_id,formula_id,location_id,batch_size,status)
            VALUES (?, ?, ?, ?, ?, 100, 'in_progress')")->execute(['TEST-STOCK-' . bin2hex(random_bytes(5)), $source['customer_id'], $source['product_id'], $source['formula_id'], $location]);
        return (int)$pdo->lastInsertId();
    };
    $addComponent = function (int $order, int $product, float $quantity, string $unit) use ($pdo): int {
        $pdo->prepare("INSERT INTO manufacturing_sourcing_components (manufacturing_order_id,formula_component_index,product_id,component_name,required_quantity,unit)
            VALUES (?,0,?,'TEST material',?,?)")->execute([$order, $product, $quantity, $unit]);
        return (int)$pdo->lastInsertId();
    };
    $order = $makeOrder();
    $first = $addComponent($order, $product, 60, 'pcs');
    $second = $addComponent($order, $product, 10, 'each');
    $ingredient = $addComponent($order, $mass, 500, 'g');
    $pdo->exec('SAVEPOINT before_deduction');
    manufacturing_deduct_sourcing_stock($pdo, $order);
    checkStock($balance($warehouses['first'], $product) === 0.0 && $balance($warehouses['second'], $product) === 30.0,
        '70 units deducted once across two warehouses, including duplicate material rows');
    checkStock($balance($warehouses['first'], $mass) === 1.5, '500 grams deducts 0.5 kg of stock');
    foreach (['waste', 'inactive', 'other location', 'other channel'] as $name) {
        checkStock($balance($warehouses[$name], $product) === 1000.0, "$name stock is untouched");
    }
    $logs = (int)$pdo->query("SELECT COUNT(*) FROM inventory_stock_logs WHERE source_type = 'manufacturing_sourcing_component' AND source_id IN ($first,$second,$ingredient)")->fetchColumn();
    manufacturing_deduct_sourcing_stock($pdo, $order);
    checkStock($balance($warehouses['second'], $product) === 30.0
        && (int)$pdo->query("SELECT COUNT(*) FROM inventory_stock_logs WHERE source_type = 'manufacturing_sourcing_component' AND source_id IN ($first,$second,$ingredient)")->fetchColumn() === $logs,
        'Repeated sourcing does not duplicate deductions or logs');
    manufacturing_save_receipt_stock($pdo, $order, [$first => 'rejected'], [$first => 'Rejected sample']);
    checkStock($balance($warehouses['first'], $product) === 40.0 && $balance($warehouses['second'], $product) === 50.0,
        'Rejection restores only its own withdrawal to each original warehouse');
    manufacturing_save_receipt_stock($pdo, $order, [$first => 'rejected'], []);
    checkStock($balance($warehouses['second'], $product) === 50.0, 'Repeated rejection cannot inflate stock');
    try {
        manufacturing_save_receipt_stock($pdo, $order, [$first => 'approved'], []);
        throw new LogicException('Returned material approved without sourcing');
    } catch (DomainException $e) {}
    manufacturing_deduct_sourcing_stock($pdo, $order);
    checkStock($balance($warehouses['second'], $product) === 30.0, 'A returned component can be sourced again without touching other consumed rows');
    foreach ([$first, $second, $ingredient] as $id) {
        manufacturing_return_component_stock($pdo, ['id' => $id, 'manufacturing_order_id' => $order]);
    }
    checkStock($balance($warehouses['first'], $product) === 40.0 && $balance($warehouses['second'], $product) === 60.0
        && $balance($warehouses['first'], $mass) === 2.0, 'Cancellation returns the original allocations and native stock units');
    manufacturing_return_component_stock($pdo, ['id' => $first, 'manufacturing_order_id' => $order]);
    checkStock($balance($warehouses['first'], $product) === 40.0, 'Repeated cancellation return is harmless');

    $pdo->exec('ROLLBACK TO SAVEPOINT before_deduction');
    checkStock((int)$pdo->query("SELECT COUNT(*) FROM inventory_stock_logs WHERE source_type = 'manufacturing_sourcing_component' AND source_id IN ($first,$second,$ingredient)")->fetchColumn() === 0,
        'Failed workflow save rolls back movements and their logs together');
    $insufficient = $makeOrder();
    $addComponent($insufficient, $product, 50, 'pcs');
    $addComponent($insufficient, $product, 60, 'pcs');
    $pdo->exec('SAVEPOINT before_shortage');
    try {
        manufacturing_deduct_sourcing_stock($pdo, $insufficient);
        throw new LogicException('Insufficient combined requirements accepted');
    } catch (DomainException $e) { $pdo->exec('ROLLBACK TO SAVEPOINT before_shortage'); }
    checkStock($balance($warehouses['first'], $product) === 40.0 && $balance($warehouses['second'], $product) === 60.0,
        'Combined shortage rolls back partial deductions and ignores excluded stock');
    $legacy = $makeOrder();
    $legacyComponent = $addComponent($legacy, $product, 5, 'pcs');
    $pdo->prepare('UPDATE manufacturing_sourcing_components SET deducted_at = NOW() WHERE id = ?')->execute([$legacyComponent]);
    try {
        manufacturing_save_receipt_stock($pdo, $legacy, [$legacyComponent => 'rejected'], []);
        throw new LogicException('Legacy allocation guessed');
    } catch (DomainException $e) { checkStock(str_contains($e->getMessage(), 'legacy deduction'), 'Historical deductions require reconciliation instead of guessing warehouse returns'); }
    $invalid = $makeOrder();
    $addComponent($invalid, $mass, 1, 'pcs');
    try { manufacturing_deduct_sourcing_stock($pdo, $invalid); throw new LogicException('Incompatible units accepted'); }
    catch (DomainException $e) { checkStock(true, 'Incompatible stock units are rejected'); }
} finally { $pdo->rollBack(); }
echo "All manufacturing stock checks passed; fixture writes rolled back.\n";

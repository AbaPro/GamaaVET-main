<?php
// Rollback-only integration checks: GAMMAVET_MANUFACTURING_TEST=1 php tests/manufacturing_waste.php
if (PHP_SAPI !== 'cli' || !getenv('GAMMAVET_MANUFACTURING_TEST')) throw new RuntimeException('CLI checks only.');
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../modules/manufacturing/lib.php';
function verifyWaste($ok, $label) { if (!$ok) throw new RuntimeException($label); echo "PASS $label\n"; }
$pdo->beginTransaction();
try {
    $source = $pdo->query('SELECT customer_id, product_id, formula_id FROM manufacturing_orders WHERE product_id IS NOT NULL LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    $product = (int)$source['product_id'];
    $waste = (int)$pdo->query("SELECT id FROM inventories WHERE system_key = 'manufacturing_waste'")->fetchColumn();
    verifyWaste($waste > 0, 'Fixed waste warehouse exists');
    $pdo->exec("INSERT INTO inventories (name,is_active) VALUES ('TEST finished output',1)");
    $normal = (int)$pdo->lastInsertId();
    $stock = function ($inventory) use ($pdo, $product) {
        $q = $pdo->prepare('SELECT quantity FROM inventory_products WHERE inventory_id = ? AND product_id = ?');
        $q->execute([$inventory,$product]); return (float)($q->fetchColumn() ?: 0);
    };
    $makeOrder = function ($final, $broken, $status = 'completed') use ($pdo, $source) {
        $pdo->prepare('INSERT INTO manufacturing_orders (order_number,customer_id,product_id,formula_id,number_of_bottles,batch_size,status) VALUES (?, ?, ?, ?, 100, 100, ?)')
            ->execute(['TEST-WASTE-' . bin2hex(random_bytes(5)), $source['customer_id'], $source['product_id'], $source['formula_id'], $status]);
        $id = (int)$pdo->lastInsertId();
        foreach (['final_product_count' => $final, 'breakage_count' => $broken, 'boxes_waste' => '50', 'last_carton_units' => '7'] as $key => $value) {
            $pdo->prepare("INSERT INTO manufacturing_packaging_checklist (manufacturing_order_id, section_name, item_key, item_type, item_value) VALUES (?, 'after', ?, 'number', ?)")->execute([$id,$key,$value]);
        }
        return ['id' => $id];
    };
    $initialWaste = $stock($waste);
    $order = $makeOrder('95','5');
    manufacturing_add_finished_goods($pdo,$order,$normal);
    verifyWaste($stock($normal) === 95.0, 'Usable final count, not ordered quantity, goes to normal stock');
    verifyWaste($stock($waste) === $initialWaste + 5, 'Only breakage goes to waste; packaging waste and partial cartons excluded');
    verifyWaste(manufacturing_add_finished_goods($pdo,$order,$normal) === null && $stock($waste) === $initialWaste + 5, 'Repeated completion cannot duplicate either stock movement');
    $q = $pdo->prepare("SELECT COUNT(*) FROM inventory_stock_logs WHERE source_type = 'manufacturing_waste' AND source_id = ?");
    $q->execute([$order['id']]); verifyWaste((int)$q->fetchColumn() === 1, 'Waste movement has one audit record linked to the manufacturing order');
    $zero = $makeOrder('100',''); manufacturing_add_finished_goods($pdo,$zero,$normal);
    verifyWaste($stock($waste) === $initialWaste + 5, 'Blank breakage adds no waste');
    $allWaste = $makeOrder('0','100'); manufacturing_add_finished_goods($pdo,$allWaste,$normal);
    verifyWaste($stock($normal) === 195.0 && $stock($waste) === $initialWaste + 105, 'Zero usable output can be completed when all units are damaged');
    $invalid = $makeOrder('90','-1');
    try { manufacturing_add_finished_goods($pdo,$invalid,$normal); throw new LogicException('Negative waste accepted'); }
    catch (RuntimeException $ex) { verifyWaste(str_contains($ex->getMessage(),'whole numbers'), 'Invalid breakage rejected'); }
    $pending = $makeOrder('90','10','in_progress');
    try { manufacturing_add_finished_goods($pdo,$pending,$normal); throw new LogicException('Incomplete order accepted'); }
    catch (RuntimeException $ex) { verifyWaste(str_contains($ex->getMessage(),'completed orders'), 'Unfinished orders cannot add stock'); }
    $badDestination = $makeOrder('90','10');
    try { manufacturing_add_finished_goods($pdo,$badDestination,$waste); throw new LogicException('Waste destination accepted as normal'); }
    catch (RuntimeException $ex) { verifyWaste(str_contains($ex->getMessage(),'normal active'), 'Waste warehouse cannot receive usable output'); }
    $rollback = $makeOrder('90','10');
    $pdo->exec('SAVEPOINT before_stock');
    $pdo->prepare('UPDATE inventories SET is_active = 0 WHERE id = ?')->execute([$waste]);
    try { manufacturing_add_finished_goods($pdo,$rollback,$normal); throw new LogicException('Missing waste warehouse accepted'); }
    catch (RuntimeException $ex) { verifyWaste(str_contains($ex->getMessage(),'waste inventory is missing'), 'Missing waste warehouse prevents completion'); }
    $pdo->exec('ROLLBACK TO SAVEPOINT before_stock');
    verifyWaste($stock($normal) === 195.0 && $stock($waste) === $initialWaste + 105, 'Failure rolls back stock movements together');
    $q = $pdo->prepare('SELECT finished_goods_added_at FROM manufacturing_orders WHERE id = ?'); $q->execute([$rollback['id']]);
    verifyWaste($q->fetchColumn() === null, 'Failed transfer does not leave a completion marker');
} finally { $pdo->rollBack(); }
echo "All waste inventory checks passed; fixture writes rolled back.\n";

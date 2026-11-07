<?php
// Rollback-only integration checks: GAMMAVET_MANUFACTURING_TEST=1 php tests/manufacturing_waste.php
if (PHP_SAPI !== 'cli' || !getenv('GAMMAVET_MANUFACTURING_TEST')) throw new RuntimeException('CLI checks only.');
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../modules/manufacturing/lib.php';
function verifyWaste($ok, $label) { if (!$ok) throw new RuntimeException($label); echo "PASS $label\n"; }
$user = $conn->query('SELECT id, session_version FROM users WHERE is_active = 1 LIMIT 1')->fetch_assoc();
$_SESSION = ['user_id' => (int)$user['id'], 'session_version' => $user['session_version'], 'role_slug' => 'admin', 'permissions' => []];
$_SESSION['formula_unlocked'] = true;
$_SESSION['formula_password_version'] = formulaPasswordVersion();
$pdo->beginTransaction();
try {
    $source = $pdo->query('SELECT customer_id, product_id, formula_id FROM manufacturing_orders WHERE product_id IS NOT NULL LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    $final = (int)$source['product_id'];
    $waste = (int)$pdo->query("SELECT id FROM inventories WHERE system_key = 'manufacturing_waste'")->fetchColumn();
    verifyWaste($waste > 0, 'Fixed waste warehouse exists');
    verifyWaste(!canAddProductToInventory($waste, $final), 'Manual additions and transfers cannot put final products into waste');
    $pdo->exec("INSERT INTO inventories (name,is_active) VALUES ('TEST finished output',1)");
    $normal = (int)$pdo->lastInsertId();
    $category = $pdo->query('SELECT category_id FROM products LIMIT 1')->fetchColumn();
    $raw = [];
    foreach (['kilo', 'each'] as $unit) {
        $pdo->prepare("INSERT INTO products (name,category_id,type,unit) VALUES ('TEST waste material',?,'material',?)")->execute([$category, $unit]);
        $raw[] = (int)$pdo->lastInsertId();
    }
    $stock = function ($inventory, $product) use ($pdo) {
        $q = $pdo->prepare('SELECT quantity FROM inventory_products WHERE inventory_id = ? AND product_id = ?');
        $q->execute([$inventory,$product]); return (float)($q->fetchColumn() ?: 0);
    };
    $makeOrder = function ($usable, $broken, $status = 'in_progress') use ($pdo, $source) {
        $pdo->prepare('INSERT INTO manufacturing_orders (order_number,customer_id,product_id,formula_id,number_of_bottles,batch_size,status) VALUES (?, ?, ?, ?, 100, 100, ?)')
            ->execute(['TEST-WASTE-' . bin2hex(random_bytes(5)), $source['customer_id'], $source['product_id'], $source['formula_id'], $status]);
        $id = (int)$pdo->lastInsertId();
        foreach (['final_product_count' => $usable, 'breakage_count' => $broken, 'boxes_waste' => '50'] as $key => $value) {
            $pdo->prepare("INSERT INTO manufacturing_packaging_checklist (manufacturing_order_id, section_name, item_key, item_type, item_value) VALUES (?, 'after', ?, 'number', ?)")->execute([$id,$key,$value]);
        }
        return ['id' => $id];
    };
    $consume = function ($order, $product, $unit, $amount, $sourceType = 'formula') use ($pdo) {
        $pdo->prepare("INSERT INTO manufacturing_sourcing_components (manufacturing_order_id,source,formula_component_index,product_id,component_name,required_quantity,unit,deducted_at) VALUES (?,?,0,?,'TEST waste material',?,?,NOW())")->execute([$order['id'],$sourceType,$product,$amount,$unit]);
        return (int)$pdo->lastInsertId();
    };
    $initialFinalWaste = $stock($waste, $final);
    $order = $makeOrder('95','5');
    $ingredient = $consume($order,$raw[0],'g',1000);
    $packaging = $consume($order,$raw[1],'pcs',100,'packaging');
    manufacturing_save_material_waste($pdo,$order['id'],[$ingredient=>'250', $packaging=>'5']);
    manufacturing_save_material_waste($pdo,$order['id'],[$ingredient=>'250', $packaging=>'5']);
    verifyWaste((int)$pdo->query('SELECT COUNT(*) FROM manufacturing_material_waste WHERE manufacturing_order_id = ' . $order['id'])->fetchColumn() === 2, 'Saving waste twice updates drafts without duplicate lines');
    foreach ([[$ingredient=>'1001'], [$ingredient=>'-1'], [$ingredient=>'abc'], [$ingredient=>'0.001'], [999999999=>'1']] as $bad) {
        try { manufacturing_save_material_waste($pdo,$order['id'],$bad); throw new LogicException('Invalid waste accepted'); }
        catch (DomainException $e) {}
    }
    verifyWaste(true, 'Reject over-consumption, negative, invalid, unrepresentable and unrelated waste');
    $_SESSION['role_slug'] = 'driver'; $_SESSION['permissions'] = ['manufacturing.view_step_delivering'];
    try { manufacturing_add_finished_goods($pdo,$order,$normal); throw new LogicException('Driver completed an order'); } catch (DomainException $e) {}
    try { manufacturing_save_material_waste($pdo,$order['id'],[$ingredient=>'1']); throw new LogicException('Driver saved waste'); } catch (DomainException $e) {}
    verifyWaste(true, 'Delivery permission cannot complete orders or record waste');
    $_SESSION['role_slug'] = 'admin';
    $pdo->prepare("UPDATE manufacturing_orders SET status = 'completed' WHERE id = ?")->execute([$order['id']]);
    manufacturing_add_finished_goods($pdo,$order,$normal);
    verifyWaste($stock($normal,$final) === 95.0, 'Usable output goes to finished stock');
    verifyWaste($stock($waste,$raw[0]) === 0.25 && $stock($waste,$raw[1]) === 5.0, 'Ingredient and packaging waste enter their actual SKUs in native stock units');
    verifyWaste($stock($waste,$final) === $initialFinalWaste, 'Final-product breakage never adds final-product stock to waste');
    verifyWaste($stock($normal,$raw[0]) === 0.0, 'Already-consumed material is not deducted a second time');
    verifyWaste(manufacturing_add_finished_goods($pdo,$order,$normal) === null && $stock($waste,$raw[0]) === 0.25, 'Repeated completion cannot duplicate stock');
    $q = $pdo->prepare("SELECT COUNT(*) FROM inventory_stock_logs WHERE source_type = 'manufacturing_material_waste' AND source_id IN (SELECT id FROM manufacturing_material_waste WHERE manufacturing_order_id = ?)");
    $q->execute([$order['id']]); verifyWaste((int)$q->fetchColumn() === 2, 'Each material waste movement has an audit record');
    try { manufacturing_save_material_waste($pdo,$order['id'],[$ingredient=>'0']); throw new LogicException('Completed waste edited'); } catch (DomainException $e) {}
    $onlyBroken = $makeOrder('0','100','completed');
    manufacturing_add_finished_goods($pdo,$onlyBroken,$normal);
    verifyWaste($stock($normal,$final) === 95.0 && $stock($waste,$final) === $initialFinalWaste, 'All-broken output remains a metric without guessed material waste');
    $rollback = $makeOrder('90','10');
    $row = $consume($rollback,$raw[1],'pcs',100,'packaging');
    manufacturing_save_material_waste($pdo,$rollback['id'],[$row=>'10']);
    $pdo->exec('SAVEPOINT before_stock');
    $pdo->prepare("UPDATE manufacturing_orders SET status = 'completed' WHERE id = ?")->execute([$rollback['id']]);
    $pdo->prepare('UPDATE inventories SET is_active = 0 WHERE id = ?')->execute([$waste]);
    try { manufacturing_add_finished_goods($pdo,$rollback,$normal); throw new LogicException('Missing warehouse accepted'); } catch (RuntimeException $e) { verifyWaste(str_contains($e->getMessage(),'waste inventory is missing'), 'Missing waste warehouse prevents posting'); }
    $pdo->exec('ROLLBACK TO SAVEPOINT before_stock');
    verifyWaste($stock($normal,$final) === 95.0 && $stock($waste,$raw[1]) === 5.0, 'Failed completion rolls back all stock changes together');
    $q = $pdo->prepare('SELECT finished_goods_added_at FROM manufacturing_orders WHERE id = ?'); $q->execute([$rollback['id']]);
    verifyWaste($q->fetchColumn() === null, 'Failed posting leaves no completion marker');
} finally { $pdo->rollBack(); }
echo "All material waste checks passed; fixture writes rolled back.\n";

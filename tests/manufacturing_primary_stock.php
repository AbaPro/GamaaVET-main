<?php
if (PHP_SAPI !== 'cli' || !getenv('GAMMAVET_MANUFACTURING_TEST')) throw new RuntimeException('CLI regression checks only.');
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../modules/manufacturing/lib.php';
function checkPrimary(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "PASS $message\n";
}
$user = $pdo->query('SELECT id, session_version FROM users WHERE is_active = 1 LIMIT 1')->fetch(PDO::FETCH_ASSOC);
$_SESSION = ['user_id' => (int)$user['id'], 'session_version' => $user['session_version'], 'role_slug' => 'admin', 'login_region' => 'factory'];
$pdo->beginTransaction();
try {
    $source = $pdo->query('SELECT customer_id FROM manufacturing_orders LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    $category = (int)$pdo->query('SELECT id FROM categories LIMIT 1')->fetchColumn();
    $pdo->exec("INSERT INTO locations (name) VALUES ('TEST primary stock location')");
    $location = (int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO locations (name) VALUES ('TEST other primary stock location')");
    $otherLocation = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO inventories (name,location_id) VALUES ('TEST primary stock source',?)")->execute([$location]);
    $inventory = (int)$pdo->lastInsertId();
    $products = [];
    foreach ([['final','each'], ['material','each'], ['material','each'], ['material','kilo']] as [$type,$unit]) {
        $pdo->prepare('INSERT INTO products (name,category_id,type,unit,customer_id) VALUES (?, ?, ?, ?, ?)')
            ->execute(['TEST primary ' . count($products), $category, $type, $unit, $source['customer_id']]);
        $products[] = (int)$pdo->lastInsertId();
    }
    foreach (array_slice($products, 1) as $product) $pdo->prepare('INSERT INTO inventory_products (inventory_id,product_id,quantity) VALUES (?,?,500)')->execute([$inventory,$product]);
    $components = [['product_id'=>$products[3],'name'=>'Ingredient','quantity'=>5,'unit'=>'kg']];
    $formulaJson = json_encode($components);
    $pdo->prepare("INSERT INTO manufacturing_formulas (customer_id,product_id,name,batch_size,batch_unit,components_json)
        VALUES (?,?,'TEST primary formula',100,'L',?)")->execute([$source['customer_id'],$products[0],$formulaJson]);
    $formula = (int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO bottle_sizes (name,size,unit,type) VALUES ('TEST primary 100 ml',100,'ml','liquid')");
    $bottleSize = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO packaging_options (customer_id,product_id,name) VALUES (?,?,'TEST primary kit')")->execute([$source['customer_id'],$products[0]]);
    $kit = (int)$pdo->lastInsertId();
    foreach ([1=>1,2=>2] as $index=>$quantity) $pdo->prepare("INSERT INTO packaging_option_items (packaging_option_id,product_id,quantity,unit) VALUES (?,?,?,'pcs')")->execute([$kit,$products[$index],$quantity]);
    $makeOrder = function (int $count, int $primary = 0) use ($pdo,$source,$products,$formula,$location,$bottleSize,$kit): int {
        $pdo->prepare("INSERT INTO manufacturing_orders (order_number,customer_id,product_id,formula_id,location_id,bottle_size_id,number_of_bottles,batch_size,packaging_option_id,primary_stock_quantity,status)
            VALUES (?,?,?,?,?,?,?,?,?,?,'in_progress')")->execute(['TEST-PRIMARY-'.bin2hex(random_bytes(5)),$source['customer_id'],$products[0],$formula,$location,$bottleSize,$count,$count*100,$kit,$primary]);
        $id = (int)$pdo->lastInsertId();
        manufacturing_sync_sourcing($id);
        return $id;
    };
    $balance = function (int $inventory, int $product) use ($pdo): float {
        $stmt=$pdo->prepare('SELECT quantity FROM inventory_products WHERE inventory_id=? AND product_id=?');
        $stmt->execute([$inventory,$product]); return (float)($stmt->fetchColumn() ?: 0);
    };
    $materialIds = function (int $order) use ($pdo): array {
        $stmt=$pdo->prepare("SELECT product_id,id FROM manufacturing_sourcing_components WHERE manufacturing_order_id=? AND source='packaging'");
        $stmt->execute([$order]);return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    };
    $order = $makeOrder(100);
    manufacturing_deduct_sourcing_stock($pdo,$order);
    $ids = $materialIds($order);
    $entries = [$ids[$products[1]]=>['issued'=>'100','used'=>'80','waste'=>'5'], $ids[$products[2]]=>['issued'=>'200','used'=>'160','waste'=>'10']];
    manufacturing_save_packaging_material_entries($pdo,$order,100,$entries);
    checkPrimary($balance($inventory,$products[1])===400.0 && manufacturing_primary_availability($pdo,$products[0])['primary_product_id']===null,
        'Saving actual packaging drafts changes no stock and creates no primary SKU');
    manufacturing_reconcile_finished_count($pdo,$order,100);
    $primary = manufacturing_primary_availability($pdo,$products[0]);
    $primaryId = (int)$primary['primary_product_id'];
    $primaryInventory = (int)$pdo->query("SELECT id FROM inventories WHERE system_key='manufacturing_primary' AND location_id=$location")->fetchColumn();
    $wasteInventory = (int)$pdo->query("SELECT id FROM inventories WHERE system_key='manufacturing_waste' AND location_id=$location")->fetchColumn();
    checkPrimary($primaryInventory > 0 && $wasteInventory > 0,'New locations automatically receive both permanent warehouses');
    $output = $pdo->query("SELECT * FROM manufacturing_packaging_output WHERE manufacturing_order_id=$order")->fetch(PDO::FETCH_ASSOC);
    checkPrimary((int)$output['finished_quantity']===80 && (int)$output['primary_quantity']===20 && (int)$primary['quantity']===20,
        '100 produced minus 80 equivalent used packaging creates 20 primary products');
    checkPrimary($primaryId!==$products[0] && manufacturing_ensure_primary_product($pdo,$products[0])===$primaryId,
        'Each final product has one separate reusable primary SKU');
    checkPrimary($balance($inventory,$products[1])===415.0 && $balance($inventory,$products[2])===330.0,
        'Actual returns of 15 bottles and 30 labels go back to their source stock');
    checkPrimary($balance($wasteInventory,$products[1])===5.0 && $balance($wasteInventory,$products[2])===10.0,
        'Packaging waste goes directly into the waste inventory during packaging completion');
    $otherPrimary = (int)$pdo->query("SELECT id FROM inventories WHERE system_key='manufacturing_primary' AND location_id=$otherLocation")->fetchColumn();
    $otherWaste = (int)$pdo->query("SELECT id FROM inventories WHERE system_key='manufacturing_waste' AND location_id=$otherLocation")->fetchColumn();
    checkPrimary($balance($otherPrimary,$primaryId)===0.0 && $balance($otherWaste,$products[1])===0.0,
        'Waste and primary output never spill into another location');
    checkPrimary(manufacturing_reconcile_finished_count($pdo,$order,100)===null && (int)manufacturing_primary_availability($pdo,$products[0])['quantity']===20,
        'Repeated completion cannot duplicate primary output, returns or waste');
    $pdo->prepare("UPDATE manufacturing_orders SET status='completed' WHERE id=?")->execute([$order]);
    manufacturing_add_finished_goods($pdo,['id'=>$order],$inventory);
    checkPrimary($balance($inventory,$products[0])===80.0 && $balance($primaryInventory,$primaryId)===20.0,
        'Final stock-in adds only 80 packaged products and never duplicates the 20 primary products');
    checkPrimary($pdo->query("SELECT components_json FROM manufacturing_formulas WHERE id=$formula")->fetchColumn()===$formulaJson,
        'Actual output and packaging quantities never modify the stored formula');
    checkPrimary(!canAddProductToInventory($primaryInventory,$products[0]),'The primary warehouse cannot receive a final-product SKU');

    $pdo->prepare('INSERT INTO inventory_products (inventory_id,product_id,quantity) VALUES (?,?,100)')->execute([$otherPrimary,$primaryId]);
    checkPrimary((int)manufacturing_primary_availability($pdo,$products[0],$location)['quantity']===20
        && (int)manufacturing_primary_availability($pdo,$products[0],$otherLocation)['quantity']===100,
        'Availability reports the selected location’s primary stock separately');
    checkPrimary(manufacturing_validate_primary_choice($pdo,$products[0],'20',30,$location)===20,'Existing primary stock can be selected for a later manufacturing order');
    foreach (['21','-1','1.5',[]] as $bad) {
        try { manufacturing_validate_primary_choice($pdo,$products[0],$bad,30,$location); throw new LogicException('Invalid reuse accepted'); }
        catch (DomainException $e) {}
    }
    checkPrimary(true,'Unavailable, negative, fractional and invalid primary quantities are rejected');
    $reuse = $makeOrder(30,20);
    $requirements=$pdo->query("SELECT product_id,required_quantity FROM manufacturing_sourcing_components WHERE manufacturing_order_id=$reuse")->fetchAll(PDO::FETCH_KEY_PAIR);
    checkPrimary((float)$requirements[$products[3]]===0.05 && (float)$requirements[$products[1]]===30.0 && (float)$requirements[$products[2]]===60.0,
        'Reusing 20 of 30 products requires fresh ingredients for only 10 and packaging for all 30');
    $rawBefore = $balance($inventory,$products[3]);
    manufacturing_deduct_sourcing_stock($pdo,$reuse);
    checkPrimary($balance($primaryInventory,$primaryId)===0.0 && abs($balance($inventory,$products[3])-($rawBefore-0.05))<1e-8,
        'Sourcing deducts selected primary stock once and consumes only the fresh ingredient remainder');
    manufacturing_deduct_sourcing_stock($pdo,$reuse);
    checkPrimary($balance($primaryInventory,$primaryId)===0.0,'Repeated sourcing cannot consume primary stock twice');
    $pdo->exec('SAVEPOINT actual_primary_usage');
    $pdo->prepare("INSERT INTO manufacturing_packaging_checklist (manufacturing_order_id,section_name,item_key,item_text,item_type,item_value)
        VALUES (?,'after','primary_product_used','منتج اولي مستخدم','number','15')")->execute([$reuse]);
    manufacturing_reconcile_finished_count($pdo,$reuse,30);
    checkPrimary($balance($primaryInventory,$primaryId)===5.0
        && (int)$pdo->query("SELECT primary_used_quantity FROM manufacturing_packaging_output WHERE manufacturing_order_id=$reuse")->fetchColumn()===15,
        'منتج اولي مستخدم records actual use and returns the five selected primary units that were not used');
    checkPrimary(manufacturing_reconcile_finished_count($pdo,$reuse,30)===null && $balance($primaryInventory,$primaryId)===5.0,
        'Repeated packaging cannot repeat the actual-primary adjustment');
    manufacturing_reverse_primary_stock($pdo,manufacturing_lock_stock_order($pdo,$reuse));
    checkPrimary($balance($primaryInventory,$primaryId)===20.0,'Cancellation restores actual primary usage rather than the original planned quantity');
    $pdo->exec('ROLLBACK TO SAVEPOINT actual_primary_usage');
    $pdo->prepare('UPDATE inventory_products SET quantity=5 WHERE inventory_id=? AND product_id=?')->execute([$primaryInventory,$primaryId]);
    $pdo->prepare("INSERT INTO manufacturing_packaging_checklist (manufacturing_order_id,section_name,item_key,item_text,item_type,item_value)
        VALUES (?,'after','primary_product_used','منتج اولي مستخدم','number','25')")->execute([$reuse]);
    manufacturing_reconcile_finished_count($pdo,$reuse,30);
    checkPrimary($balance($primaryInventory,$primaryId)===0.0,'Actual primary use above the selected amount deducts only the extra units at this location');
    manufacturing_reverse_primary_stock($pdo,manufacturing_lock_stock_order($pdo,$reuse));
    checkPrimary($balance($primaryInventory,$primaryId)===25.0,'Cancelling extra primary usage restores the full actual consumed quantity');
    $pdo->exec('ROLLBACK TO SAVEPOINT actual_primary_usage');
    $pdo->prepare("INSERT INTO manufacturing_packaging_checklist (manufacturing_order_id,section_name,item_key,item_text,item_type,item_value)
        VALUES (?,'after','primary_product_used','منتج اولي مستخدم','number','31')")->execute([$reuse]);
    try { manufacturing_reconcile_finished_count($pdo,$reuse,30); throw new LogicException('Primary usage exceeds output'); }
    catch (DomainException $e) { checkPrimary(str_contains($e->getMessage(),'cannot exceed'),'Actual primary usage cannot exceed the total production count'); }
    $pdo->exec('ROLLBACK TO SAVEPOINT actual_primary_usage');
    $pdo->exec('SAVEPOINT primary_shortage');
    $unavailable = $makeOrder(20,20);
    try { manufacturing_deduct_sourcing_stock($pdo,$unavailable); throw new LogicException('Concurrent shortage accepted'); }
    catch (DomainException $e) { $pdo->exec('ROLLBACK TO SAVEPOINT primary_shortage'); }
    checkPrimary($balance($primaryInventory,$primaryId)===0.0,'Availability is checked again at consumption to prevent overspending stock');
    $locked=manufacturing_lock_stock_order($pdo,$reuse);
    manufacturing_reverse_primary_stock($pdo,$locked);
    $stmt=$pdo->query("SELECT * FROM manufacturing_sourcing_components WHERE manufacturing_order_id=$reuse ORDER BY product_id,id");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $component) manufacturing_return_component_stock($pdo,$component);
    checkPrimary($balance($primaryInventory,$primaryId)===20.0 && $balance($inventory,$products[3])===$rawBefore,
        'Cancellation restores consumed primary stock and its exact fresh-material allocations');
    $allPrimary=$makeOrder(20,20);
    $requirements=$pdo->query("SELECT source FROM manufacturing_sourcing_components WHERE manufacturing_order_id=$allPrimary")->fetchAll(PDO::FETCH_COLUMN);
    checkPrimary($requirements===['packaging','packaging'],'Using primary stock for the whole order requires packaging without any fresh ingredients');
    $_SESSION['login_region']='curva';
    try { manufacturing_validate_primary_choice($pdo,$products[0],'1',20); throw new LogicException('Cross-channel stock used'); }
    catch (DomainException $e) { checkPrimary(true,'Direct-sales channels cannot consume Factory primary stock'); }
} finally { $pdo->rollBack(); }
echo "All primary stock checks passed; fixture writes rolled back.\n";

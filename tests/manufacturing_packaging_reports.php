<?php
// All database fixtures are rolled back. Run in the local app container.
if (PHP_SAPI !== 'cli' || !getenv('GAMMAVET_MANUFACTURING_TEST')) throw new RuntimeException('CLI regression checks only.');
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../modules/manufacturing/lib.php';
function checkPackaging($ok, $message) {
    if (!$ok) throw new RuntimeException($message);
    echo "PASS $message\n";
}
manufacturing_validate_packaging_counts(['last_carton_units' => '']);
manufacturing_validate_packaging_counts(['last_carton_units' => '7', 'units_per_carton' => '12']);
checkPackaging(true, 'Partial carton accepts blank or a whole-number remainder');
foreach (['-1', '1.5', 'abc', '2147483648', []] as $bad) {
    try {
        manufacturing_validate_packaging_counts(['last_carton_units' => $bad]);
        throw new RuntimeException('Invalid count accepted');
    } catch (InvalidArgumentException $ex) {}
    try {
        manufacturing_validate_packaging_counts(['primary_product_used' => $bad]);
        throw new RuntimeException('Invalid primary usage accepted');
    } catch (InvalidArgumentException $ex) {}
}
checkPackaging(true, 'Negative, fractional, nonnumeric, oversized and array counts rejected');
try {
    manufacturing_validate_packaging_counts(['last_carton_units' => '12', 'units_per_carton' => '12']);
    throw new RuntimeException('Full carton accepted as a remainder');
} catch (InvalidArgumentException $ex) {}
$pdo->beginTransaction();
try {
    $source = $pdo->query('SELECT * FROM orders LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    $product = (int)$pdo->query("SELECT id FROM products WHERE type = 'final' LIMIT 1")->fetchColumn();
    $formula = (int)$pdo->query('SELECT id FROM manufacturing_formulas LIMIT 1')->fetchColumn();
    $pdo->prepare("INSERT INTO orders (customer_id,contact_id,order_date,total_amount,discount_percentage,discount_amount,shipping_cost,created_by,notes) VALUES (?, ?, CURDATE(), 900, 10, 150, 50, ?, 'Original sales note')")
        ->execute([$source['customer_id'], $source['contact_id'], $source['created_by']]);
    $salesId = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO order_items (order_id,product_id,quantity,unit_price,total_price) VALUES (?, ?, 100, 10, 1000)')->execute([$salesId, $product]);
    $pdo->prepare("INSERT INTO manufacturing_orders (order_number,customer_id,product_id,formula_id,number_of_bottles,batch_size,sales_order_id,notes) VALUES (?, ?, ?, ?, 100, 100, ?, 'Original manufacturing note')")
        ->execute(['TEST-PACK-' . bin2hex(random_bytes(5)), $source['customer_id'], $product, $formula, $salesId]);
    $mfgId = (int)$pdo->lastInsertId();
    foreach ([100, 110, 90] as $count) {
        checkPackaging(manufacturing_reconcile_finished_count($pdo, $mfgId, $count) === null, 'Orders without a packaging kit do not invent material movements');
    }
    $sales = $pdo->query("SELECT * FROM orders WHERE id = $salesId")->fetch(PDO::FETCH_ASSOC);
    $item = $pdo->query("SELECT * FROM order_items WHERE order_id = $salesId")->fetch(PDO::FETCH_ASSOC);
    $mfg = $pdo->query("SELECT * FROM manufacturing_orders WHERE id = $mfgId")->fetch(PDO::FETCH_ASSOC);
    checkPackaging((int)$item['quantity'] === 100 && (float)$item['total_price'] === 1000.0
        && (float)$sales['total_amount'] === 900.0 && (float)$sales['discount_amount'] === 150.0,
        'Final output never changes sales quantities, prices or discounts');
    checkPackaging((int)$mfg['number_of_bottles'] === 100 && (float)$mfg['batch_size'] === 100.0
        && $sales['notes'] === 'Original sales note' && $mfg['notes'] === 'Original manufacturing note',
        'Final output preserves the manufacturing plan and sales notes');
} finally { $pdo->rollBack(); }

// Report fixtures use the same mysqli connection as the report builders.
$conn->begin_transaction();
$imagePath = 'assets/uploads/manufacturing/packaging-regression-' . bin2hex(random_bytes(5)) . '.png';
$absoluteImage = __DIR__ . '/../' . $imagePath;
try {
    $user = $conn->query('SELECT id, session_version FROM users WHERE is_active = 1 LIMIT 1')->fetch_assoc();
    $_SESSION = ['user_id' => (int)$user['id'], 'session_version' => $user['session_version'], 'role_slug' => 'regression', 'permissions' => []];
    $source = $conn->query('SELECT customer_id, product_id, formula_id FROM manufacturing_orders LIMIT 1')->fetch_assoc();
    $customer = (int)$source['customer_id'];
    $product = (int)$source['product_id'];
    $json = $conn->real_escape_string(json_encode([['name' => 'SECRET-MATERIAL-REGRESSION', 'quantity' => 5, 'unit' => 'kg']]));
    $conn->query("INSERT INTO manufacturing_formulas (customer_id, name, batch_size, batch_unit, components_json) VALUES ($customer, 'SECRET-FORMULA-REGRESSION', 100, 'L', '$json')");
    $formula = $conn->insert_id;
    $conn->query("INSERT INTO bottle_sizes (name,size,unit,type) VALUES ('Report test', 100, 'ml', 'liquid')");
    $bottle = $conn->insert_id;
    $number = 'TEST-REPORT-' . bin2hex(random_bytes(5));
    $conn->query("INSERT INTO manufacturing_orders (order_number,customer_id,product_id,formula_id,bottle_size_id,number_of_bottles,batch_size) VALUES ('$number', $customer, $product, $formula, $bottle, 2000, 200000)");
    $id = $conn->insert_id;
    foreach (['preparation', 'packaging', 'dispatch', 'delivering'] as $step) {
        $conn->query("INSERT INTO manufacturing_order_steps (manufacturing_order_id,step_key,label) VALUES ($id, '$step', '$step')");
    }
    foreach (['final_product_count' => '2000', 'carton_count' => '167', 'units_per_carton' => '12', 'last_carton_units' => '8'] as $key => $value) {
        $conn->query("INSERT INTO manufacturing_packaging_checklist (manufacturing_order_id,section_name,item_key,item_text,item_type,item_value) VALUES ($id,'after','$key','$key','number','$value')");
    }
    $migration = file_get_contents(__DIR__ . '/../migrations/20261103_packaging_partial_carton.sql');
    $conn->query($migration);
    $conn->query($migration);
    checkPackaging((int)$conn->query("SELECT COUNT(*) n FROM manufacturing_packaging_checklist WHERE manufacturing_order_id = $id AND item_key = 'last_carton_units'")->fetch_assoc()['n'] === 1, 'Partial-carton migration is idempotent and retains existing values');
    // Reproduces the repeated custom-material rows previously created by each page visit.
    for ($i = 0; $i < 3; $i++) $conn->query("INSERT INTO manufacturing_dispatch_checklist (manufacturing_order_id,section_name,item_key,item_text,item_type,item_value) VALUES ($id,'details','custom_material_name','Repeated material','text','WEIRD-DUPLICATE')");
    $conn->query("INSERT INTO manufacturing_dispatch_checklist (manufacturing_order_id,section_name,item_key,item_text,item_type,item_value) VALUES ($id,'details','final_product_count','Old count','number','999999')");
    $conn->query("INSERT INTO manufacturing_sourcing_components (manufacturing_order_id,formula_component_index,component_name,required_quantity,unit,receipt_status) VALUES ($id,0,'SECRET-MATERIAL-REGRESSION',10,'kg','pending')");
    $category = (int)$conn->query('SELECT id FROM categories LIMIT 1')->fetch_assoc()['id'];
    $conn->query("INSERT INTO products (name,category_id,type,unit) VALUES ('SECRET-PACKAGING-REGRESSION',$category,'material','each')");
    $packingProduct = $conn->insert_id;
    $conn->query("INSERT INTO manufacturing_sourcing_components (manufacturing_order_id,source,formula_component_index,product_id,component_name,required_quantity,unit,deducted_at)
        VALUES ($id,'packaging',0,$packingProduct,'SECRET-PACKAGING-REGRESSION',2000,'pcs',NOW())");
    $packingComponent = $conn->insert_id;
    $conn->query("INSERT INTO manufacturing_packaging_material_tracking (sourcing_component_id,planned_quantity,issued_quantity,used_quantity,returned_quantity,waste_quantity)
        VALUES ($packingComponent,2000,2000,2000,0,0)");
    $img = imagecreatetruecolor(400, 200);
    imagefill($img, 0, 0, imagecolorallocate($img, 40, 130, 200));
    imagepng($img, $absoluteImage); imagedestroy($img);
    foreach (['preparation', 'packaging'] as $step) {
        $stepId = $conn->query("SELECT id FROM manufacturing_order_steps WHERE manufacturing_order_id = $id AND step_key = '$step'")->fetch_assoc()['id'];
        $conn->query("INSERT INTO manufacturing_{$step}_images (manufacturing_order_id,manufacturing_order_step_id,file_path,original_name) VALUES ($id,$stepId,'$imagePath','Regression image')");
    }
    $photos = $conn->real_escape_string(json_encode([['path' => $imagePath, 'original_name' => 'Load image']]));
    $conn->query("INSERT INTO manufacturing_dispatch_checklist (manufacturing_order_id,section_name,item_key,item_text,item_type,item_value) VALUES ($id,'before_loading','load_photographed','Load photo','image','$photos')");
    $conn->query("INSERT INTO manufacturing_delivery_info (manufacturing_order_id,photo_path) VALUES ($id,'$imagePath')");
    $_SESSION['permissions'] = ['manufacturing.orders.technical_details.view', 'manufacturing.view_step_packaging', 'manufacturing.view_step_dispatch', 'manufacturing.view_step_delivering'];
    $html = manufacturing_build_full_report_html($conn, $id);
    checkPackaging(!str_contains($html, 'SECRET-MATERIAL-REGRESSION') && !str_contains($html, 'SECRET-FORMULA-REGRESSION'), 'Unauthorized final report and audit hide material and formula names');
    checkPackaging(!str_contains($html, 'SECRET-PACKAGING-REGRESSION'), 'Actual packaging names stay hidden in unauthorized reports');
    checkPackaging(!str_contains($html, '999999') && !str_contains($html, 'Repeated material'), 'Dispatch report ignores stale counts and repeated custom-material rows');
    checkPackaging(substr_count($html, 'data:image/jpeg;base64,') === 3, 'Only permitted packaging, dispatch and delivery steps embed images');
    checkPackaging(substr_count($html, 'كسور في اخر كرتونة') === 3, 'Partial-carton field appears in packaging, dispatch and delivery reports');
    $deliveryCounts = manufacturing_delivery_counts($conn, $id);
    checkPackaging($deliveryCounts['final_product_count'] === '2000' && $deliveryCounts['carton_count'] === '167', 'Driver verification uses saved packaging counts instead of stale dispatch counts');
    checkPackaging(str_contains(manufacturing_delivery_counts_html(['carton_count' => '0']), '>0</td>')
        && str_contains(manufacturing_delivery_counts_html([]), 'Not recorded'), 'Missing driver counts stay distinct from zero');
    $salesId = (int)$conn->query('SELECT id FROM orders LIMIT 1')->fetch_assoc()['id'];
    $conn->query("UPDATE manufacturing_orders SET sales_order_id = $salesId WHERE id = $id");
    $conn->query("INSERT INTO manufacturing_orders (order_number, customer_id, product_id, formula_id, sales_order_id, batch_size) VALUES ('$number-SIBLING', $customer, $product, $formula, $salesId, 100)");
    $sibling = $conn->insert_id;
    $conn->query("INSERT INTO manufacturing_packaging_checklist (manufacturing_order_id,section_name,item_key,item_text,item_type,item_value) VALUES ($sibling,'after','final_product_count','Count','number','20'), ($sibling,'after','carton_count','Cartons','number','2')");
    $groups = manufacturing_delivery_groups($conn, $salesId);
    $group = $groups['sales-' . $salesId] ?? [];
    checkPackaging(in_array($id, array_column($group, 'id')) && in_array($sibling, array_column($group, 'id')), 'Products belonging to one sales order share a delivery group');
    $fixtureRows = array_values(array_filter($group, fn($row) => in_array((int)$row['id'], [$id, $sibling], true)));
    $groupHtml = manufacturing_delivery_group_html($fixtureRows);
    checkPackaging(str_contains($groupHtml, '<th>2020</th><th>169</th>'), 'Sales-order delivery totals add saved quantities and cartons across products');
    $conn->query("UPDATE manufacturing_orders SET sales_order_id = NULL WHERE id IN ($id, $sibling)");
    $groups = manufacturing_delivery_groups($conn);
    checkPackaging(isset($groups['manufacturing-' . $id], $groups['manufacturing-' . $sibling]), 'Unlinked manufacturing orders stay in separate delivery groups');
    $_SESSION['permissions'] = ['manufacturing.component.name.view', 'manufacturing.formula.view_all', 'manufacturing.orders.technical_details.view', 'manufacturing.view_step_sourcing', 'manufacturing.view_step_receipt', 'manufacturing.view_step_preparation'];
    unset($_SESSION['formula_unlocked'], $_SESSION['formula_password_version']);
    foreach (['sourcing', 'receipt', 'preparation'] as $key) checkPackaging(canAccessManufacturingStep($key), 'Locked formula does not hide the ' . $key . ' step');
    checkPackaging(!canViewManufacturingMaterialNames(), 'Material names remain hidden until formula unlock, even with name permission');
    $receipt = ['step_key' => 'receipt', 'status' => 'pending', 'notes' => ''];
    $testOrder = $conn->query("SELECT mo.*, c.name AS customer_name FROM manufacturing_orders mo JOIN customers c ON c.id = mo.customer_id WHERE mo.id = $id")->fetch_assoc();
    $receiptHtml = manufacturing_build_step_document_html($testOrder, $receipt, [], [], '', 'Receipt', $conn);
    checkPackaging(str_contains($receiptHtml, 'Quality Check Results') && !str_contains($receiptHtml, 'SECRET-MATERIAL-REGRESSION'), 'Locked receipt handoff keeps its table while hiding material names');
    $_SESSION['permissions'] = [];
    checkPackaging(!canAccessManufacturingStep('preparation'), 'Step permissions still apply when removing the password gate');
    $_SESSION['permissions'] = ['manufacturing.component.name.view', 'manufacturing.formula.view_all', 'manufacturing.orders.technical_details.view', 'manufacturing.view_step_preparation', 'manufacturing.view_step_packaging', 'manufacturing.view_step_dispatch', 'manufacturing.view_step_delivering'];
    $_SESSION['formula_unlocked'] = true; $_SESSION['formula_password_version'] = formulaPasswordVersion();
    $html = manufacturing_build_full_report_html($conn, $id);
    checkPackaging(substr_count($html, 'SECRET-PACKAGING-REGRESSION') === 2,
        'Packaging and dispatch reports show the same actual linked material');
    checkPackaging(str_contains($html, 'SECRET-MATERIAL-REGRESSION</td><td style="text-align:center;">10</td>'), 'Final report converts 2000 × 100ml against 100L formula to 10kg');
    require_once __DIR__ . '/../tcpdf/tcpdf.php';
    $pdf = new TCPDF(); $pdf->setPrintHeader(false); $pdf->SetFont('aealarabiya', '', 11); $pdf->AddPage();
    $pdf->writeHTML($html);
    $bytes = $pdf->Output('', 'S');
    checkPackaging(str_starts_with($bytes, '%PDF') && str_contains($bytes, '/Subtype /Image'), 'Full report renders as PDF with embedded image objects');
    file_put_contents('/tmp/manufacturing-regression-report.pdf', $bytes);
} finally {
    $conn->rollback();
    if (is_file($absoluteImage)) unlink($absoluteImage);
}
echo "All manufacturing packaging/report regression checks passed.\n";

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
    $note = manufacturing_reconcile_finished_count($pdo, $mfgId, 110);
    $sales = $pdo->query("SELECT * FROM orders WHERE id = $salesId")->fetch(PDO::FETCH_ASSOC);
    $item = $pdo->query("SELECT * FROM order_items WHERE order_id = $salesId")->fetch(PDO::FETCH_ASSOC);
    checkPackaging((int)$item['quantity'] === 110 && (float)$item['unit_price'] === 10.0 && (float)$item['total_price'] === 1100.0, 'Excess production increases quantity at the existing unit price');
    checkPackaging((float)$sales['total_amount'] === 990.0 && (float)$sales['discount_amount'] === 160.0, 'Percentage discount recalculated; cash discount and shipping preserved');
    $mfg = $pdo->query("SELECT * FROM manufacturing_orders WHERE id = $mfgId")->fetch(PDO::FETCH_ASSOC);
    checkPackaging(str_contains($sales['notes'], $note) && str_contains($mfg['notes'], $note) && str_contains($sales['notes'], 'Original sales note'), 'Both screens retain original notes and receive the adjustment');
    checkPackaging(manufacturing_reconcile_finished_count($pdo, $mfgId, 110) === null && manufacturing_reconcile_finished_count($pdo, $mfgId, 90) === null, 'Repeated saves and lower counts do not add excess again or reduce the order');
    manufacturing_reconcile_finished_count($pdo, $mfgId, 112);
    checkPackaging((int)$pdo->query("SELECT quantity FROM order_items WHERE order_id = $salesId")->fetchColumn() === 112, 'A later increase adds only its new excess');
    $pdo->exec('SAVEPOINT packaging_ambiguity');
    $pdo->prepare('INSERT INTO order_items (order_id,product_id,quantity,unit_price,total_price) VALUES (?, ?, 1, 10, 10)')->execute([$salesId, $product]);
    try {
        manufacturing_reconcile_finished_count($pdo, $mfgId, 115);
        throw new LogicException('Ambiguous sales lines accepted');
    } catch (RuntimeException $ex) {
        checkPackaging(str_contains($ex->getMessage(), 'unique paid'), 'Ambiguous sales lines rejected without guessing');
    }
    $pdo->exec('ROLLBACK TO SAVEPOINT packaging_ambiguity');
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
    checkPackaging(!str_contains($html, '999999') && !str_contains($html, 'Repeated material'), 'Dispatch report ignores stale counts and repeated custom-material rows');
    checkPackaging(substr_count($html, 'data:image/jpeg;base64,') === 3, 'Only permitted packaging, dispatch and delivery steps embed images');
    checkPackaging(substr_count($html, 'كسور في اخر كرتونة') === 2, 'Partial-carton field appears in packaging and dispatch reports');
    $_SESSION['permissions'] = ['manufacturing.component.name.view', 'manufacturing.formula.view_all', 'manufacturing.orders.technical_details.view', 'manufacturing.view_step_preparation', 'manufacturing.view_step_packaging', 'manufacturing.view_step_dispatch', 'manufacturing.view_step_delivering'];
    $_SESSION['formula_unlocked'] = true; $_SESSION['formula_password_version'] = formulaPasswordVersion();
    $html = manufacturing_build_full_report_html($conn, $id);
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

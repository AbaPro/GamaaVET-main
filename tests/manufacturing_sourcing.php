<?php
// Integration regression checks. All fixture writes are rolled back.
// docker compose exec -T -e GAMMAVET_MANUFACTURING_TEST=1 app php tests/manufacturing_sourcing.php
if (PHP_SAPI !== 'cli' || !getenv('GAMMAVET_MANUFACTURING_TEST')) {
    throw new RuntimeException('CLI manufacturing integration checks only.');
}
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../modules/manufacturing/lib.php';
require_once __DIR__ . '/../modules/manufacturing/sourcing_planning.php';
function verify($condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
    echo "PASS $message\n";
}
$pdo->beginTransaction();
try {
    $pdo->exec("INSERT INTO customers (name, type) VALUES ('Temporary sourcing regression', 'factory')");
    $customer = (int)$pdo->lastInsertId();
    $category = (int)$pdo->query('SELECT id FROM categories LIMIT 1')->fetchColumn();
    $products = [];
    foreach (['Final', 'Raw', 'Bottle', 'Label', 'Cap'] as $i => $name) {
        $stmt = $pdo->prepare("INSERT INTO products (name, category_id, type, unit, customer_id) VALUES (?, ?, ?, 'each', ?)");
        $stmt->execute(['Sourcing test ' . $name, $category, $i === 0 ? 'final' : 'material', $customer]);
        $products[] = (int)$pdo->lastInsertId();
    }
    $components = [
        ['product_id' => $products[1], 'name' => 'Raw', 'quantity' => 5, 'unit' => 'kg'],
        ['product_id' => $products[3], 'name' => 'Label', 'quantity' => 1000, 'unit' => 'pcs'],
    ];
    $stmt = $pdo->prepare("INSERT INTO manufacturing_formulas (customer_id, product_id, name, batch_size, batch_unit, components_json) VALUES (?, ?, 'Sourcing regression', 100, 'L', ?)");
    $stmt->execute([$customer, $products[0], json_encode($components)]);
    $formula = (int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO bottle_sizes (name, size, unit, type) VALUES ('Sourcing test 100ml', 100, 'ml', 'liquid')");
    $bottle = (int)$pdo->lastInsertId();
    $stmt = $pdo->prepare("INSERT INTO packaging_options (customer_id, product_id, name) VALUES (?, ?, 'Z Product kit')");
    $stmt->execute([$customer, $products[0]]);
    $option = (int)$pdo->lastInsertId();
    foreach (array_slice($products, 2) as $product) {
        $stmt = $pdo->prepare("INSERT INTO packaging_option_items (packaging_option_id, product_id, quantity, unit) VALUES (?, ?, 1, 'pcs')");
        $stmt->execute([$option, $product]);
    }
    $pdo->prepare("INSERT INTO packaging_options (customer_id, name) VALUES (?, 'A General kit')")->execute([$customer]);
    $pdo->prepare("INSERT INTO packaging_options (customer_id, product_id, name, is_active) VALUES (?, ?, 'A Inactive kit', 0)")->execute([$customer, $products[0]]);
    verify((int)manufacturing_packaging_options($customer, $products[0])[0]['id'] === $option, 'Product kit precedes customer-wide kits; inactive kits excluded');
    verify(count(manufacturing_packaging_options($customer, $products[1])) === 1, 'Other products do not receive this product-specific kit');
    $stmt = $pdo->prepare("INSERT INTO manufacturing_orders (order_number, customer_id, product_id, formula_id, bottle_size_id, number_of_bottles, batch_size) VALUES (?, ?, ?, ?, ?, 1000, 100000)");
    $stmt->execute(['TEST-' . bin2hex(random_bytes(6)), $customer, $products[0], $formula, $bottle]);
    $order = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO manufacturing_order_steps (manufacturing_order_id, step_key, label) VALUES (?, 'sourcing', 'Sourcing & Procurement')")->execute([$order]);
    $load = function () use ($pdo, $order) {
        $stmt = $pdo->prepare('SELECT * FROM manufacturing_sourcing_components WHERE manufacturing_order_id = ? ORDER BY id');
        $stmt->execute([$order]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    };
    $pdo->prepare("INSERT INTO manufacturing_sourcing_components
        (manufacturing_order_id, source, formula_component_index, product_id, component_name, required_quantity, unit, notes)
        VALUES (?, 'formula', 1, ?, 'Label', 1000, 'pcs', 'Legacy supplier note')")->execute([$order, $products[3]]);
    manufacturing_sync_sourcing($order);
    $rows = $load();
    verify(count($rows) === 4, 'Formula + three packaging materials, with legacy label counted once');
    verify((float)$rows[0]['required_quantity'] === 5.0, 'Raw quantity uses 100 L formula versus 1000 × 100 ml production');
    foreach (array_slice($rows, 1) as $row) verify($row['source'] === 'packaging' && (float)$row['required_quantity'] === 1000.0, 'Packaging uses per-bottle quantities');
    verify((int)$pdo->query("SELECT packaging_option_id FROM manufacturing_orders WHERE id = $order")->fetchColumn() === $option, 'Missing order kit is resolved and saved');
    verify($rows[2]['notes'] === 'Legacy supplier note', 'Legacy formula packaging note moves into its packaging requirement');
    verify(count(manufacturing_sourcing_requirements($components, [], 1000)) === 2, 'Orders without a kit retain all formula materials');
    $handoff = manufacturing_sourcing_document_html($order, true);
    verify(str_contains($handoff, 'Sourcing test Bottle') && str_contains($handoff, 'Sourcing test Cap')
        && substr_count($handoff, 'Sourcing test Label') === 1, 'Handoff includes formula and packaging with no duplicate label');
    verify(!str_contains(manufacturing_sourcing_document_html($order, false), 'Sourcing test Bottle'), 'Handoff respects hidden component names');
    $labelId = $rows[2]['id'];
    $pdo->prepare("UPDATE manufacturing_sourcing_components SET notes = 'Keep supplier note' WHERE id = ?")->execute([$labelId]);
    manufacturing_sync_sourcing($order);
    $rows = $load();
    $snapshot = $rows;
    manufacturing_sync_sourcing($order);
    verify($snapshot === $load(), 'Repeated sync preserves rows, IDs, and timestamps');
    $pdo->prepare('UPDATE manufacturing_orders SET number_of_bottles = 2000 WHERE id = ?')->execute([$order]);
    manufacturing_sync_sourcing($order);
    $rows = $load();
    verify((float)$rows[0]['required_quantity'] === 10.0 && (float)$rows[1]['required_quantity'] === 2000.0, 'Production change scales raw materials and packaging separately');
    verify($rows[2]['id'] === $labelId && $rows[2]['notes'] === 'Keep supplier note', 'Notes and IDs survive refresh');
    $pdo->prepare('DELETE FROM packaging_option_items WHERE packaging_option_id = ? AND product_id = ?')->execute([$option, $products[2]]);
    manufacturing_sync_sourcing($order);
    $rows = $load();
    verify(count($rows) === 3 && $rows[1]['id'] === $labelId && $rows[1]['notes'] === 'Keep supplier note', 'Kit changes remove stale requirements without shifting notes');
    $snapshot = $rows;
    $pdo->prepare("UPDATE manufacturing_order_steps SET status = 'completed' WHERE manufacturing_order_id = ?")->execute([$order]);
    $pdo->prepare('UPDATE manufacturing_orders SET number_of_bottles = 3000 WHERE id = ?')->execute([$order]);
    verify(!manufacturing_sync_sourcing($order) && $snapshot === $load(), 'Completed sourcing remains unchanged');
    $pdo->prepare("UPDATE manufacturing_order_steps SET status = 'pending' WHERE manufacturing_order_id = ?")->execute([$order]);
    foreach (['completed', 'cancelled'] as $status) {
        $pdo->prepare('UPDATE manufacturing_orders SET status = ? WHERE id = ?')->execute([$status, $order]);
        verify(!manufacturing_sync_sourcing($order) && $snapshot === $load(), ucfirst($status) . ' orders remain unchanged');
    }
    $pdo->prepare("UPDATE manufacturing_orders SET status = 'pending' WHERE id = ?")->execute([$order]);
    $pdo->prepare('UPDATE manufacturing_sourcing_components SET deducted_at = NOW() WHERE id = ?')->execute([$labelId]);
    $snapshot = $load();
    verify(!manufacturing_sync_sourcing($order) && $snapshot === $load(), 'Deducted requirements remain unchanged and cannot be recreated for a second deduction');
    $pdo->prepare("UPDATE manufacturing_sourcing_components SET deducted_at = NULL, receipt_status = 'approved' WHERE id = ?")->execute([$labelId]);
    $snapshot = $load();
    verify(!manufacturing_sync_sourcing($order) && $snapshot === $load(), 'Approved receipt requirements remain unchanged');
    $pdo->prepare("UPDATE manufacturing_sourcing_components SET receipt_status = 'pending' WHERE id = ?")->execute([$labelId]);
    $pdo->prepare('UPDATE manufacturing_orders SET number_of_bottles = NULL WHERE id = ?')->execute([$order]);
    // A legacy order without a count should preserve its saved packaging rows.
    $snapshot = $load();
    verify(!manufacturing_sync_sourcing($order) && $snapshot === $load(), 'Legacy kit orders without bottle counts remain unchanged');
    // Consolidated planning: include untouched orders and normalize compatible units.
    $pdo->prepare('UPDATE manufacturing_orders SET number_of_bottles = 1000 WHERE id = ?')->execute([$order]);
    manufacturing_sync_sourcing($order);
    $location = (int)$pdo->query('SELECT id FROM locations LIMIT 1')->fetchColumn();
    $secondComponents = [['product_id' => $products[1], 'name' => 'Raw', 'quantity' => 5000, 'unit' => 'g']];
    $stmt = $pdo->prepare("INSERT INTO manufacturing_formulas (customer_id, product_id, name, batch_size, batch_unit, components_json) VALUES (?, ?, 'Second sourcing regression', 100, 'L', ?)");
    $stmt->execute([$customer, $products[0], json_encode($secondComponents)]);
    $secondFormula = (int)$pdo->lastInsertId();
    $stmt = $pdo->prepare("INSERT INTO manufacturing_orders (order_number, customer_id, product_id, formula_id, bottle_size_id, number_of_bottles, batch_size, location_id) VALUES (?, ?, ?, ?, ?, 2000, 200000, ?)");
    $stmt->execute(['TEST-' . bin2hex(random_bytes(6)), $customer, $products[0], $secondFormula, $bottle, $location]);
    $secondOrder = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO manufacturing_order_steps (manufacturing_order_id, step_key, label) VALUES (?, 'sourcing', 'Sourcing & Procurement')")->execute([$secondOrder]);
    $getMaterial = static function ($plan, $id, $unit = null) {
        foreach ($plan['materials'] as $material) {
            if ((int)$material['product_id'] === $id && ($unit === null || $material['unit'] === $unit)) return $material;
        }
        return null;
    };
    $before = $load();
    $plan = manufacturing_sourcing_plan(['customer_id' => $customer]);
    $raw = $getMaterial($plan, $products[1]);
    verify($plan['order_count'] === 2 && $raw['quantity'] === 15.0 && $raw['unit'] === 'kg'
        && count($raw['orders']) === 2, 'Combined plan adds 5 kg + 10000 g as 15 kg with both order breakdowns');
    verify($getMaterial($plan, $products[3])['quantity'] === 3000.0, 'Combined plan adds shared packaging across orders');
    verify($before === $load() && $pdo->query("SELECT packaging_option_id FROM manufacturing_orders WHERE id = $secondOrder")->fetchColumn() === null,
        'Planning does not change saved requirements or save an inferred packaging option');
    verify((int)$pdo->query("SELECT COUNT(*) FROM manufacturing_sourcing_components WHERE manufacturing_order_id = $secondOrder")->fetchColumn() === 0,
        'Untouched orders contribute requirements without creating sourcing rows');
    $filtered = manufacturing_sourcing_plan(['customer_id' => $customer, 'location_id' => $location, 'search' => 'Raw']);
    verify($filtered['order_count'] === 1 && count($filtered['materials']) === 1 && $filtered['materials'][0]['quantity'] === 10.0,
        'Location and material search filters restrict totals and order counts');
    $filtered = manufacturing_sourcing_plan(['customer_id' => $customer, 'source' => 'packaging']);
    verify($getMaterial($filtered, $products[1]) === null && count($filtered['materials']) === 2, 'Packaging-only filter excludes raw materials');
    verify(manufacturing_sourcing_plan(['customer_id' => $customer, 'search' => 'NoSuchMaterial'])['materials'] === [], 'Unmatched material search returns an empty plan');
    verify(manufacturing_sourcing_plan(['customer_id' => $customer, 'search' => 'Raw'], false)['materials'] === [], 'Hidden component names cannot be discovered through search');
    $secondComponents[0]['unit'] = 'ml';
    $pdo->prepare('UPDATE manufacturing_formulas SET components_json = ? WHERE id = ?')->execute([json_encode($secondComponents), $secondFormula]);
    $mixed = manufacturing_sourcing_plan(['customer_id' => $customer]);
    verify($getMaterial($mixed, $products[1], 'kg')['quantity'] === 5.0 && $getMaterial($mixed, $products[1], 'L')['quantity'] === 10.0,
        'Incompatible weight and volume remain separate material lines');
    $secondComponents[0]['unit'] = 'g';
    $pdo->prepare('UPDATE manufacturing_formulas SET components_json = ? WHERE id = ?')->execute([json_encode($secondComponents), $secondFormula]);
    foreach (['completed', 'cancelled'] as $state) {
        $pdo->prepare('UPDATE manufacturing_orders SET status = ? WHERE id = ?')->execute([$state, $order]);
        $plan = manufacturing_sourcing_plan(['customer_id' => $customer]);
        verify($plan['order_count'] === 1 && $getMaterial($plan, $products[1])['quantity'] === 10.0, ucfirst($state) . ' orders are excluded from the combined plan');
    }
    $pdo->prepare("UPDATE manufacturing_orders SET status = 'pending' WHERE id = ?")->execute([$order]);
    $pdo->prepare("UPDATE manufacturing_order_steps SET status = 'completed' WHERE manufacturing_order_id = ?")->execute([$order]);
    verify(manufacturing_sourcing_plan(['customer_id' => $customer])['order_count'] === 1, 'Completed sourcing steps are excluded from the combined plan');
    $pdo->prepare("UPDATE manufacturing_order_steps SET status = 'pending' WHERE manufacturing_order_id = ?")->execute([$order]);
    $pdo->prepare('UPDATE manufacturing_sourcing_components SET deducted_at = NOW() WHERE manufacturing_order_id = ? AND product_id = ?')->execute([$order, $products[1]]);
    $pdo->prepare("UPDATE manufacturing_sourcing_components SET receipt_status = 'approved' WHERE manufacturing_order_id = ? AND product_id = ?")->execute([$order, $products[3]]);
    $plan = manufacturing_sourcing_plan(['customer_id' => $customer]);
    verify($getMaterial($plan, $products[1])['quantity'] === 10.0 && $getMaterial($plan, $products[3])['quantity'] === 2000.0
        && $getMaterial($plan, $products[4])['quantity'] === 3000.0, 'Deducted and received materials are excluded while other outstanding saved items remain');
    $pdo->exec("INSERT INTO customers (name, type, direct_sale) VALUES ('Temporary direct-sales sourcing test', 'factory', 'curva')");
    $outsideCustomer = (int)$pdo->lastInsertId();
    $pdo->prepare('UPDATE manufacturing_orders SET customer_id = ? WHERE id = ?')->execute([$outsideCustomer, $secondOrder]);
    verify(manufacturing_sourcing_plan(['customer_id' => $outsideCustomer])['materials'] === [], 'Factory sourcing cannot include another company channel');
    echo "All manufacturing sourcing checks passed. Fixture writes rolled back.\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}

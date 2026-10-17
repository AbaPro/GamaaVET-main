<?php
// Isolated regression check: no application connection or data is used.
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE purchase_orders (id INTEGER PRIMARY KEY, vendor_id INTEGER, contact_id INTEGER, created_by INTEGER)');
$pdo->exec('CREATE TABLE vendors (id INTEGER PRIMARY KEY, name TEXT)');
$pdo->exec('CREATE TABLE vendor_contacts (id INTEGER PRIMARY KEY, name TEXT, phone TEXT)');
$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
$pdo->exec("INSERT INTO vendors VALUES (1, 'Test vendor')");
$pdo->exec("INSERT INTO vendor_contacts VALUES (1, 'Test contact', '01234567890')");
$pdo->exec("INSERT INTO users VALUES (1, 'Test creator')");
$pdo->exec('INSERT INTO purchase_orders VALUES (1, 1, 1, 1), (2, 1, NULL, 1), (3, 1, 0, 1), (4, 1, 99, 1), (5, 1, 1, 99), (6, 99, 1, 1)');

// Run the actual detail-page lookup so this detects a return to inner joins.
$source = file_get_contents(__DIR__ . '/../modules/purchases/po_details.php');
if (!preg_match('/\$stmt = \$pdo->prepare\("([\s\S]*?)"\);/', $source, $match)) {
    throw new RuntimeException('PO detail lookup not found.');
}
$stmt = $pdo->prepare($match[1]);
foreach ([1, 2, 3, 4, 5] as $id) {
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || (int)$row['id'] !== $id || $row['vendor_name'] !== 'Test vendor') {
        throw new RuntimeException("PO #$id must remain visible even without optional contact or creator records.");
    }
    if (in_array($id, [2, 3, 4], true) && ($row['contact_name'] !== null || $row['contact_phone'] !== null)) {
        throw new RuntimeException('Missing contacts must not resolve to another contact.');
    }
    if ($id === 1 && ($row['contact_name'] !== 'Test contact' || $row['created_by_name'] !== 'Test creator')) {
        throw new RuntimeException('Existing contact and creator details must remain available.');
    }
    if ($id === 5 && $row['created_by_name'] !== null) {
        throw new RuntimeException('Missing creator must not resolve to another user.');
    }
}
foreach ([6, 999] as $id) {
    $stmt->execute([$id]);
    if ($stmt->fetch()) throw new RuntimeException('Missing orders or required vendor records must not resolve.');
}
echo "PASS: PO details handle null, zero and missing contacts, missing creators, intact records and nonexistent orders.\n";

<?php
// Run the page's payment-loading code against an isolated in-memory database.
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE purchase_order_payments (id INTEGER PRIMARY KEY, purchase_order_id INTEGER, created_by INTEGER, payment_source_type TEXT, payment_source_id INTEGER, transaction_date TEXT, created_at TEXT)');
$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
$pdo->exec('CREATE TABLE safes (id INTEGER PRIMARY KEY, name TEXT)');
$pdo->exec('CREATE TABLE bank_accounts (id INTEGER PRIMARY KEY, bank_name TEXT)');
$pdo->exec('CREATE TABLE personal_accounts (id INTEGER PRIMARY KEY, name TEXT)');
$pdo->exec('CREATE TABLE purchase_order_payment_attachments (purchase_order_payment_id INTEGER, file_path TEXT, original_name TEXT, created_at TEXT)');
$pdo->exec("INSERT INTO users VALUES (1, 'Creator')");
$pdo->exec("INSERT INTO safes VALUES (1, 'Allowed safe'), (2, 'Restricted safe')");
$pdo->exec("INSERT INTO bank_accounts VALUES (1, 'Allowed bank'), (2, 'Restricted bank')");
$pdo->exec("INSERT INTO purchase_order_payments VALUES
    (1, 97, 1, 'safe', 1, '2026-10-17', '2026-10-17'),
    (2, 97, 1, 'safe', 2, '2026-10-17', '2026-10-17'),
    (3, 97, 1, 'bank', 1, '2026-10-17', '2026-10-17'),
    (4, 97, 1, 'bank', 2, '2026-10-17', '2026-10-17'),
    (5, 97, 1, NULL, NULL, '2026-10-17', '2026-10-17')");
$permissions = ['finance.po_payment.process'];
$restricted = true;
function hasPermission($key) { global $permissions; return in_array($key, $permissions, true); }
function getSafeScopeSql($alias) { global $restricted; return $restricted ? "$alias.id = 1" : '1=1'; }
function getSafeReferenceScopeSql($column) {
    global $restricted;
    return $restricted ? "($column IS NULL OR $column = 0 OR $column = 1)" : '1=1';
}
function getBankReferenceScopeSql($column) { return getSafeReferenceScopeSql($column); }
function tableHasColumn($table, $column) {
    global $pdo;
    return in_array($column, $pdo->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_COLUMN, 1), true);
}
function loadPayments($canViewPOPrices = true) {
    global $pdo;
    $po_id = 97;
    $source = file_get_contents(__DIR__ . '/../modules/purchases/po_details.php');
    $start = strpos($source, '// Fetch payments');
    $end = strpos($source, '$stmt = $pdo->prepare("' . "\n    SELECT por.", $start);
    if ($start === false || $end === false) throw new RuntimeException('Payment-loading block not found.');
    eval(substr($source, $start, $end - $start));
    $ids = array_map('intval', array_column($payments, 'id'));
    sort($ids);
    return $ids;
}
function expectIds($expected, $actual, $case) {
    if ($actual !== $expected) throw new RuntimeException($case . ': ' . json_encode($actual));
    echo "PASS: $case\n";
}
expectIds([1, 3, 5], loadPayments(), 'restricted user, standard schema without legacy safe_id');
$restricted = false;
expectIds([1, 2, 3, 4, 5], loadPayments(), 'unrestricted user');
$restricted = true;
$pdo->exec('ALTER TABLE purchase_order_payments ADD COLUMN safe_id INTEGER');
$pdo->exec('UPDATE purchase_order_payments SET safe_id = 2 WHERE id = 5');
expectIds([1, 3], loadPayments(), 'legacy safe restrictions still apply when the column exists');
$permissions = ['finance.purchase_payments.delete', 'finance.deletions.request'];
expectIds([1, 3], loadPayments(), 'authorized payment deletion requester');
$permissions = ['finance.purchase_payments.delete'];
expectIds([], loadPayments(), 'delete permission alone does not expose payment history');
$pdo->exec('DROP TABLE purchase_order_payments');
$permissions = [];
expectIds([], loadPayments(), 'PO viewer does not query payment tables');
$permissions = ['finance.po_payment.process'];
expectIds([], loadPayments(false), 'user without price permission does not query payment tables');

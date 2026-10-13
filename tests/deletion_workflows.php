<?php
// Run only against a disposable MariaDB instance using fixtures/deletion_local.php.
if (!getenv('GAMMAVET_DELETION_TESTS')) throw new RuntimeException('Disposable test database required.');
session_start();
require_once __DIR__ . '/../includes/functions.php';
if (DB_NAME !== 'gammavet_deletion_tests') throw new RuntimeException('Refusing to use application database.');
require_once __DIR__ . '/../modules/finance/account_deletion.php';
require_once __DIR__ . '/../modules/manufacturing/deletion_approval.php';
function check($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
}
function scalar($sql) { global $pdo; return $pdo->query($sql)->fetchColumn(); }
function fixture() {
    global $pdo;
    foreach (['manufacturing_deletion_requests', 'finance_deletion_requests', 'manufacturing_orders', 'manufacturing_formulas', 'manufacturing_formula_templates', 'bottle_sizes', 'packaging_options', 'manufacturing_step_documents', 'manufacturing_order_steps', 'safes', 'bank_accounts', 'personal_accounts', 'finance_transfers', 'purchase_order_payments'] as $table) $pdo->exec("DELETE FROM `$table`");
    $pdo->exec("DELETE rp FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = 1 AND p.`key` IN ('finance.deletions.approve', 'manufacturing.deletions.approve')");
    $pdo->exec('ALTER TABLE manufacturing_deletion_requests AUTO_INCREMENT = 1');
    $pdo->exec('ALTER TABLE finance_deletion_requests AUTO_INCREMENT = 1');
    $pdo->exec("INSERT INTO manufacturing_orders VALUES (1, 'MO-1', 1, NULL, NULL), (2, 'MO-2', 2, NULL, NULL)");
    $pdo->exec('INSERT INTO manufacturing_formulas VALUES (1, 1), (2, 2)');
    $pdo->exec('INSERT INTO packaging_options VALUES (1, 1), (2, 2)');
    $pdo->exec('INSERT INTO bottle_sizes VALUES (1)');
    $pdo->exec('INSERT INTO manufacturing_formula_templates VALUES (1)');
    $pdo->exec("INSERT INTO safes VALUES (1, 'Safe', 0, 'EGP', 1)");
    $pdo->exec("INSERT INTO bank_accounts VALUES (1, 'Bank', 0, 'EGP', 1)");
    $pdo->exec("INSERT INTO personal_accounts VALUES (1, 'Personal', 0, 1)");
    $_SESSION = ['user_id' => 1, 'role_slug' => 'user', 'login_region' => 'factory', 'permissions' => ['manufacturing.view', 'manufacturing.delete', 'manufacturing.deletions.request', 'finance.safes.delete', 'finance.deletions.request']];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = ['id' => 1, 'deletion_token' => manufacturingDeletionToken()];
}
$mode = $argv[1] ?? 'suite';
if ($mode === 'suite') {
    check(DB_NAME === 'gammavet_deletion_tests', 'Refusing to use application database.');
    $schema = [
        'roles' => 'id INT PRIMARY KEY, slug VARCHAR(100)',
        'permissions' => 'id INT AUTO_INCREMENT PRIMARY KEY, module VARCHAR(50), name VARCHAR(100), `key` VARCHAR(100) UNIQUE, description TEXT',
        'role_permissions' => 'role_id INT, permission_id INT, PRIMARY KEY(role_id, permission_id)',
        'users' => 'id INT PRIMARY KEY, name VARCHAR(100), is_active INT, role VARCHAR(100), role_id INT, safe_access_ids TEXT NULL',
        'accounts' => 'id INT PRIMARY KEY, slug VARCHAR(100)',
        'factories' => 'id INT PRIMARY KEY, sales_person_id INT NULL',
        'customers' => 'id INT PRIMARY KEY, factory_id INT NULL, sales_person_id INT NULL, direct_sale VARCHAR(30) NULL',
        'manufacturing_orders' => 'id INT PRIMARY KEY, order_number VARCHAR(100), customer_id INT, formula_id INT NULL, packaging_option_id INT NULL',
        'manufacturing_formulas' => 'id INT PRIMARY KEY, customer_id INT',
        'packaging_options' => 'id INT PRIMARY KEY, customer_id INT',
        'manufacturing_formula_templates' => 'id INT PRIMARY KEY',
        'bottle_sizes' => 'id INT PRIMARY KEY',
        'manufacturing_order_steps' => 'id INT PRIMARY KEY, manufacturing_order_id INT',
        'manufacturing_step_documents' => 'id INT PRIMARY KEY, manufacturing_order_step_id INT, file_path TEXT',
        'safes' => 'id INT PRIMARY KEY, name VARCHAR(100), balance DECIMAL(15,2), currency VARCHAR(10), account_id INT',
        'bank_accounts' => 'id INT PRIMARY KEY, bank_name VARCHAR(100), balance DECIMAL(15,2), currency VARCHAR(10), account_id INT',
        'personal_accounts' => 'id INT PRIMARY KEY, name VARCHAR(100), balance DECIMAL(15,2), account_id INT',
        'finance_transfers' => 'id INT PRIMARY KEY, from_type VARCHAR(30), from_id INT, to_type VARCHAR(30), to_id INT',
        'purchase_order_payments' => 'id INT PRIMARY KEY, payment_source_type VARCHAR(30), payment_source_id INT',
        'activity_logs' => 'id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, action VARCHAR(250), details TEXT, ip_address VARCHAR(100) NULL',
    ];
    foreach ($schema as $table => $columns) $pdo->exec("CREATE TABLE `$table` ($columns) ENGINE=InnoDB");
    $pdo->exec("INSERT INTO roles VALUES (1, 'user'), (2, 'admin'), (3, 'viewer')");
    $pdo->exec("INSERT INTO users VALUES (1, 'Requester', 1, 'user', 1, NULL), (2, 'Admin', 1, 'admin', 2, NULL)");
    $pdo->exec("INSERT INTO accounts VALUES (1, 'factory'), (2, 'primer')");
    $pdo->exec("INSERT INTO customers VALUES (1, NULL, NULL, NULL), (2, NULL, NULL, 'primer')");
    $pdo->exec("INSERT INTO permissions (module, name, `key`) VALUES ('manufacturing', 'Delete', 'manufacturing.delete'), ('finance', 'Delete', 'finance.safes.delete'), ('region', 'Factory', 'region.factory'), ('manufacturing', 'View', 'manufacturing.view')");
    $pdo->exec('INSERT INTO role_permissions SELECT 1, id FROM permissions');
    foreach (['20261007_finance_deletion_approval.sql', '20261013_deletion_request_permissions.sql', '20261013_deletion_request_permissions.sql'] as $file) $pdo->exec(file_get_contents(__DIR__ . '/../migrations/' . $file));
    check((int)scalar("SELECT COUNT(*) FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = 1 AND p.`key` LIKE '%.deletions.request'") === 2, 'Existing delete roles retain request access.');
    check((int)scalar("SELECT COUNT(*) FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = 1 AND p.`key` LIKE '%.deletions.approve'") === 0, 'Requesters must not receive approval access.');
    check((int)scalar("SELECT COUNT(*) FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = 2 AND p.`key` LIKE '%.deletions.approve'") === 2, 'Admins receive both approvals.');
    $cases = ['m-request', 'm-no-request', 'm-no-delete', 'm-no-view', 'm-csrf', 'm-get', 'm-customer', 'm-self', 'm-admin-self', 'm-no-approve', 'm-account', 'm-mismatch', 'm-reviewed', 'm-in-use', 'm-template-locked', 'm-approve-order', 'm-approve-formula', 'm-approve-template', 'm-approve-bottle_size', 'm-approve-packaging_option', 'f-request', 'f-no-request', 'f-no-delete', 'f-csrf', 'f-self', 'f-admin-self', 'f-no-approve', 'f-account', 'f-mismatch', 'f-reviewed', 'f-force-mismatch', 'f-balance', 'f-history', 'f-approve', 'f-force-request', 'f-force-approve', 'm-reject', 'm-reject-self', 'm-reject-user-self', 'm-reject-denied', 'f-reject', 'f-reject-self', 'f-reject-user-self', 'f-reject-denied', 'm-list-denied', 'f-list-denied'];
    foreach ($cases as $case) {
        passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' ' . escapeshellarg($case), $status);
        check($status === 0, 'Failed scenario: ' . $case);
    }
    echo 'PASS: migrations are idempotent; role grants and ' . count($cases) . " workflow scenarios verified.\n";
    exit;
}
fixture();
$isFinance = str_starts_with($mode, 'f-');
$table = $isFinance ? 'finance_deletion_requests' : 'manufacturing_deletion_requests';
$type = 'order';
$expectedCount = 0;
$expectedStatus = null;
$expectedRecord = 1;
$expectedAlert = 'danger';
if (str_contains($mode, '-request') && !str_contains($mode, '-no-request')) { $expectedCount = 1; $expectedStatus = 'pending'; $expectedAlert = 'success'; }
$isApproval = preg_match('/-(self|no-approve|account|mismatch|reviewed|force-mismatch|balance|history|in-use|template-locked|approve.*)$/', $mode);
if ($isApproval || str_contains($mode, '-reject')) {
    $_SESSION['user_id'] = 2;
    $_SESSION['role_slug'] = 'admin';
    if (str_starts_with($mode, 'm-approve-')) $type = substr($mode, strlen('m-approve-'));
    if ($mode === 'm-in-use') { $type = 'formula'; $pdo->exec('UPDATE manufacturing_orders SET formula_id = 1 WHERE id = 1'); }
    if ($mode === 'm-template-locked') $type = 'template';
    $requestType = $isFinance ? 'safe' : $type;
    $forceColumn = $isFinance ? ', force_delete' : '';
    $forceValue = $isFinance ? ', 0' : '';
    $pdo->exec("INSERT INTO `$table` (id, account_id, entity_type, entity_id, requested_by$forceColumn) VALUES (1, 1, '$requestType', 1, 1$forceValue)");
    $_POST['deletion_request_id'] = 1;
    $expectedCount = 1; $expectedStatus = 'pending';
}
if ($mode === 'm-self' || $mode === 'f-self') {
    $_SESSION['user_id'] = 1;
    $_SESSION['role_slug'] = 'user';
    $_SESSION['permissions'][] = $isFinance ? 'finance.deletions.approve' : 'manufacturing.deletions.approve';
}
if (str_contains($mode, '-admin-self')) {
    $pdo->exec("UPDATE `$table` SET requested_by = 2 WHERE id = 1");
    $expectedStatus = 'approved'; $expectedRecord = 0; $expectedAlert = 'success';
}
if (str_ends_with($mode, '-no-approve')) { $_SESSION['role_slug'] = 'user'; $_SESSION['permissions'][] = 'manufacturing.delete'; }
if (str_ends_with($mode, '-account')) $pdo->exec("UPDATE `$table` SET account_id = 2 WHERE id = 1");
if (str_ends_with($mode, '-mismatch')) $_POST['id'] = 2;
if ($mode === 'f-force-mismatch') $_POST['id'] = 1;
if (str_ends_with($mode, '-reviewed')) { $pdo->exec("UPDATE `$table` SET status = 'rejected' WHERE id = 1"); $expectedStatus = 'rejected'; }
if (str_ends_with($mode, '-no-request')) $_SESSION['permissions'] = array_values(array_diff($_SESSION['permissions'], [$isFinance ? 'finance.deletions.request' : 'manufacturing.deletions.request']));
if (str_ends_with($mode, '-no-delete')) $_SESSION['permissions'] = array_values(array_diff($_SESSION['permissions'], [$isFinance ? 'finance.safes.delete' : 'manufacturing.delete']));
if ($mode === 'm-no-view') $_SESSION['permissions'] = array_values(array_diff($_SESSION['permissions'], ['manufacturing.view']));
if (str_ends_with($mode, '-csrf')) $_POST['deletion_token'] = 'invalid';
if ($mode === 'm-get') $_SERVER['REQUEST_METHOD'] = 'GET';
if ($mode === 'm-customer') $_POST['id'] = 2;
if ($mode === 'f-balance') $pdo->exec('UPDATE safes SET balance = 10 WHERE id = 1');
if ($mode === 'f-history') $pdo->exec("INSERT INTO finance_transfers VALUES (1, 'safe', 1, 'bank', 1)");
if ($mode === 'm-approve-template') { $_SESSION['formula_unlocked'] = true; $_SESSION['formula_password_version'] = formulaPasswordVersion(); }
if (str_contains($mode, '-approve') && !str_contains($mode, '-no-approve')) { $expectedStatus = 'approved'; $expectedRecord = 0; $expectedAlert = 'success'; }
if ($isFinance) {
    $_SESSION['finance_account_delete_token'] = 'account-token';
    $_POST['csrf_token'] = 'account-token';
    $_POST['deletion_token'] = str_ends_with($mode, '-csrf') ? 'invalid' : financeDeletionToken();
    $_POST[in_array($mode, ['f-force-mismatch', 'f-force-request', 'f-force-approve'], true) ? 'force_delete_account' : 'delete_account'] = $_POST['id'];
}
if ($mode === 'f-force-request') $_SESSION['permissions'] = ['finance.safes.force_delete', 'finance.deletions.request'];
if ($mode === 'f-force-approve') {
    $pdo->exec("UPDATE finance_deletion_requests SET force_delete = 1 WHERE id = 1");
    $pdo->exec("INSERT INTO finance_transfers VALUES (1, 'safe', 1, 'bank', 1)");
    $expectedAlert = 'warning';
}
if (str_contains($mode, '-reject')) {
    $_POST['reject'] = 1;
    if (str_ends_with($mode, '-denied')) { $_SESSION['role_slug'] = 'user'; $_SESSION['user_id'] = 1; }
    if ($mode === 'm-reject' || $mode === 'f-reject') { $expectedStatus = 'rejected'; $expectedAlert = 'success'; }
}
if (str_contains($mode, '-list-denied')) {
    $_SESSION['permissions'] = ['region.factory'];
    $pdo->exec("DELETE rp FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = 1 AND p.`key` LIKE '%.deletions.%'");
}
if (str_ends_with($mode, '-reject-self')) {
    $_SESSION['user_id'] = 2;
    $pdo->exec("UPDATE `$table` SET requested_by = 2 WHERE id = 1");
    $expectedStatus = 'rejected'; $expectedAlert = 'success';
}
if (str_ends_with($mode, '-reject-user-self')) {
    $_SESSION['user_id'] = 1; $_SESSION['role_slug'] = 'user';
    $permission = $isFinance ? 'finance.deletions.approve' : 'manufacturing.deletions.approve';
    $pdo->prepare('INSERT IGNORE INTO role_permissions (role_id, permission_id) SELECT 1, id FROM permissions WHERE `key` = ?')->execute([$permission]);
}
$recordTable = $isFinance ? 'safes' : manufacturingDeletionConfig($type)['table'];
register_shutdown_function(function () use ($mode, $table, $recordTable, $expectedCount, $expectedStatus, $expectedRecord, $expectedAlert) {
    try {
        check((int)scalar("SELECT COUNT(*) FROM `$table`") === $expectedCount, "$mode request count");
        if ($expectedStatus) check(scalar("SELECT status FROM `$table` WHERE id = 1") === $expectedStatus, "$mode status");
        check((int)scalar("SELECT COUNT(*) FROM `$recordTable` WHERE id = 1") === $expectedRecord, "$mode record preservation/deletion");
        check(($_SESSION['alert']['type'] ?? '') === $expectedAlert, "$mode expected alert: " . json_encode($_SESSION['alert'] ?? null));
        if ($expectedStatus === 'approved' || $expectedStatus === 'rejected' && str_ends_with($mode, '-reject')) check((int)scalar("SELECT reviewed_by FROM `$table` WHERE id = 1") === 2, "$mode reviewer recorded");
        echo "PASS $mode\n";
    } catch (Throwable $e) { fwrite(STDERR, $e->getMessage() . "\n"); exit(1); }
});
if (str_contains($mode, '-reject') || str_contains($mode, '-list-denied')) {
    $directory = $isFinance ? 'finance' : 'manufacturing';
    chdir(__DIR__ . '/../modules/' . $directory);
    $_SERVER['PHP_SELF'] = 'deletion_requests.php';
    $_SERVER['REQUEST_URI'] = '/modules/' . $directory . '/deletion_requests.php';
    if (str_contains($mode, '-list-denied')) $_SERVER['REQUEST_METHOD'] = 'GET';
    require 'deletion_requests.php';
} elseif ($isFinance) {
    handleFinanceAccountDeletion('safe', hasPermission('finance.safes.delete'), 'safes.php', hasPermission('finance.safes.force_delete'));
} else {
    handleManufacturingDeletion($type);
}

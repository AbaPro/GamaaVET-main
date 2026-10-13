<?php
// Read-only GET checks against the local Apache app. No financial records change.
if (PHP_SAPI !== 'cli' || !getenv('GAMMAVET_FINANCE_SMOKE')) throw new RuntimeException('CLI smoke checks only.');
ob_start();
require_once __DIR__ . '/../includes/functions.php';
$adminId = $pdo->query("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.slug = 'admin' AND u.is_active = 1 LIMIT 1")->fetchColumn();
if (!$adminId) throw new RuntimeException('An active local admin is required.');
session_id('finance-smoke-' . bin2hex(random_bytes(12)));
session_start();
$_SESSION = ['user_id' => (int)$adminId, 'login_region' => 'factory'];
$cookie = session_name() . '=' . session_id();
session_write_close();
$pages = [
 'modules/finance/customers.php' => 'Request Account Closure',
 'modules/finance/vendors.php' => 'Request Account Closure',
 'modules/finance/bills.php' => 'Request Invoice Deletion',
 'modules/finance/po.php' => 'Request Invoice Deletion',
 'modules/finance/expenses/index.php' => 'Request Deletion',
 'modules/finance/expenses/recurring.php' => null,
 'modules/finance/transfers.php' => null,
 'modules/finance/deletion_requests.php' => null,
 'modules/finance/safes.php' => null,
 'modules/finance/banks.php' => null,
 'modules/finance/personal.php' => null,
 'modules/sales/order_list.php' => 'Request Deletion',
 'modules/purchases/po_list.php' => 'Request Deletion',
];
$records = [
 ['customers', "SELECT id FROM customers WHERE direct_sale IS NULL LIMIT 1", 'modules/customers/wallet.php', 'Request Account Closure'],
 ['vendors', 'SELECT id FROM vendors LIMIT 1', 'modules/vendors/wallet.php', 'Request Account Closure'],
 ['safes', "SELECT id FROM safes WHERE " . getAccountScopeSql() . ' LIMIT 1', 'modules/finance/safe_details.php', null],
 ['banks', "SELECT id FROM bank_accounts WHERE " . getAccountScopeSql() . ' LIMIT 1', 'modules/finance/bank_details.php', null],
 ['personal', "SELECT id FROM personal_accounts WHERE " . getAccountScopeSql() . ' LIMIT 1', 'modules/finance/personal_details.php', null],
 ['expenses', "SELECT id FROM expenses WHERE " . getAccountScopeSql() . ' LIMIT 1', 'modules/finance/expenses/details.php', 'Request Deletion'],
 ['orders', 'SELECT o.id FROM orders o JOIN customers c ON c.id = o.customer_id WHERE c.direct_sale IS NULL LIMIT 1', 'modules/sales/order_details.php', null],
 ['purchases', 'SELECT id FROM purchase_orders LIMIT 1', 'modules/purchases/po_details.php', null],
];
foreach ($records as [$name, $sql, $route, $needle]) {
    $id = $pdo->query($sql)->fetchColumn();
    if ($id) $pages[$route . '?id=' . (int)$id] = $needle;
}
$context = stream_context_create(['http' => ['header' => "Cookie: $cookie\r\n", 'ignore_errors' => true, 'timeout' => 20, 'follow_location' => 0]]);
try {
    foreach ($pages as $route => $needle) {
        $html = file_get_contents('http://127.0.0.1/' . $route, false, $context);
        if (!str_contains($http_response_header[0] ?? '', '200')) throw new RuntimeException('Unexpected response: ' . $route . ' ' . ($http_response_header[0] ?? ''));
        if (preg_match('/(?:Fatal error|Parse error|Uncaught (?:Error|Exception)|Warning:|Notice:)/', $html, $error, PREG_OFFSET_CAPTURE)) {
            $detail = strip_tags(substr($html, $error[0][1], 500));
            throw new RuntimeException('PHP error on ' . $route . ': ' . $detail);
        }
        if ($needle && !str_contains($html, $needle)) throw new RuntimeException('Missing action: ' . $route);
        libxml_use_internal_errors(true);
        $dom = new DOMDocument(); $dom->loadHTML($html);
        $xpath = new DOMXPath($dom);
        foreach ($xpath->query('//table') as $table) {
            $headers = $xpath->query('./thead/tr', $table);
            if ($headers->length !== 1) continue;
            $width = 0;
            foreach ($xpath->query('./th', $headers->item(0)) as $th) $width += max(1, (int)$th->getAttribute('colspan'));
            if (!$width) continue;
            foreach ($xpath->query('./tbody/tr', $table) as $row) {
                $count = 0;
                foreach ($xpath->query('./td', $row) as $td) $count += max(1, (int)$td->getAttribute('colspan'));
                if ($count !== $width) throw new RuntimeException("Table columns on $route: expected $width, got $count");
            }
        }
        echo "PASS $route\n";
    }
    echo count($pages) . " finance pages rendered without PHP errors or table-column mismatches.\n";
} finally {
    session_start(); session_unset(); session_destroy();
    ob_end_flush();
}

<?php
if (PHP_SAPI !== 'cli') exit;
ob_start();
require_once __DIR__ . '/../includes/currency.php';
function currencyAssert($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
}
$rates = ['EGP' => ['rate_to_egp' => 1], 'USD' => ['rate_to_egp' => 50], 'EUR' => ['rate_to_egp' => 60], 'SAR' => ['rate_to_egp' => null]];
foreach ([[2, 'USD', 'EGP', 100.0], [100, 'EGP', 'USD', 2.0], [6, 'USD', 'EUR', 5.0], [5, 'EUR', 'USD', 6.0], [1.235, 'USD', 'USD', 1.24], [0, 'USD', 'EGP', 0.0]] as [$amount, $from, $to, $expected]) {
    currencyAssert(convertCurrencyAmount($amount, $from, $to, $rates) === $expected, "$from to $to conversion failed");
}
foreach ([[1, 'SAR', 'EGP'], [1, 'XXX', 'EGP'], [INF, 'USD', 'EGP'], ['invalid', 'USD', 'EGP']] as [$amount, $from, $to]) {
    try {
        convertCurrencyAmount($amount, $from, $to, $rates);
        throw new RuntimeException('Invalid conversion accepted');
    } catch (InvalidArgumentException $expected) {}
}
echo "PASS conversion, cross rates, rounding, invalid input and missing rates\n";

if (!getenv('GAMMAVET_CURRENCY_HTTP')) exit;
require_once __DIR__ . '/../includes/functions.php';
$roles = []; $users = []; $sessions = [];
function currencyRequest($cookie, $body = null) {
    $options = ['header' => "Cookie: $cookie\r\n", 'ignore_errors' => true, 'follow_location' => 0];
    if ($body !== null) {
        $options['method'] = 'POST';
        $options['header'] .= "Content-Type: application/x-www-form-urlencoded\r\n";
        $options['content'] = http_build_query($body);
    }
    $html = file_get_contents('http://127.0.0.1/modules/settings/index.php', false, stream_context_create(['http' => $options]));
    return [(int)explode(' ', $http_response_header[0])[1], $html];
}
try {
    foreach (['none' => [], 'view' => ['settings.currencies.view'], 'manage' => ['settings.currencies.manage']] as $level => $permissions) {
        $slug = 'currency-test-' . bin2hex(random_bytes(5));
        $pdo->prepare('INSERT INTO roles (name, slug) VALUES (?, ?)')->execute([$slug, $slug]);
        $roleId = (int)$pdo->lastInsertId(); $roles[] = $roleId;
        foreach (array_merge(['region.curva'], $permissions) as $key) {
            $pdo->prepare('INSERT INTO role_permissions (role_id, permission_id) SELECT ?, id FROM permissions WHERE `key` = ?')->execute([$roleId, $key]);
        }
        $pdo->prepare('INSERT INTO users (username, name, email, password, role_id) VALUES (?, ?, ?, ?, ?)')->execute([$slug, $slug, $slug . '@example.invalid', password_hash(bin2hex(random_bytes(12)), PASSWORD_DEFAULT), $roleId]);
        $userId = (int)$pdo->lastInsertId(); $users[] = $userId;
        session_id('currency-test-' . bin2hex(random_bytes(12)));
        session_start();
        $_SESSION = ['user_id' => $userId, 'user_name' => $slug, 'login_region' => 'curva'];
        $cookie = session_name() . '=' . session_id(); $sessions[] = session_id();
        session_write_close();
        [$status, $html] = currencyRequest($cookie);
        currencyAssert($status === ($level === 'none' ? 403 : 200), "$level GET permission failed: $status " . substr(strip_tags($html), 0, 500));
        if ($level === 'none') continue;
        currencyAssert(!preg_match('/Fatal error|Warning:|Notice:|Parse error/', $html), 'Settings rendering error');
        currencyAssert(str_contains($html, 'Save rates') === ($level === 'manage'), 'Rate editor visibility failed');
        preg_match('/name="csrf_token" value="([^"]+)"/', $html, $matches);
        $token = $matches[1];
        [$status] = currencyRequest($cookie, ['action' => 'save_rates', 'csrf_token' => 'invalid']);
        currencyAssert($status === 403, 'CSRF bypass');
        $invalidRates = array_map(static fn($row) => $row['rate_to_egp'] ?? '', getCurrencyRates());
        $invalidRates['USD'] = '-1';
        [$status, $html] = currencyRequest($cookie, ['action' => 'save_rates', 'csrf_token' => $token, 'rates' => $invalidRates]);
        currencyAssert($status === ($level === 'view' ? 403 : 200), 'Write permission failed');
        if ($level === 'manage') currencyAssert(str_contains($html, 'Enter a positive rate for USD'), 'Invalid rate accepted');
        [$status, $html] = currencyRequest($cookie, ['action' => 'convert', 'csrf_token' => $token, 'amount' => '25', 'from_currency' => 'EGP', 'to_currency' => 'EGP']);
        currencyAssert($status === 200 && str_contains($html, 'role="status"'), 'Converter unavailable');
        if ($level === 'manage') {
            $before = getCurrencyRates();
            $unchangedRates = array_map(static fn($row) => $row['rate_to_egp'] ?? '', $before);
            [$status] = currencyRequest($cookie, ['action' => 'save_rates', 'csrf_token' => $token, 'rates' => $unchangedRates]);
            currencyAssert($status === 302 && getCurrencyRates() === $before, 'Saving unchanged rates failed');
        }
        echo "PASS $level permissions, CSRF, converter and settings rendering\n";
    }
    echo "PASS unauthorized settings access blocked\n";
} finally {
    foreach ($users as $id) $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
    foreach ($roles as $id) {
        $pdo->prepare('DELETE FROM role_permissions WHERE role_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM roles WHERE id = ?')->execute([$id]);
    }
    foreach ($sessions as $id) { session_id($id); session_start(); session_destroy(); }
}

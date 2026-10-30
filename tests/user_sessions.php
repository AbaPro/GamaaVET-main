<?php
// Run inside the local app container: php tests/user_sessions.php
// Uses temporary accounts, real PHP sessions and HTTP requests; cleans up fixtures.
if (PHP_SAPI !== 'cli') exit;
ob_start();
require_once __DIR__ . '/../includes/functions.php';

function sessionAssert($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
}

$fixtureUsers = $fixtureSessions = [];
$tag = 'session-test-' . bin2hex(random_bytes(6));
$password = bin2hex(random_bytes(12));
function sessionFixture($id) {
    global $fixtureSessions;
    session_id('test-' . bin2hex(random_bytes(16)));
    session_start();
    // Exercise compatibility with existing sessions that have no version key.
    $_SESSION = ['user_id' => $id, 'user_name' => 'Session test', 'login_region' => 'factory'];
    $cookie = session_name() . '=' . session_id();
    $fixtureSessions[] = session_id();
    session_write_close();
    return $cookie;
}
function sessionRequest($path, $cookie = '', $body = null) {
    global $fixtureSessions;
    $opts = ['header' => "Cookie: $cookie\r\n", 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 20];
    if ($body !== null) {
        $opts['method'] = 'POST';
        $opts['header'] .= "Content-Type: application/x-www-form-urlencoded\r\n";
        $opts['content'] = http_build_query($body);
    }
    $html = file_get_contents('http://127.0.0.1/' . $path, false, stream_context_create(['http' => $opts]));
    sessionAssert($html !== false, "HTTP request failed: $path");
    $status = (int)explode(' ', $http_response_header[0])[1];
    foreach ($http_response_header as $header) {
        if (preg_match('/^Set-Cookie: (PHPSESSID=([^;]+))/', $header, $match)) {
            $cookie = $match[1];
            $fixtureSessions[] = $match[2];
        }
    }
    sessionAssert(!preg_match('/Fatal error|Warning:|Notice:|Parse error/', $html), "PHP error in $path: " . substr(strip_tags($html), 0, 600));
    return [$status, $html, $cookie];
}
function sessionToken($html, $name) {
    sessionAssert((bool)preg_match('/name="' . $name . '" value="([^"]+)"/', $html, $match), "Missing $name");
    return $match[1];
}
function sessionLogin($username, $password) {
    [, , $cookie] = sessionRequest('index.php', '', ['login' => '1', 'username' => $username, 'password' => $password, 'region' => 'factory']);
    return $cookie;
}
function sessionActive($cookie) {
    [$status, $html] = sessionRequest('ajax/session_status.php', $cookie);
    $data = json_decode($html, true, 512, JSON_THROW_ON_ERROR);
    sessionAssert($status === ($data['authenticated'] ? 200 : 401), 'Invalid heartbeat status');
    return $data['authenticated'];
}

try {
    $roleId = (int)$pdo->query("SELECT id FROM roles WHERE slug = 'admin'")->fetchColumn();
    sessionAssert($roleId > 0, 'Admin role missing');
    foreach (['manager', 'target', 'denied'] as $kind) {
        $username = "$tag-$kind";
        $pdo->prepare('INSERT INTO users (username, name, email, password, role, role_id, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)')
            ->execute([$username, $username, "$username@example.invalid", password_hash($password, PASSWORD_DEFAULT), $kind === 'denied' ? 'salesman' : 'admin', $kind === 'denied' ? null : $roleId]);
        $fixtureUsers[$kind] = (int)$pdo->lastInsertId();
    }
    $manager = sessionFixture($fixtureUsers['manager']);
    $target = sessionFixture($fixtureUsers['target']);
    $otherDevice = sessionFixture($fixtureUsers['target']);
    $denied = sessionFixture($fixtureUsers['denied']);
    sessionAssert(sessionActive($target) && sessionActive($otherDevice), 'Existing sessions rejected');
    [$status, $html] = sessionRequest('modules/users/index.php', $manager);
    sessionAssert($status === 200 && str_contains($html, 'Force Logout'), 'Missing force logout UI');
    $token = sessionToken($html, 'csrf_token');
    $action = ['action' => 'force_logout', 'user_id' => $fixtureUsers['target'], 'csrf_token' => $token];
    [$status] = sessionRequest('modules/users/index.php', $manager, array_replace($action, ['csrf_token' => 'invalid']));
    sessionAssert($status === 403 && sessionActive($target), 'CSRF bypass');
    [$status] = sessionRequest('modules/users/index.php', $denied, $action);
    sessionAssert($status === 302 && sessionActive($target), 'Permission bypass');
    [$status] = sessionRequest('modules/users/index.php', $manager, $action);
    sessionAssert($status === 302 && !sessionActive($target) && !sessionActive($otherDevice) && sessionActive($manager), 'Forced logout failed across devices');
    [$status] = sessionRequest('modules/users/edit.php?id=' . $fixtureUsers['manager'], $otherDevice, ['name' => 'forbidden']);
    sessionAssert($status === 302, 'Revoked session could POST');
    echo "PASS forced logout across devices, legacy sessions, permissions, CSRF and revoked POST\n";

    $target = sessionLogin("$tag-target", $password);
    sessionAssert(sessionActive($target), 'Fresh login rejected after forced logout');
    $otherDevice = sessionLogin("$tag-target", $password);
    sessionAssert(sessionActive($target) && sessionActive($otherDevice), 'Second login invalidated first device');
    [, $html] = sessionRequest('modules/users/edit.php?id=' . $fixtureUsers['target'], $manager);
    $edit = ['name' => "$tag-target", 'username' => "$tag-target", 'email' => "$tag-target@example.invalid", 'role_id' => $roleId,
        'safe_access_mode' => 'all', 'bank_access_mode' => 'all', 'safe_access_token' => sessionToken($html, 'safe_access_token')];
    [$status] = sessionRequest('modules/users/edit.php?id=' . $fixtureUsers['target'], $manager, array_replace($edit, ['safe_access_token' => 'invalid']));
    sessionAssert($status === 403 && sessionActive($target), 'Deactivation CSRF bypass');
    [$status] = sessionRequest('modules/users/edit.php?id=' . $fixtureUsers['target'], $manager, $edit);
    sessionAssert($status === 302, 'Deactivation request failed');
    sessionAssert((int)$pdo->query('SELECT is_active FROM users WHERE id = ' . $fixtureUsers['target'])->fetchColumn() === 0, 'Unchecked Active User was saved as active');
    // Do not touch either cookie until after reactivation: even an idle device must stay revoked.
    $blockedLogin = sessionLogin("$tag-target", $password);
    sessionAssert(!sessionActive($blockedLogin), 'Inactive account could login');
    [$status] = sessionRequest('modules/users/edit.php?id=' . $fixtureUsers['target'], $manager, $edit + ['is_active' => 'on']);
    sessionAssert($status === 302 && !sessionActive($target) && !sessionActive($otherDevice), 'Reactivation revived old sessions');
    $target = sessionLogin("$tag-target", $password);
    sessionAssert(sessionActive($target), 'Reactivated account cannot login');
    echo "PASS deactivation through edit form, inactive login rejection and safe reactivation\n";

    [$status] = sessionRequest('modules/users/edit.php?id=' . $fixtureUsers['target'], $manager, $edit + ['is_active' => 'on', 'password' => $password . 'new', 'confirm_password' => $password . 'new']);
    sessionAssert($status === 302 && !sessionActive($target), 'Password reset failed to revoke sessions');
    sessionAssert(!sessionActive(sessionLogin("$tag-target", $password)), 'Old password accepted');
    sessionAssert(sessionActive(sessionLogin("$tag-target", $password . 'new')), 'New password rejected');
    echo "PASS password reset revocation and fresh authentication\n";

    [, $html] = sessionRequest('modules/users/edit.php?id=' . $fixtureUsers['manager'], $manager);
    $selfEdit = array_replace($edit, ['name' => "$tag-manager", 'username' => "$tag-manager", 'email' => "$tag-manager@example.invalid", 'safe_access_token' => sessionToken($html, 'safe_access_token')]);
    sessionRequest('modules/users/edit.php?id=' . $fixtureUsers['manager'], $manager, $selfEdit);
    sessionAssert(sessionActive($manager), 'Self-deactivation allowed');
    sessionRequest('modules/users/index.php', $manager, array_replace($action, ['user_id' => $fixtureUsers['manager']]));
    sessionAssert(!sessionActive($manager), 'Self forced logout failed');
    sessionAssert(sessionActive(sessionLogin("$tag-manager", $password)), 'Forced logout disabled account');
    echo "PASS self-deactivation protection and self forced logout\n";
} finally {
    foreach ($fixtureUsers as $id) {
        $pdo->prepare('DELETE FROM activity_logs WHERE user_id = ? OR (entity_type = \'user\' AND entity_id = ?)')->execute([$id, $id]);
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
    }
    foreach (array_unique($fixtureSessions) as $id) { session_id($id); session_start(); session_destroy(); }
}

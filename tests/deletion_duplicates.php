<?php
// Run from deletion_workflows.php against its disposable database.
if (!getenv('GAMMAVET_DELETION_TESTS')) throw new RuntimeException('Disposable database required.');
require_once __DIR__ . '/../includes/functions.php';
if (DB_NAME !== 'gammavet_deletion_tests') throw new RuntimeException('Refusing application database.');
require_once __DIR__ . '/../includes/deletion_requests.php';
$_SESSION = ['user_id' => 1, 'login_region' => 'factory'];
function verifyDuplicate($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
}
if (($argv[1] ?? '') === 'submit') {
    $_SESSION['login_region'] = $argv[3] ?? 'factory';
    try { createPendingDeletionRequest($argv[2], 'race', 99); }
    catch (DomainException $e) {
        verifyDuplicate(str_contains($e->getMessage(), 'already exists'), 'Unexpected submission error');
    }
    exit;
}
$tables = ['finance_deletion_requests', 'manufacturing_deletion_requests'];
foreach ($tables as $table) {
    $pdo->exec("INSERT INTO `$table` (account_id, entity_type, entity_id, requested_by, status) VALUES
        (1, 'legacy', 1, 1, 'pending'), (1, 'legacy', 1, 2, 'pending'),
        (1, 'legacy', 2, 1, 'approved'), (1, 'legacy', 2, 2, 'pending'),
        (1, 'reopened', 3, 1, 'approved'), (1, 'reopened', 3, 1, 'pending')");
    $pdo->exec("UPDATE `$table` SET reviewed_at = NOW() WHERE entity_type = 'legacy' AND status = 'approved'");
    $pdo->exec("UPDATE `$table` SET reviewed_at = DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE entity_type = 'reopened' AND status = 'approved'");
}
$migration = file_get_contents(__DIR__ . '/../migrations/20261024_unique_pending_deletion_requests.sql');
$pdo->exec($migration);
$pdo->exec($migration);
foreach ($tables as $table) {
    verifyDuplicate((int)$pdo->query("SELECT COUNT(*) FROM `$table` WHERE entity_type = 'legacy' AND status = 'pending'")->fetchColumn() === 1, 'Migration closes duplicates and requests already approved');
    verifyDuplicate((int)$pdo->query("SELECT COUNT(*) FROM `$table` WHERE entity_type = 'reopened' AND status = 'pending'")->fetchColumn() === 1, 'Migration preserves new requests after an earlier approval');
    verifyDuplicate((int)$pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn() === 6, 'Migration preserves history');
    // Separate PHP processes use separate connections and compete for one key.
    $processes = [];
    for ($i = 0; $i < 6; $i++) {
        $processes[] = proc_open([PHP_BINARY, __FILE__, 'submit', $table], [0 => ['file', '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR], $pipes);
    }
    foreach ($processes as $process) verifyDuplicate(proc_close($process) === 0, 'Concurrent submission failed');
    verifyDuplicate((int)$pdo->query("SELECT COUNT(*) FROM `$table` WHERE entity_type = 'race'")->fetchColumn() === 1, 'Concurrent submissions create one request');
    if ($table === 'finance_deletion_requests') {
        try {
            createPendingDeletionRequest($table, 'race', 99, true);
            throw new RuntimeException('Force delete must not bypass pending request');
        } catch (DomainException $e) {}
        verifyDuplicate((int)$pdo->query("SELECT force_delete FROM `$table` WHERE entity_type = 'race'")->fetchColumn() === 0, 'Duplicate must not escalate to force delete');
    }
    $pdo->exec("UPDATE `$table` SET status = 'rejected' WHERE entity_type = 'race'");
    createPendingDeletionRequest($table, 'race', 99);
    $otherAccount = proc_open([PHP_BINARY, __FILE__, 'submit', $table, 'primer'], [0 => ['file', '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR], $pipes);
    verifyDuplicate(proc_close($otherAccount) === 0, 'Separate account submission failed');
    verifyDuplicate((int)$pdo->query("SELECT COUNT(*) FROM `$table` WHERE entity_type = 'race' AND status = 'pending'")->fetchColumn() === 2, 'Resubmission and separate account scope allowed');
    $pdo->exec("DELETE FROM `$table`");
}
echo "PASS duplicate cleanup, migration reruns, concurrent submissions, force delete, resubmission and account isolation\n";

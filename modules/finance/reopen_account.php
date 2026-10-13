<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/record_deletion.php';
$type = is_string($_POST['entity_type'] ?? null) ? $_POST['entity_type'] : '';
$id = filter_var($_POST['record_id'] ?? null, FILTER_VALIDATE_INT);
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !in_array($type, ['customer_account', 'vendor_account'], true) || !$id) throw new DomainException('Invalid account.');
    financeDeletionValidateToken();
    financeDeletionCheckPermission($type, false);
    $pdo->beginTransaction();
    financeRecordDeletionRow($type, $id, true);
    $table = financeRecordDeletionConfig($type);
    $pdo->prepare("UPDATE `$table` SET finance_account_closed_at = NULL, finance_account_closed_by = NULL WHERE id = ?")->execute([$id]);
    $pdo->commit();
    logActivity('Reopened finance account', ['type' => $type, 'id' => $id], 'update', $type === 'customer_account' ? 'customer' : 'vendor', $id);
    setAlert('success', 'Finance account reopened.');
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    setAlert('danger', $error instanceof DomainException ? $error->getMessage() : 'Unable to reopen the account.');
}
redirect($type === 'vendor_account' ? 'vendors.php?show_closed=1' : 'customers.php?show_closed=1');

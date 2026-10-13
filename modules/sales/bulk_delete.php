<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../finance/record_deletion.php';
$count = 0;
$errors = [];
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !is_array($_POST['order_ids'] ?? null)) throw new DomainException('Select invoices to request deletion.');
    financeDeletionValidateToken();
    financeDeletionCheckPermission('sales_invoice', false);
    foreach (array_unique(array_map('intval', $_POST['order_ids'])) as $id) {
        try {
            financeRecordDeletionRow('sales_invoice', $id);
            financeCreateDeletionRequest('sales_invoice', $id);
            $count++;
        } catch (Throwable $error) { $errors[] = $error instanceof DomainException ? $error->getMessage() : 'Unable to request invoice deletion.'; }
    }
    setAlert($count ? 'success' : 'danger', $count . ' invoice deletion request(s) submitted. ' . implode(' ', array_unique($errors)));
} catch (Throwable $error) { setAlert('danger', $error instanceof DomainException ? $error->getMessage() : 'Unable to request deletion.'); }
redirect('order_list.php');

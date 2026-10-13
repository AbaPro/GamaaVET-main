<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../finance/record_deletion.php';
$id = filter_var($_POST['payment_id'] ?? null, FILTER_VALIDATE_INT);
handleFinanceRecordDeletion('purchase_payment', $id ?: 0, '../finance/deletion_requests.php');

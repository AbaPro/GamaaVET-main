<?php
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../record_deletion.php';
$id = filter_var($_POST['expense_id'] ?? null, FILTER_VALIDATE_INT);
handleFinanceRecordDeletion('expense', $id ?: 0, '../deletion_requests.php');

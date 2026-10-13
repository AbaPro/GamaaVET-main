<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/record_deletion.php';
$type = is_string($_POST['entity_type'] ?? null) ? $_POST['entity_type'] : '';
$id = filter_var($_POST['record_id'] ?? null, FILTER_VALIDATE_INT);
handleFinanceRecordDeletion($type, $id ?: 0);

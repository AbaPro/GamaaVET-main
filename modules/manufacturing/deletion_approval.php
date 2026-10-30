<?php
require_once __DIR__ . '/../../includes/deletion_requests.php';
require_once __DIR__ . '/../../includes/formula_access.php';

function manufacturingDeletionConfig($type) {
    return [
        'order' => ['table' => 'manufacturing_orders', 'page' => 'index.php', 'route' => 'delete.php', 'customer' => true],
        'formula' => ['table' => 'manufacturing_formulas', 'page' => 'formulas.php', 'route' => 'formula_delete.php', 'customer' => true],
        'template' => ['table' => 'manufacturing_formula_templates', 'page' => 'formula_templates.php', 'route' => 'formula_template_delete.php', 'customer' => false],
        'bottle_size' => ['table' => 'bottle_sizes', 'page' => 'bottle_sizes.php', 'route' => 'bottle_size_delete.php', 'customer' => false],
        'packaging_option' => ['table' => 'packaging_options', 'page' => 'packaging_options.php', 'route' => 'packaging_option_delete.php', 'customer' => true],
    ][$type] ?? null;
}

// Activity-log entity for each deletable type, so a record's history includes its deletion.
function manufacturingDeletionLogEntity($type) {
    return [
        'order' => 'manufacturing_order',
        'formula' => 'formula',
        'template' => 'formula_template',
        'bottle_size' => 'bottle_size',
        'packaging_option' => 'packaging_option',
    ][$type] ?? 'manufacturing_deletion_request';
}

function manufacturingDeletionToken() {
    if (empty($_SESSION['manufacturing_deletion_token'])) $_SESSION['manufacturing_deletion_token'] = bin2hex(random_bytes(32));
    return $_SESSION['manufacturing_deletion_token'];
}

function manufacturingDeletionValidateToken() {
    $token = $_POST['deletion_token'] ?? '';
    if (!is_string($token) || !hash_equals(manufacturingDeletionToken(), $token)) {
        throw new DomainException('Invalid deletion request. Refresh and try again.');
    }
}

function canRequestManufacturingDeletion() {
    return hasPermission('manufacturing.view') && hasPermission('manufacturing.delete')
        && hasPermission('manufacturing.deletions.request');
}

function manufacturingDeletionCheckAccess($type) {
    if (($_SESSION['login_region'] ?? 'factory') !== 'factory'
        || !hasPermission('manufacturing.view') || !hasPermission('manufacturing.delete')) {
        throw new DomainException('Manufacturing deletion permission is required.');
    }
    if ($type === 'template' && (!hasPermission('manufacturing.formula.view_all') || !areFormulasUnlocked())) {
        throw new DomainException('Formula template access and unlocked formulas are required. Unlock formulas and try again.');
    }
}

function manufacturingDeletionRecord($type, $id, $lock = false) {
    global $pdo;
    $config = manufacturingDeletionConfig($type);
    if (!$config || $id < 1) throw new DomainException('Invalid manufacturing record.');
    $stmt = $pdo->prepare("SELECT * FROM `{$config['table']}` WHERE id = ?" . ($lock ? ' FOR UPDATE' : ''));
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || ($config['customer'] && !canAccessCustomer((int)$row['customer_id']))) {
        throw new DomainException('Manufacturing record not found or access denied.');
    }
    return $row;
}

// Approval and deletion share one PDO transaction, so failed deletions stay pending.
function handleManufacturingDeletion($type) {
    global $pdo;
    $config = manufacturingDeletionConfig($type);
    $returnPage = $config['page'];
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        setAlert('danger', 'Use Request Deletion to submit a deletion request.');
        redirect($returnPage);
        return;
    }
    $files = [];
    try {
        manufacturingDeletionValidateToken();
        manufacturingDeletionCheckAccess($type);
        $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
        if (!$id || $id < 1) throw new DomainException('Invalid manufacturing record.');
        $isApproval = isset($_POST['deletion_request_id']);
        if (!$isApproval) {
            if (!hasPermission('manufacturing.deletions.request')) throw new DomainException('Manufacturing deletion request permission is required.');
            manufacturingDeletionRecord($type, $id);
            $requestId = createPendingDeletionRequest('manufacturing_deletion_requests', $type, $id);
            logActivity("Requested deletion of manufacturing $type ID: $id", ['type' => $type, 'id' => $id, 'request_id' => $requestId], 'other', manufacturingDeletionLogEntity($type), $id);
            setAlert('success', 'Deletion requested. An authorized user must approve it. Admins can approve their own requests.');
            redirect($returnPage);
            return;
        }
        if (!hasPermission('manufacturing.deletions.approve')) throw new DomainException('Manufacturing deletion approval permission is required.');
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT * FROM manufacturing_deletion_requests WHERE id = ? AND account_id = ? FOR UPDATE');
        $stmt->execute([(int)$_POST['deletion_request_id'], getCurrentAccountId()]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$request || $request['status'] !== 'pending' || $request['entity_type'] !== $type || (int)$request['entity_id'] !== $id) {
            throw new DomainException('This deletion request is invalid or has already been reviewed.');
        }
        if ((int)$request['requested_by'] === (int)$_SESSION['user_id'] && !isAdminUser()) throw new DomainException('You cannot approve your own deletion request.');
        $record = manufacturingDeletionRecord($type, $id, true);
        if ($type === 'formula' || $type === 'packaging_option') {
            $column = $type === 'formula' ? 'formula_id' : 'packaging_option_id';
            $stmt = $pdo->prepare("SELECT id FROM manufacturing_orders WHERE `$column` = ? LIMIT 1 FOR UPDATE");
            $stmt->execute([$id]);
            if ($stmt->fetchColumn()) throw new DomainException('Cannot delete a record used by manufacturing orders.');
        }
        if ($type === 'order') {
            $stmt = $pdo->prepare('SELECT d.file_path FROM manufacturing_step_documents d JOIN manufacturing_order_steps s ON d.manufacturing_order_step_id = s.id WHERE s.manufacturing_order_id = ?');
            $stmt->execute([$id]);
            $files = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }
        $stmt = $pdo->prepare("DELETE FROM `{$config['table']}` WHERE id = ?");
        $stmt->execute([$id]);
        if ($stmt->rowCount() !== 1) throw new DomainException('Record could not be deleted.');
        $stmt = $pdo->prepare("UPDATE manufacturing_deletion_requests SET status = 'approved', reviewed_by = ?, reviewed_at = NOW() WHERE id = ?");
        $stmt->execute([(int)$_SESSION['user_id'], (int)$request['id']]);
        $pdo->commit();
        foreach ($files as $file) {
            $path = realpath(ROOT_PATH . '/' . $file);
            if ($path && strpos($path, ROOT_PATH . '/uploads/') === 0 && is_file($path)) unlink($path);
        }
        logActivity("Deleted manufacturing $type ID: $id (deletion approved)", ['type' => $type, 'id' => $id, 'name' => $record['name'] ?? ($record['order_number'] ?? null), 'request_id' => (int)$request['id'], 'requested_by' => (int)$request['requested_by']], 'delete', manufacturingDeletionLogEntity($type), $id);
        setAlert('success', 'Deletion approved and record deleted.');
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (!($error instanceof DomainException)) error_log('Manufacturing deletion failed: ' . $error->getMessage());
        setAlert('danger', $error instanceof DomainException ? $error->getMessage() : 'Unable to delete this record. Please try again.');
    }
    redirect($returnPage);
}

<?php
// All executions use the same connection/transaction as the financial deletion.
function financeDeletionQuery($db, $sql, array $args = []) {
    $stmt = $db->prepare($sql);
    if ($db instanceof PDO) {
        $stmt->execute($args);
    } else {
        if ($args) $stmt->bind_param(str_repeat('s', count($args)), ...$args);
        $stmt->execute();
    }
    return $stmt;
}

function financeDeletionToken() {
    if (empty($_SESSION['finance_deletion_token'])) $_SESSION['finance_deletion_token'] = bin2hex(random_bytes(32));
    return $_SESSION['finance_deletion_token'];
}

function financeDeletionValidateToken() {
    $token = $_POST['deletion_token'] ?? '';
    if (!is_string($token) || !hash_equals(financeDeletionToken(), $token)) {
        throw new DomainException('Invalid deletion request. Refresh and try again.');
    }
}

// Returns only for an approval attempt; ordinary deletion actions create requests.
function financeDeletionRequest($type, $id, $force, $returnPage) {
    global $pdo;
    financeDeletionValidateToken();
    if (isset($_POST['deletion_request_id'])) return;
    $stmt = $pdo->prepare("INSERT INTO finance_deletion_requests (account_id, entity_type, entity_id, force_delete, requested_by) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([getCurrentAccountId(), $type, $id, (int)$force, (int)$_SESSION['user_id']]);
    logActivity('Requested finance deletion', ['type' => $type, 'id' => $id, 'force' => $force], 'create', 'finance_deletion_request', (int)$pdo->lastInsertId());
    setAlert('success', 'Deletion requested. Another user with Finance deletion approval permission must approve it.');
    redirect($returnPage);
    exit;
}

function financeDeletionApprove($db, $type, $id, $force = false) {
    financeDeletionValidateToken();
    if (!hasPermission('finance.deletions.approve')) throw new DomainException('Finance deletion approval permission is required.');
    $stmt = financeDeletionQuery($db, "SELECT * FROM finance_deletion_requests WHERE id = ? AND account_id = ? FOR UPDATE", [(int)($_POST['deletion_request_id'] ?? 0), getCurrentAccountId()]);
    $row = $db instanceof PDO ? $stmt->fetch(PDO::FETCH_ASSOC) : $stmt->get_result()->fetch_assoc();
    if (!$row || $row['status'] !== 'pending' || $row['entity_type'] !== $type || (int)$row['entity_id'] !== (int)$id || (bool)$row['force_delete'] !== (bool)$force) {
        throw new DomainException('This deletion request is invalid or has already been reviewed.');
    }
    if ((int)$row['requested_by'] === (int)$_SESSION['user_id']) throw new DomainException('You cannot approve your own deletion request.');
    financeDeletionQuery($db, "UPDATE finance_deletion_requests SET status = 'approved', reviewed_by = ?, reviewed_at = NOW() WHERE id = ?", [(int)$_SESSION['user_id'], (int)$row['id']]);
    // This update rolls back if any subsequent deletion or balance check fails.
}

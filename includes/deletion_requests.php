<?php
// The unique pending-record key also protects against simultaneous submissions.
function createPendingDeletionRequest($table, $type, $id, $force = null) {
    global $pdo;
    if (!in_array($table, ['finance_deletion_requests', 'manufacturing_deletion_requests'], true)) {
        throw new InvalidArgumentException('Invalid deletion request table.');
    }
    $columns = 'account_id, entity_type, entity_id, requested_by';
    $args = [getCurrentAccountId(), $type, $id, (int)$_SESSION['user_id']];
    if ($force !== null) {
        $columns .= ', force_delete';
        $args[] = (int)$force;
    }
    try {
        $stmt = $pdo->prepare("INSERT INTO `$table` ($columns) VALUES (" . implode(', ', array_fill(0, count($args), '?')) . ')');
        $stmt->execute($args);
    } catch (PDOException $error) {
        if ((int)($error->errorInfo[1] ?? 0) !== 1062) throw $error;
        throw new DomainException('A pending deletion request already exists for this record. Review the existing request before submitting another.');
    }
    return (int)$pdo->lastInsertId();
}

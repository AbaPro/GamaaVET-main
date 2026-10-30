<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/deletion_approval.php';
$canApprove = hasPermission('manufacturing.deletions.approve');
if (!hasPermission('manufacturing.view') || (!$canApprove && !hasPermission('manufacturing.deletions.request'))) {
    setAlert('danger', 'Access denied.');
    redirect('../../dashboard.php');
    exit;
}
$userId = (int)$_SESSION['user_id'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        manufacturingDeletionValidateToken();
        if (!$canApprove) throw new DomainException('Manufacturing deletion approval permission is required.');
        $stmt = $pdo->prepare("UPDATE manufacturing_deletion_requests SET status = 'rejected', reviewed_by = ?, reviewed_at = NOW() WHERE id = ? AND account_id = ? AND status = 'pending' AND (requested_by <> ? OR ? = 1)");
        $stmt->execute([$userId, (int)($_POST['reject'] ?? 0), getCurrentAccountId(), $userId, (int)isAdminUser()]);
        if ($stmt->rowCount() !== 1) throw new DomainException('Request unavailable. You cannot review your own request.');
        $rejectedStmt = $pdo->prepare('SELECT entity_type, entity_id FROM manufacturing_deletion_requests WHERE id = ?');
        $rejectedStmt->execute([(int)$_POST['reject']]);
        $rejected = $rejectedStmt->fetch(PDO::FETCH_ASSOC);
        logActivity("Rejected deletion of manufacturing {$rejected['entity_type']} ID: {$rejected['entity_id']}", ['request_id' => (int)$_POST['reject']], 'other', manufacturingDeletionLogEntity($rejected['entity_type']), (int)$rejected['entity_id']);
        setAlert('success', 'Deletion rejected.');
    } catch (Throwable $error) { setAlert('danger', $error->getMessage()); }
    redirect('deletion_requests.php');
}
$sql = 'SELECT d.*, u.name AS requester, reviewer.name AS reviewer FROM manufacturing_deletion_requests d LEFT JOIN users u ON u.id = d.requested_by LEFT JOIN users reviewer ON reviewer.id = d.reviewed_by WHERE d.account_id = ?';
$args = [getCurrentAccountId()];
if (!$canApprove) { $sql .= ' AND d.requested_by = ?'; $args[] = $userId; }
$stmt = $pdo->prepare($sql . ' ORDER BY d.id DESC LIMIT 200');
$stmt->execute($args);
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
$page_title = 'Manufacturing Deletion Requests';
require_once '../../includes/header.php';
?>
<div class="container-fluid px-4 mt-4">
    <h1>Manufacturing Deletion Requests</h1>
    <p>Records remain unchanged until an authorized user approves deletion. Admins can approve their own requests. Approvers also need manufacturing deletion permission and access to the record. Formula templates require unlocked formulas.</p>
    <?php include '../../includes/messages.php'; ?>
    <div class="table-responsive"><table class="table table-hover">
        <thead><tr><th>Request</th><th>Record</th><th>Requested by</th><th>Created</th><th>Status</th><th>Reviewed by</th><th>Actions</th></tr></thead>
        <tbody><?php foreach ($requests as $request):
            $config = manufacturingDeletionConfig($request['entity_type']);
            $canReview = $canApprove && $request['status'] === 'pending' && ((int)$request['requested_by'] !== $userId || isAdminUser());
        ?>
        <tr>
            <td><?= (int)$request['id'] ?></td>
            <td><?= htmlspecialchars($request['entity_type']) ?> #<?= (int)$request['entity_id'] ?>
                <?php
                $recordPages = ['order' => 'order.php', 'formula' => 'formula_edit.php', 'template' => 'formula_template_edit.php', 'bottle_size' => 'bottle_size_edit.php', 'packaging_option' => 'packaging_option_edit.php'];
                $recordExists = false;
                if ($config && isset($recordPages[$request['entity_type']])) {
                    $existsStmt = $pdo->prepare("SELECT 1 FROM `{$config['table']}` WHERE id = ?");
                    $existsStmt->execute([(int)$request['entity_id']]);
                    $recordExists = (bool)$existsStmt->fetchColumn();
                }
                ?>
                <?php if ($recordExists): ?>
                    <a href="<?= htmlspecialchars($recordPages[$request['entity_type']] . '?id=' . (int)$request['entity_id']) ?>" class="btn btn-sm btn-outline-primary ms-1">View</a>
                <?php endif; ?></td>
            <td><?= htmlspecialchars($request['requester'] ?? '') ?></td><td><?= htmlspecialchars($request['created_at']) ?></td>
            <td><?= htmlspecialchars($request['status']) ?></td><td><?= htmlspecialchars($request['reviewer'] ?? '') ?></td>
            <td><?php if ($canReview): ?>
                <?php if ($recordExists && $config && hasPermission('manufacturing.delete') && ($request['entity_type'] !== 'template' || (hasPermission('manufacturing.formula.view_all') && areFormulasUnlocked()))): ?>
                <form method="post" action="<?= htmlspecialchars($config['route']) ?>" class="d-inline" onsubmit="return confirm('Approve and execute this deletion now?');">
                    <input type="hidden" name="deletion_token" value="<?= htmlspecialchars(manufacturingDeletionToken()) ?>">
                    <input type="hidden" name="deletion_request_id" value="<?= (int)$request['id'] ?>">
                    <input type="hidden" name="id" value="<?= (int)$request['entity_id'] ?>">
                    <button class="btn btn-sm btn-danger">Approve Deletion</button>
                </form>
                <?php endif; ?>
                <form method="post" class="d-inline">
                    <input type="hidden" name="deletion_token" value="<?= htmlspecialchars(manufacturingDeletionToken()) ?>">
                    <button name="reject" value="<?= (int)$request['id'] ?>" class="btn btn-sm btn-secondary">Reject</button>
                </form>
            <?php endif; ?></td>
        </tr><?php endforeach; ?>
        <?php if (!$requests): ?><tr><td colspan="7" class="text-center text-muted">No deletion requests.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>
<?php require_once '../../includes/footer.php'; ?>

<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once __DIR__ . '/deletion_approval.php';
$canApprove = hasPermission('finance.deletions.approve');
$userId = (int)$_SESSION['user_id'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        financeDeletionValidateToken();
        if (!$canApprove) throw new DomainException('Access denied.');
        $stmt = $pdo->prepare("UPDATE finance_deletion_requests SET status = 'rejected', reviewed_by = ?, reviewed_at = NOW() WHERE id = ? AND account_id = ? AND status = 'pending' AND requested_by <> ?");
        $stmt->execute([$userId, (int)($_POST['reject'] ?? 0), getCurrentAccountId(), $userId]);
        if ($stmt->rowCount() !== 1) throw new DomainException('Request unavailable. You cannot review your own request.');
        logActivity('Rejected finance deletion', null, 'update', 'finance_deletion_request', (int)$_POST['reject']);
        setAlert('success', 'Deletion rejected.');
    } catch (Throwable $error) { setAlert('danger', $error->getMessage()); }
    redirect('deletion_requests.php');
}
$sql = 'SELECT d.*, u.name AS requester, reviewer.name AS reviewer FROM finance_deletion_requests d LEFT JOIN users u ON u.id = d.requested_by LEFT JOIN users reviewer ON reviewer.id = d.reviewed_by WHERE d.account_id = ?';
$args = [getCurrentAccountId()];
if (!$canApprove) { $sql .= ' AND d.requested_by = ?'; $args[] = $userId; }
$stmt = $pdo->prepare($sql . ' ORDER BY d.id DESC LIMIT 200');
$stmt->execute($args);
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
$routes = [
    'safe' => ['safes.php', 'delete_account', 'finance.safes'],
    'bank' => ['banks.php', 'delete_account', 'finance.bank_accounts'],
    'personal' => ['personal.php', 'delete_account', 'finance.personal_accounts'],
    'expense' => ['expenses/delete.php', 'expense_id', 'finance.expenses.manage'],
    'category' => ['expenses/categories.php', 'delete', 'finance.expenses.categories'],
];
if (empty($_SESSION['finance_account_delete_token'])) $_SESSION['finance_account_delete_token'] = bin2hex(random_bytes(32));
$page_title = 'Finance Deletion Requests';
require_once '../../includes/header.php';
?>
<div class="container-fluid px-4 mt-4">
    <h1>Finance Deletion Requests</h1>
    <p>Records and balances remain unchanged until another authorized user approves deletion. Approvers also need the permission and account access for the requested action.</p>
    <?php include '../../includes/messages.php'; ?>
    <div class="table-responsive"><table class="table table-hover">
        <thead><tr><th>Request</th><th>Record</th><th>Requested by</th><th>Created</th><th>Status</th><th>Reviewed by</th><th>Actions</th></tr></thead>
        <tbody><?php foreach ($requests as $request):
            $route = $routes[$request['entity_type']] ?? null;
            $permission = $route ? $route[2] : '';
            if ($route && in_array($request['entity_type'], ['safe', 'bank', 'personal'], true)) $permission .= $request['force_delete'] ? '.force_delete' : '.delete';
            $canReview = $canApprove && $request['status'] === 'pending' && (int)$request['requested_by'] !== $userId;
        ?>
        <tr>
            <td><?= (int)$request['id'] ?></td>
            <td><?= htmlspecialchars($request['entity_type']) ?> #<?= (int)$request['entity_id'] ?><?= $request['force_delete'] ? ' (force delete)' : '' ?></td>
            <td><?= htmlspecialchars($request['requester'] ?? '') ?></td><td><?= htmlspecialchars($request['created_at']) ?></td>
            <td><?= htmlspecialchars($request['status']) ?></td><td><?= htmlspecialchars($request['reviewer'] ?? '') ?></td>
            <td><?php if ($canReview): ?>
                <?php if ($route && hasPermission($permission)): ?>
                <form method="post" action="<?= htmlspecialchars($route[0]) ?>" class="d-inline" onsubmit="return confirm('Approve and execute this deletion now?');">
                    <input type="hidden" name="deletion_token" value="<?= htmlspecialchars(financeDeletionToken()) ?>">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['finance_account_delete_token']) ?>">
                    <input type="hidden" name="deletion_request_id" value="<?= (int)$request['id'] ?>">
                    <input type="hidden" name="<?= $request['force_delete'] ? 'force_delete_account' : $route[1] ?>" value="<?= (int)$request['entity_id'] ?>">
                    <button class="btn btn-sm btn-danger">Approve Deletion</button>
                </form>
                <?php endif; ?>
                <form method="post" class="d-inline">
                    <input type="hidden" name="deletion_token" value="<?= htmlspecialchars(financeDeletionToken()) ?>">
                    <button name="reject" value="<?= (int)$request['id'] ?>" class="btn btn-sm btn-secondary">Reject</button>
                </form>
            <?php endif; ?></td>
        </tr><?php endforeach; ?></tbody>
    </table></div>
</div>
<?php require_once '../../includes/footer.php'; ?>

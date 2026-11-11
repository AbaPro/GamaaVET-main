<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once __DIR__ . '/deletion_approval.php';
$canApprove = hasPermission('finance.deletions.approve');
if (!$canApprove && !hasPermission('finance.deletions.request')) {
    setAlert('danger', 'Access denied.');
    redirect('../../dashboard.php');
    exit;
}
$userId = (int)$_SESSION['user_id'];
$tabs = [
    'all' => 'All Requests',
    'sales' => 'Sales',
    'purchases' => 'Purchases',
    'finance' => 'Finance',
];
$activeTab = is_string($_GET['tab'] ?? null) && isset($tabs[$_GET['tab']]) ? $_GET['tab'] : 'all';
$tabTypes = [
    'sales' => ['sales_invoice', 'order_payment', 'customer_account', 'customer_transaction', 'customer_adjustment'],
    'purchases' => ['purchase_invoice', 'purchase_payment', 'vendor_account', 'vendor_transaction'],
];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        financeDeletionValidateToken();
        if (!$canApprove) throw new DomainException('Access denied.');
        $stmt = $pdo->prepare("UPDATE finance_deletion_requests SET status = 'rejected', reviewed_by = ?, reviewed_at = NOW() WHERE id = ? AND account_id = ? AND status = 'pending' AND (requested_by <> ? OR ? = 1)");
        $stmt->execute([$userId, (int)($_POST['reject'] ?? 0), getCurrentAccountId(), $userId, (int)isAdminUser()]);
        if ($stmt->rowCount() !== 1) throw new DomainException('Request unavailable. You cannot review your own request.');
        logActivity('Rejected finance deletion', null, 'update', 'finance_deletion_request', (int)$_POST['reject']);
        setAlert('success', 'Deletion rejected.');
    } catch (Throwable $error) { setAlert('danger', $error->getMessage()); }
    redirect('deletion_requests.php?tab=' . $activeTab);
}
$scope = 'd.account_id = ?';
$args = [getCurrentAccountId()];
if (!$canApprove) { $scope .= ' AND d.requested_by = ?'; $args[] = $userId; }
$countStmt = $pdo->prepare('SELECT d.entity_type, COUNT(*) AS total FROM finance_deletion_requests d WHERE ' . $scope . ' GROUP BY d.entity_type');
$countStmt->execute($args);
$tabCounts = array_fill_keys(array_keys($tabs), 0);
foreach ($countStmt->fetchAll(PDO::FETCH_ASSOC) as $count) {
    $group = 'finance';
    foreach ($tabTypes as $tab => $types) {
        if (in_array($count['entity_type'], $types, true)) { $group = $tab; break; }
    }
    $tabCounts[$group] += (int)$count['total'];
    $tabCounts['all'] += (int)$count['total'];
}
$sql = 'SELECT d.*, u.name AS requester, reviewer.name AS reviewer FROM finance_deletion_requests d LEFT JOIN users u ON u.id = d.requested_by LEFT JOIN users reviewer ON reviewer.id = d.reviewed_by WHERE ' . $scope;
if ($activeTab !== 'all') {
    $types = $activeTab === 'finance' ? array_merge(...array_values($tabTypes)) : $tabTypes[$activeTab];
    $sql .= ' AND d.entity_type ' . ($activeTab === 'finance' ? 'NOT IN' : 'IN') . ' (' . implode(',', array_fill(0, count($types), '?')) . ')';
    $args = array_merge($args, $types);
}
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
foreach (['customer_transaction', 'vendor_transaction', 'order_payment', 'purchase_payment', 'expense_payment', 'transfer', 'customer_adjustment', 'account_adjustment', 'customer_account', 'vendor_account', 'sales_invoice', 'purchase_invoice'] as $type) {
    $routes[$type] = ['delete_record.php', 'record_id', financeDeletionPermission($type)];
}
if (empty($_SESSION['finance_account_delete_token'])) $_SESSION['finance_account_delete_token'] = bin2hex(random_bytes(32));
$page_title = 'Deletion Requests';
require_once '../../includes/header.php';
?>
<div class="container-fluid px-4 mt-4">
    <h1>Deletion Requests</h1>
    <p>Records and balances remain unchanged until an authorized user approves deletion. Admins can approve their own requests. Approvers also need the permission and account access for the requested action.</p>
    <?php include '../../includes/messages.php'; displayMessages(); ?>
    <nav class="nav nav-tabs mb-3" aria-label="Deletion request categories">
        <?php foreach ($tabs as $tab => $label): ?>
            <a class="nav-link<?= $activeTab === $tab ? ' active' : '' ?>" href="?tab=<?= htmlspecialchars($tab) ?>"<?= $activeTab === $tab ? ' aria-current="page"' : '' ?>>
                <?= htmlspecialchars($label) ?> <span class="badge bg-secondary ms-1"><?= number_format($tabCounts[$tab]) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>
    <div class="table-responsive"><table class="table table-hover">
        <thead><tr><th>Request</th><th>Record</th><th>Requested by</th><th>Created</th><th>Status</th><th>Reviewed by</th><th>Actions</th></tr></thead>
        <tbody><?php if (!$requests): ?>
            <tr><td colspan="7" class="text-center text-muted py-4">No deletion requests in this category.</td></tr>
        <?php endif; ?>
        <?php foreach ($requests as $request):
            $route = $routes[$request['entity_type']] ?? null;
            $permission = $route ? $route[2] : '';
            if ($route && in_array($request['entity_type'], ['safe', 'bank', 'personal'], true)) $permission .= $request['force_delete'] ? '.force_delete' : '.delete';
            $canReview = $canApprove && $request['status'] === 'pending' && ((int)$request['requested_by'] !== $userId || isAdminUser());
        ?>
        <tr>
            <td><?= (int)$request['id'] ?></td>
            <td><?= htmlspecialchars($request['entity_type']) ?> <?= htmlspecialchars(financeDeletionRecordLabel($request['entity_type'], $request['entity_id'])) ?><?= $request['force_delete'] ? ' (force delete)' : '' ?>
                <?php if ($recordUrl = financeDeletionRecordUrl($request['entity_type'], $request['entity_id'])): ?>
                    <a href="<?= htmlspecialchars($recordUrl) ?>" class="btn btn-sm btn-outline-primary ms-1">View</a>
                <?php endif; ?></td>
            <td><?= htmlspecialchars($request['requester'] ?? '') ?></td><td><?= htmlspecialchars($request['created_at']) ?></td>
            <td><?= htmlspecialchars($request['status']) ?></td><td><?= htmlspecialchars($request['reviewer'] ?? '') ?></td>
            <td><?php if ($canReview): ?>
                <?php if ($recordUrl && $route && hasPermission($permission)): ?>
                <form method="post" action="<?= htmlspecialchars($route[0]) ?>" class="d-inline" onsubmit="return confirm('Approve and execute this deletion now?');">
                    <input type="hidden" name="deletion_token" value="<?= htmlspecialchars(financeDeletionToken()) ?>">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['finance_account_delete_token']) ?>">
                    <input type="hidden" name="deletion_request_id" value="<?= (int)$request['id'] ?>">
                    <input type="hidden" name="entity_type" value="<?= htmlspecialchars($request['entity_type']) ?>">
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

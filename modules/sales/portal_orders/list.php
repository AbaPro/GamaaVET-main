<?php
require_once '../../../includes/auth.php';
require_once '../../../includes/header.php';
require_once '../../../config/database.php';

if (!hasPermission('sales.portal_orders.manage')) {
    $_SESSION['error'] = "You don't have permission to access this page";
    header("Location: ../../../dashboard.php");
    exit();
}

$canDelete = hasPermission('sales.portal_orders.delete');
$canForceDelete = hasPermission('sales.portal_orders.force_delete');

if (empty($_SESSION['portal_order_delete_token'])) {
    $_SESSION['portal_order_delete_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $deleteId = (int)($_POST['id'] ?? 0);
    $token = $_POST['csrf_token'] ?? '';

    if (!is_string($token) || !hash_equals($_SESSION['portal_order_delete_token'], $token)) {
        $_SESSION['error'] = 'Invalid request. Refresh the page and try again.';
    } elseif (!canAccessPortalOrder($deleteId)) {
        $_SESSION['error'] = 'Portal order request not found.';
    } else {
        $statusStmt = $pdo->prepare("SELECT status FROM portal_orders WHERE id = ?");
        $statusStmt->execute([$deleteId]);
        $currentStatus = $statusStmt->fetchColumn();

        $isForceDelete = $currentStatus === 'approved';
        $requiredPermission = $isForceDelete ? 'sales.portal_orders.force_delete' : 'sales.portal_orders.delete';

        if (!hasPermission($requiredPermission)) {
            $_SESSION['error'] = "You don't have permission to delete this portal order request.";
        } elseif (!in_array($currentStatus, ['pending_review', 'priced', 'rejected', 'approved'], true)) {
            $_SESSION['error'] = 'This request can no longer be deleted.';
        } else {
            try {
                $pdo->beginTransaction();
                $pdo->prepare("DELETE FROM portal_order_items WHERE portal_order_id = ?")->execute([$deleteId]);
                $pdo->prepare("DELETE FROM portal_orders WHERE id = ?")->execute([$deleteId]);
                $pdo->commit();

                logActivity(
                    $isForceDelete ? 'Force-deleted portal order request' : 'Deleted portal order request',
                    ['portal_order_id' => $deleteId, 'status' => $currentStatus],
                    'delete',
                    'portal_order',
                    $deleteId
                );
                $_SESSION['success'] = 'Portal order request deleted.';
            } catch (Throwable $e) {
                $pdo->rollBack();
                $_SESSION['error'] = 'Failed to delete request: ' . $e->getMessage();
            }
        }
    }

    header('Location: list.php');
    exit();
}

$status = $_GET['status'] ?? '';

$query = "SELECT po.id, po.status, po.total_amount, po.created_at, po.reviewed_at,
                 po.converted_order_id, c.name AS customer_name
          FROM portal_orders po
          JOIN customers c ON c.id = po.customer_id
          LEFT JOIN factories f ON f.id = c.factory_id
          WHERE " . getCustomerChannelScopeSql('c', 'f');
$params = [];

if (!empty($status)) {
    $query .= " AND po.status = ?";
    $params[] = $status;
}

$query .= " ORDER BY po.created_at DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$portalOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);

$statusClassMap = [
    'pending_review' => 'bg-warning text-dark',
    'priced' => 'bg-info',
    'approved' => 'bg-primary',
    'rejected' => 'bg-danger',
    'converted' => 'bg-success',
];
$statusLabelMap = [
    'pending_review' => 'Pending Review',
    'priced' => 'Priced',
    'approved' => 'Approved',
    'rejected' => 'Rejected',
    'converted' => 'Converted',
];
?>

<div class="container mt-4">
    <h2>Portal Order Requests</h2>

    <?php include '../../../includes/messages.php'; ?>

    <div class="card">
        <div class="card-header">
            <h4>Requests submitted through the customer portal</h4>
        </div>
        <div class="card-body">
            <form method="get" class="mb-4">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label for="status" class="form-label">Status</label>
                        <select class="form-select" id="status" name="status">
                            <option value="">All Statuses</option>
                            <?php foreach ($statusLabelMap as $key => $label): ?>
                                <option value="<?= $key ?>" <?= $status === $key ? 'selected' : '' ?>><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary me-2">Filter</button>
                        <a href="list.php" class="btn btn-secondary">Reset</a>
                    </div>
                </div>
            </form>

            <div class="table-responsive">
                <table class="table js-datatable table-striped table-hover">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Customer</th>
                            <th>Submitted</th>
                            <th>Status</th>
                            <th>Total (after pricing)</th>
                            <th>Order</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($portalOrders as $portalOrder): ?>
                            <tr>
                                <td><?= (int)$portalOrder['id'] ?></td>
                                <td><?= htmlspecialchars($portalOrder['customer_name']) ?></td>
                                <td><?= date('M d, Y H:i', strtotime($portalOrder['created_at'])) ?></td>
                                <td>
                                    <span class="badge <?= $statusClassMap[$portalOrder['status']] ?? 'bg-secondary' ?>">
                                        <?= $statusLabelMap[$portalOrder['status']] ?? ucfirst($portalOrder['status']) ?>
                                    </span>
                                </td>
                                <td><?= number_format($portalOrder['total_amount'], 2) ?></td>
                                <td>
                                    <?php if ($portalOrder['converted_order_id']): ?>
                                        <a href="../order_details.php?id=<?= (int)$portalOrder['converted_order_id'] ?>" class="btn btn-sm btn-info">View Order</a>
                                    <?php else: ?>
                                        <span class="text-muted">N/A</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="d-flex gap-1">
                                        <a href="review.php?id=<?= (int)$portalOrder['id'] ?>" class="btn btn-sm btn-primary">Review</a>
                                        <?php if ($canDelete && in_array($portalOrder['status'], ['pending_review', 'priced', 'rejected'], true)): ?>
                                            <form method="post" onsubmit="return confirm('Permanently delete this portal order request? This cannot be undone.');">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= (int)$portalOrder['id'] ?>">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['portal_order_delete_token'], ENT_QUOTES, 'UTF-8') ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                            </form>
                                        <?php elseif ($canForceDelete && $portalOrder['status'] === 'approved' && !$portalOrder['converted_order_id']): ?>
                                            <form method="post" onsubmit="return confirm('This request has already been approved. Force delete it anyway? This cannot be undone.');">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= (int)$portalOrder['id'] ?>">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['portal_order_delete_token'], ENT_QUOTES, 'UTF-8') ?>">
                                                <button type="submit" class="btn btn-sm btn-danger">Force Delete</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$portalOrders): ?>
                            <tr>
                                <td colspan="7" class="text-center text-muted">No portal order requests found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once '../../../includes/footer.php'; ?>

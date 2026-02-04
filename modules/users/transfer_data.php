<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once 'users_functions.php';

if (!hasPermission('users.manage')) {
    setAlert('danger', 'Access denied.');
    redirect('../../dashboard.php');
}

$users = getAllUsers();
$transferSummary = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fromUser = (int)($_POST['from_user'] ?? 0);
    $toUser = (int)($_POST['to_user'] ?? 0);
    $deleteSourceUser = !empty($_POST['delete_source_user']);

    if ($fromUser <= 0 || $toUser <= 0 || $fromUser === $toUser) {
        setAlert('danger', 'Please choose two different valid users.');
        redirect('transfer_data.php');
    }

    $stmt = $conn->prepare("SELECT COUNT(*) FROM users WHERE id=?");
    $stmt->bind_param("i", $fromUser);
    $stmt->execute();
    $stmt->bind_result($exists);
    $stmt->fetch();
    $stmt->close();
    if (!$exists) {
        setAlert('danger', 'Source user not found.');
        redirect('transfer_data.php');
    }

    $stmt = $conn->prepare("SELECT COUNT(*) FROM users WHERE id=?");
    $stmt->bind_param("i", $toUser);
    $stmt->execute();
    $stmt->bind_result($exists);
    $stmt->fetch();
    $stmt->close();
    if (!$exists) {
        setAlert('danger', 'Target user not found.');
        redirect('transfer_data.php');
    }

    $tables = [
        'activity_logs' => ['user_id'],
        'customer_wallet_transactions' => ['created_by'],
        'vendor_wallet_transactions' => ['created_by'],
        'finance_transfers' => ['created_by'],
        'orders' => ['created_by'],
        'order_payments' => ['created_by'],
        'order_returns' => ['created_by'],
        'quotations' => ['created_by'],
        'purchase_orders' => ['created_by'],
        'purchase_order_payments' => ['created_by'],
        'inventory_transfers' => ['requested_by', 'accepted_by']
    ];

    $conn->begin_transaction();
    try {
        foreach ($tables as $table => $columns) {
            foreach ($columns as $column) {
                $sql = "UPDATE {$table} SET {$column} = ? WHERE {$column} = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("ii", $toUser, $fromUser);
                $stmt->execute();
                $affected = $stmt->affected_rows;
                $stmt->close();
                if ($affected > 0) {
                    $transferSummary[] = "{$table}.{$column}: {$affected}";
                }
            }
        }

        logActivity('Transferred user data', [
            'from' => $fromUser,
            'to' => $toUser,
            'summary' => $transferSummary
        ]);

        $conn->commit();
        $message = empty($transferSummary) ? 'No records required reassignment.' : 'Data transfer completed: ' . implode(', ', $transferSummary);

        if ($deleteSourceUser) {
            if (deleteUser($fromUser)) {
                $message .= ' Source user deleted.';
            } else {
                setAlert('warning', $message . ' However, the source user could not be deleted. Make sure at least one admin remains.');
                redirect('transfer_data.php');
                exit();
            }
        }

        setAlert('success', $message);
    } catch (Exception $e) {
        $conn->rollback();
        setAlert('danger', 'Failed to transfer data: ' . $e->getMessage());
    }

    redirect('transfer_data.php');
}

$pageTitle = 'Transfer User Data';
include __DIR__ . '/../../includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-lg-7">
            <div class="card">
                <div class="card-header bg-light">
                    <h5 class="mb-0">Transfer Ownership</h5>
                </div>
                <div class="card-body">
                    <form method="post">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">From User</label>
                            <select name="from_user" class="form-select" required>
                                <option value="">Select user</option>
                                <?php foreach ($users as $user): ?>
                                    <option value="<?= $user['id']; ?>">
                                        <?= htmlspecialchars($user['name']); ?> (<?= htmlspecialchars($user['role']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">All records created by this user will be reassigned.</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">To User</label>
                            <select name="to_user" class="form-select" required>
                                <option value="">Select user</option>
                                <?php foreach ($users as $user): ?>
                                    <option value="<?= $user['id']; ?>">
                                        <?= htmlspecialchars($user['name']); ?> (<?= htmlspecialchars($user['role']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" value="1" id="delete_source_user" name="delete_source_user">
                            <label class="form-check-label" for="delete_source_user">
                                Delete source user after transfer
                            </label>
                            <div class="form-text">The user account will be permanently removed once all data is reassigned.</div>
                        </div>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-random me-2"></i>Transfer Data
                        </button>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="card h-100">
                <div class="card-header bg-light">
                    <h5 class="mb-0">What gets transferred?</h5>
                </div>
                <div class="card-body">
                    <ul class="list-unstyled mb-0 small">
                        <li><i class="fas fa-check text-success me-2"></i>Orders, order payments & returns</li>
                        <li><i class="fas fa-check text-success me-2"></i>Quotations & purchase orders</li>
                        <li><i class="fas fa-check text-success me-2"></i>Finance transfers & wallets</li>
                        <li><i class="fas fa-check text-success me-2"></i>Inventory transfer approvals</li>
                        <li><i class="fas fa-check text-success me-2"></i>Activity logs for audit trail</li>
                    </ul>
                    <p class="text-muted mt-3 mb-0">
                        Use this when a sales rep leaves or moves to a new territory so the new owner sees
                        all history in their dashboards.
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

<?php
require_once '../../../includes/auth.php';
require_once '../../../config/database.php';
require_once '../../../includes/functions.php';

if (!hasPermission('finance.expenses.view')) {
    setAlert('danger', 'Access denied.');
    redirect('index.php');
}

$expense_id = $_GET['id'] ?? 0;

$sql = "SELECT e.*, ec.name as category_name, u.name as creator_name, v.name as vendor_name,
               po.id as po_id, po.status as po_status, a.name as account_name
        FROM expenses e
        JOIN expense_categories ec ON e.category_id = ec.id
        JOIN users u ON e.created_by = u.id
        LEFT JOIN vendors v ON e.vendor_id = v.id
        LEFT JOIN purchase_orders po ON e.po_id = po.id
        LEFT JOIN accounts a ON e.account_id = a.id
        WHERE e.id = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute([$expense_id]);
$expense = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$expense) {
    setAlert('danger', 'Expense not found.');
    redirect('index.php');
}

// Fetch Payments
$sql_payments = "SELECT ep.*, u.name as recorder_name, s.name as safe_name, b.bank_name, b.account_number
                 FROM expense_payments ep
                 JOIN users u ON ep.created_by = u.id
                 LEFT JOIN safes s ON ep.safe_id = s.id
                 LEFT JOIN bank_accounts b ON ep.bank_account_id = b.id
                 WHERE ep.expense_id = ?
                 ORDER BY ep.created_at DESC";
$stmt = $pdo->prepare($sql_payments);
$stmt->execute([$expense_id]);
$payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

$balance = $expense['amount'] - $expense['paid_amount'];

$page_title = 'Expense Details: ' . $expense['name'];
require_once '../../../includes/header.php';
?>

<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1>Expense Details</h1>
        <div>
            <a href="index.php" class="btn btn-outline-secondary me-2">Back to List</a>
            <?php if (hasPermission('finance.expenses.manage')): ?>
                <a href="create.php?edit=<?= $expense['id'] ?>" class="btn btn-warning me-2">
                    <i class="fas fa-edit me-1"></i> Edit
                </a>
                <button type="button" class="btn btn-danger" onclick="confirmDelete(<?= $expense['id'] ?>)">
                    <i class="fas fa-trash me-1"></i> Delete
                </button>
            <?php endif; ?>
        </div>
    </div>

    <?php include '../../../includes/messages.php'; ?>

    <div class="row">
        <div class="col-md-4">
            <!-- Expense Summary Card -->
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-primary text-white fw-bold">Summary</div>
                <div class="card-body">
                    <div class="text-center mb-4">
                        <div class="display-6 fw-bold"><?= number_format($expense['amount'], 2) ?></div>
                        <div class="text-muted text-uppercase small">Total Amount</div>
                        <span class="badge bg-secondary mt-1"><?= htmlspecialchars($expense['currency'] ?? 'EGP') ?></span>
                    </div>
                    <hr>
                    <div class="d-flex justify-content-between mb-2">
                        <span>Status:</span>
                        <?php
                        $status_class = [
                            'pending' => 'bg-danger',
                            'partially-paid' => 'bg-warning text-dark',
                            'paid' => 'bg-success'
                        ];
                        ?>
                        <span class="badge <?= $status_class[$expense['status']] ?>">
                            <?= ucwords(str_replace('-', ' ', $expense['status'])) ?>
                        </span>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span>Paid:</span>
                        <span class="text-success fw-bold"><?= number_format($expense['paid_amount'], 2) ?></span>
                    </div>
                    <div class="d-flex justify-content-between mb-3 text-danger fw-bold">
                        <span>Balance:</span>
                        <span><?= number_format($balance, 2) ?></span>
                    </div>

                    <?php if ($balance > 0 && hasPermission('finance.expenses.manage')): ?>
                        <a href="pay.php?id=<?= $expense['id'] ?>" class="btn btn-success w-100">
                            <i class="fas fa-credit-card me-1"></i> Record Payment
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Meta Information -->
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-light fw-bold">Meta Information</div>
                <div class="card-body">
                    <table class="table table-sm mb-0">
                        <tr>
                            <td class="text-muted">Category:</td>
                            <td class="text-end fw-bold"><?= htmlspecialchars($expense['category_name']) ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Date:</td>
                            <td class="text-end"><?= date('M j, Y', strtotime($expense['expense_date'])) ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Created By:</td>
                            <td class="text-end"><?= htmlspecialchars($expense['creator_name']) ?></td>
                        </tr>
                        <?php if ($expense['vendor_name']): ?>
                        <tr>
                            <td class="text-muted">Vendor:</td>
                            <td class="text-end fw-bold"><?= htmlspecialchars($expense['vendor_name']) ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if ($expense['po_id']): ?>
                        <tr>
                            <td class="text-muted">PO Link:</td>
                            <td class="text-end">
                                <a href="../../purchases/po_details.php?id=<?= $expense['po_id'] ?>">
                                    PO #<?= $expense['po_id'] ?> <small>(<?= $expense['po_status'] ?>)</small>
                                </a>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <?php if ($expense['account_name']): ?>
                        <tr>
                            <td class="text-muted">Account:</td>
                            <td class="text-end fw-bold"><?= htmlspecialchars($expense['account_name']) ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if ($expense['is_recurring']): ?>
                        <tr>
                            <td class="text-muted">Recurring:</td>
                            <td class="text-end text-primary"><i class="fas fa-redo me-1"></i> <?= ucfirst($expense['recurrence_interval']) ?></td>
                        </tr>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-md-8">
            <!-- Notes Section -->
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-light fw-bold">Notes / Description</div>
                <div class="card-body">
                    <p class="mb-0"><?= nl2br(htmlspecialchars($expense['notes'] ?: 'No additional notes provided.')) ?></p>
                </div>
            </div>

            <!-- Payment History -->
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-success text-white fw-bold">Payment History</div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th class="ps-3">Date</th>
                                    <th>Method</th>
                                    <th>Account/Safe</th>
                                    <th>Reference</th>
                                    <th>Amount</th>
                                    <th class="pe-3">Recorder</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($payments as $p): ?>
                                <tr>
                                    <td class="ps-3 small"><?= date('M j, Y g:i A', strtotime($p['created_at'])) ?></td>
                                    <td><?= ucfirst($p['payment_method']) ?></td>
                                    <td>
                                        <?php if ($p['safe_name']): ?>
                                            <i class="fas fa-vault me-1"></i> <?= htmlspecialchars($p['safe_name']) ?>
                                        <?php elseif ($p['bank_name']): ?>
                                            <i class="fas fa-university me-1"></i> <?= htmlspecialchars($p['bank_name']) ?>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($p['reference'] ?: '-') ?></td>
                                    <td class="fw-bold text-success"><?= number_format($p['amount'], 2) ?></td>
                                    <td class="pe-3 small"><?= htmlspecialchars($p['recorder_name']) ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($payments)): ?>
                                <tr>
                                    <td colspan="6" class="text-center py-4 text-muted">No payments recorded yet.</td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<form id="deleteForm" method="post" action="delete.php" style="display:none;">
    <input type="hidden" name="expense_id" id="delete_expense_id">
</form>

<script>
function confirmDelete(id) {
    if (confirm('Are you sure you want to delete this expense? This will also delete all associated payment records.')) {
        document.getElementById('delete_expense_id').value = id;
        document.getElementById('deleteForm').submit();
    }
}
</script>

<?php require_once '../../../includes/footer.php'; ?>

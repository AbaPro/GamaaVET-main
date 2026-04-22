<?php
require_once '../../../includes/auth.php';
require_once '../../../config/database.php';
require_once '../../../includes/functions.php';

if (!hasPermission('finance.expenses.view')) {
    setAlert('danger', 'Access denied.');
    redirect('index.php');
}

$page_title = 'Recurring Expenses';
require_once '../../../includes/header.php';

// Fetch Recurring Expenses
$sql = "SELECT e.*, ec.name as category_name, v.name as vendor_name, a.name as account_name
        FROM expenses e
        JOIN expense_categories ec ON e.category_id = ec.id
        LEFT JOIN vendors v ON e.vendor_id = v.id
        LEFT JOIN accounts a ON e.account_id = a.id
        WHERE e.is_recurring = 1
        ORDER BY e.expense_date DESC";
$stmt = $pdo->query($sql);
$recurring_expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Compute next due dates
$today          = new DateTime();
$warn_threshold = new DateTime('+7 days');
$due_soon_count = 0;

foreach ($recurring_expenses as &$exp) {
    $last = new DateTime($exp['expense_date']);
    switch ($exp['recurrence_interval']) {
        case 'monthly':   $next = (clone $last)->modify('+1 month'); break;
        case 'quarterly': $next = (clone $last)->modify('+3 months'); break;
        case 'yearly':    $next = (clone $last)->modify('+1 year'); break;
        default:          $next = null;
    }
    $exp['next_due_date'] = $next ? $next->format('Y-m-d') : null;
    $exp['is_overdue']    = $next && $next < $today;
    $exp['is_due_soon']   = $next && !$exp['is_overdue'] && $next <= $warn_threshold;
    if ($exp['is_overdue'] || $exp['is_due_soon']) $due_soon_count++;
}
unset($exp);
?>

<div class="container-fluid px-4">
    <div class="d-flex justify-content-between align-items-center mb-4 mt-4">
        <h1>Recurring Expenses</h1>
        <div>
            <a href="index.php" class="btn btn-outline-secondary me-2">
                <i class="fas fa-list me-1"></i> All Expenses
            </a>
            <?php if (hasPermission('finance.expenses.manage')): ?>
            <a href="process_recurring.php" class="btn btn-outline-warning me-2">
                <i class="fas fa-sync me-1"></i> Process Now
            </a>
            <?php endif; ?>
            <a href="create.php" class="btn btn-primary">
                <i class="fas fa-plus me-1"></i> New Recurring Expense
            </a>
        </div>
    </div>

    <?php include '../../../includes/messages.php'; ?>

    <?php if ($due_soon_count > 0): ?>
    <div class="alert alert-warning d-flex align-items-center mb-4" role="alert">
        <i class="fas fa-exclamation-triangle me-2 fs-5"></i>
        <div>
            <strong><?= $due_soon_count ?> recurring expense(s)</strong> are due within 7 days or are overdue.
            Review them below and process if needed.
        </div>
    </div>
    <?php endif; ?>

    <div class="card shadow-sm mb-4">
        <div class="card-header bg-light">
            <i class="fas fa-info-circle me-1"></i> About Recurring Expenses
        </div>
        <div class="card-body">
            <p class="text-muted mb-0">These items are cloned automatically based on their interval when the "Process Now" button is clicked or via a scheduled CRON job. All users with expense access are notified when new records are generated.</p>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead class="table-light">
                        <tr>
                            <th>Name</th>
                            <th>Category</th>
                            <th>Account</th>
                            <th>Amount</th>
                            <th>Interval</th>
                            <th>Last Date</th>
                            <th>Next Due</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recurring_expenses as $exp): ?>
                            <?php
                            $sym = ['EGP' => 'ج.م', 'USD' => '$', 'EUR' => '€', 'SAR' => 'ر.س'][$exp['currency'] ?? 'EGP'] ?? ($exp['currency'] ?? 'EGP');
                            ?>
                            <tr class="<?= $exp['is_overdue'] ? 'table-danger' : ($exp['is_due_soon'] ? 'table-warning' : '') ?>">
                                <td><strong><?= htmlspecialchars($exp['name']) ?></strong></td>
                                <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($exp['category_name']) ?></span></td>
                                <td class="small text-muted"><?= htmlspecialchars($exp['account_name'] ?? 'Gamma Vet') ?></td>
                                <td class="fw-bold"><?= $sym ?> <?= number_format($exp['amount'], 2) ?></td>
                                <td><span class="badge bg-info"><?= ucfirst($exp['recurrence_interval']) ?></span></td>
                                <td><?= date('M j, Y', strtotime($exp['expense_date'])) ?></td>
                                <td>
                                    <?php if ($exp['next_due_date']): ?>
                                        <?php if ($exp['is_overdue']): ?>
                                            <span class="badge bg-danger">
                                                <i class="fas fa-exclamation-circle me-1"></i>
                                                Overdue — <?= date('M j, Y', strtotime($exp['next_due_date'])) ?>
                                            </span>
                                        <?php elseif ($exp['is_due_soon']): ?>
                                            <span class="badge bg-warning text-dark">
                                                <i class="fas fa-clock me-1"></i>
                                                Due <?= date('M j', strtotime($exp['next_due_date'])) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted small"><?= date('M j, Y', strtotime($exp['next_due_date'])) ?></span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <div class="btn-group">
                                        <a href="details.php?id=<?= $exp['id'] ?>" class="btn btn-sm btn-outline-info">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <?php if (hasPermission('finance.expenses.manage')): ?>
                                        <a href="create.php?edit=<?= $exp['id'] ?>" class="btn btn-sm btn-outline-warning">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($recurring_expenses)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-4 text-muted">No recurring expenses found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once '../../../includes/footer.php'; ?>

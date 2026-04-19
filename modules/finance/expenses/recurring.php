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
$sql = "SELECT e.*, ec.name as category_name, v.name as vendor_name
        FROM expenses e
        JOIN expense_categories ec ON e.category_id = ec.id
        LEFT JOIN vendors v ON e.vendor_id = v.id
        WHERE e.is_recurring = 1
        ORDER BY e.expense_date DESC";
$stmt = $pdo->query($sql);
$recurring_expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="container-fluid px-4">
    <div class="d-flex justify-content-between align-items-center mb-4 mt-4">
        <h1>Recurring Expenses</h1>
        <div>
            <a href="index.php" class="btn btn-outline-secondary me-2">
                <i class="fas fa-list me-1"></i> All Expenses
            </a>
            <a href="create.php" class="btn btn-primary">
                <i class="fas fa-plus me-1"></i> New Recurring Expense
            </a>
        </div>
    </div>

    <?php include '../../../includes/messages.php'; ?>

    <div class="card shadow-sm">
        <div class="card-header bg-light">
            <i class="fas fa-info-circle me-1"></i> About Recurring Expenses
        </div>
        <div class="card-body">
            <p class="text-muted mb-0">These items will be cloned automatically based on their interval. Note: Automatic cloning requires a CRON job setup. You can also manually trigger the next occurrence by clicking "Duplicate".</p>
        </div>
    </div>

    <div class="card shadow-sm mt-4">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead class="table-light">
                        <tr>
                            <th>Name</th>
                            <th>Category</th>
                            <th>Amount</th>
                            <th>Interval</th>
                            <th>Last Date</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recurring_expenses as $exp): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($exp['name']) ?></strong></td>
                                <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($exp['category_name']) ?></span></td>
                                <td class="fw-bold"><?= number_format($exp['amount'], 2) ?></td>
                                <td><span class="badge bg-info"><?= ucfirst($exp['recurrence_interval']) ?></span></td>
                                <td><?= date('M j, Y', strtotime($exp['expense_date'])) ?></td>
                                <td class="text-end">
                                    <div class="btn-group">
                                        <a href="details.php?id=<?= $exp['id'] ?>" class="btn btn-sm btn-outline-info">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <a href="create.php?edit=<?= $exp['id'] ?>" class="btn btn-sm btn-outline-warning">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($recurring_expenses)): ?>
                            <tr>
                                <td colspan="6" class="text-center py-4 text-muted">No recurring expenses found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once '../../../includes/footer.php'; ?>

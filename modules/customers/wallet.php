<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

if (!hasPermission('customers.wallet') && !hasPermission('finance.customer_wallet.view')) {
    setAlert('danger', 'You do not have permission to access this page.');
    redirect('../../dashboard.php');
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    setAlert('danger', 'Invalid customer ID.');
    redirect('index.php');
}

$customer_id = sanitize($_GET['id']);
$page_title = 'Customer Wallet';

// Get customer info for header
$customer_sql = "SELECT name, wallet_balance FROM customers WHERE id = ?";
$customer_stmt = $conn->prepare($customer_sql);
$customer_stmt->bind_param("i", $customer_id);
$customer_stmt->execute();
$customer_result = $customer_stmt->get_result();

if ($customer_result->num_rows === 0) {
    setAlert('danger', 'Customer not found.');
    redirect('index.php');
}

$customer = $customer_result->fetch_assoc();
$customer_stmt->close();

// Handle wallet transactions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $amount = sanitize($_POST['amount']);
    $type = sanitize($_POST['type']);
    $notes = sanitize($_POST['notes']);
    $user_id = $_SESSION['user_id'];
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Insert wallet transaction
        $transaction_sql = "INSERT INTO customer_wallet_transactions 
                           (customer_id, amount, type, notes, created_by) 
                           VALUES (?, ?, ?, ?, ?)";
        $transaction_stmt = $conn->prepare($transaction_sql);
        $transaction_stmt->bind_param("idssi", $customer_id, $amount, $type, $notes, $user_id);
        $transaction_stmt->execute();
        $transaction_id = $transaction_stmt->insert_id;
        $transaction_stmt->close();
        
        // Update customer wallet balance
        $update_sql = "UPDATE customers SET wallet_balance = wallet_balance ";
        $update_sql .= $type === 'deposit' ? '+' : '-';
        $update_sql .= " ? WHERE id = ?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param("di", $amount, $customer_id);
        $update_stmt->execute();
        $update_stmt->close();
        
        // Commit transaction
        $conn->commit();

        
        setAlert('success', 'Wallet transaction completed successfully.');
        logActivity("Processed wallet transaction ID: $transaction_id for customer ID: $customer_id ($type: $amount)");
        $conn->close();
    } catch (Exception $e) {
        $conn->rollback();
        setAlert('danger', 'Error processing wallet transaction: ' . $e->getMessage());
    }

    header("Location: http://localhost/GammaVET/modules/finance/customers.php");
    exit();
}

// Get wallet transactions
$transactions_sql = "SELECT wt.*, u.name as created_by_name 
                     FROM customer_wallet_transactions wt 
                     LEFT JOIN users u ON wt.created_by = u.id 
                     WHERE wt.customer_id = ? 
                     ORDER BY wt.created_at DESC";
$transactions_stmt = $conn->prepare($transactions_sql);
$transactions_stmt->bind_param("i", $customer_id);
$transactions_stmt->execute();
$transactions_result = $transactions_stmt->get_result();

require_once '../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>
        Wallet for: <?php echo htmlspecialchars($customer['name']); ?>
        <span class="badge bg-<?php echo $customer['wallet_balance'] >= 0 ? 'success' : 'danger'; ?>">
            Balance: <?php echo number_format($customer['wallet_balance'], 2); ?>
        </span>
    </h2>
    <a href="view.php?id=<?php echo $customer_id; ?>" class="btn btn-secondary">Back to Customer</a>
</div>

<div class="row mb-4">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">Wallet Transaction</h5>
            </div>
            <div class="card-body">
                <form action="wallet.php?id=<?php echo $customer_id; ?>" method="POST">
                    <div class="mb-3">
                        <label for="type" class="form-label">Transaction Type*</label>
                        <select class="form-select" id="type" name="type" required>
                            <option value="deposit">Deposit</option>
                            <option value="withdrawal">Withdrawal</option>
                            <option value="payment">Payment</option>
                            <option value="refund">Refund</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="amount" class="form-label">Amount*</label>
                        <input type="number" class="form-control" id="amount" name="amount" min="0.01" step="0.01" required>
                    </div>
                    <div class="mb-3">
                        <label for="notes" class="form-label">Notes</label>
                        <textarea class="form-control" id="notes" name="notes" rows="2"></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary">Process Transaction</button>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h5 class="card-title mb-0">Transaction History</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table js-datatable table-hover">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Type</th>
                        <th>Amount</th>
                        <th>Notes</th>
                        <th>Processed By</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($transactions_result->num_rows > 0): ?>
                        <?php while ($transaction = $transactions_result->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo date('M d, Y H:i', strtotime($transaction['created_at'])); ?></td>
                                <td>
                                    <span class="badge bg-<?php echo $transaction['type'] === 'deposit' || $transaction['type'] === 'refund' ? 'success' : 'danger'; ?>">
                                        <?php echo ucfirst($transaction['type']); ?>
                                    </span>
                                </td>
                                <td><?php echo number_format($transaction['amount'], 2); ?></td>
                                <td><?php echo $transaction['notes'] ? htmlspecialchars($transaction['notes']) : '-'; ?></td>
                                <td><?php echo $transaction['created_by_name'] ? htmlspecialchars($transaction['created_by_name']) : 'System'; ?></td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" class="text-center">No transactions found</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>

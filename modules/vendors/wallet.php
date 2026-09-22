<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../finance/account_balance_adjustments.php';

$canManageVendorWallet = hasPermission('vendors.wallet');
$canViewFinanceVendorWallet = ($_SESSION['login_region'] ?? 'factory') === 'factory'
    && hasPermission('finance.vendor_wallet.view');
$canSetVendorBalance = ($_SESSION['login_region'] ?? 'factory') === 'factory'
    && canSettleFinanceBalances();

if (!$canManageVendorWallet && !$canViewFinanceVendorWallet && !$canSetVendorBalance) {
    setAlert('danger', 'You do not have permission to access this page.');
    redirect('../../dashboard.php');
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    setAlert('danger', 'Invalid vendor ID.');
    redirect('index.php');
}

$vendor_id = (int)$_GET['id'];
$page_title = 'Vendor Wallet';

// Get vendor info for header
$vendor_sql = "SELECT name, wallet_balance FROM vendors WHERE id = ?";
$vendor_stmt = $conn->prepare($vendor_sql);
$vendor_stmt->bind_param("i", $vendor_id);
$vendor_stmt->execute();
$vendor_result = $vendor_stmt->get_result();

if ($vendor_result->num_rows === 0) {
    setAlert('danger', 'Vendor not found.');
    redirect('index.php');
}

$vendor = $vendor_result->fetch_assoc();
$vendor_stmt->close();
$vendorBackUrl = hasPermission('vendors.view')
    ? 'view.php?id=' . $vendor_id
    : '../finance/vendors.php';
$vendorBackLabel = hasPermission('vendors.view') ? 'Back to Vendor' : 'Back to Vendor Wallets';
$formToken = financeAccountFormToken();
handleFinanceAccountBalanceSettlement('vendor', $vendor_id, $canSetVendorBalance, 'wallet.php?id=' . $vendor_id);
$balanceAdjustments = financeAccountBalanceAdjustments('vendor', $vendor_id);

// Handle wallet transactions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canManageVendorWallet) {
        setAlert('danger', 'You do not have permission to process vendor wallet transactions.');
        redirect("wallet.php?id=$vendor_id");
    }
    validateFinanceAccountFormToken("wallet.php?id=$vendor_id");
    $amount = sanitize($_POST['amount']);
    $type = sanitize($_POST['type']);
    $notes = sanitize($_POST['notes']);
    $transactionDate = normalizeTransactionDate($_POST['transaction_date'] ?? '');
    $user_id = $_SESSION['user_id'];

    if ($transactionDate === null) {
        setAlert('danger', 'Enter a valid transaction date.');
        redirect("wallet.php?id=$vendor_id");
    }

    $attachmentError = null;
    $uploadedAttachments = uploadImageAttachments(
        'attachment',
        'assets/uploads/vendor_wallet',
        'wallet_' . $vendor_id,
        0, // optional
        $attachmentError
    );
    if ($attachmentError !== null) {
        setAlert('danger', $attachmentError);
        redirect("wallet.php?id=$vendor_id");
    }

    // Start transaction
    $conn->begin_transaction();

    try {
        // Insert wallet transaction
        $transaction_sql = "INSERT INTO vendor_wallet_transactions
                           (vendor_id, amount, type, notes, transaction_date, created_by)
                           VALUES (?, ?, ?, ?, ?, ?)";
        $transaction_stmt = $conn->prepare($transaction_sql);
        $transaction_stmt->bind_param("idsssi", $vendor_id, $amount, $type, $notes, $transactionDate, $user_id);
        $transaction_stmt->execute();
        $transaction_id = $transaction_stmt->insert_id;
        $transaction_stmt->close();

        if (!empty($uploadedAttachments)) {
            $attStmt = $conn->prepare("INSERT INTO vendor_wallet_transaction_attachments (vendor_wallet_transaction_id, file_path, original_name, created_by) VALUES (?, ?, ?, ?)");
            foreach ($uploadedAttachments as $file) {
                $attStmt->bind_param("issi", $transaction_id, $file['path'], $file['original_name'], $user_id);
                $attStmt->execute();
            }
            $attStmt->close();
        }

        // Update vendor wallet balance
        $update_sql = "UPDATE vendors SET wallet_balance = wallet_balance ";
        $update_sql .= $type === 'deposit' ? '+' : '-';
        $update_sql .= " ? WHERE id = ?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param("di", $amount, $vendor_id);
        $update_stmt->execute();
        $update_stmt->close();

        // Commit transaction
        $conn->commit();

        setAlert('success', 'Wallet transaction completed successfully.');
        logActivity("Processed wallet transaction ID: $transaction_id for vendor ID: $vendor_id ($type: $amount)");
    } catch (Exception $e) {
        $conn->rollback();
        foreach ($uploadedAttachments as $file) {
            $full = ROOT_PATH . '/' . $file['path'];
            if (is_file($full)) {
                unlink($full);
            }
        }
        setAlert('danger', 'Error processing wallet transaction: ' . $e->getMessage());
    }

    redirect("wallet.php?id=$vendor_id");
}

// Get wallet transactions
$transactions_sql = "SELECT wt.*, u.name as created_by_name 
                     FROM vendor_wallet_transactions wt 
                     LEFT JOIN users u ON wt.created_by = u.id 
                     WHERE wt.vendor_id = ? 
                     ORDER BY wt.transaction_date DESC, wt.created_at DESC";
$transactions_stmt = $conn->prepare($transactions_sql);
$transactions_stmt->bind_param("i", $vendor_id);
$transactions_stmt->execute();
$transactions_result = $transactions_stmt->get_result();

$walletAttachmentsByTxn = [];
$waStmt = $conn->prepare("SELECT vendor_wallet_transaction_id, file_path, original_name FROM vendor_wallet_transaction_attachments WHERE vendor_wallet_transaction_id IN (SELECT id FROM vendor_wallet_transactions WHERE vendor_id = ?) ORDER BY created_at ASC");
$waStmt->bind_param("i", $vendor_id);
$waStmt->execute();
$waResult = $waStmt->get_result();
while ($waRow = $waResult->fetch_assoc()) {
    $walletAttachmentsByTxn[$waRow['vendor_wallet_transaction_id']][] = $waRow;
}
$waStmt->close();
require_once '../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>
        Wallet for: <?php echo htmlspecialchars($vendor['name']); ?>
        <span class="badge bg-<?php echo $vendor['wallet_balance'] >= 0 ? 'success' : 'danger'; ?>">
            Balance: <?php echo number_format($vendor['wallet_balance'], 2); ?>
        </span>
    </h2>
    <a href="<?php echo e($vendorBackUrl); ?>" class="btn btn-secondary"><?php echo e($vendorBackLabel); ?></a>
</div>

<div class="row mb-4">
    <?php if ($canManageVendorWallet): ?>
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">Wallet Transaction</h5>
            </div>
            <div class="card-body">
                <form action="wallet.php?id=<?php echo $vendor_id; ?>" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?php echo e($formToken); ?>">
                    <div class="mb-3">
                        <label for="type" class="form-label">Transaction Type*</label>
                        <select class="form-select" id="type" name="type" required>
                            <option value="deposit" data-description="Increase vendor balance (e.g. advance or adjustment)">Deposit - Increase balance</option>
                            <option value="withdrawal" data-description="Decrease balance by taking funds out">Withdrawal - Decrease balance</option>
                            <option value="payment" data-description="Record a payment issued to the vendor">Payment - Pay vendor</option>
                            <option value="refund" data-description="Vendor returned money / credit note">Refund - Vendor refund</option>
                        </select>
                        <small id="typeHelp" class="text-muted d-block mt-1">Deposit - Increase balance</small>
                    </div>
                    <div class="mb-3">
                        <label for="amount" class="form-label">Amount*</label>
                        <input type="number" class="form-control" id="amount" name="amount" min="0.01" step="0.01" required>
                    </div>
                    <div class="mb-3">
                        <label for="transaction_date" class="form-label">Transaction Date*</label>
                        <input type="date" class="form-control" id="transaction_date" name="transaction_date" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label for="notes" class="form-label">Notes</label>
                        <textarea class="form-control" id="notes" name="notes" rows="2"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Attachment (Optional)</label>
                        <input type="file" class="form-control" name="attachment[]" accept="image/jpeg,image/png,image/gif,image/webp" multiple>
                        <small class="text-muted">Attach payment receipt / screenshot (JPG, PNG, GIF, WEBP, max 5MB).</small>
                    </div>
                    <button type="submit" class="btn btn-primary">Process Transaction</button>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <?php if ($canSetVendorBalance): ?>
    <div class="col-md-6">
        <div class="card border-warning" id="set-balance">
            <div class="card-header"><h5 class="card-title mb-0">Set Vendor Balance</h5></div>
            <div class="card-body">
                <?php if (financeAccountBalanceAdjustmentTypeReady('vendor')): ?>
                <div class="alert alert-warning py-2">Set the reconciled wallet amount directly. The old and new values, reason, user, and date are retained in the audit history.</div>
                <form action="wallet.php?id=<?php echo $vendor_id; ?>" method="post" onsubmit="return confirm('Set this vendor wallet to the entered balance?');">
                    <input type="hidden" name="csrf_token" value="<?php echo e($formToken); ?>">
                    <input type="hidden" name="set_finance_account_balance" value="1">
                    <div class="mb-3"><label for="vendor_new_balance" class="form-label">New Balance*</label><input type="number" class="form-control" id="vendor_new_balance" name="new_balance" value="<?php echo e(number_format((float)$vendor['wallet_balance'], 2, '.', '')); ?>" min="-999999999999.99" max="999999999999.99" step="0.01" required></div>
                    <div class="mb-3"><label for="vendor_adjustment_reason" class="form-label">Reconciliation Reason*</label><textarea class="form-control" id="vendor_adjustment_reason" name="adjustment_reason" rows="2" maxlength="500" required></textarea></div>
                    <div class="mb-3"><label for="vendor_adjustment_date" class="form-label">Transaction Date*</label><input type="date" class="form-control" id="vendor_adjustment_date" name="transaction_date" value="<?php echo date('Y-m-d'); ?>" required></div>
                    <button type="submit" class="btn btn-warning">Set Balance</button>
                </form>
                <?php else: ?>
                <div class="alert alert-warning mb-0">Apply migration <code>20260922_finance_wide_balance_settlement.sql</code> to enable audited balance updates.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php if ($balanceAdjustments): ?>
<div class="card mb-4">
    <div class="card-header"><h5 class="card-title mb-0">Balance Settlement History</h5></div>
    <div class="card-body"><div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th>Date</th><th class="text-end">Previous</th><th class="text-end">New</th><th class="text-end">Change</th><th>Reason</th><th>Settled By</th></tr></thead>
            <tbody><?php foreach ($balanceAdjustments as $adjustment): $change = (float)$adjustment['new_balance'] - (float)$adjustment['previous_balance']; ?>
                <tr>
                    <td><?php echo date('M d, Y', strtotime($adjustment['transaction_date'])); ?></td>
                    <td class="text-end"><?php echo number_format((float)$adjustment['previous_balance'], 2); ?></td>
                    <td class="text-end fw-semibold"><?php echo number_format((float)$adjustment['new_balance'], 2); ?></td>
                    <td class="text-end <?php echo $change < 0 ? 'text-danger' : 'text-success'; ?>"><?php echo ($change > 0 ? '+' : '') . number_format($change, 2); ?></td>
                    <td><?php echo e($adjustment['reason']); ?></td>
                    <td><?php echo e($adjustment['created_by_name'] ?: 'System'); ?></td>
                </tr>
            <?php endforeach; ?></tbody>
        </table>
    </div></div>
</div>
<?php endif; ?>

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
                        <th>Attachment</th>
                        <th>Processed By</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($transactions_result->num_rows > 0): ?>
                        <?php while ($transaction = $transactions_result->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo date('M d, Y', strtotime($transaction['transaction_date'])); ?></td>
                                <td>
                                    <span class="badge bg-<?php echo $transaction['type'] === 'deposit' || $transaction['type'] === 'refund' ? 'success' : 'danger'; ?>">
                                        <?php echo ucfirst($transaction['type']); ?>
                                    </span>
                                </td>
                                <td><?php echo number_format($transaction['amount'], 2); ?></td>
                                <td><?php echo $transaction['notes'] ? htmlspecialchars($transaction['notes']) : '-'; ?></td>
                                <td>
                                    <?php echo renderAttachmentThumbnails($walletAttachmentsByTxn[$transaction['id']] ?? []); ?>
                                </td>
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

<script>
document.addEventListener('DOMContentLoaded', function () {
    const typeSelect = document.getElementById('type');
    const help = document.getElementById('typeHelp');
    if (!typeSelect || !help) return;
    const updateHelp = () => {
        const option = typeSelect.selectedOptions[0];
        help.textContent = option ? option.dataset.description : '';
    };
    typeSelect.addEventListener('change', updateHelp);
    updateHelp();
});
</script>

<?php require_once '../../includes/footer.php'; ?>

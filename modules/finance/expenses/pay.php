<?php
require_once '../../../includes/auth.php';
require_once '../../../config/database.php';
require_once '../../../includes/functions.php';

if (!hasPermission('finance.expenses.manage')) {
    setAlert('danger', 'Access denied.');
    redirect('index.php');
}

$expense_id = $_GET['id'] ?? 0;

$stmt = $pdo->prepare("SELECT e.*, ec.name as category_name, v.name as vendor_name FROM expenses e JOIN expense_categories ec ON e.category_id = ec.id LEFT JOIN vendors v ON e.vendor_id = v.id WHERE e.id = ?");
$stmt->execute([$expense_id]);
$expense = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$expense) {
    setAlert('danger', 'Expense not found.');
    redirect('index.php');
}

$balance = $expense['amount'] - $expense['paid_amount'];
if ($balance <= 0) {
    setAlert('info', 'This expense is already fully paid.');
    redirect('details.php?id=' . $expense_id);
}

// Handle Payment Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pay_amount = (float)$_POST['amount'];
    $payment_method = $_POST['payment_method'];
    $safe_id = !empty($_POST['safe_id']) ? $_POST['safe_id'] : null;
    $bank_account_id = !empty($_POST['bank_account_id']) ? $_POST['bank_account_id'] : null;
    $reference = $_POST['reference'] ?? '';

    if ($pay_amount <= 0 || $pay_amount > $balance) {
        setAlert('danger', 'Invalid payment amount.');
    } else {
        try {
            $pdo->beginTransaction();

            // Insert payment record
            $stmt = $pdo->prepare("INSERT INTO expense_payments (expense_id, amount, payment_method, safe_id, bank_account_id, reference, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$expense_id, $pay_amount, $payment_method, $safe_id, $bank_account_id, $reference, $_SESSION['user_id']]);

            // Update expense
            $new_paid_amount = $expense['paid_amount'] + $pay_amount;
            $new_status = ($new_paid_amount >= $expense['amount']) ? 'paid' : 'partially-paid';
            $stmt = $pdo->prepare("UPDATE expenses SET paid_amount = ?, status = ? WHERE id = ?");
            $stmt->execute([$new_paid_amount, $new_status, $expense_id]);

            // Update Safe/Bank Balance
            if ($payment_method == 'cash' && $safe_id) {
                $stmt = $pdo->prepare("UPDATE safes SET balance = balance - ? WHERE id = ?");
                $stmt->execute([$pay_amount, $safe_id]);
            } elseif ($payment_method == 'transfer' && $bank_account_id) {
                $stmt = $pdo->prepare("UPDATE bank_accounts SET balance = balance - ? WHERE id = ?");
                $stmt->execute([$pay_amount, $bank_account_id]);
            }
            
            // If PO is linked
            if ($expense['po_id']) {
                 $stmt = $pdo->prepare("UPDATE purchase_orders SET paid_amount = paid_amount + ? WHERE id = ?");
                 $stmt->execute([$pay_amount, $expense['po_id']]);
                 
                 $stmt = $pdo->prepare("INSERT INTO purchase_order_payments (purchase_order_id, amount, payment_method, reference, notes, safe_id, bank_account_id, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                 $stmt->execute([$expense['po_id'], $pay_amount, $payment_method, $reference, 'Payment via expense: ' . $expense['name'], $safe_id, $bank_account_id, $_SESSION['user_id']]);
            }
            
            // Vendor Wallet
            if ($expense['vendor_id'] && $payment_method == 'wallet') {
                 $vStmt = $pdo->prepare("SELECT wallet_balance FROM vendors WHERE id = ?");
                 $vStmt->execute([$expense['vendor_id']]);
                 $v_balance = $vStmt->fetchColumn();
                 if ($v_balance < $pay_amount) {
                      throw new Exception("Insufficient vendor wallet balance.");
                 }
                 $stmt = $pdo->prepare("UPDATE vendors SET wallet_balance = wallet_balance - ? WHERE id = ?");
                 $stmt->execute([$pay_amount, $expense['vendor_id']]);

                 // Record Wallet Transaction History
                 $stmt = $pdo->prepare("INSERT INTO vendor_wallet_transactions (vendor_id, amount, type, notes, created_by) VALUES (?, ?, 'payment', ?, ?)");
                 $stmt->execute([$expense['vendor_id'], $pay_amount, 'Payment for expense: ' . $expense['name'], $_SESSION['user_id']]);
            }

            $pdo->commit();
            setAlert('success', 'Payment recorded successfully.');
            redirect('details.php?id=' . $expense_id);
        } catch (Exception $e) {
            $pdo->rollBack();
            setAlert('danger', 'Error recording payment: ' . $e->getMessage());
        }
    }
}

$safes = $pdo->query("SELECT id, name FROM safes ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$banks = $pdo->query("SELECT id, bank_name, account_number FROM bank_accounts ORDER BY bank_name ASC")->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Record Payment: ' . $expense['name'];
require_once '../../../includes/header.php';
?>

<div class="container mt-4">
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="card shadow">
                <div class="card-header bg-success text-white">
                    <h4 class="mb-0">Record Payment</h4>
                <div class="card-body">
                    <div class="mb-4">
                        <h5><?= htmlspecialchars($expense['name']) ?></h5>
                        <p class="text-muted mb-0">Category: <?= htmlspecialchars($expense['category_name']) ?></p>
                        <?php if ($expense['vendor_name']): ?>
                            <p class="text-muted">Vendor: <?= htmlspecialchars($expense['vendor_name']) ?></p>
                        <?php endif; ?>
                        <div class="d-flex justify-content-between border-top pt-2">
                             <span>Balance Due:</span>
                             <span class="h5 text-danger"><?= number_format($balance, 2) ?> EGP</span>
                        </div>
                    </div>

                    <form method="post">
                        <div class="mb-3">
                            <label class="form-label">Payment Amount</label>
                            <input type="number" name="amount" class="form-control form-control-lg" step="0.01" min="0.01" max="<?= $balance ?>" value="<?= $balance ?>" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Method</label>
                            <select name="payment_method" id="payment_method" class="form-select" required>
                                <option value="cash">Cash</option>
                                <option value="transfer">Bank Transfer</option>
                                <?php if ($expense['vendor_id']): ?>
                                <option value="wallet">Vendor Wallet</option>
                                <?php endif; ?>
                            </select>
                        </div>

                        <div id="safe_select" class="mb-3">
                            <label class="form-label">Safe</label>
                            <select name="safe_id" class="form-select">
                                <?php foreach ($safes as $s): ?>
                                    <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div id="bank_select" class="mb-3" style="display:none;">
                            <label class="form-label">Bank Account</label>
                            <select name="bank_account_id" class="form-select">
                                <?php foreach ($banks as $b): ?>
                                    <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['bank_name']) ?> (<?= $b['account_number'] ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-4">
                            <label class="form-label">Reference</label>
                            <input type="text" name="reference" class="form-control" placeholder="Check #, ID, etc.">
                        </div>

                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-success btn-lg">Record Payment</button>
                            <a href="details.php?id=<?= $expense_id ?>" class="btn btn-outline-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    $('#payment_method').change(function() {
        const method = $(this).val();
        $('#safe_select, #bank_select').hide();
        if (method === 'cash') $('#safe_select').show();
        else if (method === 'transfer') $('#bank_select').show();
    });
});
</script>

<?php require_once '../../../includes/footer.php'; ?>

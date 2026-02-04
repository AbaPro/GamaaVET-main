<?php
require_once '../../includes/auth.php';
require_once '../../config/database.php';

// Permission check
if (!hasPermission('finance.po_payment.process')) {
    $_SESSION['error'] = "You don't have permission to access this page";
    header("Location: ../../dashboard.php");
    exit();
}

// Get PO ID
$po_id = $_GET['po_id'] ?? 0;

// Fetch PO details
$stmt = $pdo->prepare("
    SELECT po.*, v.name AS vendor_name, v.wallet_balance
    FROM purchase_orders po
    JOIN vendors v ON po.vendor_id = v.id
    WHERE po.id = ?
");
$stmt->execute([$po_id]);
$po = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$po) {
    $_SESSION['error'] = "Purchase order not found";
    header("Location: po_list.php");
    exit();
}

$balance = $po['total_amount'] - $po['paid_amount'];
$selectedPaymentMethod = $_POST['payment_method'] ?? 'cash';

// Handle payment submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $amount = (float)$_POST['amount'];
    $payment_method = $_POST['payment_method'];
    $selectedPaymentMethod = $payment_method;
    $reference = $_POST['reference'] ?? '';
    $notes = $_POST['notes'] ?? '';
    
    // Validate amount
    if ($amount <= 0 || $amount > $balance) {
        $_SESSION['error'] = "Invalid payment amount";
    } else {
        try {
            $pdo->beginTransaction();
            
            // Insert payment record
            $stmt = $pdo->prepare("
                INSERT INTO purchase_order_payments 
                (purchase_order_id, amount, payment_method, reference, notes, created_by)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $po_id,
                $amount,
                $payment_method,
                $reference,
                $notes,
                $_SESSION['user_id']
            ]);
            
            // Update PO paid amount
            $stmt = $pdo->prepare("
                UPDATE purchase_orders SET paid_amount = paid_amount + ? 
                WHERE id = ?
            ");
            $stmt->execute([$amount, $po_id]);
            
            // If payment is from wallet, update vendor wallet
            if ($payment_method == 'wallet') {
                $stmt = $pdo->prepare("
                    UPDATE vendors SET wallet_balance = wallet_balance + ? 
                    WHERE id = ?
                ");
                $stmt->execute([$amount, $po['vendor_id']]);
                
                // Record wallet transaction
                $stmt = $pdo->prepare("
                    INSERT INTO vendor_wallet_transactions
                    (vendor_id, amount, type, reference_id, reference_type, notes, created_by)
                    VALUES (?, ?, 'payment', ?, 'purchase_order', ?, ?)
                ");
                $stmt->execute([
                    $po['vendor_id'],
                    $amount,
                    $po_id,
                    $notes,
                    $_SESSION['user_id']
                ]);
            }
            
            $pdo->commit();
            
            $_SESSION['success'] = "Payment recorded successfully!";
            header("Location: po_details.php?id=" . $po_id);
            exit();
        } catch (PDOException $e) {
            $pdo->rollBack();
            $_SESSION['error'] = "Error recording payment: " . $e->getMessage();
        }
    }
}
?>

<div class="container mt-4">
    <h2>Record Payment</h2>
    
    <?php include '../../includes/messages.php'; ?>
    
    <div class="card">
        <div class="card-header">
            <h4>Purchase Order #<?= $po['id'] ?></h4>
        </div>
        <div class="card-body">
            <div class="row mb-4">
                <div class="col-md-6">
                    <p><strong>Vendor:</strong> <?= htmlspecialchars($po['vendor_name']) ?></p>
                    <p><strong>PO Total:</strong> <?= number_format($po['total_amount'], 2) ?></p>
                </div>
                <div class="col-md-6">
                    <p><strong>Paid Amount:</strong> <?= number_format($po['paid_amount'], 2) ?></p>
                    <p><strong>Balance:</strong> <span class="text-danger"><?= number_format($balance, 2) ?></span></p>
                    <?php if ($selectedPaymentMethod === 'wallet') : ?>
                        <p><strong>Wallet Balance:</strong> <?= number_format($po['wallet_balance'], 2) ?></p>
                    <?php endif; ?>
                </div>
            </div>
            
            <form method="post">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="amount" class="form-label">Amount</label>
                        <input type="number" class="form-control" id="amount" name="amount" 
                               step="0.01" min="0.01" max="<?= $balance ?>" value="<?= $balance ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label for="payment_method" class="form-label">Payment Method</label>
                        <select class="form-select" id="payment_method" name="payment_method" required>
                            <option value="cash" <?= $selectedPaymentMethod === 'cash' ? 'selected' : ''; ?>>Cash</option>
                            <option value="transfer" <?= $selectedPaymentMethod === 'transfer' ? 'selected' : ''; ?>>Bank Transfer</option>
                            <option value="wallet" <?= $po['wallet_balance'] > 0 ? '' : 'disabled'; ?> <?= $selectedPaymentMethod === 'wallet' ? 'selected' : ''; ?>>
                                Vendor Wallet (Balance: <?= number_format($po['wallet_balance'], 2) ?>)
                            </option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label for="reference" class="form-label">Reference</label>
                        <input type="text" class="form-control" id="reference" name="reference">
                    </div>
                    <div class="col-md-6">
                        <label for="notes" class="form-label">Notes</label>
                        <textarea class="form-control" id="notes" name="notes" rows="1"></textarea>
                    </div>
                    <div class="col-md-12">
                        <button type="submit" class="btn btn-primary">Record Payment</button>
                        <a href="po_details.php?id=<?= $po_id ?>" class="btn btn-secondary">Cancel</a>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>

<script>
$(document).ready(function() {
    // Update max amount when payment method changes
    $('#payment_method').change(function() {
        const method = $(this).val();
        const balance = <?= $balance ?>;
        const walletBalance = <?= $po['wallet_balance'] ?>;
        
        if (method == 'wallet') {
            $('#amount').attr('max', Math.min(balance, walletBalance));
            if ($('#amount').val() > walletBalance) {
                $('#amount').val(walletBalance);
            }
        } else {
            $('#amount').attr('max', balance);
        }
    });
});
</script>


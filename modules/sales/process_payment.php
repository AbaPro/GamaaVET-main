<?php
require_once '../../includes/auth.php';
require_once '../../config/database.php';

// Permission check
if (!hasPermission('finance.customer_payment.process')) {
    $_SESSION['error'] = "You don't have permission to access this page";
    header("Location: ../../dashboard.php");
    exit();
}

// Get order ID
$order_id = $_GET['order_id'] ?? 0;

// Fetch order details
$stmt = $pdo->prepare("
    SELECT o.*, c.name AS customer_name, c.wallet_balance
    FROM orders o
    JOIN customers c ON o.customer_id = c.id
    WHERE o.id = ?
");
$stmt->execute([$order_id]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    $_SESSION['error'] = "Order not found";
    header("Location: order_list.php");
    exit();
}

$balance = $order['total_amount'] - $order['paid_amount'];
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
                INSERT INTO order_payments 
                (order_id, amount, payment_method, reference, notes, created_by)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $order_id,
                $amount,
                $payment_method,
                $reference,
                $notes,
                $_SESSION['user_id']
            ]);
            
            // Update order paid amount
            $stmt = $pdo->prepare("
                UPDATE orders SET paid_amount = paid_amount + ? 
                WHERE id = ?
            ");
            $stmt->execute([$amount, $order_id]);
            
            // If payment is from wallet, update customer wallet
            if ($payment_method == 'wallet') {
                $stmt = $pdo->prepare("
                    UPDATE customers SET wallet_balance = wallet_balance - ? 
                    WHERE id = ?
                ");
                $stmt->execute([$amount, $order['customer_id']]);
                
                // Record wallet transaction
                $stmt = $pdo->prepare("
                    INSERT INTO customer_wallet_transactions
                    (customer_id, amount, type, reference_id, reference_type, notes, created_by)
                    VALUES (?, ?, 'payment', ?, 'order', ?, ?)
                ");
                $stmt->execute([
                    $order['customer_id'],
                    $amount,
                    $order_id,
                    $notes,
                    $_SESSION['user_id']
                ]);
            }
            
            $pdo->commit();
            
            $_SESSION['success'] = "Payment recorded successfully!";
            header("Location: order_details.php?id=" . $order_id);
            exit();
        } catch (PDOException $e) {
            $pdo->rollBack();
            $_SESSION['error'] = "Error recording payment: " . $e->getMessage();
        }
    }
}

require_once '../../includes/header.php';
?>

<div class="container mt-4">
    <h2>Record Payment</h2>
    
    <?php include '../../includes/messages.php'; ?>
    
    <div class="card">
        <div class="card-header">
            <h4>Order #<?= htmlspecialchars($order['internal_id']) ?></h4>
        </div>
        <div class="card-body">
            <div class="row mb-4">
                <div class="col-md-6">
                    <p><strong>Customer:</strong> <?= htmlspecialchars($order['customer_name']) ?></p>
                    <p><strong>Order Total:</strong> <?= number_format($order['total_amount'], 2) ?></p>
                </div>
                <div class="col-md-6">
                    <p><strong>Paid Amount:</strong> <?= number_format($order['paid_amount'], 2) ?></p>
                    <p><strong>Balance:</strong> <span class="text-danger"><?= number_format($balance, 2) ?></span></p>
                    <?php if ($selectedPaymentMethod === 'wallet') : ?>
                        <p><strong>Wallet Balance:</strong> <?= number_format($order['wallet_balance'], 2) ?></p>
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
                            <option value="wallet" <?= $order['wallet_balance'] > 0 ? '' : 'disabled'; ?> <?= $selectedPaymentMethod === 'wallet' ? 'selected' : ''; ?>>
                                Customer Wallet (Balance: <?= number_format($order['wallet_balance'], 2) ?>)
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
                        <a href="order_details.php?id=<?= $order_id ?>" class="btn btn-secondary">Cancel</a>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Update max amount when payment method changes
    $('#payment_method').change(function() {
        const method = $(this).val();
        const balance = <?= $balance ?>;
        const walletBalance = <?= $order['wallet_balance'] ?>;
        
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

<?php require_once '../../includes/footer.php'; ?>

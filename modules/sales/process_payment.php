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

// Fetch available Safes
$stmtSafes = $pdo->query("SELECT id, name FROM safes ORDER BY name");
$allSafes = $stmtSafes->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Fetch available Banks
$stmtBanks = $pdo->query("SELECT id, bank_name as name FROM bank_accounts ORDER BY bank_name");
$allBanks = $stmtBanks->fetchAll(PDO::FETCH_ASSOC) ?: [];

$balance = $order['total_amount'] - $order['paid_amount'];
$selectedPaymentMethod = $_POST['payment_method'] ?? 'cash';

// Handle payment submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $payments = $_POST['payments'] ?? [];
    
    $total_payment_amount = 0;
    foreach ($payments as $payment) {
        $total_payment_amount += (float)$payment['amount'];
    }
    
    if (empty($payments) || $total_payment_amount <= 0) {
        $_SESSION['error'] = "Invalid payment amount";
    } elseif ($total_payment_amount > $balance) {
        $_SESSION['error'] = "Total payment amount exceeds balance (" . number_format($balance, 2) . ")";
    } else {
        try {
            $pdo->beginTransaction();
            
            foreach ($payments as $payment) {
                $amount = (float)$payment['amount'];
                if ($amount <= 0) continue;
                
                $payment_method = $payment['payment_method'];
                $reference = $payment['reference'] ?? '';
                $notes = $payment['notes'] ?? '';
                
                $safe_id = null;
                $bank_account_id = null;
                
                if ($payment_method == 'cash') {
                    $safe_id = !empty($payment['safe_id']) ? (int)$payment['safe_id'] : null;
                    if (!$safe_id) {
                        throw new Exception("Please select a safe for cash payment.");
                    }
                } elseif ($payment_method == 'transfer') {
                    $bank_account_id = !empty($payment['bank_account_id']) ? (int)$payment['bank_account_id'] : null;
                    if (!$bank_account_id) {
                        throw new Exception("Please select a bank account for transfer payment.");
                    }
                }
                
                if (empty($reference) || empty($notes)) {
                    throw new Exception("Reference and Notes are required fields for all payments.");
                }

                // Insert payment record
                $stmt = $pdo->prepare("
                    INSERT INTO order_payments 
                    (order_id, amount, payment_method, reference, notes, safe_id, bank_account_id, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $order_id,
                    $amount,
                    $payment_method,
                    $reference,
                    $notes,
                    $safe_id,
                    $bank_account_id,
                    $_SESSION['user_id']
                ]);
                
                // Update specific destination balance (safe or bank account)
                if ($payment_method == 'cash' && $safe_id) {
                    $stmtSafe = $pdo->prepare("UPDATE safes SET balance = balance + ? WHERE id = ?");
                    $stmtSafe->execute([$amount, $safe_id]);
                    
                    // Create a finance transfer entry for deposit
                    $stmtTrans = $pdo->prepare("INSERT INTO finance_transfers (from_type, from_id, to_type, to_id, amount, notes, created_by) VALUES ('personal', 0, 'safe', ?, ?, ?, ?)");
                    $stmtTrans->execute([$safe_id, $amount, 'Order Payment Reference: ' . $reference, $_SESSION['user_id']]);
                } elseif ($payment_method == 'transfer' && $bank_account_id) {
                    $stmtBank = $pdo->prepare("UPDATE bank_accounts SET balance = balance + ? WHERE id = ?");
                    $stmtBank->execute([$amount, $bank_account_id]);
                    
                    // Create a finance transfer entry for deposit
                    $stmtTrans = $pdo->prepare("INSERT INTO finance_transfers (from_type, from_id, to_type, to_id, amount, notes, created_by) VALUES ('personal', 0, 'bank', ?, ?, ?, ?)");
                    $stmtTrans->execute([$bank_account_id, $amount, 'Order Payment Reference: ' . $reference, $_SESSION['user_id']]);
                }
                
                // Update order paid amount
                $stmt = $pdo->prepare("
                    UPDATE orders SET paid_amount = paid_amount + ? 
                    WHERE id = ?
                ");
                $stmt->execute([$amount, $order_id]);
                
                // If payment is from wallet, update customer wallet
                if ($payment_method == 'wallet') {
                    // Recheck wallet balance to be safe
                    $stmtWallet = $pdo->prepare("SELECT wallet_balance FROM customers WHERE id = ?");
                    $stmtWallet->execute([$order['customer_id']]);
                    $current_wallet = $stmtWallet->fetchColumn();
                    
                    if ($current_wallet < $amount) {
                        throw new Exception("Insufficient wallet balance.");
                    }
                    
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
            }
            
            $pdo->commit();
            
            $_SESSION['success'] = "Payment(s) recorded successfully!";
            header("Location: order_details.php?id=" . $order_id);
            exit();
        } catch (Exception $e) {
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
                    <p><strong>Balance:</strong> <span class="text-danger"><?= number_format($balance * -1, 2) ?></span></p>
                    <?php if ($selectedPaymentMethod === 'wallet') : ?>
                        <p><strong>Wallet Balance:</strong> <?= number_format($order['wallet_balance'], 2) ?></p>
                    <?php endif; ?>
                </div>
            </div>
            
            <form method="post" id="paymentForm">
                <div id="paymentsContainer">
                    <div class="payment-row row g-3 mb-3 pb-3 border-bottom align-items-end" data-index="0">
                        <div class="col-md-2">
                            <label class="form-label">Amount</label>
                            <input type="number" class="form-control payment-amount" name="payments[0][amount]" 
                                   step="0.01" min="0.01" max="<?= $balance ?>" value="<?= $balance ?>" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Payment Method</label>
                            <select class="form-select payment-method" name="payments[0][payment_method]" required>
                                <option value="cash" <?= $selectedPaymentMethod === 'cash' ? 'selected' : ''; ?>>Cash</option>
                                <option value="transfer" <?= $selectedPaymentMethod === 'transfer' ? 'selected' : ''; ?>>Bank Transfer</option>
                                <option value="wallet" <?= $order['wallet_balance'] > 0 ? '' : 'disabled'; ?> <?= $selectedPaymentMethod === 'wallet' ? 'selected' : ''; ?>>
                                    Wallet (<?= number_format($order['wallet_balance'], 2) ?>)
                                </option>
                            </select>
                        </div>
                        <div class="col-md-3 payment-destination-container">
                            <!-- Populated via JS based on payment method -->
                            <label class="form-label destination-label">Destination</label>
                            <select class="form-select destination-select" disabled>
                                <option value="">Select Method First</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Reference*</label>
                            <input type="text" class="form-control" name="payments[0][reference]" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Notes*</label>
                            <input type="text" class="form-control" name="payments[0][notes]" required>
                        </div>
                        <div class="col-md-1 text-end">
                            <button type="button" class="btn btn-danger remove-payment" style="display:none;"><i class="fas fa-trash"></i></button>
                        </div>
                    </div>
                </div>

                <div class="mb-4">
                    <button type="button" id="addPaymentBtn" class="btn btn-info text-white">
                        <i class="fas fa-plus"></i> Add Payment
                    </button>
                </div>

                <div class="col-md-12 mt-3 pt-3 border-top">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0">Remaining Balance: <span id="remainingBalanceDisplay" class="text-danger"><?= number_format($balance, 2) ?></span></h5>
                    </div>
                    <button type="submit" class="btn btn-primary">Record Payment(s)</button>
                    <a href="order_details.php?id=<?= $order_id ?>" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    let paymentIndex = 1;
    const balance = <?= $balance ?>;
    const initialWalletBalance = <?= $order['wallet_balance'] ?>;
    const allSafes = <?= json_encode($allSafes) ?>;
    const allBanks = <?= json_encode($allBanks) ?>;

    function buildOptions(items, valueKey, textKey) {
        let opts = `<option value="">-- Select --</option>`;
        items.forEach(item => {
            opts += `<option value="${escapeHtml(item[valueKey])}">${escapeHtml(item[textKey])}</option>`;
        });
        return opts;
    }

    const safesOptions = buildOptions(allSafes, 'id', 'name');
    const banksOptions = buildOptions(allBanks, 'id', 'name');

    function updateDestinationDropdown(row) {
        const method = row.find('.payment-method').val();
        const container = row.find('.payment-destination-container');
        const label = container.find('.destination-label');
        const select = container.find('.destination-select');
        const rIndex = row.data('index');

        select.prop('disabled', false).prop('required', true);

        if (method === 'cash') {
            label.text('Safe');
            select.attr('name', `payments[${rIndex}][safe_id]`);
            select.html(safesOptions);
            container.show();
        } else if (method === 'transfer') {
            label.text('Bank Account');
            select.attr('name', `payments[${rIndex}][bank_account_id]`);
            select.html(banksOptions);
            container.show();
        } else {
            // wallet
            select.prop('disabled', true).prop('required', false).removeAttr('name');
            select.html('<option value="">N/A</option>');
            label.text('Destination');
            container.hide();
        }
    }

    function escapeHtml(unsafe) {
        return (unsafe || '').toString()
             .replace(/&/g, "&amp;")
             .replace(/</g, "&lt;")
             .replace(/>/g, "&gt;")
             .replace(/"/g, "&quot;")
             .replace(/'/g, "&#039;");
    }

    function calculateTotalPayment() {
        let total = 0;
        $('.payment-amount').each(function() {
            total += parseFloat($(this).val()) || 0;
        });
        return total;
    }

    function calculateTotalWalletUsed() {
        let total = 0;
        $('.payment-row').each(function() {
            const method = $(this).find('.payment-method').val();
            if (method === 'wallet') {
                total += parseFloat($(this).find('.payment-amount').val()) || 0;
            }
        });
        return total;
    }

    function validatePaymentAmounts(changedInput) {
        const totalPayment = calculateTotalPayment();
        const totalWallet = calculateTotalWalletUsed();
        
        // Ensure total doesn't exceed balance
        if (totalPayment > balance && changedInput && !$(changedInput).prop('readonly')) {
            const currentVal = parseFloat($(changedInput).val()) || 0;
            const excess = totalPayment - balance;
            const newVal = Math.max(0, currentVal - excess);
            $(changedInput).val(newVal.toFixed(2));
        }

        // Ensure wallet payments don't exceed wallet balance
        if (totalWallet > initialWalletBalance && changedInput && !$(changedInput).prop('readonly')) {
            const row = $(changedInput).closest('.payment-row');
            if (row.find('.payment-method').val() === 'wallet') {
                const currentVal = parseFloat($(changedInput).val()) || 0;
                const excess = totalWallet - initialWalletBalance;
                const newVal = Math.max(0, currentVal - excess);
                $(changedInput).val(newVal.toFixed(2));
            }
        }
        
        // Update Remaining Display
        const currentTotal = calculateTotalPayment();
        const remaining = balance - currentTotal;
        $('#remainingBalanceDisplay').text(Math.max(0, remaining).toFixed(2));
    }

    $(document).on('input', '.payment-amount', function() {
        validatePaymentAmounts(this);
    });

    $(document).on('change', '.payment-method', function() {
        const row = $(this).closest('.payment-row');
        updateDestinationDropdown(row);
        const amountInput = row.find('.payment-amount');
        validatePaymentAmounts(amountInput[0]);
    });

    $('#addPaymentBtn').click(function() {
        const currentTotal = calculateTotalPayment();
        const remaining = balance - currentTotal;
        
        if (remaining <= 0) {
            alert('Full balance is already covered by existing payment rows.');
            return;
        }

        const walletBalanceDisplayStr = initialWalletBalance > 0 ? '' : 'disabled';
        
        const newRow = `
            <div class="payment-row row g-3 mb-3 pb-3 border-bottom align-items-end" data-index="${paymentIndex}">
                <div class="col-md-2">
                    <label class="form-label">Amount</label>
                    <input type="number" class="form-control payment-amount" name="payments[${paymentIndex}][amount]" 
                           step="0.01" min="0.01" max="${balance}" value="${remaining.toFixed(2)}" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Payment Method</label>
                    <select class="form-select payment-method" name="payments[${paymentIndex}][payment_method]" required>
                        <option value="cash" selected>Cash</option>
                        <option value="transfer">Bank Transfer</option>
                        <option value="wallet" ${walletBalanceDisplayStr}>
                            Wallet (${initialWalletBalance.toFixed(2)})
                        </option>
                    </select>
                </div>
                <div class="col-md-3 payment-destination-container">
                    <label class="form-label destination-label">Safe</label>
                    <select class="form-select destination-select" name="payments[${paymentIndex}][safe_id]" required>
                        ${safesOptions}
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Reference*</label>
                    <input type="text" class="form-control" name="payments[${paymentIndex}][reference]" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Notes*</label>
                    <input type="text" class="form-control" name="payments[${paymentIndex}][notes]" required>
                </div>
                <div class="col-md-1 text-end">
                    <button type="button" class="btn btn-danger remove-payment"><i class="fas fa-trash"></i></button>
                </div>
            </div>
        `;
        
        $('#paymentsContainer').append(newRow);
        
        const addedRow = $('#paymentsContainer .payment-row').last();
        updateDestinationDropdown(addedRow);

        // Show remove buttons if more than 1 row
        if ($('.payment-row').length > 1) {
            $('.remove-payment').show();
        }
        
        paymentIndex++;
        validatePaymentAmounts(null);
    });

    $(document).on('click', '.remove-payment', function() {
        if ($('.payment-row').length > 1) {
            $(this).closest('.payment-row').remove();
            
            // Hide remove buttons if only 1 row left
            if ($('.payment-row').length === 1) {
                $('.remove-payment').hide();
            }
            validatePaymentAmounts(null);
        }
    });

    // Initial setup for the first row
    $('.payment-row').each(function() {
        updateDestinationDropdown($(this));
    });
    
    // Initial validation
    validatePaymentAmounts(null);
});
</script>

<?php require_once '../../includes/footer.php'; ?>

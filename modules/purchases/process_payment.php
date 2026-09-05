<?php
require_once '../../includes/auth.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once __DIR__ . '/payment_sources.php';

// Permission check
if (!hasPermission('finance.po_payment.process')) {
    $_SESSION['error'] = "You don't have permission to access this page";
    header("Location: ../../dashboard.php");
    exit();
}

// Get PO ID
$po_id = filter_input(INPUT_GET, 'po_id', FILTER_VALIDATE_INT) ?: 0;
$canViewPODetails = hasPermission('purchases.view');

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
    header("Location: ../finance/po.php");
    exit();
}

$balance = $po['total_amount'] - $po['paid_amount'];
$selectedPaymentMethod = $_POST['payment_method'] ?? 'cash';

$paymentSources = poPaymentSources();
$selectedSource = (string)($_POST['payment_source'] ?? '');
if (empty($_SESSION['po_payment_token'])) $_SESSION['po_payment_token'] = bin2hex(random_bytes(32));

// Handle payment submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $amount = round((float)($_POST['amount'] ?? 0), 2);
    $payment_method = $_POST['payment_method'];
    $selectedPaymentMethod = $payment_method;
    $reference = $_POST['reference'] ?? '';
    $notes = $_POST['notes'] ?? '';

    // Validate amount
    if (!is_string($_POST['csrf_token'] ?? null) || !hash_equals($_SESSION['po_payment_token'], $_POST['csrf_token'])) {
        $_SESSION['error'] = 'Invalid request. Refresh and try again.';
    } elseif (!in_array($payment_method, ['cash', 'transfer', 'wallet'], true)) {
        $_SESSION['error'] = 'Invalid payment method.';
    } elseif ($payment_method !== 'wallet' && !isset($paymentSources[$selectedSource])) {
        $_SESSION['error'] = 'Select an available payment source.';
    } elseif (!is_finite($amount) || $amount <= 0 || $amount > $balance) {
        $_SESSION['error'] = "Invalid payment amount";
    } elseif (empty($reference) || empty($notes)) {
        $_SESSION['error'] = "Reference and Notes are required fields.";
    } else {
        // Handle screenshot upload(s)
        $screenshotError = null;
        $uploadedScreenshots = uploadImageAttachments(
            'screenshot',
            'assets/uploads/po_payments',
            'payment_' . $po_id,
            0, // optional
            $screenshotError
        );
        if ($screenshotError !== null) {
            $_SESSION['error'] = $screenshotError;
            header("Location: process_payment.php?po_id=" . $po_id);
            exit();
        }

        try {
            $pdo->beginTransaction();

            // Lock the PO and recheck the remaining balance after concurrent payments.
            $stmt = $pdo->prepare('SELECT total_amount, paid_amount FROM purchase_orders WHERE id = ? FOR UPDATE');
            $stmt->execute([$po_id]);
            $lockedPO = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$lockedPO || $amount > round($lockedPO['total_amount'] - $lockedPO['paid_amount'], 2)) {
                throw new DomainException('Payment exceeds the remaining PO balance. Refresh and try again.');
            }
            $sourceType = null;
            $sourceId = null;
            if ($payment_method !== 'wallet') {
                $sourceType = $paymentSources[$selectedSource]['type'];
                $sourceId = (int)$paymentSources[$selectedSource]['id'];
                debitPoPaymentSource($sourceType, $sourceId, $amount);
            }

            // Insert payment record
            $stmt = $pdo->prepare("
                INSERT INTO purchase_order_payments
                (purchase_order_id, amount, payment_method, reference, notes, created_by, payment_source_type, payment_source_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $po_id,
                $amount,
                $payment_method,
                $reference,
                $notes,
                $_SESSION['user_id'],
                $sourceType,
                $sourceId
            ]);
            $payment_id = $pdo->lastInsertId();

            if (!empty($uploadedScreenshots)) {
                $attStmt = $pdo->prepare("INSERT INTO purchase_order_payment_attachments (purchase_order_payment_id, file_path, original_name, created_by) VALUES (?, ?, ?, ?)");
                foreach ($uploadedScreenshots as $file) {
                    $attStmt->execute([$payment_id, $file['path'], $file['original_name'], $_SESSION['user_id']]);
                }
            }

            // Update PO paid amount
            $stmt = $pdo->prepare("
                UPDATE purchase_orders SET paid_amount = paid_amount + ? 
                WHERE id = ?
            ");
            $stmt->execute([$amount, $po_id]);
            
            // If payment is from wallet, spend the vendor's credit balance
            if ($payment_method == 'wallet') {
                $vStmt = $pdo->prepare("SELECT wallet_balance FROM vendors WHERE id = ? FOR UPDATE");
                $vStmt->execute([$po['vendor_id']]);
                $walletBalance = (float)$vStmt->fetchColumn();
                if ($walletBalance < $amount) {
                    throw new DomainException("Insufficient vendor wallet balance. Available: " . number_format($walletBalance, 2));
                }

                $stmt = $pdo->prepare("
                    UPDATE vendors SET wallet_balance = wallet_balance - ?
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
            header("Location: " . ($canViewPODetails ? 'po_details.php?id=' . $po_id : '../finance/po.php'));
            exit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            foreach ($uploadedScreenshots as $file) {
                $full = ROOT_PATH . '/' . $file['path'];
                if (is_file($full)) {
                    unlink($full);
                }
            }
            error_log('PO payment failed: ' . $e->getMessage());
            $_SESSION['error'] = $e instanceof DomainException ? $e->getMessage() : 'Unable to record payment. Please try again.';
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
            <h4 class="mb-0">
                <?php if ($canViewPODetails): ?>
                    <a href="po_details.php?id=<?= (int)$po['id']; ?>" class="text-decoration-none">PO-<?= (int)$po['id']; ?></a>
                <?php else: ?>
                    PO-<?= (int)$po['id']; ?>
                <?php endif; ?>
            </h4>
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
            
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['po_payment_token']); ?>">
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
                    <div class="col-md-12" id="payment-source-field">
                        <label for="payment_source" class="form-label">Pay From*</label>
                        <select class="form-select" id="payment_source" name="payment_source" required>
                            <option value="">-- Select safe, bank account, or personal account --</option>
                            <?php foreach ($paymentSources as $value => $source): ?>
                                <option value="<?= e($value); ?>" <?= $selectedSource === $value ? 'selected' : ''; ?>><?= e($source['label'] . ' — ' . $source['name']); ?> (Balance: <?= number_format($source['balance'], 2); ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label for="reference" class="form-label">Reference*</label>
                        <input type="text" class="form-control" id="reference" name="reference" required>
                    </div>
                    <div class="col-md-6">
                        <label for="notes" class="form-label">Notes*</label>
                        <textarea class="form-control" id="notes" name="notes" rows="1" required></textarea>
                    </div>
                    <div class="col-md-12">
                        <label for="screenshot" class="form-label">Payment Screenshot <span class="text-muted">(optional - JPG, PNG, GIF, WEBP, max 5MB)</span></label>
                        <input type="file" class="form-control" id="screenshot" name="screenshot[]" accept="image/jpeg,image/png,image/gif,image/webp" multiple>
                        <div id="screenshot-preview" class="mt-2 d-flex flex-wrap gap-2"></div>
                    </div>
                    <div class="col-md-12">
                        <button type="submit" class="btn btn-primary">Record Payment</button>
                        <a href="<?= $canViewPODetails ? 'po_details.php?id=' . (int)$po_id : '../finance/po.php'; ?>" class="btn btn-secondary">Cancel</a>
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
        $('#payment-source-field').toggle(method !== 'wallet');
        $('#payment_source').prop('disabled', method === 'wallet').prop('required', method !== 'wallet');
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

    $('#payment_method').trigger('change');

    // Screenshot preview (multi-file)
    $('#screenshot').change(function() {
        const container = $('#screenshot-preview');
        container.empty();
        const files = this.files;
        if (!files || files.length === 0) {
            return;
        }
        Array.from(files).forEach(function(file) {
            const reader = new FileReader();
            reader.onload = function(e) {
                container.append($('<img>', {
                    src: e.target.result,
                    class: 'img-thumbnail',
                    css: { maxHeight: '150px' }
                }));
            };
            reader.readAsDataURL(file);
        });
    });
});
</script>

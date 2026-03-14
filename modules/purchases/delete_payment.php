<?php
require_once '../../includes/auth.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Permission check
if (!hasPermission('finance.purchase_payments.delete')) {
    setAlert('danger', "You don't have permission to delete Purchase Order payments.");
    header("Location: " . ($_SERVER['HTTP_REFERER'] ?: 'po_list.php'));
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['payment_id'])) {
    $payment_id = (int)$_POST['payment_id'];
    
    try {
        $pdo->beginTransaction();

        // 1. Fetch payment details
        $stmt = $pdo->prepare("SELECT pop.*, po.vendor_id FROM purchase_order_payments pop JOIN purchase_orders po ON pop.purchase_order_id = po.id WHERE pop.id = ?");
        $stmt->execute([$payment_id]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$payment) {
            throw new Exception("Payment record not found.");
        }

        $po_id = $payment['purchase_order_id'];
        $amount = (float)$payment['amount'];
        $method = $payment['payment_method'];
        $vendor_id = $payment['vendor_id'];

        // 2. Reverse impact on PO paid_amount
        $stmt = $pdo->prepare("UPDATE purchase_orders SET paid_amount = paid_amount - ? WHERE id = ?");
        $stmt->execute([$amount, $po_id]);

        // 3. Reverse impact on Vendor Wallet (if applicable)
        if ($method === 'wallet') {
            // Reversing the '+' done in process_payment.php
            $stmt = $pdo->prepare("UPDATE vendors SET wallet_balance = wallet_balance - ? WHERE id = ?");
            $stmt->execute([$amount, $vendor_id]);

            // Delete related wallet transaction
            $stmt = $pdo->prepare("DELETE FROM vendor_wallet_transactions WHERE vendor_id = ? AND amount = ? AND type = 'payment' AND reference_id = ? AND reference_type = 'purchase_order' ORDER BY created_at DESC LIMIT 1");
            $stmt->execute([$vendor_id, $amount, $po_id]);
        }

        // 4. Delete the payment record itself
        $stmt = $pdo->prepare("DELETE FROM purchase_order_payments WHERE id = ?");
        $stmt->execute([$payment_id]);

        $pdo->commit();
        
        logActivity("Deleted PO payment ID $payment_id for PO #$po_id. Amount: $amount reversed.");
        setAlert('success', "Payment deleted and financial impact reversed successfully.");

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        setAlert('danger', "System error deleting payment: " . $e->getMessage());
    }
}

$redirect = $_SERVER['HTTP_REFERER'] ?: 'po_list.php';
header("Location: $redirect");
exit();

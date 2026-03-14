<?php
require_once '../../includes/auth.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Permission check
if (!hasPermission('finance.payments.delete')) {
    setAlert('danger', "You don't have permission to delete payments");
    header("Location: " . ($_SERVER['HTTP_REFERER'] ?: 'order_list.php'));
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['payment_id'])) {
    $payment_id = (int)$_POST['payment_id'];
    
    try {
        $pdo->beginTransaction();

        // 1. Fetch payment details
        $stmt = $pdo->prepare("SELECT * FROM order_payments WHERE id = ?");
        $stmt->execute([$payment_id]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$payment) {
            throw new Exception("Payment record not found.");
        }

        $order_id = $payment['order_id'];
        $amount = (float)$payment['amount'];
        $method = $payment['payment_method'];

        // 2. Reverse impact on order paid_amount
        $stmt = $pdo->prepare("UPDATE orders SET paid_amount = paid_amount - ? WHERE id = ?");
        $stmt->execute([$amount, $order_id]);

        // 3. Reverse impact on financial accounts (Safe/Bank)
        if ($method === 'cash' && !empty($payment['safe_id'])) {
            $stmt = $pdo->prepare("UPDATE safes SET balance = balance - ? WHERE id = ?");
            $stmt->execute([$amount, $payment['safe_id']]);
            
            // Delete related finance transfer (best effort - matching amount, safe, and reference in notes)
            $ref_like = '%' . $payment['reference'] . '%';
            $stmt = $pdo->prepare("DELETE FROM finance_transfers WHERE to_type = 'safe' AND to_id = ? AND amount = ? AND notes LIKE ? ORDER BY created_at DESC LIMIT 1");
            $stmt->execute([$payment['safe_id'], $amount, $ref_like]);

        } elseif ($method === 'transfer' && !empty($payment['bank_account_id'])) {
            $stmt = $pdo->prepare("UPDATE bank_accounts SET balance = balance - ? WHERE id = ?");
            $stmt->execute([$amount, $payment['bank_account_id']]);

            // Delete related finance transfer
            $ref_like = '%' . $payment['reference'] . '%';
            $stmt = $pdo->prepare("DELETE FROM finance_transfers WHERE to_type = 'bank' AND to_id = ? AND amount = ? AND notes LIKE ? ORDER BY created_at DESC LIMIT 1");
            $stmt->execute([$payment['bank_account_id'], $amount, $ref_like]);

        } elseif ($method === 'wallet') {
            // Get customer ID from order
            $stmt = $pdo->prepare("SELECT customer_id FROM orders WHERE id = ?");
            $stmt->execute([$order_id]);
            $customer_id = $stmt->fetchColumn();

            if ($customer_id) {
                // Restore wallet balance
                $stmt = $pdo->prepare("UPDATE customers SET wallet_balance = wallet_balance + ? WHERE id = ?");
                $stmt->execute([$amount, $customer_id]);

                // Delete related wallet transaction
                $stmt = $pdo->prepare("DELETE FROM customer_wallet_transactions WHERE customer_id = ? AND amount = ? AND type = 'payment' AND reference_id = ? AND reference_type = 'order' ORDER BY created_at DESC LIMIT 1");
                $stmt->execute([$customer_id, $amount, $order_id]);
            }
        }

        // 4. Delete the payment record itself
        $stmt = $pdo->prepare("DELETE FROM order_payments WHERE id = ?");
        $stmt->execute([$payment_id]);

        $pdo->commit();
        
        logActivity("Deleted payment ID $payment_id for Order ID $order_id. Amount: $amount reversed.");
        setAlert('success', "Payment deleted and financial impact reversed successfully.");

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        setAlert('danger', "System error deleting payment: " . $e->getMessage());
    }
}

$redirect = $_SERVER['HTTP_REFERER'] ?: 'order_list.php';
header("Location: $redirect");
exit();

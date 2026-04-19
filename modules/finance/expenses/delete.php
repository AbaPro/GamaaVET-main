<?php
require_once '../../../includes/auth.php';
require_once '../../../config/database.php';
require_once '../../../includes/functions.php';

if (!hasPermission('finance.expenses.manage')) {
    setAlert('danger', 'Access denied.');
    redirect('index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $expense_id = $_POST['expense_id'] ?? 0;

    try {
        $pdo->beginTransaction();

        // Fetch payments to reverse balances
        $stmt = $pdo->prepare("SELECT * FROM expense_payments WHERE expense_id = ?");
        $stmt->execute([$expense_id]);
        $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($payments as $p) {
            if ($p['payment_method'] == 'cash' && $p['safe_id']) {
                $stmt = $pdo->prepare("UPDATE safes SET balance = balance + ? WHERE id = ?");
                $stmt->execute([$p['amount'], $p['safe_id']]);
            } elseif ($p['payment_method'] == 'transfer' && $p['bank_account_id']) {
                $stmt = $pdo->prepare("UPDATE bank_accounts SET balance = balance + ? WHERE id = ?");
                $stmt->execute([$p['amount'], $p['bank_account_id']]);
            }
            
            // If PO is linked, we should ideally reverse PO paid_amount too
            $eStmt = $pdo->prepare("SELECT po_id, vendor_id FROM expenses WHERE id = ?");
            $eStmt->execute([$expense_id]);
            $exp = $eStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($exp && $exp['po_id']) {
                 $stmt = $pdo->prepare("UPDATE purchase_orders SET paid_amount = paid_amount - ? WHERE id = ?");
                 $stmt->execute([$p['amount'], $exp['po_id']]);
            }
            
            if ($exp && $exp['vendor_id'] && $p['payment_method'] == 'wallet') {
                 $stmt = $pdo->prepare("UPDATE vendors SET wallet_balance = wallet_balance + ? WHERE id = ?");
                 $stmt->execute([$p['amount'], $exp['vendor_id']]);
            }
        }

        // Delete payments (CASCADE should handle it if set in SQL, but let's be explicit)
        $stmt = $pdo->prepare("DELETE FROM expense_payments WHERE expense_id = ?");
        $stmt->execute([$expense_id]);

        // Delete expense
        $stmt = $pdo->prepare("DELETE FROM expenses WHERE id = ?");
        $stmt->execute([$expense_id]);

        $pdo->commit();
        setAlert('success', 'Expense and associated payments deleted successfully.');
    } catch (Exception $e) {
        $pdo->rollBack();
        setAlert('danger', 'Error deleting expense: ' . $e->getMessage());
    }
}

redirect('index.php');
?>

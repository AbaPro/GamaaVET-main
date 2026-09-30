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

    $expenseScope = getAccountScopeSql();
    $scopeStmt = $pdo->prepare("SELECT id FROM expenses WHERE id = ? AND $expenseScope");
    $scopeStmt->execute([$expense_id]);
    if (!$scopeStmt->fetchColumn()) {
        setAlert('danger', 'Expense not found.');
        redirect('index.php');
    }

    try {
        $pdo->beginTransaction();

        // Fetch payments to reverse balances
        $stmt = $pdo->prepare("SELECT epa.file_path FROM expense_payment_attachments epa JOIN expense_payments ep ON ep.id = epa.expense_payment_id WHERE ep.expense_id = ?");
        $stmt->execute([$expense_id]);
        $filesToDelete = $stmt->fetchAll(PDO::FETCH_COLUMN);

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
            
            if (($_SESSION['login_region'] ?? 'factory') === 'factory' && $exp && $exp['po_id']) {
                 $stmt = $pdo->prepare("UPDATE purchase_orders SET paid_amount = paid_amount - ? WHERE id = ?");
                 $stmt->execute([$p['amount'], $exp['po_id']]);
            }
            
            if (($_SESSION['login_region'] ?? 'factory') === 'factory' && $p['payment_method'] == 'wallet') {
                 $walletStmt = $pdo->prepare("SELECT vendor_id FROM vendor_wallet_transactions WHERE reference_type = 'expense_payment' AND reference_id = ? LIMIT 1");
                 $walletStmt->execute([$p['id']]);
                 $walletVendorId = $walletStmt->fetchColumn() ?: ($exp['vendor_id'] ?? null);
                 if ($walletVendorId) {
                     $stmt = $pdo->prepare("UPDATE vendors SET wallet_balance = wallet_balance + ? WHERE id = ?");
                     $stmt->execute([$p['amount'], $walletVendorId]);
                 }
            }

            $stmt = $pdo->prepare("DELETE FROM vendor_wallet_transactions WHERE reference_type = 'expense_payment' AND reference_id = ?");
            $stmt->execute([$p['id']]);
        }

        $stmt = $pdo->prepare("DELETE epa FROM expense_payment_attachments epa JOIN expense_payments ep ON ep.id = epa.expense_payment_id WHERE ep.expense_id = ?");
        $stmt->execute([$expense_id]);

        // Delete payments (CASCADE should handle it if set in SQL, but let's be explicit)
        $stmt = $pdo->prepare("DELETE FROM expense_payments WHERE expense_id = ?");
        $stmt->execute([$expense_id]);

        // Delete expense
        $stmt = $pdo->prepare("DELETE FROM expenses WHERE id = ?");
        $stmt->execute([$expense_id]);

        $pdo->commit();
        foreach ($filesToDelete as $path) {
            $fullPath = ROOT_PATH . '/' . $path;
            if (is_file($fullPath)) {
                unlink($fullPath);
            }
        }
        logActivity("Deleted expense ID: $expense_id", null, 'delete', 'expense', $expense_id);
        setAlert('success', 'Expense and associated payments deleted successfully.');
    } catch (Exception $e) {
        $pdo->rollBack();
        setAlert('danger', 'Error deleting expense: ' . $e->getMessage());
    }
}

redirect('index.php');
?>

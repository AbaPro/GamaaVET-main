<?php
// This script can be called via CRON or manually
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../includes/functions.php';

// If not CLI, check permission
if (php_sapi_name() !== 'cli') {
    if (!hasPermission('finance.expenses.manage')) {
        die('Access denied.');
    }
}

try {
    $pdo->beginTransaction();

    // Fetch recurring expenses where the next occurrence is due
    $sql = "SELECT * FROM expenses WHERE is_recurring = 1";
    $stmt = $pdo->query($sql);
    $recurring = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $cloned_count  = 0;
    $new_expense_ids = [];
    $today = date('Y-m-d');

    foreach ($recurring as $exp) {
        $last_date = $exp['expense_date'];
        $next_date = '';

        switch ($exp['recurrence_interval']) {
            case 'monthly':
                $next_date = date('Y-m-d', strtotime($last_date . ' +1 month'));
                break;
            case 'quarterly':
                $next_date = date('Y-m-d', strtotime($last_date . ' +3 months'));
                break;
            case 'yearly':
                $next_date = date('Y-m-d', strtotime($last_date . ' +1 year'));
                break;
        }

        // If next_date is today or in the past, and it hasn't been cloned yet for that date
        if ($next_date && $next_date <= $today) {
            // Check if already cloned
            $checkStmt = $pdo->prepare("SELECT id FROM expenses WHERE name = ? AND expense_date = ? AND category_id = ?");
            $checkStmt->execute([$exp['name'], $next_date, $exp['category_id']]);
            if (!$checkStmt->fetch()) {
                // Clone it (preserve account_id and currency from original)
                $insertStmt = $pdo->prepare("INSERT INTO expenses (account_id, category_id, vendor_id, po_id, name, amount, currency, expense_date, notes, status, created_by, is_recurring, recurrence_interval) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $insertStmt->execute([
                    $exp['account_id'] ?? null,
                    $exp['category_id'],
                    $exp['vendor_id'],
                    $exp['po_id'],
                    $exp['name'],
                    $exp['amount'],
                    $exp['currency'] ?? 'EGP',
                    $next_date,
                    'Auto-generated from recurring expense #' . $exp['id'],
                    'pending',
                    $exp['created_by'],
                    1, // Keep it recurring
                    $exp['recurrence_interval']
                ]);
                $new_expense_ids[] = $pdo->lastInsertId();
                
                // Disable recurrence on the OLD one so it doesn't get processed again
                $updateOld = $pdo->prepare("UPDATE expenses SET is_recurring = 0 WHERE id = ?");
                $updateOld->execute([$exp['id']]);

                $cloned_count++;
            }
        }
    }

    $pdo->commit();

    // Send notifications to all roles with finance.expenses.view permission
    if ($cloned_count > 0 && isset($conn) && $conn instanceof mysqli) {
        $perm_stmt = $conn->prepare(
            "SELECT DISTINCT rp.role_id FROM role_permissions rp
             JOIN permissions p ON p.id = rp.permission_id
             WHERE p.`key` = 'finance.expenses.view'"
        );
        $perm_stmt->execute();
        $roles = $perm_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $perm_stmt->close();
        foreach ($roles as $role) {
            createNotification(
                'recurring_expense_created',
                'Recurring Expenses Generated',
                "$cloned_count recurring expense(s) were automatically created and require attention.",
                'finance',
                'expense',
                count($new_expense_ids) === 1 ? $new_expense_ids[0] : null,
                'info',
                $role['role_id'],
                null,
                null
            );
        }
    }

    if (php_sapi_name() !== 'cli') {
        setAlert('success', "Processed recurring expenses. $cloned_count new records created.");
        redirect('recurring.php');
    } else {
        echo "Processed recurring expenses. $cloned_count new records created.\n";
    }

} catch (Exception $e) {
    $pdo->rollBack();
    if (php_sapi_name() !== 'cli') {
        setAlert('danger', "Error: " . $e->getMessage());
        redirect('recurring.php');
    } else {
        echo "Error: " . $e->getMessage() . "\n";
    }
}
?>

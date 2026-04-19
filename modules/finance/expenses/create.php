<?php
require_once '../../../includes/auth.php';
require_once '../../../config/database.php';
require_once '../../../includes/functions.php';

if (!hasPermission('finance.expenses.manage')) {
    setAlert('danger', 'Access denied.');
    redirect('index.php');
}

$expense = null;
$is_edit = false;

if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM expenses WHERE id = ?");
    $stmt->execute([$_GET['edit']]);
    $expense = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($expense) {
        $is_edit = true;
    }
}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();

        $name = $_POST['name'];
        $category_id = $_POST['category_id'];
        $amount = (float)$_POST['amount'];
        $expense_date = $_POST['expense_date'];
        $vendor_id = !empty($_POST['vendor_id']) ? $_POST['vendor_id'] : null;
        $po_id = !empty($_POST['po_id']) ? $_POST['po_id'] : null;
        $notes = $_POST['notes'] ?? '';
        $is_recurring = isset($_POST['is_recurring']) ? 1 : 0;
        $recurrence_interval = $_POST['recurrence_interval'] ?? null;
        
        $status = 'pending';
        $paid_amount = 0;

        if ($is_edit) {
            $stmt = $pdo->prepare("UPDATE expenses SET category_id = ?, vendor_id = ?, po_id = ?, name = ?, amount = ?, expense_date = ?, notes = ?, is_recurring = ?, recurrence_interval = ? WHERE id = ?");
            $stmt->execute([$category_id, $vendor_id, $po_id, $name, $amount, $expense_date, $notes, $is_recurring, $recurrence_interval, $expense['id']]);
            $expense_id = $expense['id'];
        } else {
            $stmt = $pdo->prepare("INSERT INTO expenses (category_id, vendor_id, po_id, name, amount, expense_date, notes, status, created_by, is_recurring, recurrence_interval) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$category_id, $vendor_id, $po_id, $name, $amount, $expense_date, $notes, $status, $_SESSION['user_id'], $is_recurring, $recurrence_interval]);
            $expense_id = $pdo->lastInsertId();
        }

        // Handle Immediate Payment
        if (!$is_edit && isset($_POST['pay_now']) && $_POST['pay_now'] == '1') {
            $pay_amount = (float)$_POST['pay_amount'];
            $payment_method = $_POST['payment_method'];
            $safe_id = !empty($_POST['safe_id']) ? $_POST['safe_id'] : null;
            $bank_account_id = !empty($_POST['bank_account_id']) ? $_POST['bank_account_id'] : null;
            $reference = $_POST['payment_reference'] ?? '';

            if ($pay_amount > 0) {
                // Insert payment
                $stmt = $pdo->prepare("INSERT INTO expense_payments (expense_id, amount, payment_method, safe_id, bank_account_id, reference, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$expense_id, $pay_amount, $payment_method, $safe_id, $bank_account_id, $reference, $_SESSION['user_id']]);

                // Update expense status/paid_amount
                $new_status = ($pay_amount >= $amount) ? 'paid' : 'partially-paid';
                $stmt = $pdo->prepare("UPDATE expenses SET paid_amount = ?, status = ? WHERE id = ?");
                $stmt->execute([$pay_amount, $new_status, $expense_id]);

                // Update Safe/Bank Balance
                if ($payment_method == 'cash' && $safe_id) {
                    $stmt = $pdo->prepare("UPDATE safes SET balance = balance - ? WHERE id = ?");
                    $stmt->execute([$pay_amount, $safe_id]);
                } elseif ($payment_method == 'transfer' && $bank_account_id) {
                    $stmt = $pdo->prepare("UPDATE bank_accounts SET balance = balance - ? WHERE id = ?");
                    $stmt->execute([$pay_amount, $bank_account_id]);
                }
                
                // If PO is linked, update PO paid amount as well
                if ($po_id) {
                    $stmt = $pdo->prepare("UPDATE purchase_orders SET paid_amount = paid_amount + ? WHERE id = ?");
                    $stmt->execute([$pay_amount, $po_id]);
                    
                    // Add PO payment record
                    $stmt = $pdo->prepare("INSERT INTO purchase_order_payments (purchase_order_id, amount, payment_method, reference, notes, safe_id, bank_account_id, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$po_id, $pay_amount, $payment_method, $reference, 'Payment via expense: ' . $name, $safe_id, $bank_account_id, $_SESSION['user_id']]);
                }

                // Update Vendor Wallet if applicable
                if ($vendor_id && $payment_method == 'wallet') {
                     // Check wallet balance
                     $vStmt = $pdo->prepare("SELECT wallet_balance FROM vendors WHERE id = ?");
                     $vStmt->execute([$vendor_id]);
                     $v_balance = $vStmt->fetchColumn();
                     if ($v_balance < $pay_amount) {
                         throw new Exception("Insufficient vendor wallet balance.");
                     }
                     
                     $stmt = $pdo->prepare("UPDATE vendors SET wallet_balance = wallet_balance - ? WHERE id = ?");
                     $stmt->execute([$pay_amount, $vendor_id]);

                     // Record Wallet Transaction History
                     $stmt = $pdo->prepare("INSERT INTO vendor_wallet_transactions (vendor_id, amount, type, notes, created_by) VALUES (?, ?, 'payment', ?, ?)");
                     $stmt->execute([$vendor_id, $pay_amount, 'Payment for expense: ' . $name, $_SESSION['user_id']]);
                }
            }
        }

        $pdo->commit();
        setAlert('success', $is_edit ? 'Expense updated.' : 'Expense recorded.');
        redirect('index.php');
    } catch (Exception $e) {
        $pdo->rollBack();
        setAlert('danger', 'Error: ' . $e->getMessage());
    }
}

// Fetch Data for form
$categories = $pdo->query("SELECT * FROM expense_categories ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$vendors = $pdo->query("SELECT id, name FROM vendors ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$safes = $pdo->query("SELECT id, name FROM safes ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$banks = $pdo->query("SELECT id, bank_name, account_number FROM bank_accounts ORDER BY bank_name ASC")->fetchAll(PDO::FETCH_ASSOC);

$page_title = ($is_edit ? 'Edit' : 'Record') . ' Expense';
require_once '../../../includes/header.php';
?>

<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1><?= $page_title ?></h1>
        <a href="index.php" class="btn btn-outline-secondary">Back to List</a>
    </div>

    <?php include '../../../includes/messages.php'; ?>

    <form method="post" id="expenseForm">
        <div class="row">
            <div class="col-md-8">
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-light fw-bold">Basic Information</div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-12">
                                <label class="form-label">Expense Name *</label>
                                <input type="text" name="name" class="form-control" value="<?= $expense['name'] ?? '' ?>" required placeholder="e.g. Monthly Rent March 2026">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Category *</label>
                                <select name="category_id" class="form-select" required>
                                    <option value="">Select Category</option>
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?= $cat['id'] ?>" <?= ($expense['category_id'] ?? '') == $cat['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($cat['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Amount *</label>
                                <div class="input-group">
                                    <input type="number" name="amount" class="form-control" step="0.01" value="<?= $expense['amount'] ?? '' ?>" required>
                                    <span class="input-group-text">EGP</span>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Date *</label>
                                <input type="date" name="expense_date" class="form-control" value="<?= $expense['expense_date'] ?? date('Y-m-d') ?>" required>
                            </div>
                            <div class="col-md-12">
                                <label class="form-label">Notes</label>
                                <textarea name="notes" class="form-control" rows="3"><?= $expense['notes'] ?? '' ?></textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-light fw-bold">Linking (Optional)</div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Vendor</label>
                                <select name="vendor_id" id="vendor_id" class="form-select">
                                    <option value="">No Vendor</option>
                                    <?php foreach ($vendors as $v): ?>
                                        <option value="<?= $v['id'] ?>" <?= ($expense['vendor_id'] ?? '') == $v['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($v['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Purchase Order</label>
                                <select name="po_id" id="po_id" class="form-select" <?= ($expense['vendor_id'] ?? '') ? '' : 'disabled' ?>>
                                    <option value="">No PO</option>
                                    <!-- Populated via AJAX -->
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <?php if (!$is_edit): ?>
                <div class="card shadow-sm mb-4 border-primary">
                    <div class="card-header bg-primary text-white fw-bold">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="pay_now" value="1" id="pay_now">
                            <label class="form-check-label" for="pay_now">Pay Now</label>
                        </div>
                    </div>
                    <div class="card-body" id="payment_section" style="display:none;">
                        <div class="mb-3">
                            <label class="form-label">Payment Amount</label>
                            <input type="number" name="pay_amount" id="pay_amount" class="form-control" step="0.01">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Method</label>
                            <select name="payment_method" id="payment_method" class="form-select">
                                <option value="cash">Cash</option>
                                <option value="transfer">Bank Transfer</option>
                                <option value="wallet">Vendor Wallet</option>
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
                        <div class="mb-3">
                            <label class="form-label">Reference</label>
                            <input type="text" name="payment_reference" class="form-control" placeholder="Check #, Transaction ID">
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-light fw-bold">Recurrence</div>
                    <div class="card-body">
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" name="is_recurring" value="1" id="is_recurring" <?= ($expense['is_recurring'] ?? 0) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="is_recurring">Recurring Expense</label>
                        </div>
                        <div id="recurrence_section" style="<?= ($expense['is_recurring'] ?? 0) ? '' : 'display:none;' ?>">
                            <label class="form-label">Interval</label>
                            <select name="recurrence_interval" class="form-select">
                                <option value="monthly" <?= ($expense['recurrence_interval'] ?? '') == 'monthly' ? 'selected' : '' ?>>Monthly</option>
                                <option value="quarterly" <?= ($expense['recurrence_interval'] ?? '') == 'quarterly' ? 'selected' : '' ?>>Quarterly</option>
                                <option value="yearly" <?= ($expense['recurrence_interval'] ?? '') == 'yearly' ? 'selected' : '' ?>>Yearly</option>
                            </select>
                        </div>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary btn-lg w-100 shadow">
                    <i class="fas fa-save me-2"></i> <?= $is_edit ? 'Update' : 'Save' ?> Expense
                </button>
            </div>
        </div>
    </form>
</div>

<script>
$(document).ready(function() {
    // Pay Now Toggle
    $('#pay_now').change(function() {
        if ($(this).is(':checked')) {
            $('#payment_section').slideDown();
            $('#pay_amount').val($('input[name="amount"]').val());
        } else {
            $('#payment_section').slideUp();
        }
    });

    // Update Pay Amount if Total changes
    $('input[name="amount"]').on('input', function() {
        if ($('#pay_now').is(':checked')) {
            $('#pay_amount').val($(this).val());
        }
    });

    // Payment Method Toggle
    $('#payment_method').change(function() {
        const method = $(this).val();
        $('#safe_select, #bank_select').hide();
        if (method === 'cash') $('#safe_select').show();
        else if (method === 'transfer') $('#bank_select').show();
    });

    // Recurrence Toggle
    $('#is_recurring').change(function() {
        if ($(this).is(':checked')) $('#recurrence_section').slideDown();
        else $('#recurrence_section').slideUp();
    });

    // Vendor PO AJAX
    $('#vendor_id').change(function() {
        const vendorId = $(this).val();
        const poSelect = $('#po_id');
        
        if (vendorId) {
            poSelect.prop('disabled', false).html('<option value="">Loading POs...</option>');
            $.getJSON('../../../ajax/get_vendor_pos.php?vendor_id=' + vendorId, function(data) {
                if (data.success) {
                    let options = '<option value="">No PO</option>';
                    data.pos.forEach(po => {
                        options += `<option value="${po.id}">PO #${po.id} (${po.order_date}) - ${po.total_amount} EGP [${po.status}]</option>`;
                    });
                    poSelect.html(options);
                    
                    // If editing, try to select the current PO
                    <?php if ($is_edit && $expense['po_id']): ?>
                    poSelect.val(<?= $expense['po_id'] ?>);
                    <?php endif; ?>
                }
            });
        } else {
            poSelect.prop('disabled', true).html('<option value="">No Vendor Selected</option>');
        }
    });

    // Trigger vendor change on load if editing
    <?php if ($is_edit && $expense['vendor_id']): ?>
    $('#vendor_id').trigger('change');
    <?php endif; ?>
});
</script>

<?php require_once '../../../includes/footer.php'; ?>

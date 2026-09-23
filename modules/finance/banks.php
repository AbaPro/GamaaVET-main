<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once __DIR__ . '/account_deletion.php';
require_once __DIR__ . '/account_balance_adjustments.php';

$canCreate = hasPermission('finance.bank_accounts.create');
$canEdit = hasPermission('finance.bank_accounts.edit');
$canDelete = hasPermission('finance.bank_accounts.delete');
$canSetBalance = canSettleFinanceBalances() || hasPermission('finance.bank_accounts.balance.edit');
$canView = $canCreate || $canEdit || $canDelete || $canSetBalance
    || hasPermission('finance.transfers.create') || hasPermission('finance.transfers.approve');

if (!$canView) {
    setAlert('danger', 'Access denied.');
    redirect('../../dashboard.php');
}

$page_title = 'Bank Accounts';
$formToken = financeAccountFormToken();
handleFinanceAccountDeletion('bank', $canDelete, 'banks.php');

$currentAccountId = getCurrentAccountId();
$accountStmt = $conn->prepare('SELECT id, name FROM accounts WHERE id = ? AND is_active = 1 LIMIT 1');
$accountStmt->bind_param('i', $currentAccountId);
$accountStmt->execute();
$accounts = $accountStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$accountStmt->close();
$allowedAccountIds = array_fill_keys(array_map('intval', array_column($accounts, 'id')), true);
$currencies = financeAccountCurrencies();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_bank_account'])) {
    if (!$canCreate) {
        setAlert('danger', 'Access denied.');
        redirect('banks.php');
    }
    validateFinanceAccountFormToken('banks.php');

    $bankName = trim(strip_tags((string)($_POST['bank_name'] ?? '')));
    $accountNumber = trim(strip_tags((string)($_POST['account_number'] ?? '')));
    $currency = strtoupper(trim((string)($_POST['currency'] ?? 'EGP')));
    $accountId = (int)($_POST['account_id'] ?? 0);
    $accountHolder = trim(strip_tags((string)($_POST['account_holder'] ?? '')));
    $branchName = trim(strip_tags((string)($_POST['branch_name'] ?? '')));
    $iban = trim(strip_tags((string)($_POST['iban'] ?? '')));
    $notes = trim(strip_tags((string)($_POST['notes'] ?? '')));

    if ($bankName === '' || $accountNumber === '') {
        setAlert('danger', 'Bank name and account number are required.');
    } elseif (!$accountId || !isset($allowedAccountIds[$accountId])) {
        setAlert('danger', 'Please select an available brand.');
    } elseif (!isset($currencies[$currency])) {
        setAlert('danger', 'Please select an available currency.');
    } else {
        $stmt = $conn->prepare("
            INSERT INTO bank_accounts
                (bank_name, account_number, currency, account_holder, branch_name, iban, balance, notes, account_id)
            VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?)
        ");
        $stmt->bind_param('sssssssi', $bankName, $accountNumber, $currency, $accountHolder, $branchName, $iban, $notes, $accountId);
        $stmt->execute();
        $newId = $stmt->insert_id;
        $stmt->close();
        logActivity('Created bank account', ['bank_account_id' => $newId, 'bank_name' => $bankName, 'currency' => $currency], 'create', 'bank_account', $newId);
        setAlert('success', 'Bank account added.');
    }
    redirect('banks.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_bank_account'])) {
    if (!$canEdit) {
        setAlert('danger', 'You do not have permission to edit bank accounts.');
        redirect('banks.php');
    }
    validateFinanceAccountFormToken('banks.php');

    $bankId = filter_var($_POST['edit_bank_account'], FILTER_VALIDATE_INT);
    $bankName = trim(strip_tags((string)($_POST['bank_name'] ?? '')));
    $accountNumber = trim(strip_tags((string)($_POST['account_number'] ?? '')));
    $currency = strtoupper(trim((string)($_POST['currency'] ?? 'EGP')));
    $accountId = (int)($_POST['account_id'] ?? 0);
    $accountHolder = trim(strip_tags((string)($_POST['account_holder'] ?? '')));
    $branchName = trim(strip_tags((string)($_POST['branch_name'] ?? '')));
    $iban = trim(strip_tags((string)($_POST['iban'] ?? '')));
    $notes = trim(strip_tags((string)($_POST['notes'] ?? '')));

    if (!$bankId || !isBankAccountInCurrentAccount($bankId) || $bankName === '' || $accountNumber === '') {
        setAlert('danger', 'Bank name and account number are required.');
    } elseif (!$accountId || !isset($allowedAccountIds[$accountId])) {
        setAlert('danger', 'Please select an available brand.');
    } elseif (!isset($currencies[$currency])) {
        setAlert('danger', 'Please select an available currency.');
    } else {
        $scope = getAccountScopeSql();
        $existingStmt = $conn->prepare("SELECT balance, currency FROM bank_accounts WHERE id = ? AND $scope LIMIT 1");
        $existingStmt->bind_param('i', $bankId);
        $existingStmt->execute();
        $existing = $existingStmt->get_result()->fetch_assoc();
        $existingStmt->close();

        if (!$existing) {
            setAlert('danger', 'Bank account not found.');
        } elseif ($existing['currency'] !== $currency
            && (abs((float)$existing['balance']) >= 0.005 || financeAccountHasFinancialHistory('bank', $bankId))) {
            setAlert('danger', 'Currency can only be changed before the bank account has a balance or financial history.');
        } else {
            $stmt = $conn->prepare("
                UPDATE bank_accounts
                SET bank_name = ?, account_number = ?, currency = ?, account_holder = ?,
                    branch_name = ?, iban = ?, notes = ?, account_id = ?
                WHERE id = ?
            ");
            $stmt->bind_param('sssssssii', $bankName, $accountNumber, $currency, $accountHolder, $branchName, $iban, $notes, $accountId, $bankId);
            $stmt->execute();
            $stmt->close();
            logActivity('Edited bank account', ['bank_account_id' => $bankId, 'bank_name' => $bankName, 'currency' => $currency], 'update', 'bank_account', $bankId);
            setAlert('success', 'Bank account updated.');
        }
    }
    redirect('banks.php');
}

$bankScope = getAccountScopeSql('b');
$bankRows = $conn->query("
    SELECT b.*, a.name AS account_name
    FROM bank_accounts b
    LEFT JOIN accounts a ON a.id = b.account_id
    WHERE $bankScope
    ORDER BY b.bank_name, b.account_number
")->fetch_all(MYSQLI_ASSOC);

$summaries = [];
foreach ($bankRows as $row) {
    $currency = $row['currency'] ?: 'EGP';
    if (!isset($summaries[$currency])) {
        $summaries[$currency] = ['balance' => 0.0, 'accounts' => 0, 'funded' => 0, 'brands' => []];
    }
    $summaries[$currency]['balance'] += (float)$row['balance'];
    $summaries[$currency]['accounts']++;
    if (abs((float)$row['balance']) >= 0.005) {
        $summaries[$currency]['funded']++;
    }
    $summaries[$currency]['brands'][$row['account_name'] ?: 'GammaVet'] = true;
}
ksort($summaries);

require_once '../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div><h2 class="mb-1">Bank Accounts</h2><div class="text-muted">Balances are kept separate by currency.</div></div>
    <?php if ($canCreate): ?><button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addBankModal"><i class="fas fa-plus me-1"></i>Add Bank</button><?php endif; ?>
</div>

<?php if ($summaries): ?>
<div class="row mb-3">
    <?php foreach ($summaries as $currency => $summary): ?>
    <div class="col-xl-3 col-md-6 mb-3"><div class="card border-0 shadow-sm h-100"><div class="card-body">
        <div class="d-flex justify-content-between align-items-start">
            <div><div class="small text-uppercase text-muted fw-bold">Total <?= e($currency); ?> Balance</div><div class="h3 mb-2 <?= $summary['balance'] < 0 ? 'text-danger' : 'text-success'; ?>"><?= e(formatCurrency($summary['balance'], $currency)); ?></div></div>
            <span class="badge bg-light text-dark border"><?= e($currency); ?></span>
        </div>
        <div class="small text-muted"><?= number_format($summary['accounts']); ?> account<?= $summary['accounts'] === 1 ? '' : 's'; ?> · <?= number_format($summary['funded']); ?> with balance · <?= number_format(count($summary['brands'])); ?> brand<?= count($summary['brands']) === 1 ? '' : 's'; ?></div>
    </div></div></div>
    <?php endforeach; ?>
</div>
<?php else: ?><div class="alert alert-info">No bank accounts have been created yet.</div><?php endif; ?>

<div class="card border-0 shadow-sm"><div class="card-body"><div class="table-responsive">
    <table class="table js-datatable table-hover align-middle mb-0">
        <thead><tr><th>ID</th><th>Bank</th><th>Account # / IBAN</th><th>Currency</th><th>Brand</th><th class="text-end">Balance</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($bankRows as $row): ?>
            <tr>
                <td><?= (int)$row['id']; ?></td>
                <td><a href="bank_details.php?id=<?= (int)$row['id']; ?>" class="fw-semibold text-decoration-none"><i class="fas fa-university me-1"></i><?= e($row['bank_name']); ?></a><?php if ($row['account_holder'] || $row['branch_name']): ?><div class="small text-muted"><?= e(implode(' · ', array_filter([$row['account_holder'], $row['branch_name']]))); ?></div><?php endif; ?></td>
                <td><?= e($row['account_number']); ?><?php if ($row['iban']): ?><div class="small text-muted">IBAN: <?= e($row['iban']); ?></div><?php endif; ?></td>
                <td><span class="badge bg-light text-dark border"><?= e($row['currency'] ?: 'EGP'); ?></span></td>
                <td><?= e($row['account_name'] ?: 'GammaVet'); ?></td>
                <td class="text-end fw-semibold <?= (float)$row['balance'] < 0 ? 'text-danger' : 'text-success'; ?>"><?= e(formatCurrency((float)$row['balance'], $row['currency'] ?: 'EGP')); ?></td>
                <td class="text-nowrap">
                    <a href="bank_details.php?id=<?= (int)$row['id']; ?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-history me-1"></i>History</a>
                    <?php if ($canEdit): ?><button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editBankModal<?= (int)$row['id']; ?>"><i class="fas fa-pen me-1"></i>Edit</button><?php endif; ?>
                    <?php if ($canSetBalance): ?><a href="bank_details.php?id=<?= (int)$row['id']; ?>#set-balance" class="btn btn-sm btn-outline-warning"><i class="fas fa-scale-balanced me-1"></i>Set Balance</a><?php endif; ?>
                    <?php if ($canDelete) renderFinanceAccountDeleteButton($row); ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div></div></div>

<?php if ($canCreate): ?>
<div class="modal fade" id="addBankModal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content"><form method="post">
    <input type="hidden" name="csrf_token" value="<?= e($formToken); ?>"><input type="hidden" name="create_bank_account" value="1">
    <div class="modal-header"><h5 class="modal-title">Add Bank Account</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body"><div class="row">
        <div class="col-md-6 mb-3"><label class="form-label">Bank Name*</label><input type="text" class="form-control" name="bank_name" maxlength="100" required></div>
        <div class="col-md-6 mb-3"><label class="form-label">Account Number*</label><input type="text" class="form-control" name="account_number" maxlength="100" required></div>
        <div class="col-md-6 mb-3"><label class="form-label">Currency*</label><select class="form-select" name="currency" required><?php foreach ($currencies as $currency): ?><option value="<?= e($currency['code']); ?>"><?= e($currency['code'] . ' — ' . $currency['name']); ?></option><?php endforeach; ?></select></div>
        <div class="col-md-6 mb-3"><label class="form-label">Brand*</label><select class="form-select" name="account_id" required><?php foreach ($accounts as $account): ?><option value="<?= (int)$account['id']; ?>"><?= e($account['name']); ?></option><?php endforeach; ?></select></div>
        <div class="col-md-6 mb-3"><label class="form-label">Account Holder</label><input type="text" class="form-control" name="account_holder" maxlength="150"></div>
        <div class="col-md-6 mb-3"><label class="form-label">Branch</label><input type="text" class="form-control" name="branch_name" maxlength="150"></div>
        <div class="col-12 mb-3"><label class="form-label">IBAN</label><input type="text" class="form-control" name="iban" maxlength="100"></div>
        <div class="col-12"><label class="form-label">Notes</label><textarea class="form-control" name="notes" rows="2"></textarea></div>
    </div></div>
    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button><button type="submit" class="btn btn-primary">Save</button></div>
</form></div></div></div>
<?php endif; ?>

<?php if ($canEdit): foreach ($bankRows as $row): ?>
<div class="modal fade" id="editBankModal<?= (int)$row['id']; ?>" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content"><form method="post">
    <input type="hidden" name="csrf_token" value="<?= e($formToken); ?>"><input type="hidden" name="edit_bank_account" value="<?= (int)$row['id']; ?>">
    <div class="modal-header"><h5 class="modal-title">Edit <?= e($row['bank_name']); ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body"><div class="row">
        <div class="col-md-6 mb-3"><label class="form-label">Bank Name*</label><input type="text" class="form-control" name="bank_name" value="<?= e($row['bank_name']); ?>" maxlength="100" required></div>
        <div class="col-md-6 mb-3"><label class="form-label">Account Number*</label><input type="text" class="form-control" name="account_number" value="<?= e($row['account_number']); ?>" maxlength="100" required></div>
        <div class="col-md-6 mb-3"><label class="form-label">Currency*</label><select class="form-select" name="currency" required><?php foreach ($currencies as $currency): ?><option value="<?= e($currency['code']); ?>" <?= $currency['code'] === ($row['currency'] ?: 'EGP') ? 'selected' : ''; ?>><?= e($currency['code'] . ' — ' . $currency['name']); ?></option><?php endforeach; ?></select><div class="form-text">Currency can change only before any financial activity.</div></div>
        <div class="col-md-6 mb-3"><label class="form-label">Brand*</label><select class="form-select" name="account_id" required><?php foreach ($accounts as $account): ?><option value="<?= (int)$account['id']; ?>" <?= (int)$account['id'] === (int)$row['account_id'] ? 'selected' : ''; ?>><?= e($account['name']); ?></option><?php endforeach; ?></select></div>
        <div class="col-md-6 mb-3"><label class="form-label">Account Holder</label><input type="text" class="form-control" name="account_holder" value="<?= e($row['account_holder']); ?>" maxlength="150"></div>
        <div class="col-md-6 mb-3"><label class="form-label">Branch</label><input type="text" class="form-control" name="branch_name" value="<?= e($row['branch_name']); ?>" maxlength="150"></div>
        <div class="col-12 mb-3"><label class="form-label">IBAN</label><input type="text" class="form-control" name="iban" value="<?= e($row['iban']); ?>" maxlength="100"></div>
        <div class="col-12"><label class="form-label">Notes</label><textarea class="form-control" name="notes" rows="2"><?= e($row['notes']); ?></textarea></div>
    </div></div>
    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button><button type="submit" class="btn btn-primary">Save Changes</button></div>
</form></div></div></div>
<?php endforeach; endif; ?>

<?php require_once '../../includes/footer.php'; ?>

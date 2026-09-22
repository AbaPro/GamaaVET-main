<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once __DIR__ . '/account_deletion.php';
require_once __DIR__ . '/account_balance_adjustments.php';

$canCreate = hasPermission('finance.safes.create');
$canEdit = hasPermission('finance.safes.edit');
$canDelete = hasPermission('finance.safes.delete');
$canSetBalance = canSettleFinanceBalances() || hasPermission('finance.safes.balance.edit');
$canView = $canCreate || $canEdit || $canDelete || $canSetBalance
    || hasPermission('finance.transfers.create') || hasPermission('finance.transfers.approve');

if (!$canView) {
    setAlert('danger', 'Access denied.');
    redirect('../../dashboard.php');
}

$page_title = 'Safes';
$formToken = financeAccountFormToken();
handleFinanceAccountDeletion('safe', $canDelete, 'safes.php');

$currentAccountId = getCurrentAccountId();
$accountStmt = $conn->prepare('SELECT id, name FROM accounts WHERE id = ? AND is_active = 1 LIMIT 1');
$accountStmt->bind_param('i', $currentAccountId);
$accountStmt->execute();
$accounts = $accountStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$accountStmt->close();
$allowedAccountIds = array_fill_keys(array_map('intval', array_column($accounts, 'id')), true);
$showLocations = ($_SESSION['login_region'] ?? 'factory') === 'factory';
$locations = $showLocations
    ? $conn->query('SELECT id, name, address, is_active FROM locations ORDER BY is_active DESC, name')->fetch_all(MYSQLI_ASSOC)
    : [];
$allowedLocationIds = array_fill_keys(array_map('intval', array_column($locations, 'id')), true);
$currencies = financeAccountCurrencies();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_safe'])) {
    if (!$canCreate) {
        setAlert('danger', 'Access denied.');
        redirect('safes.php');
    }
    validateFinanceAccountFormToken('safes.php');

    $name = trim(strip_tags((string)($_POST['name'] ?? '')));
    $currency = strtoupper(trim((string)($_POST['currency'] ?? 'EGP')));
    $accountId = (int)($_POST['account_id'] ?? 0);
    $locationId = $showLocations && !empty($_POST['location_id']) ? (int)$_POST['location_id'] : null;
    $notes = trim(strip_tags((string)($_POST['notes'] ?? '')));

    if ($name === '') {
        setAlert('danger', 'Safe name is required.');
    } elseif (!$accountId || !isset($allowedAccountIds[$accountId])) {
        setAlert('danger', 'Please select an available brand.');
    } elseif ($locationId && !isset($allowedLocationIds[$locationId])) {
        setAlert('danger', 'Please select an available location.');
    } elseif (!isset($currencies[$currency])) {
        setAlert('danger', 'Please select an available currency.');
    } else {
        $stmt = $conn->prepare('INSERT INTO safes (name, currency, balance, notes, account_id, location_id) VALUES (?, ?, 0, ?, ?, ?)');
        $stmt->bind_param('sssii', $name, $currency, $notes, $accountId, $locationId);
        $stmt->execute();
        $newId = $stmt->insert_id;
        $stmt->close();
        logActivity('Created safe', ['safe_id' => $newId, 'name' => $name, 'currency' => $currency, 'location_id' => $locationId]);
        setAlert('success', 'Safe added.');
    }
    redirect('safes.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_safe'])) {
    if (!$canEdit) {
        setAlert('danger', 'You do not have permission to edit safes.');
        redirect('safes.php');
    }
    validateFinanceAccountFormToken('safes.php');

    $safeId = filter_var($_POST['edit_safe'], FILTER_VALIDATE_INT);
    $name = trim(strip_tags((string)($_POST['name'] ?? '')));
    $currency = strtoupper(trim((string)($_POST['currency'] ?? 'EGP')));
    $accountId = (int)($_POST['account_id'] ?? 0);
    $locationId = $showLocations && !empty($_POST['location_id']) ? (int)$_POST['location_id'] : null;
    $notes = trim(strip_tags((string)($_POST['notes'] ?? '')));

    if (!$safeId || !isSafeInCurrentAccount($safeId) || $name === '') {
        setAlert('danger', 'Safe name is required.');
    } elseif (!$accountId || !isset($allowedAccountIds[$accountId])) {
        setAlert('danger', 'Please select an available brand.');
    } elseif ($locationId && !isset($allowedLocationIds[$locationId])) {
        setAlert('danger', 'Please select an available location.');
    } elseif (!isset($currencies[$currency])) {
        setAlert('danger', 'Please select an available currency.');
    } else {
        $scope = getAccountScopeSql();
        $existingStmt = $conn->prepare("SELECT balance, currency FROM safes WHERE id = ? AND $scope LIMIT 1");
        $existingStmt->bind_param('i', $safeId);
        $existingStmt->execute();
        $existing = $existingStmt->get_result()->fetch_assoc();
        $existingStmt->close();

        if (!$existing) {
            setAlert('danger', 'Safe not found.');
        } elseif ($existing['currency'] !== $currency
            && (abs((float)$existing['balance']) >= 0.005 || financeAccountHasFinancialHistory('safe', $safeId))) {
            setAlert('danger', 'Currency can only be changed before the safe has a balance or financial history.');
        } else {
            $stmt = $conn->prepare('UPDATE safes SET name = ?, currency = ?, notes = ?, account_id = ?, location_id = ? WHERE id = ?');
            $stmt->bind_param('sssiii', $name, $currency, $notes, $accountId, $locationId, $safeId);
            $stmt->execute();
            $stmt->close();
            logActivity('Edited safe', ['safe_id' => $safeId, 'name' => $name, 'currency' => $currency, 'location_id' => $locationId]);
            setAlert('success', 'Safe updated.');
        }
    }
    redirect('safes.php');
}

$selectedLocation = $showLocations && isset($_GET['location_id']) && $_GET['location_id'] !== '' ? (int)$_GET['location_id'] : 0;
if ($selectedLocation && !isset($allowedLocationIds[$selectedLocation])) {
    $selectedLocation = 0;
}

$safeScope = getAccountScopeSql('s');
$safeSql = "
    SELECT s.*, a.name AS account_name, l.name AS location_name, l.address AS location_address
    FROM safes s
    LEFT JOIN accounts a ON a.id = s.account_id
    LEFT JOIN locations l ON l.id = s.location_id
    WHERE $safeScope
";
if ($selectedLocation) {
    $safeSql .= ' AND s.location_id = ?';
}
$safeSql .= ' ORDER BY l.name, s.name';

if ($selectedLocation) {
    $safeStmt = $conn->prepare($safeSql);
    $safeStmt->bind_param('i', $selectedLocation);
    $safeStmt->execute();
    $safeRows = $safeStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $safeStmt->close();
} else {
    $safeRows = $conn->query($safeSql)->fetch_all(MYSQLI_ASSOC);
}

$summaries = [];
foreach ($safeRows as $row) {
    $currency = $row['currency'] ?: 'EGP';
    if (!isset($summaries[$currency])) {
        $summaries[$currency] = ['balance' => 0.0, 'safes' => 0, 'funded' => 0, 'locations' => []];
    }
    $summaries[$currency]['balance'] += (float)$row['balance'];
    $summaries[$currency]['safes']++;
    if (abs((float)$row['balance']) >= 0.005) {
        $summaries[$currency]['funded']++;
    }
    $summaries[$currency]['locations'][$row['location_name'] ?: 'Unassigned'] = true;
}
ksort($summaries);

require_once '../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div><h2 class="mb-1">Safes</h2><div class="text-muted">Cash balances by currency and physical location.</div></div>
    <?php if ($canCreate): ?><button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addSafeModal"><i class="fas fa-plus me-1"></i>Add Safe</button><?php endif; ?>
</div>

<div class="card border-0 shadow-sm mb-4"><div class="card-body py-3">
    <?php if ($showLocations): ?><form method="get" class="row g-2 align-items-end">
        <div class="col-md-5"><label for="locationFilter" class="form-label mb-1">Filter by Location</label><select class="form-select" id="locationFilter" name="location_id"><option value="">All locations</option><?php foreach ($locations as $location): ?><option value="<?= (int)$location['id']; ?>" <?= $selectedLocation === (int)$location['id'] ? 'selected' : ''; ?>><?= e($location['name']); ?><?= !$location['is_active'] ? ' (inactive)' : ''; ?></option><?php endforeach; ?></select></div>
        <div class="col-auto"><button type="submit" class="btn btn-primary"><i class="fas fa-filter me-1"></i>Apply</button></div>
        <?php if ($selectedLocation): ?><div class="col-auto"><a href="safes.php" class="btn btn-outline-secondary">Clear</a></div><?php endif; ?>
    </form><?php else: ?><div class="text-muted">Only <?= e($accounts[0]['name'] ?? 'this brand'); ?> cash accounts are shown.</div><?php endif; ?>
</div></div>

<?php if ($summaries): ?>
<div class="row mb-3">
    <?php foreach ($summaries as $currency => $summary): ?>
    <div class="col-xl-3 col-md-6 mb-3"><div class="card border-0 shadow-sm h-100"><div class="card-body">
        <div class="d-flex justify-content-between align-items-start">
            <div><div class="small text-uppercase text-muted fw-bold">Total <?= e($currency); ?> Cash</div><div class="h3 mb-2 <?= $summary['balance'] < 0 ? 'text-danger' : 'text-success'; ?>"><?= e(formatCurrency($summary['balance'], $currency)); ?></div></div>
            <span class="badge bg-light text-dark border"><?= e($currency); ?></span>
        </div>
        <div class="small text-muted"><?= number_format($summary['safes']); ?> safe<?= $summary['safes'] === 1 ? '' : 's'; ?> · <?= number_format($summary['funded']); ?> with balance · <?= number_format(count($summary['locations'])); ?> location<?= count($summary['locations']) === 1 ? '' : 's'; ?></div>
    </div></div></div>
    <?php endforeach; ?>
</div>
<?php else: ?><div class="alert alert-info">No safes match the selected location.</div><?php endif; ?>

<div class="card border-0 shadow-sm"><div class="card-body"><div class="table-responsive">
    <table class="table js-datatable table-striped table-hover align-middle mb-0">
        <thead><tr><th>ID</th><th>Name</th><?php if ($showLocations): ?><th>Location</th><?php endif; ?><th>Currency</th><th>Brand</th><th class="text-end">Balance</th><th>Actions</th></tr></thead>
        <tbody><?php foreach ($safeRows as $row): ?>
            <tr>
                <td><?= (int)$row['id']; ?></td>
                <td><a href="safe_details.php?id=<?= (int)$row['id']; ?>" class="fw-semibold text-decoration-none"><i class="fas fa-vault me-1"></i><?= e($row['name']); ?></a><?php if ($row['notes']): ?><div class="small text-muted text-truncate" style="max-width:260px"><?= e($row['notes']); ?></div><?php endif; ?></td>
                <?php if ($showLocations): ?><td><?= $row['location_name'] ? e($row['location_name']) : '<span class="text-muted">Unassigned</span>'; ?><?php if ($row['location_address']): ?><div class="small text-muted"><?= e($row['location_address']); ?></div><?php endif; ?></td><?php endif; ?>
                <td><span class="badge bg-light text-dark border"><?= e($row['currency'] ?: 'EGP'); ?></span></td>
                <td><?= e($row['account_name'] ?: 'GammaVet'); ?></td>
                <td class="text-end fw-semibold <?= (float)$row['balance'] < 0 ? 'text-danger' : 'text-success'; ?>"><?= e(formatCurrency((float)$row['balance'], $row['currency'] ?: 'EGP')); ?></td>
                <td class="text-nowrap">
                    <a href="safe_details.php?id=<?= (int)$row['id']; ?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-history me-1"></i>History</a>
                    <?php if ($canEdit): ?><button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editSafeModal<?= (int)$row['id']; ?>"><i class="fas fa-pen me-1"></i>Edit</button><?php endif; ?>
                    <?php if ($canSetBalance): ?><a href="safe_details.php?id=<?= (int)$row['id']; ?>#set-balance" class="btn btn-sm btn-outline-warning"><i class="fas fa-scale-balanced me-1"></i>Set Balance</a><?php endif; ?>
                    <?php if ($canDelete) renderFinanceAccountDeleteButton($row); ?>
                </td>
            </tr>
        <?php endforeach; ?></tbody>
    </table>
</div></div></div>

<?php if ($canCreate): ?>
<div class="modal fade" id="addSafeModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><form method="post">
    <input type="hidden" name="csrf_token" value="<?= e($formToken); ?>"><input type="hidden" name="create_safe" value="1">
    <div class="modal-header"><h5 class="modal-title">Add Safe</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
        <div class="mb-3"><label class="form-label">Safe Name*</label><input type="text" class="form-control" name="name" maxlength="100" required></div>
        <?php if ($showLocations): ?><div class="mb-3"><label class="form-label">Location</label><select class="form-select" name="location_id"><option value="">Unassigned</option><?php foreach ($locations as $location): if (!$location['is_active']) continue; ?><option value="<?= (int)$location['id']; ?>"><?= e($location['name']); ?></option><?php endforeach; ?></select></div><?php endif; ?>
        <div class="mb-3"><label class="form-label">Currency*</label><select class="form-select" name="currency" required><?php foreach ($currencies as $currency): ?><option value="<?= e($currency['code']); ?>"><?= e($currency['code'] . ' — ' . $currency['name']); ?></option><?php endforeach; ?></select></div>
        <div class="mb-3"><label class="form-label">Brand*</label><select class="form-select" name="account_id" required><?php foreach ($accounts as $account): ?><option value="<?= (int)$account['id']; ?>"><?= e($account['name']); ?></option><?php endforeach; ?></select></div>
        <div><label class="form-label">Notes</label><textarea class="form-control" name="notes" rows="2"></textarea></div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button><button type="submit" class="btn btn-primary">Save</button></div>
</form></div></div></div>
<?php endif; ?>

<?php if ($canEdit): foreach ($safeRows as $row): ?>
<div class="modal fade" id="editSafeModal<?= (int)$row['id']; ?>" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><form method="post">
    <input type="hidden" name="csrf_token" value="<?= e($formToken); ?>"><input type="hidden" name="edit_safe" value="<?= (int)$row['id']; ?>">
    <div class="modal-header"><h5 class="modal-title">Edit <?= e($row['name']); ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
        <div class="mb-3"><label class="form-label">Safe Name*</label><input type="text" class="form-control" name="name" value="<?= e($row['name']); ?>" maxlength="100" required></div>
        <?php if ($showLocations): ?><div class="mb-3"><label class="form-label">Location</label><select class="form-select" name="location_id"><option value="">Unassigned</option><?php foreach ($locations as $location): ?><option value="<?= (int)$location['id']; ?>" <?= (int)$row['location_id'] === (int)$location['id'] ? 'selected' : ''; ?>><?= e($location['name']); ?><?= !$location['is_active'] ? ' (inactive)' : ''; ?></option><?php endforeach; ?></select></div><?php endif; ?>
        <div class="mb-3"><label class="form-label">Currency*</label><select class="form-select" name="currency" required><?php foreach ($currencies as $currency): ?><option value="<?= e($currency['code']); ?>" <?= $currency['code'] === ($row['currency'] ?: 'EGP') ? 'selected' : ''; ?>><?= e($currency['code'] . ' — ' . $currency['name']); ?></option><?php endforeach; ?></select><div class="form-text">Currency can change only before any financial activity.</div></div>
        <div class="mb-3"><label class="form-label">Brand*</label><select class="form-select" name="account_id" required><?php foreach ($accounts as $account): ?><option value="<?= (int)$account['id']; ?>" <?= (int)$account['id'] === (int)$row['account_id'] ? 'selected' : ''; ?>><?= e($account['name']); ?></option><?php endforeach; ?></select></div>
        <div><label class="form-label">Notes</label><textarea class="form-control" name="notes" rows="2"><?= e($row['notes']); ?></textarea></div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button><button type="submit" class="btn btn-primary">Save Changes</button></div>
</form></div></div></div>
<?php endforeach; endif; ?>

<?php require_once '../../includes/footer.php'; ?>

<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once __DIR__ . '/ledger_helpers.php';

// Same access as the safe / bank ledgers this report is built from.
$canTransferAccess = hasPermission('finance.transfers.create') || hasPermission('finance.transfers.approve');
$canSettle = canSettleFinanceBalances();
$canViewSafes = $canTransferAccess || $canSettle
    || hasPermission('finance.safes.create') || hasPermission('finance.safes.edit')
    || hasPermission('finance.safes.delete') || hasPermission('finance.safes.force_delete') || hasPermission('finance.safes.balance.edit');
$canViewBanks = $canTransferAccess || $canSettle
    || hasPermission('finance.bank_accounts.create') || hasPermission('finance.bank_accounts.edit')
    || hasPermission('finance.bank_accounts.delete') || hasPermission('finance.bank_accounts.force_delete') || hasPermission('finance.bank_accounts.balance.edit');
if (!$canViewSafes && !$canViewBanks) {
    setAlert('danger', 'Access denied.');
    redirect('../../dashboard.php');
}

$groupBy = ($_GET['group'] ?? 'day') === 'month' ? 'month' : 'day';
$today = new DateTimeImmutable('today');
$dateFrom = normalizeTransactionDate($_GET['from'] ?? '')
    ?: ($groupBy === 'month' ? $today->format('Y-01-01') : $today->modify('first day of this month')->format('Y-m-d'));
$dateTo = normalizeTransactionDate($_GET['to'] ?? '') ?: $today->format('Y-m-d');
if ($dateFrom > $dateTo) {
    [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
}
$selectedAccount = (string)($_GET['account'] ?? '');
$selectedCurrency = strtoupper(preg_replace('/[^A-Za-z]/', '', (string)($_GET['currency'] ?? '')));

$accounts = [];
if ($canViewSafes) {
    $result = $conn->query("SELECT s.id, s.name, s.balance, s.currency, l.name AS location_name
                            FROM safes s LEFT JOIN locations l ON l.id = s.location_id
                            WHERE " . getSafeScopeSql('s') . " ORDER BY s.name");
    while ($result && $row = $result->fetch_assoc()) {
        $accounts['safe-' . $row['id']] = [
            'type' => 'safe', 'id' => (int)$row['id'], 'name' => $row['name'],
            'meta' => $row['location_name'] ?: '', 'balance' => (float)$row['balance'],
            'currency' => $row['currency'] ?: 'EGP', 'url' => 'safe_details.php?id=' . (int)$row['id'],
        ];
    }
}
if ($canViewBanks) {
    $result = $conn->query("SELECT b.id, b.bank_name, b.account_number, b.balance, b.currency
                            FROM bank_accounts b WHERE " . getBankScopeSql('b') . " ORDER BY b.bank_name, b.account_number");
    while ($result && $row = $result->fetch_assoc()) {
        $accounts['bank-' . $row['id']] = [
            'type' => 'bank', 'id' => (int)$row['id'], 'name' => $row['bank_name'],
            'meta' => '#' . $row['account_number'], 'balance' => (float)$row['balance'],
            'currency' => $row['currency'] ?: 'EGP', 'url' => 'bank_details.php?id=' . (int)$row['id'],
        ];
    }
}
$currencies = array_values(array_unique(array_column($accounts, 'currency')));
sort($currencies);

$reportAccounts = array_filter($accounts, static function ($account, $key) use ($selectedAccount, $selectedCurrency) {
    if ($selectedAccount !== '' && $key !== $selectedAccount) return false;
    if ($selectedCurrency !== '' && $account['currency'] !== $selectedCurrency) return false;
    return true;
}, ARRAY_FILTER_USE_BOTH);

// For each account: opening = current balance minus everything dated from the start
// of the range onward; closing = current balance minus everything after the range.
$periods = [];
$accountRows = [];
$currencyTotals = [];
foreach ($reportAccounts as $key => $account) {
    $ledger = financeAccountLedger($account['type'], $account['id'], $account['currency'], $account['balance']);
    $currency = $account['currency'];
    $row = $account + ['opening' => $account['balance'], 'in' => 0.0, 'out' => 0.0, 'closing' => $account['balance'], 'count' => 0];

    foreach ($ledger['transactions'] as $transaction) {
        $date = $transaction['transaction_date'];
        $signed = $transaction['direction'] === 'in' ? $transaction['amount'] : ($transaction['direction'] === 'out' ? -$transaction['amount'] : 0);
        if ($date >= $dateFrom) $row['opening'] -= $signed;
        if ($date > $dateTo) $row['closing'] -= $signed;
        if ($date < $dateFrom || $date > $dateTo || $signed == 0) continue;

        $bucket = $groupBy === 'month' ? substr($date, 0, 7) : $date;
        $periods[$bucket][$currency] = $periods[$bucket][$currency] ?? ['in' => 0.0, 'out' => 0.0, 'count' => 0];
        if ($signed > 0) {
            $row['in'] += $signed;
            $periods[$bucket][$currency]['in'] += $signed;
        } else {
            $row['out'] -= $signed;
            $periods[$bucket][$currency]['out'] -= $signed;
        }
        $row['count']++;
        $periods[$bucket][$currency]['count']++;
    }
    $accountRows[$key] = $row;

    $totals = $currencyTotals[$currency] ?? ['opening' => 0.0, 'in' => 0.0, 'out' => 0.0, 'closing' => 0.0, 'count' => 0];
    foreach (['opening', 'in', 'out', 'closing', 'count'] as $field) {
        $totals[$field] += $row[$field];
    }
    $currencyTotals[$currency] = $totals;
}
ksort($currencyTotals);

// Period rows oldest first so each closing balance carries into the next period.
ksort($periods);
$periodRows = [];
$runningByCurrency = array_map(static fn($totals) => $totals['opening'], $currencyTotals);
foreach ($periods as $bucket => $byCurrency) {
    ksort($byCurrency);
    foreach ($byCurrency as $currency => $values) {
        $runningByCurrency[$currency] += $values['in'] - $values['out'];
        $periodRows[] = ['period' => $bucket, 'currency' => $currency, 'closing' => $runningByCurrency[$currency]] + $values;
    }
}
$periodRows = array_reverse($periodRows);

function treasuryPeriodLabel($bucket, $groupBy) {
    return $groupBy === 'month' ? date('F Y', strtotime($bucket . '-01')) : date('D, M d, Y', strtotime($bucket));
}

$filterQuery = http_build_query(['date_from' => $dateFrom, 'date_to' => $dateTo]);
$page_title = 'Treasury Movements';
require_once '../../includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-4">
    <div>
        <h2 class="mb-1">Treasury Movements</h2>
        <div class="text-muted">Daily or monthly money in / out for safes and bank accounts, with opening and closing balances.</div>
    </div>
    <div class="d-flex gap-2 d-print-none">
        <button type="button" class="btn btn-outline-secondary" onclick="window.print()"><i class="fas fa-print me-1"></i>Print</button>
        <a href="index.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-1"></i>Finance Dashboard</a>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4 d-print-none"><div class="card-body py-3">
    <form method="get" class="row g-2 align-items-end">
        <div class="col-md-2">
            <label class="form-label mb-1">Group by</label>
            <select class="form-select" name="group">
                <option value="day" <?= $groupBy === 'day' ? 'selected' : ''; ?>>Daily</option>
                <option value="month" <?= $groupBy === 'month' ? 'selected' : ''; ?>>Monthly</option>
            </select>
        </div>
        <div class="col-md-2"><label class="form-label mb-1">From</label><input type="date" class="form-control" name="from" value="<?= e($dateFrom); ?>"></div>
        <div class="col-md-2"><label class="form-label mb-1">To</label><input type="date" class="form-control" name="to" value="<?= e($dateTo); ?>"></div>
        <div class="col-md-3">
            <label class="form-label mb-1">Safe / Bank account</label>
            <select class="form-select" name="account">
                <option value="">All safes and bank accounts</option>
                <?php foreach ($accounts as $key => $account): ?>
                    <option value="<?= e($key); ?>" <?= $selectedAccount === $key ? 'selected' : ''; ?>>
                        <?= e(($account['type'] === 'safe' ? 'Safe: ' : 'Bank: ') . $account['name'] . ($account['meta'] ? ' · ' . $account['meta'] : '') . ' (' . $account['currency'] . ')'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-1">
            <label class="form-label mb-1">Currency</label>
            <select class="form-select" name="currency">
                <option value="">All</option>
                <?php foreach ($currencies as $code): ?>
                    <option value="<?= e($code); ?>" <?= $selectedCurrency === $code ? 'selected' : ''; ?>><?= e($code); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-auto"><button type="submit" class="btn btn-primary"><i class="fas fa-filter me-1"></i>Apply</button></div>
    </form>
</div></div>

<div class="text-muted small mb-2"><i class="far fa-calendar me-1"></i><?= date('M d, Y', strtotime($dateFrom)); ?> – <?= date('M d, Y', strtotime($dateTo)); ?></div>

<?php if (!$currencyTotals): ?>
    <div class="alert alert-info">No safes or bank accounts match the selected filters.</div>
<?php else: ?>
<div class="row mb-2">
    <?php foreach ($currencyTotals as $currency => $totals): ?>
    <div class="col-lg-6 mb-3"><div class="card border-0 shadow-sm h-100"><div class="card-body">
        <div class="d-flex justify-content-between"><h5 class="mb-3"><?= e($currency); ?></h5><span class="text-muted small"><?= number_format($totals['count']); ?> movement<?= $totals['count'] === 1 ? '' : 's'; ?></span></div>
        <div class="row text-center g-2">
            <div class="col-3"><div class="small text-muted">Opening</div><div class="fw-semibold"><?= e(formatCurrency($totals['opening'], $currency)); ?></div></div>
            <div class="col-3"><div class="small text-muted">Money In</div><div class="fw-semibold text-success"><?= e(formatCurrency($totals['in'], $currency)); ?></div></div>
            <div class="col-3"><div class="small text-muted">Money Out</div><div class="fw-semibold text-danger"><?= e(formatCurrency($totals['out'], $currency)); ?></div></div>
            <div class="col-3"><div class="small text-muted">Closing</div><div class="fw-bold <?= $totals['closing'] < 0 ? 'text-danger' : ''; ?>"><?= e(formatCurrency($totals['closing'], $currency)); ?></div></div>
        </div>
    </div></div></div>
    <?php endforeach; ?>
</div>
<div class="small text-muted mb-4">Totals include transfers between the company's own safes and bank accounts, which appear as money out of one account and money in to the other. Pending transfers are not counted until approved.</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white py-3"><h5 class="mb-0"><i class="fas fa-calendar-days me-2"></i><?= $groupBy === 'month' ? 'Monthly' : 'Daily'; ?> Movements</h5></div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr><th><?= $groupBy === 'month' ? 'Month' : 'Day'; ?></th><th>Currency</th><th class="text-end">Movements</th><th class="text-end">Money In</th><th class="text-end">Money Out</th><th class="text-end">Net</th><th class="text-end">Closing Balance</th></tr>
                </thead>
                <tbody>
                    <?php if (!$periodRows): ?>
                        <tr><td colspan="7" class="text-center text-muted py-4">No movements in this period.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($periodRows as $row): $net = $row['in'] - $row['out']; ?>
                        <tr>
                            <td class="fw-semibold"><?= e(treasuryPeriodLabel($row['period'], $groupBy)); ?></td>
                            <td><span class="badge bg-light text-dark border"><?= e($row['currency']); ?></span></td>
                            <td class="text-end"><?= number_format($row['count']); ?></td>
                            <td class="text-end text-success"><?= e(formatCurrency($row['in'], $row['currency'])); ?></td>
                            <td class="text-end text-danger"><?= e(formatCurrency($row['out'], $row['currency'])); ?></td>
                            <td class="text-end fw-semibold <?= $net < 0 ? 'text-danger' : 'text-success'; ?>"><?= e(formatCurrency($net, $row['currency'])); ?></td>
                            <td class="text-end fw-bold <?= $row['closing'] < 0 ? 'text-danger' : ''; ?>"><?= e(formatCurrency($row['closing'], $row['currency'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3"><h5 class="mb-0"><i class="fas fa-vault me-2"></i>By Safe / Bank Account</h5></div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr><th>Account</th><th>Currency</th><th class="text-end">Opening</th><th class="text-end">Money In</th><th class="text-end">Money Out</th><th class="text-end">Closing</th><th class="text-end">Current Balance</th><th class="d-print-none"></th></tr>
                </thead>
                <tbody>
                    <?php foreach ($accountRows as $row): ?>
                        <tr>
                            <td>
                                <span class="badge bg-secondary"><?= $row['type'] === 'safe' ? 'Safe' : 'Bank'; ?></span>
                                <span class="fw-semibold"><?= e($row['name']); ?></span>
                                <?php if ($row['meta']): ?><div class="small text-muted"><?= e($row['meta']); ?></div><?php endif; ?>
                            </td>
                            <td><span class="badge bg-light text-dark border"><?= e($row['currency']); ?></span></td>
                            <td class="text-end"><?= e(formatCurrency($row['opening'], $row['currency'])); ?></td>
                            <td class="text-end text-success"><?= e(formatCurrency($row['in'], $row['currency'])); ?></td>
                            <td class="text-end text-danger"><?= e(formatCurrency($row['out'], $row['currency'])); ?></td>
                            <td class="text-end fw-bold <?= $row['closing'] < 0 ? 'text-danger' : ''; ?>"><?= e(formatCurrency($row['closing'], $row['currency'])); ?></td>
                            <td class="text-end text-muted"><?= e(formatCurrency($row['balance'], $row['currency'])); ?></td>
                            <td class="d-print-none"><a href="<?= e($row['url'] . '&' . $filterQuery); ?>" class="btn btn-sm btn-outline-info">Statement</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once '../../includes/footer.php'; ?>

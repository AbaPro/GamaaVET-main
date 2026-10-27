<?php
require_once '../../includes/auth.php';

$canManageRates = hasPermission('settings.currencies.manage');
if (!$canManageRates && !hasPermission('settings.currencies.view')) {
    http_response_code(403);
    exit('Access denied.');
}
if (empty($_SESSION['currency_settings_token'])) {
    $_SESSION['currency_settings_token'] = bin2hex(random_bytes(32));
}
$currencies = getCurrencyRates();
$error = null;
$conversion = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!is_string($_POST['csrf_token'] ?? null)
        || !hash_equals($_SESSION['currency_settings_token'], $_POST['csrf_token'])) {
        http_response_code(403);
        exit('Invalid form token. Reload the page and try again.');
    }
    if (($_POST['action'] ?? '') === 'save_rates') {
        if (!$canManageRates) {
            http_response_code(403);
            exit('You do not have permission to manage currency rates.');
        }
        try {
            $rates = $_POST['rates'] ?? [];
            if (!is_array($rates)) throw new InvalidArgumentException('Invalid rates.');
            $validated = [];
            foreach ($currencies as $code => $currency) {
                if ($code === 'EGP') continue;
                $value = $rates[$code] ?? '';
                if (!is_string($value)) throw new InvalidArgumentException('Invalid rate for ' . $code . '.');
                $value = trim($value);
                // Blank leaves an unconfigured currency unavailable for conversion.
                if ($value === '' && $currency['rate_to_egp'] === null) continue;
                if (!preg_match('/^\d{1,6}(?:\.\d{1,4})?$/D', $value) || (float)$value <= 0) {
                    throw new InvalidArgumentException('Enter a positive rate for ' . $code . ' (up to 4 decimal places).');
                }
                $validated[$code] = $value;
            }
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('UPDATE currencies SET rate_to_egp = ?, rate_updated_at = NOW() WHERE code = ?');
            $changes = [];
            foreach ($validated as $code => $rate) {
                if ($currencies[$code]['rate_to_egp'] !== null && (float)$currencies[$code]['rate_to_egp'] === (float)$rate) continue;
                $stmt->execute([$rate, $code]);
                $changes[$code] = ['before' => $currencies[$code]['rate_to_egp'], 'after' => $rate];
            }
            $pdo->commit();
            if ($changes) logActivity('Updated currency exchange rates', ['rates' => $changes], 'update', 'currency');
            setAlert('success', 'Currency rates saved. Existing orders retain their recorded rates.');
            redirect('index.php');
            exit;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = $exception instanceof InvalidArgumentException ? $exception->getMessage() : 'Unable to save currency rates.';
        }
    } elseif (($_POST['action'] ?? '') === 'convert') {
        try {
            $from = is_string($_POST['from_currency'] ?? null) ? $_POST['from_currency'] : '';
            $to = is_string($_POST['to_currency'] ?? null) ? $_POST['to_currency'] : '';
            $converted = convertCurrencyAmount($_POST['amount'] ?? '', $from, $to, $currencies);
            $conversion = formatCurrency((float)$_POST['amount'], $from) . ' = ' . formatCurrency($converted, $to);
        } catch (InvalidArgumentException $exception) {
            $error = $exception->getMessage();
        }
    }
}
$page_title = 'Settings — Currency Rates';
require_once '../../includes/header.php';
?>
<div class="container mt-4">
    <h2>Settings</h2>
    <h4 class="mt-3">Currency exchange rates</h4>
    <p class="text-muted">Enter how many EGP equal 1 unit of each currency. These rates apply system-wide to new orders and the converter. Existing orders keep their recorded rates.</p>
    <?php if ($error): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
    <form method="post" class="card mb-4">
        <div class="card-body">
            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['currency_settings_token']) ?>">
            <input type="hidden" name="action" value="save_rates">
            <div class="table-responsive"><table class="table">
                <thead><tr><th>Currency</th><th>EGP per 1 unit</th><th>Last updated</th></tr></thead>
                <tbody><?php foreach ($currencies as $code => $currency): ?>
                    <tr><td><?= e($code . ' — ' . $currency['name']) ?></td><td>
                        <?php if ($code !== 'EGP' && $canManageRates): ?>
                            <input aria-label="<?= e($code) ?> rate to EGP" type="number" class="form-control" name="rates[<?= e($code) ?>]" min="0.0001" max="999999.9999" step="0.0001" value="<?= e($currency['rate_to_egp']) ?>" placeholder="Not configured">
                        <?php else: ?><?= e($code === 'EGP' ? '1.0000 (base currency)' : ($currency['rate_to_egp'] ?? 'Not configured')) ?><?php endif; ?>
                    </td><td><?= e($currency['rate_updated_at'] ?? '—') ?></td></tr>
                <?php endforeach; ?></tbody>
            </table></div>
            <?php if ($canManageRates): ?><button class="btn btn-primary" type="submit">Save rates</button><?php endif; ?>
        </div>
    </form>
    <div class="card mb-4"><div class="card-body">
        <h4>Currency converter</h4>
        <p class="text-muted">Calculate an equivalent amount using the saved rates. This does not move funds or change account balances.</p>
        <form method="post" class="row g-3 align-items-end">
            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['currency_settings_token']) ?>">
            <input type="hidden" name="action" value="convert">
            <div class="col-md-4"><label class="form-label" for="convert_amount">Amount</label><input id="convert_amount" class="form-control" type="number" name="amount" step="0.01" value="<?= e(is_scalar($_POST['amount'] ?? '') ? ($_POST['amount'] ?? '') : '') ?>" required></div>
            <?php foreach (['from_currency' => 'From', 'to_currency' => 'To'] as $field => $label): ?>
                <div class="col-md-3"><label class="form-label" for="<?= e($field) ?>"><?= e($label) ?></label><select class="form-select" name="<?= e($field) ?>" id="<?= e($field) ?>">
                    <?php foreach ($currencies as $code => $currency): ?><option value="<?= e($code) ?>" <?= ($_POST[$field] ?? 'EGP') === $code ? 'selected' : '' ?>><?= e($code) ?></option><?php endforeach; ?>
                </select></div>
            <?php endforeach; ?>
            <div class="col-md-2"><button class="btn btn-primary" type="submit">Convert</button></div>
        </form>
        <?php if ($conversion !== null): ?><div class="alert alert-success mt-3 mb-0" role="status"><?= e($conversion) ?></div><?php endif; ?>
    </div></div>
</div>
<?php require_once '../../includes/footer.php'; ?>

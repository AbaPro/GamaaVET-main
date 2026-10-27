<?php
// Rates always mean EGP per one unit of currency. Never invent missing rates.
function currencyRateToEgp(array $currencies, string $code): float {
    if (!isset($currencies[$code])) {
        throw new InvalidArgumentException('Please select an available currency.');
    }
    if ($code === 'EGP') return 1.0;
    $rate = $currencies[$code]['rate_to_egp'] ?? null;
    if (!is_numeric($rate) || !is_finite((float)$rate) || (float)$rate <= 0) {
        throw new InvalidArgumentException('Set an exchange rate for ' . $code . ' in Settings first.');
    }
    return (float)$rate;
}

function convertCurrencyAmount($amount, string $from, string $to, array $currencies): float {
    if (!is_numeric($amount) || !is_finite((float)$amount)) {
        throw new InvalidArgumentException('Enter a valid amount.');
    }
    if ($from === $to && isset($currencies[$from])) return round((float)$amount, 2);
    $converted = (float)$amount * currencyRateToEgp($currencies, $from) / currencyRateToEgp($currencies, $to);
    if (!is_finite($converted)) throw new InvalidArgumentException('Amount is too large.');
    return round($converted, 2);
}

function getCurrencyRates(): array {
    global $pdo;
    $rows = $pdo->query('SELECT code, name, symbol, is_default, rate_to_egp, rate_updated_at FROM currencies ORDER BY is_default DESC, code')->fetchAll(PDO::FETCH_ASSOC);
    return array_column($rows, null, 'code');
}

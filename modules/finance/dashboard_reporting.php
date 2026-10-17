<?php
// Shared reporting period and sales scope for dashboard cards and drill-downs.
// ---- Reporting period -------------------------------------------------------
// The current calendar year is the default, so totals roll over each new year.
$today = new DateTimeImmutable('today');
$periods = [
    'this_year' => 'This Year (' . $today->format('Y') . ')',
    'last_year' => 'Last Year (' . ($today->format('Y') - 1) . ')',
    'this_month' => 'This Month',
    'last_month' => 'Last Month',
    'all' => 'All Time',
    'custom' => 'Custom',
];
$period = (string)($_GET['period'] ?? 'this_year');
if (!isset($periods[$period])) {
    $period = 'this_year';
}
$dateFrom = null;
$dateTo = null;
switch ($period) {
    case 'this_month':
        $dateFrom = $today->modify('first day of this month')->format('Y-m-d');
        $dateTo = $today->format('Y-m-d');
        break;
    case 'last_month':
        $dateFrom = $today->modify('first day of last month')->format('Y-m-d');
        $dateTo = $today->modify('last day of last month')->format('Y-m-d');
        break;
    case 'this_year':
        $dateFrom = $today->format('Y-01-01');
        $dateTo = $today->format('Y-m-d');
        break;
    case 'last_year':
        $dateFrom = ($today->format('Y') - 1) . '-01-01';
        $dateTo = ($today->format('Y') - 1) . '-12-31';
        break;
    case 'custom':
        $dateFrom = normalizeTransactionDate($_GET['from'] ?? '');
        $dateTo = normalizeTransactionDate($_GET['to'] ?? '');
        if ($dateFrom && $dateTo && $dateFrom > $dateTo) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }
        break;
}

function financeDashboardDateSql($column, $dateFrom, $dateTo) {
    global $conn;
    $parts = [];
    if ($dateFrom) $parts[] = "$column >= '" . $conn->real_escape_string($dateFrom) . "'";
    if ($dateTo) $parts[] = "$column <= '" . $conn->real_escape_string($dateTo) . "'";
    return $parts ? implode(' AND ', $parts) : '1=1';
}

$orderScope = getCustomerChannelScopeSql('fd_customer', 'fd_factory');
$orderJoins = 'JOIN customers fd_customer ON fd_customer.id = o.customer_id
               LEFT JOIN factories fd_factory ON fd_factory.id = fd_customer.factory_id';
$orderDateSql = financeDashboardDateSql('o.order_date', $dateFrom, $dateTo);

// Revenue is cash actually collected: order payments dated within the period,
// limited to the safes/bank accounts this user may see.
$revenuePaymentSql = "order_payments op
    JOIN orders o ON o.id = op.order_id
    $orderJoins
    WHERE $orderScope
      AND " . financeDashboardDateSql('op.transaction_date', $dateFrom, $dateTo) . "
      AND " . getSafeReferenceScopeSql('op.safe_id') . "
      AND " . getBankReferenceScopeSql('op.bank_account_id');


$reportQuery = http_build_query(['period' => $period, 'from' => $dateFrom, 'to' => $dateTo]);
$periodLabel = $period === 'all'
    ? 'All time'
    : (($dateFrom ? date('d M Y', strtotime($dateFrom)) : 'Start') . ' – ' . ($dateTo ? date('d M Y', strtotime($dateTo)) : 'Today'));
// Short tag for cards: the year for yearly periods, otherwise the range.
$periodTag = in_array($period, ['this_year', 'last_year'], true) ? substr($dateFrom, 0, 4) : $periodLabel;
if (in_array($period, ['this_year', 'last_year'], true)) {
    $periodLabel = $periodTag . ' · ' . $periodLabel;
}

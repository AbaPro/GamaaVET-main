<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../config/database.php';
require_once __DIR__ . '/report_catalog.php';

if (!hasPermission('analysis.view_reports')) {
    setAlert('danger', 'Access denied.');
    redirect('../../dashboard.php');
}

function analysisNormalizeDate($value): string
{
    $value = is_string($value) ? trim($value) : '';
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
}

function analysisRunQuery(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Could not prepare the report query.');
    }
    if ($types !== '' && $params) {
        $stmt->bind_param($types, ...$params);
    }
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Could not run the report query.');
    }
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $rows;
}

function analysisAddDateFilters(array &$where, string &$types, array &$params, string $field, array $filters): void
{
    if ($filters['date_from'] !== '') {
        $where[] = "$field >= ?";
        $types .= 's';
        $params[] = $filters['date_from'];
    }
    if ($filters['date_to'] !== '') {
        $where[] = "$field <= ?";
        $types .= 's';
        $params[] = $filters['date_to'];
    }
}

function analysisAddIdFilter(array &$where, string &$types, array &$params, string $field, int $value): void
{
    if ($value > 0) {
        $where[] = "$field = ?";
        $types .= 'i';
        $params[] = $value;
    }
}

function analysisRequirementAllowed(?string $requirement): bool
{
    if ($requirement === 'final_prices') {
        return canViewProductPrice('final');
    }
    if ($requirement === 'purchase_prices') {
        return hasPermission('purchases.po.price.view');
    }
    return true;
}

function analysisTableHasColumn(mysqli $conn, string $table, string $column): bool
{
    $sql = 'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1';
    return analysisRunQuery($conn, $sql, 'ss', [$table, $column]) !== [];
}

function analysisFormatValue($value, string $format, array $row = []): string
{
    if ($value === null || $value === '') {
        return '<span class="text-muted">—</span>';
    }
    if ($format === 'currency') {
        return htmlspecialchars(formatCurrency((float)$value, $row['currency'] ?? 'EGP'));
    }
    if ($format === 'money') {
        return number_format((float)$value, 2);
    }
    if ($format === 'number') {
        return number_format((float)$value, 0);
    }
    if ($format === 'quantity') {
        $number = (float)$value;
        return number_format($number, floor($number) === $number ? 0 : 2);
    }
    if ($format === 'percent') {
        return number_format((float)$value, 1) . '%';
    }
    if ($format === 'date') {
        $timestamp = strtotime((string)$value);
        return $timestamp ? htmlspecialchars(date('M j, Y', $timestamp)) : htmlspecialchars((string)$value);
    }
    if ($format === 'status') {
        return htmlspecialchars(ucwords(str_replace(['-', '_'], ' ', (string)$value)));
    }
    return htmlspecialchars((string)$value);
}

function analysisFormatCell(array $column, array $row): string
{
    $display = analysisFormatValue($row[$column['key']] ?? null, $column['format'], $row);
    $link = $column['link'] ?? null;
    if (!$link) {
        return $display;
    }

    $recordId = (int)($row[$link['id_key']] ?? 0);
    if ($recordId <= 0) {
        return $display;
    }

    $parameter = $link['parameter'] ?? 'id';
    $href = BASE_URL . ltrim($link['path'], '/') . '?' . rawurlencode($parameter) . '=' . rawurlencode((string)$recordId);
    return '<a class="text-decoration-none" href="' . htmlspecialchars($href) . '">' . $display . '</a>';
}

$catalog = getAnalysisReportCatalog();
$requestedKey = trim((string)($_GET['key'] ?? ''));

$legacyAliases = [
    'sales_summary' => 'sales_overview',
    'gross_sales' => 'sales_overview',
    'net_sales' => 'sales_overview',
    'sales_growth' => 'sales_overview',
    'average_transaction_value' => 'sales_overview',
    'items_per_transaction' => 'sales_overview',
    'revenue_per_hour' => 'sales_overview',
    'sales_per_day' => 'sales_overview',
    'discount_rate' => 'sales_overview',
    'discount_impact' => 'sales_overview',
    'total_sales_today' => 'sales_overview',
    'total_sales_this_month' => 'sales_overview',
    'sales_by_product' => 'final_product_sales',
    'top_products_overview' => 'final_product_sales',
    'sales_by_category' => 'final_product_sales',
    'top_categories_overview' => 'final_product_sales',
    'top_customers' => 'customer_sales',
    'customer_lifetime_value' => 'customer_sales',
    'customer_purchase_frequency' => 'customer_sales',
    'sales_by_customer_type' => 'customer_sales',
    'accounts_receivable' => 'open_receivables',
    'ar_aging' => 'open_receivables',
    'returns_summary' => 'returns_by_product',
    'inventory_levels' => 'final_product_stock',
    'low_stock' => 'stock_alerts',
    'purchase_summary' => 'purchase_overview',
    'purchase_cost' => 'purchase_overview',
    'purchase_volume' => 'purchase_overview',
    'purchases_by_supplier' => 'material_purchases',
    'outstanding_purchase_orders' => 'open_purchase_orders',
    'accounts_payable' => 'vendor_payables',
    'ap_aging' => 'vendor_payables',
    'payments_by_method' => 'payments_by_method',
    'cash_positions' => 'cash_positions',
];

if (isset($legacyAliases[$requestedKey]) && $legacyAliases[$requestedKey] !== $requestedKey) {
    $query = $_GET;
    $query['key'] = $legacyAliases[$requestedKey];
    unset($query['format'], $query['product_type']);
    redirect('report.php?' . http_build_query($query));
}

if ($requestedKey === '' || !isset($catalog[$requestedKey])) {
    setAlert('warning', 'Report unavailable.');
    redirect('index.php');
}

$reportMeta = $catalog[$requestedKey];
$groupMeta = getAnalysisReportGroups()[$reportMeta['group']] ?? null;
if (!$groupMeta || !hasPermission($groupMeta['permission'])) {
    setAlert('danger', 'You do not have permission to view that report group.');
    redirect('index.php');
}
$reportFilters = $reportMeta['filters'];
$hasDateFilter = in_array('date', $reportFilters, true);
$filters = [
    'date_from' => analysisNormalizeDate(array_key_exists('date_from', $_GET) ? $_GET['date_from'] : ($hasDateFilter ? date('Y-m-01', strtotime('-11 months')) : '')),
    'date_to' => analysisNormalizeDate(array_key_exists('date_to', $_GET) ? $_GET['date_to'] : ($hasDateFilter ? date('Y-m-d') : '')),
    'customer_id' => max(0, (int)($_GET['customer_id'] ?? 0)),
    'vendor_id' => max(0, (int)($_GET['vendor_id'] ?? 0)),
    'category_id' => max(0, (int)($_GET['category_id'] ?? 0)),
    'inventory_id' => max(0, (int)($_GET['inventory_id'] ?? 0)),
];
$dateRangeError = $filters['date_from'] !== ''
    && $filters['date_to'] !== ''
    && $filters['date_from'] > $filters['date_to'];

$customerScope = getCustomerChannelScopeSql('c', 'f');
$inventoryScope = getInventoryChannelScopeSql('i');

$definitions = [
    'sales_overview' => [
        'columns' => [
            ['key' => 'period', 'label' => 'Month', 'format' => 'text'],
            ['key' => 'currency', 'label' => 'Currency', 'format' => 'text'],
            ['key' => 'orders', 'label' => 'Orders', 'format' => 'number'],
            ['key' => 'final_units', 'label' => 'Final Units', 'format' => 'quantity'],
            ['key' => 'free_samples', 'label' => 'Free Samples', 'format' => 'quantity'],
            ['key' => 'gross_line_value', 'label' => 'Gross Line Value', 'format' => 'currency', 'requires' => 'final_prices'],
            ['key' => 'discounts', 'label' => 'Discounts', 'format' => 'currency', 'requires' => 'final_prices'],
            ['key' => 'shipping', 'label' => 'Shipping', 'format' => 'currency', 'requires' => 'final_prices'],
            ['key' => 'invoiced_sales', 'label' => 'Invoiced Sales', 'format' => 'currency', 'requires' => 'final_prices'],
            ['key' => 'paid', 'label' => 'Paid', 'format' => 'currency', 'requires' => 'final_prices'],
            ['key' => 'open_balance', 'label' => 'Open Balance', 'format' => 'currency', 'requires' => 'final_prices'],
        ],
        'summaries' => [
            ['key' => 'orders', 'label' => 'Orders', 'format' => 'number'],
            ['key' => 'final_units', 'label' => 'Final units', 'format' => 'quantity'],
            ['key' => 'invoiced_sales', 'label' => 'Invoiced', 'format' => 'currency', 'currency_key' => 'currency', 'requires' => 'final_prices'],
            ['key' => 'open_balance', 'label' => 'Open balance', 'format' => 'currency', 'currency_key' => 'currency', 'requires' => 'final_prices'],
        ],
        'chart' => ['label_key' => 'period', 'value_key' => 'orders', 'label' => 'Orders'],
        'run' => function (mysqli $conn, array $filters) use ($customerScope): array {
            $where = [$customerScope];
            $types = '';
            $params = [];
            analysisAddDateFilters($where, $types, $params, 'o.order_date', $filters);
            analysisAddIdFilter($where, $types, $params, 'o.customer_id', $filters['customer_id']);
            $sql = "SELECT DATE_FORMAT(o.order_date, '%Y-%m') AS period,
                           o.currency,
                           COUNT(*) AS orders,
                           SUM(COALESCE(items.final_units, 0)) AS final_units,
                           SUM(COALESCE(items.free_samples, 0)) AS free_samples,
                           SUM(COALESCE(items.gross_line_value, 0)) AS gross_line_value,
                           SUM(o.discount_amount) AS discounts,
                           SUM(o.shipping_cost) AS shipping,
                           SUM(o.total_amount) AS invoiced_sales,
                           SUM(o.paid_amount) AS paid,
                           SUM(GREATEST(o.total_amount - o.paid_amount, 0)) AS open_balance
                    FROM orders o
                    JOIN customers c ON c.id = o.customer_id
                    LEFT JOIN factories f ON f.id = c.factory_id
                    LEFT JOIN (
                        SELECT oi.order_id,
                               SUM(CASE WHEN p.type = 'final' THEN oi.quantity ELSE 0 END) AS final_units,
                               SUM(CASE WHEN p.type = 'final' AND oi.is_free_sample = 1 THEN oi.quantity ELSE 0 END) AS free_samples,
                               SUM(CASE WHEN p.type = 'final' THEN oi.total_price ELSE 0 END) AS gross_line_value
                        FROM order_items oi
                        JOIN products p ON p.id = oi.product_id
                        GROUP BY oi.order_id
                    ) items ON items.order_id = o.id
                    WHERE " . implode(' AND ', $where) . "
                    GROUP BY DATE_FORMAT(o.order_date, '%Y-%m'), o.currency
                    ORDER BY period DESC, o.currency";
            return analysisRunQuery($conn, $sql, $types, $params);
        },
    ],
    'final_product_sales' => [
        'columns' => [
            ['key' => 'customer_name', 'label' => 'Customer', 'format' => 'text', 'link' => ['path' => 'modules/customers/view.php', 'id_key' => 'customer_link_id']],
            ['key' => 'product_name', 'label' => 'Final Product', 'format' => 'text', 'link' => ['path' => 'modules/products/view.php', 'id_key' => 'product_link_id']],
            ['key' => 'category_name', 'label' => 'Category', 'format' => 'text'],
            ['key' => 'currency', 'label' => 'Currency', 'format' => 'text'],
            ['key' => 'orders', 'label' => 'Orders', 'format' => 'number'],
            ['key' => 'sold_qty', 'label' => 'Sold Qty', 'format' => 'quantity'],
            ['key' => 'sample_qty', 'label' => 'Sample Qty', 'format' => 'quantity'],
            ['key' => 'line_value', 'label' => 'Line Value', 'format' => 'currency', 'requires' => 'final_prices'],
        ],
        'summaries' => [
            ['key' => 'orders', 'label' => 'Product-order links', 'format' => 'number'],
            ['key' => 'sold_qty', 'label' => 'Sold quantity', 'format' => 'quantity'],
            ['key' => 'line_value', 'label' => 'Line value', 'format' => 'currency', 'currency_key' => 'currency', 'requires' => 'final_prices'],
        ],
        'chart' => ['label_key' => 'product_name', 'value_key' => 'sold_qty', 'label' => 'Sold quantity'],
        'run' => function (mysqli $conn, array $filters) use ($customerScope): array {
            $where = ["p.type = 'final'", 'o.customer_id = p.customer_id', $customerScope];
            $types = '';
            $params = [];
            analysisAddDateFilters($where, $types, $params, 'o.order_date', $filters);
            analysisAddIdFilter($where, $types, $params, 'p.customer_id', $filters['customer_id']);
            analysisAddIdFilter($where, $types, $params, 'p.category_id', $filters['category_id']);
            $sql = "SELECT c.id AS customer_link_id,
                           p.id AS product_link_id,
                           c.name AS customer_name,
                           p.name AS product_name,
                           COALESCE(cat.name, 'Uncategorised') AS category_name,
                           o.currency,
                           COUNT(DISTINCT o.id) AS orders,
                           SUM(CASE WHEN oi.is_free_sample = 0 THEN oi.quantity ELSE 0 END) AS sold_qty,
                           SUM(CASE WHEN oi.is_free_sample = 1 THEN oi.quantity ELSE 0 END) AS sample_qty,
                           SUM(oi.total_price) AS line_value
                    FROM order_items oi
                    JOIN orders o ON o.id = oi.order_id
                    JOIN products p ON p.id = oi.product_id
                    JOIN customers c ON c.id = p.customer_id
                    LEFT JOIN factories f ON f.id = c.factory_id
                    LEFT JOIN categories cat ON cat.id = p.category_id
                    WHERE " . implode(' AND ', $where) . "
                    GROUP BY p.id, c.id, cat.id, o.currency
                    ORDER BY sold_qty DESC, p.name";
            return analysisRunQuery($conn, $sql, $types, $params);
        },
    ],
    'customer_sales' => [
        'columns' => [
            ['key' => 'customer_name', 'label' => 'Customer', 'format' => 'text', 'link' => ['path' => 'modules/customers/view.php', 'id_key' => 'customer_link_id']],
            ['key' => 'currency', 'label' => 'Currency', 'format' => 'text'],
            ['key' => 'orders', 'label' => 'Orders', 'format' => 'number'],
            ['key' => 'latest_order', 'label' => 'Latest Order', 'format' => 'date'],
            ['key' => 'invoiced_sales', 'label' => 'Invoiced Sales', 'format' => 'currency', 'requires' => 'final_prices'],
            ['key' => 'paid', 'label' => 'Paid', 'format' => 'currency', 'requires' => 'final_prices'],
            ['key' => 'open_balance', 'label' => 'Open Balance', 'format' => 'currency', 'requires' => 'final_prices'],
        ],
        'summaries' => [
            ['key' => 'orders', 'label' => 'Orders', 'format' => 'number'],
            ['key' => 'invoiced_sales', 'label' => 'Invoiced', 'format' => 'currency', 'currency_key' => 'currency', 'requires' => 'final_prices'],
            ['key' => 'open_balance', 'label' => 'Open balance', 'format' => 'currency', 'currency_key' => 'currency', 'requires' => 'final_prices'],
        ],
        'chart' => ['label_key' => 'customer_name', 'value_key' => 'orders', 'label' => 'Orders'],
        'run' => function (mysqli $conn, array $filters) use ($customerScope): array {
            $where = [$customerScope];
            $types = '';
            $params = [];
            analysisAddDateFilters($where, $types, $params, 'o.order_date', $filters);
            analysisAddIdFilter($where, $types, $params, 'o.customer_id', $filters['customer_id']);
            $sql = "SELECT c.id AS customer_link_id,
                           c.name AS customer_name,
                           o.currency,
                           COUNT(*) AS orders,
                           MAX(o.order_date) AS latest_order,
                           SUM(o.total_amount) AS invoiced_sales,
                           SUM(o.paid_amount) AS paid,
                           SUM(GREATEST(o.total_amount - o.paid_amount, 0)) AS open_balance
                    FROM orders o
                    JOIN customers c ON c.id = o.customer_id
                    LEFT JOIN factories f ON f.id = c.factory_id
                    WHERE " . implode(' AND ', $where) . "
                    GROUP BY c.id, o.currency
                    ORDER BY invoiced_sales DESC, c.name";
            return analysisRunQuery($conn, $sql, $types, $params);
        },
    ],
    'open_receivables' => [
        'columns' => [
            ['key' => 'customer_name', 'label' => 'Customer', 'format' => 'text', 'link' => ['path' => 'modules/customers/view.php', 'id_key' => 'customer_link_id']],
            ['key' => 'currency', 'label' => 'Currency', 'format' => 'text'],
            ['key' => 'open_orders', 'label' => 'Open Orders', 'format' => 'number'],
            ['key' => 'oldest_open_order', 'label' => 'Oldest Open Order', 'format' => 'date'],
            ['key' => 'invoiced', 'label' => 'Invoiced', 'format' => 'currency', 'requires' => 'final_prices'],
            ['key' => 'paid', 'label' => 'Paid', 'format' => 'currency', 'requires' => 'final_prices'],
            ['key' => 'open_balance', 'label' => 'Open Balance', 'format' => 'currency', 'requires' => 'final_prices'],
        ],
        'summaries' => [
            ['key' => 'open_orders', 'label' => 'Open orders', 'format' => 'number'],
            ['key' => 'open_balance', 'label' => 'Receivable', 'format' => 'currency', 'currency_key' => 'currency', 'requires' => 'final_prices'],
        ],
        'run' => function (mysqli $conn, array $filters) use ($customerScope): array {
            $where = [$customerScope, 'o.total_amount > o.paid_amount'];
            $types = '';
            $params = [];
            analysisAddIdFilter($where, $types, $params, 'o.customer_id', $filters['customer_id']);
            $sql = "SELECT c.id AS customer_link_id,
                           c.name AS customer_name,
                           o.currency,
                           COUNT(*) AS open_orders,
                           MIN(o.order_date) AS oldest_open_order,
                           SUM(o.total_amount) AS invoiced,
                           SUM(o.paid_amount) AS paid,
                           SUM(o.total_amount - o.paid_amount) AS open_balance
                    FROM orders o
                    JOIN customers c ON c.id = o.customer_id
                    LEFT JOIN factories f ON f.id = c.factory_id
                    WHERE " . implode(' AND ', $where) . "
                    GROUP BY c.id, o.currency
                    ORDER BY open_balance DESC, c.name";
            return analysisRunQuery($conn, $sql, $types, $params);
        },
    ],
    'returns_by_product' => [
        'columns' => [
            ['key' => 'customer_name', 'label' => 'Customer', 'format' => 'text', 'link' => ['path' => 'modules/customers/view.php', 'id_key' => 'customer_link_id']],
            ['key' => 'product_name', 'label' => 'Final Product', 'format' => 'text', 'link' => ['path' => 'modules/products/view.php', 'id_key' => 'product_link_id']],
            ['key' => 'currency', 'label' => 'Currency', 'format' => 'text'],
            ['key' => 'return_events', 'label' => 'Return Events', 'format' => 'number'],
            ['key' => 'returned_qty', 'label' => 'Returned Qty', 'format' => 'quantity'],
            ['key' => 'return_value', 'label' => 'Recorded Value', 'format' => 'currency', 'requires' => 'final_prices'],
            ['key' => 'latest_return', 'label' => 'Latest Return', 'format' => 'date'],
        ],
        'summaries' => [
            ['key' => 'return_events', 'label' => 'Return events', 'format' => 'number'],
            ['key' => 'returned_qty', 'label' => 'Returned quantity', 'format' => 'quantity'],
            ['key' => 'return_value', 'label' => 'Recorded value', 'format' => 'currency', 'currency_key' => 'currency', 'requires' => 'final_prices'],
        ],
        'chart' => ['label_key' => 'product_name', 'value_key' => 'returned_qty', 'label' => 'Returned quantity'],
        'run' => function (mysqli $conn, array $filters) use ($customerScope): array {
            $where = ["p.type = 'final'", 'o.customer_id = p.customer_id', $customerScope];
            $types = '';
            $params = [];
            analysisAddDateFilters($where, $types, $params, 'DATE(r.created_at)', $filters);
            analysisAddIdFilter($where, $types, $params, 'o.customer_id', $filters['customer_id']);
            analysisAddIdFilter($where, $types, $params, 'p.category_id', $filters['category_id']);
            $sql = "SELECT c.id AS customer_link_id,
                           p.id AS product_link_id,
                           c.name AS customer_name,
                           p.name AS product_name,
                           o.currency,
                           COUNT(DISTINCT r.id) AS return_events,
                           SUM(r.returned_quantity) AS returned_qty,
                           SUM(r.returned_quantity * oi.unit_price) AS return_value,
                           MAX(DATE(r.created_at)) AS latest_return
                    FROM order_returns r
                    JOIN orders o ON o.id = r.order_id
                    JOIN order_items oi ON oi.id = r.order_item_id
                    JOIN products p ON p.id = r.product_id
                    JOIN customers c ON c.id = o.customer_id
                    LEFT JOIN factories f ON f.id = c.factory_id
                    WHERE " . implode(' AND ', $where) . "
                    GROUP BY c.id, p.id, o.currency
                    ORDER BY returned_qty DESC, p.name";
            return analysisRunQuery($conn, $sql, $types, $params);
        },
    ],
    'final_product_stock' => [
        'columns' => [
            ['key' => 'customer_name', 'label' => 'Customer', 'format' => 'text', 'link' => ['path' => 'modules/customers/view.php', 'id_key' => 'customer_link_id']],
            ['key' => 'product_name', 'label' => 'Final Product', 'format' => 'text', 'link' => ['path' => 'modules/products/view.php', 'id_key' => 'product_link_id']],
            ['key' => 'category_name', 'label' => 'Category', 'format' => 'text'],
            ['key' => 'inventory_name', 'label' => 'Inventory', 'format' => 'text', 'link' => ['path' => 'modules/inventories/view.php', 'id_key' => 'inventory_link_id']],
            ['key' => 'quantity', 'label' => 'Quantity', 'format' => 'quantity'],
            ['key' => 'unit', 'label' => 'Unit', 'format' => 'text'],
        ],
        'run' => function (mysqli $conn, array $filters) use ($customerScope, $inventoryScope): array {
            $where = ["p.type = 'final'", $customerScope, $inventoryScope];
            $types = '';
            $params = [];
            analysisAddIdFilter($where, $types, $params, 'p.customer_id', $filters['customer_id']);
            analysisAddIdFilter($where, $types, $params, 'p.category_id', $filters['category_id']);
            analysisAddIdFilter($where, $types, $params, 'i.id', $filters['inventory_id']);
            $sql = "SELECT c.id AS customer_link_id,
                           p.id AS product_link_id,
                           i.id AS inventory_link_id,
                           c.name AS customer_name,
                           p.name AS product_name,
                           COALESCE(cat.name, 'Uncategorised') AS category_name,
                           i.name AS inventory_name,
                           ip.quantity,
                           COALESCE(NULLIF(p.unit, ''), 'each') AS unit
                    FROM inventory_products ip
                    JOIN inventories i ON i.id = ip.inventory_id
                    JOIN products p ON p.id = ip.product_id
                    JOIN customers c ON c.id = p.customer_id
                    LEFT JOIN factories f ON f.id = c.factory_id
                    LEFT JOIN categories cat ON cat.id = p.category_id
                    WHERE " . implode(' AND ', $where) . "
                    ORDER BY c.name, p.name, i.name";
            return analysisRunQuery($conn, $sql, $types, $params);
        },
    ],
    'raw_material_stock' => [
        'columns' => [
            ['key' => 'material_name', 'label' => 'Raw Material', 'format' => 'text', 'link' => ['path' => 'modules/products/view.php', 'id_key' => 'product_link_id']],
            ['key' => 'category_name', 'label' => 'Category', 'format' => 'text'],
            ['key' => 'inventory_name', 'label' => 'Inventory', 'format' => 'text', 'link' => ['path' => 'modules/inventories/view.php', 'id_key' => 'inventory_link_id']],
            ['key' => 'quantity', 'label' => 'Quantity', 'format' => 'quantity'],
            ['key' => 'unit', 'label' => 'Unit', 'format' => 'text'],
        ],
        'run' => function (mysqli $conn, array $filters) use ($inventoryScope): array {
            $where = ["p.type = 'material'", $inventoryScope];
            $types = '';
            $params = [];
            analysisAddIdFilter($where, $types, $params, 'p.category_id', $filters['category_id']);
            analysisAddIdFilter($where, $types, $params, 'i.id', $filters['inventory_id']);
            $sql = "SELECT p.id AS product_link_id,
                           i.id AS inventory_link_id,
                           p.name AS material_name,
                           COALESCE(cat.name, 'Uncategorised') AS category_name,
                           i.name AS inventory_name,
                           ip.quantity,
                           COALESCE(NULLIF(p.unit, ''), 'unit') AS unit
                    FROM inventory_products ip
                    JOIN inventories i ON i.id = ip.inventory_id
                    JOIN products p ON p.id = ip.product_id
                    LEFT JOIN categories cat ON cat.id = p.category_id
                    WHERE " . implode(' AND ', $where) . "
                    ORDER BY p.name, i.name";
            return analysisRunQuery($conn, $sql, $types, $params);
        },
    ],
    'stock_alerts' => [
        'columns' => [
            ['key' => 'product_type', 'label' => 'Stock Type', 'format' => 'text'],
            ['key' => 'customer_name', 'label' => 'Customer', 'format' => 'text', 'link' => ['path' => 'modules/customers/view.php', 'id_key' => 'customer_link_id']],
            ['key' => 'product_name', 'label' => 'Product / Material', 'format' => 'text', 'link' => ['path' => 'modules/products/view.php', 'id_key' => 'product_link_id']],
            ['key' => 'total_stock', 'label' => 'Current Stock', 'format' => 'quantity'],
            ['key' => 'min_stock_level', 'label' => 'Minimum', 'format' => 'quantity'],
            ['key' => 'shortage', 'label' => 'Shortage', 'format' => 'quantity'],
            ['key' => 'unit', 'label' => 'Unit', 'format' => 'text'],
        ],
        'summaries' => [
            ['key' => '__row_count', 'label' => 'Products needing attention', 'format' => 'number'],
        ],
        'run' => function (mysqli $conn, array $filters) use ($customerScope, $inventoryScope): array {
            $where = [
                "p.type IN ('final', 'material')",
                'p.min_stock_level > 0',
                "(p.type = 'material' OR (p.type = 'final' AND $customerScope))",
            ];
            $stockWhere = [$inventoryScope];
            $types = '';
            $params = [];
            analysisAddIdFilter($stockWhere, $types, $params, 'i.id', $filters['inventory_id']);
            $sql = "SELECT p.id AS product_link_id,
                           c.id AS customer_link_id,
                           CASE WHEN p.type = 'final' THEN 'Final product' ELSE 'Raw material' END AS product_type,
                           CASE WHEN p.type = 'final' THEN c.name ELSE NULL END AS customer_name,
                           p.name AS product_name,
                           COALESCE(stock.total_stock, 0) AS total_stock,
                           p.min_stock_level,
                           GREATEST(p.min_stock_level - COALESCE(stock.total_stock, 0), 0) AS shortage,
                           COALESCE(NULLIF(p.unit, ''), 'unit') AS unit
                    FROM products p
                    LEFT JOIN customers c ON c.id = p.customer_id
                    LEFT JOIN factories f ON f.id = c.factory_id
                    LEFT JOIN (
                        SELECT ip.product_id, SUM(ip.quantity) AS total_stock
                        FROM inventory_products ip
                        JOIN inventories i ON i.id = ip.inventory_id
                        WHERE " . implode(' AND ', $stockWhere) . "
                        GROUP BY ip.product_id
                    ) stock ON stock.product_id = p.id
                    WHERE " . implode(' AND ', $where) . "
                      AND COALESCE(stock.total_stock, 0) <= p.min_stock_level
                    ORDER BY shortage DESC, p.name";
            return analysisRunQuery($conn, $sql, $types, $params);
        },
    ],
    'purchase_overview' => [
        'columns' => [
            ['key' => 'period', 'label' => 'Month', 'format' => 'text'],
            ['key' => 'purchase_orders', 'label' => 'POs', 'format' => 'number'],
            ['key' => 'material_lines', 'label' => 'Material Lines', 'format' => 'number'],
            ['key' => 'received_lines', 'label' => 'Fully Received Lines', 'format' => 'number'],
            ['key' => 'receipt_rate', 'label' => 'Receipt Rate', 'format' => 'percent'],
            ['key' => 'total_amount', 'label' => 'PO Value', 'format' => 'money', 'requires' => 'purchase_prices'],
            ['key' => 'paid_amount', 'label' => 'Paid', 'format' => 'money', 'requires' => 'purchase_prices'],
            ['key' => 'open_balance', 'label' => 'Open Balance', 'format' => 'money', 'requires' => 'purchase_prices'],
        ],
        'summaries' => [
            ['key' => 'purchase_orders', 'label' => 'Purchase orders', 'format' => 'number'],
            ['key' => 'material_lines', 'label' => 'Material lines', 'format' => 'number'],
            ['key' => 'total_amount', 'label' => 'PO value', 'format' => 'money', 'requires' => 'purchase_prices'],
            ['key' => 'open_balance', 'label' => 'Open balance', 'format' => 'money', 'requires' => 'purchase_prices'],
        ],
        'chart' => ['label_key' => 'period', 'value_key' => 'purchase_orders', 'label' => 'Purchase orders'],
        'run' => function (mysqli $conn, array $filters): array {
            $where = ["po.status <> 'cancelled'"];
            $types = '';
            $params = [];
            analysisAddDateFilters($where, $types, $params, 'po.order_date', $filters);
            analysisAddIdFilter($where, $types, $params, 'po.vendor_id', $filters['vendor_id']);
            $sql = "SELECT DATE_FORMAT(po.order_date, '%Y-%m') AS period,
                           COUNT(*) AS purchase_orders,
                           SUM(items.material_lines) AS material_lines,
                           SUM(items.received_lines) AS received_lines,
                           CASE WHEN SUM(items.material_lines) > 0
                                THEN SUM(items.received_lines) * 100 / SUM(items.material_lines)
                                ELSE 0 END AS receipt_rate,
                           SUM(po.total_amount) AS total_amount,
                           SUM(po.paid_amount) AS paid_amount,
                           SUM(GREATEST(po.total_amount - po.paid_amount, 0)) AS open_balance
                    FROM purchase_orders po
                    JOIN (
                        SELECT poi.purchase_order_id,
                               COUNT(*) AS material_lines,
                               SUM(CASE WHEN poi.received_quantity >= poi.quantity THEN 1 ELSE 0 END) AS received_lines
                        FROM purchase_order_items poi
                        JOIN products p ON p.id = poi.product_id AND p.type = 'material'
                        GROUP BY poi.purchase_order_id
                    ) items ON items.purchase_order_id = po.id
                    WHERE " . implode(' AND ', $where) . "
                    GROUP BY DATE_FORMAT(po.order_date, '%Y-%m')
                    ORDER BY period DESC";
            return analysisRunQuery($conn, $sql, $types, $params);
        },
    ],
    'material_purchases' => [
        'columns' => [
            ['key' => 'vendor_name', 'label' => 'Vendor', 'format' => 'text', 'link' => ['path' => 'modules/vendors/view.php', 'id_key' => 'vendor_link_id']],
            ['key' => 'material_name', 'label' => 'Raw Material', 'format' => 'text', 'link' => ['path' => 'modules/products/view.php', 'id_key' => 'product_link_id']],
            ['key' => 'category_name', 'label' => 'Category', 'format' => 'text'],
            ['key' => 'purchase_orders', 'label' => 'POs', 'format' => 'number'],
            ['key' => 'ordered_qty', 'label' => 'Ordered Qty', 'format' => 'quantity'],
            ['key' => 'received_qty', 'label' => 'Received Qty', 'format' => 'quantity'],
            ['key' => 'unit', 'label' => 'Unit', 'format' => 'text'],
            ['key' => 'total_value', 'label' => 'Total Value', 'format' => 'money', 'requires' => 'purchase_prices'],
        ],
        'summaries' => [
            ['key' => 'purchase_orders', 'label' => 'Material-order links', 'format' => 'number'],
            ['key' => 'total_value', 'label' => 'Purchase value', 'format' => 'money', 'requires' => 'purchase_prices'],
        ],
        'chart' => ['label_key' => 'material_name', 'value_key' => 'ordered_qty', 'label' => 'Ordered quantity'],
        'run' => function (mysqli $conn, array $filters): array {
            $where = ["p.type = 'material'", "po.status <> 'cancelled'"];
            $types = '';
            $params = [];
            analysisAddDateFilters($where, $types, $params, 'po.order_date', $filters);
            analysisAddIdFilter($where, $types, $params, 'po.vendor_id', $filters['vendor_id']);
            analysisAddIdFilter($where, $types, $params, 'p.category_id', $filters['category_id']);
            $sql = "SELECT v.id AS vendor_link_id,
                           p.id AS product_link_id,
                           v.name AS vendor_name,
                           p.name AS material_name,
                           COALESCE(cat.name, 'Uncategorised') AS category_name,
                           COUNT(DISTINCT po.id) AS purchase_orders,
                           SUM(poi.quantity) AS ordered_qty,
                           SUM(poi.received_quantity) AS received_qty,
                           COALESCE(NULLIF(p.unit, ''), 'unit') AS unit,
                           SUM(poi.total_price) AS total_value
                    FROM purchase_order_items poi
                    JOIN purchase_orders po ON po.id = poi.purchase_order_id
                    JOIN vendors v ON v.id = po.vendor_id
                    JOIN products p ON p.id = poi.product_id
                    LEFT JOIN categories cat ON cat.id = p.category_id
                    WHERE " . implode(' AND ', $where) . "
                    GROUP BY v.id, p.id, cat.id
                    ORDER BY total_value DESC, v.name, p.name";
            return analysisRunQuery($conn, $sql, $types, $params);
        },
    ],
    'open_purchase_orders' => [
        'columns' => [
            ['key' => 'vendor_name', 'label' => 'Vendor', 'format' => 'text', 'link' => ['path' => 'modules/vendors/view.php', 'id_key' => 'vendor_link_id']],
            ['key' => 'order_date', 'label' => 'Order Date', 'format' => 'date'],
            ['key' => 'status', 'label' => 'Status', 'format' => 'status'],
            ['key' => 'material_lines', 'label' => 'Material Lines', 'format' => 'number'],
            ['key' => 'received_lines', 'label' => 'Fully Received', 'format' => 'number'],
            ['key' => 'total_amount', 'label' => 'PO Value', 'format' => 'money', 'requires' => 'purchase_prices'],
            ['key' => 'open_balance', 'label' => 'Open Balance', 'format' => 'money', 'requires' => 'purchase_prices'],
            ['key' => 'details_label', 'label' => '', 'format' => 'text', 'link' => ['path' => 'modules/purchases/po_details.php', 'id_key' => 'purchase_order_link_id']],
        ],
        'summaries' => [
            ['key' => '__row_count', 'label' => 'Open purchase orders', 'format' => 'number'],
            ['key' => 'open_balance', 'label' => 'Open balance', 'format' => 'money', 'requires' => 'purchase_prices'],
        ],
        'run' => function (mysqli $conn, array $filters): array {
            $where = ["po.status IN ('new', 'ordered', 'partially-received')"];
            $types = '';
            $params = [];
            analysisAddIdFilter($where, $types, $params, 'po.vendor_id', $filters['vendor_id']);
            $sql = "SELECT po.id AS purchase_order_link_id,
                           v.id AS vendor_link_id,
                           v.name AS vendor_name,
                           'View' AS details_label,
                           po.order_date,
                           po.status,
                           items.material_lines,
                           items.received_lines,
                           po.total_amount,
                           GREATEST(po.total_amount - po.paid_amount, 0) AS open_balance
                    FROM purchase_orders po
                    JOIN vendors v ON v.id = po.vendor_id
                    JOIN (
                        SELECT poi.purchase_order_id,
                               COUNT(*) AS material_lines,
                               SUM(CASE WHEN poi.received_quantity >= poi.quantity THEN 1 ELSE 0 END) AS received_lines
                        FROM purchase_order_items poi
                        JOIN products p ON p.id = poi.product_id AND p.type = 'material'
                        GROUP BY poi.purchase_order_id
                    ) items ON items.purchase_order_id = po.id
                    WHERE " . implode(' AND ', $where) . "
                    ORDER BY po.order_date ASC, po.id";
            return analysisRunQuery($conn, $sql, $types, $params);
        },
    ],
    'vendor_payables' => [
        'columns' => [
            ['key' => 'vendor_name', 'label' => 'Vendor', 'format' => 'text', 'link' => ['path' => 'modules/vendors/view.php', 'id_key' => 'vendor_link_id']],
            ['key' => 'open_orders', 'label' => 'Unpaid POs', 'format' => 'number'],
            ['key' => 'oldest_open_order', 'label' => 'Oldest Open PO', 'format' => 'date'],
            ['key' => 'po_value', 'label' => 'PO Value', 'format' => 'money', 'requires' => 'purchase_prices'],
            ['key' => 'paid', 'label' => 'Paid', 'format' => 'money', 'requires' => 'purchase_prices'],
            ['key' => 'open_balance', 'label' => 'Open Balance', 'format' => 'money', 'requires' => 'purchase_prices'],
        ],
        'summaries' => [
            ['key' => 'open_orders', 'label' => 'Unpaid POs', 'format' => 'number'],
            ['key' => 'open_balance', 'label' => 'Vendor payable', 'format' => 'money', 'requires' => 'purchase_prices'],
        ],
        'run' => function (mysqli $conn, array $filters): array {
            $where = ["po.status <> 'cancelled'", 'po.total_amount > po.paid_amount'];
            $types = '';
            $params = [];
            analysisAddIdFilter($where, $types, $params, 'po.vendor_id', $filters['vendor_id']);
            $sql = "SELECT v.id AS vendor_link_id,
                           v.name AS vendor_name,
                           COUNT(*) AS open_orders,
                           MIN(po.order_date) AS oldest_open_order,
                           SUM(po.total_amount) AS po_value,
                           SUM(po.paid_amount) AS paid,
                           SUM(po.total_amount - po.paid_amount) AS open_balance
                    FROM purchase_orders po
                    JOIN vendors v ON v.id = po.vendor_id
                    WHERE " . implode(' AND ', $where) . "
                    GROUP BY v.id
                    ORDER BY open_balance DESC, v.name";
            return analysisRunQuery($conn, $sql, $types, $params);
        },
    ],
    'payments_by_method' => [
        'columns' => [
            ['key' => 'period', 'label' => 'Month', 'format' => 'text'],
            ['key' => 'direction', 'label' => 'Direction', 'format' => 'text'],
            ['key' => 'method', 'label' => 'Method', 'format' => 'text'],
            ['key' => 'currency', 'label' => 'Currency', 'format' => 'text'],
            ['key' => 'transactions', 'label' => 'Transactions', 'format' => 'number'],
            ['key' => 'total_amount', 'label' => 'Amount', 'format' => 'currency'],
        ],
        'summaries' => [
            ['key' => 'transactions', 'label' => 'Payment transactions', 'format' => 'number'],
            ['key' => 'total_amount', 'label' => 'Recorded amount', 'format' => 'currency', 'currency_key' => 'currency'],
        ],
        'run' => function (mysqli $conn, array $filters) use ($customerScope): array {
            $salesWhere = [$customerScope];
            $salesTypes = '';
            $salesParams = [];
            analysisAddDateFilters($salesWhere, $salesTypes, $salesParams, 'DATE(op.created_at)', $filters);

            $purchaseWhere = [];
            $purchaseTypes = '';
            $purchaseParams = [];
            analysisAddDateFilters($purchaseWhere, $purchaseTypes, $purchaseParams, 'DATE(pop.created_at)', $filters);

            $sql = "SELECT DATE_FORMAT(op.created_at, '%Y-%m') AS period,
                           'Customer receipt' AS direction,
                           op.payment_method AS method,
                           o.currency,
                           COUNT(*) AS transactions,
                           SUM(op.amount) AS total_amount
                    FROM order_payments op
                    JOIN orders o ON o.id = op.order_id
                    JOIN customers c ON c.id = o.customer_id
                    LEFT JOIN factories f ON f.id = c.factory_id
                    WHERE " . implode(' AND ', $salesWhere) . "
                    GROUP BY DATE_FORMAT(op.created_at, '%Y-%m'), op.payment_method, o.currency
                    UNION ALL
                    SELECT DATE_FORMAT(pop.created_at, '%Y-%m') AS period,
                           'Vendor payment' AS direction,
                           pop.payment_method AS method,
                           'EGP' AS currency,
                           COUNT(*) AS transactions,
                           SUM(pop.amount) AS total_amount
                    FROM purchase_order_payments pop";
            if ($purchaseWhere) {
                $sql .= ' WHERE ' . implode(' AND ', $purchaseWhere);
            }
            $sql .= " GROUP BY DATE_FORMAT(pop.created_at, '%Y-%m'), pop.payment_method
                      ORDER BY period DESC, direction, method";
            return analysisRunQuery(
                $conn,
                $sql,
                $salesTypes . $purchaseTypes,
                array_merge($salesParams, $purchaseParams)
            );
        },
    ],
    'cash_positions' => [
        'columns' => [
            ['key' => 'source_type', 'label' => 'Account Type', 'format' => 'text'],
            ['key' => 'account_name', 'label' => 'Account', 'format' => 'text'],
            ['key' => 'balance', 'label' => 'Current Balance', 'format' => 'money'],
        ],
        'run' => function (mysqli $conn): array {
            $supportsAccountScope = analysisTableHasColumn($conn, 'safes', 'account_id')
                && analysisTableHasColumn($conn, 'bank_accounts', 'account_id');
            $scope = hasPermission('finance.expenses.all_accounts') || !$supportsAccountScope
                ? '1=1'
                : getAccountScopeSql();
            $rows = [];
            foreach ([
                "SELECT 'Safe' AS source_type, name AS account_name, balance FROM safes WHERE $scope",
                "SELECT 'Bank' AS source_type, bank_name AS account_name, balance FROM bank_accounts WHERE $scope",
                "SELECT 'Personal' AS source_type, name AS account_name, balance FROM personal_accounts WHERE is_active = 1",
            ] as $sql) {
                $result = $conn->query($sql);
                if (!$result) {
                    throw new RuntimeException('Could not load current account balances.');
                }
                $rows = array_merge($rows, $result->fetch_all(MYSQLI_ASSOC));
            }
            usort($rows, static fn(array $a, array $b): int => (float)$b['balance'] <=> (float)$a['balance']);
            return $rows;
        },
    ],
];

$report = array_merge($reportMeta, $definitions[$requestedKey]);
$queryError = '';
$rows = [];
if (!$dateRangeError) {
    try {
        $rows = $report['run']($conn, $filters);
    } catch (Throwable $exception) {
        error_log('Analysis report failed [' . $requestedKey . ']: ' . $exception->getMessage());
        $queryError = 'The report could not be loaded. Please try again or contact an administrator.';
    }
}

$visibleColumns = array_values(array_filter(
    $report['columns'],
    static fn(array $column): bool => analysisRequirementAllowed($column['requires'] ?? null)
));

$format = (string)($_GET['format'] ?? '');
if (in_array($format, ['csv', 'pdf'], true) && ($dateRangeError || $queryError !== '')) {
    setAlert('danger', $dateRangeError ? 'The start date must be before the end date.' : $queryError);
    redirect('report.php?key=' . urlencode($requestedKey));
}

if ($format === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="analysis_' . $requestedKey . '_' . date('Y-m-d') . '.csv"');
    $output = fopen('php://output', 'w');
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, array_column($visibleColumns, 'label'));
    foreach ($rows as $row) {
        $line = [];
        foreach ($visibleColumns as $column) {
            $line[] = $row[$column['key']] ?? '';
        }
        fputcsv($output, $line);
    }
    fclose($output);
    exit;
}

if ($format === 'pdf') {
    require_once '../../tcpdf/tcpdf.php';
    $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    $pdf->SetCreator('GammaVet');
    $pdf->SetAuthor('GammaVet');
    $pdf->SetTitle($report['title']);
    $pdf->SetMargins(10, 15, 10);
    $pdf->AddPage('L');
    $pdf->SetFont('dejavusans', 'B', 14);
    $pdf->Cell(0, 9, $report['title'], 0, 1, 'L');
    $pdf->SetFont('dejavusans', '', 8);
    $table = '<table border="1" cellpadding="4"><thead><tr style="font-weight:bold;background-color:#f1f3f5;">';
    foreach ($visibleColumns as $column) {
        $table .= '<th>' . htmlspecialchars($column['label']) . '</th>';
    }
    $table .= '</tr></thead><tbody>';
    foreach ($rows as $row) {
        $table .= '<tr>';
        foreach ($visibleColumns as $column) {
            $table .= '<td>' . htmlspecialchars((string)($row[$column['key']] ?? '')) . '</td>';
        }
        $table .= '</tr>';
    }
    $table .= '</tbody></table>';
    $pdf->writeHTML($table, true, false, false, false, '');
    $pdf->Output('analysis_' . $requestedKey . '_' . date('Y-m-d') . '.pdf', 'D');
    exit;
}

$customers = [];
if (in_array('customer', $reportFilters, true)) {
    $source = $reportMeta['customer_source'] ?? 'orders';
    $joins = '';
    $extraWhere = '';
    if ($source === 'final_products') {
        $joins = "JOIN products source_product ON source_product.customer_id = c.id AND source_product.type = 'final'";
    } elseif ($source === 'open_orders') {
        $joins = 'JOIN orders source_order ON source_order.customer_id = c.id';
        $extraWhere = ' AND source_order.total_amount > source_order.paid_amount';
    } elseif ($source === 'returns') {
        $joins = 'JOIN orders source_order ON source_order.customer_id = c.id JOIN order_returns source_return ON source_return.order_id = source_order.id';
    } else {
        $joins = 'JOIN orders source_order ON source_order.customer_id = c.id';
    }
    $sql = "SELECT DISTINCT c.id, c.name
            FROM customers c
            LEFT JOIN factories f ON f.id = c.factory_id
            $joins
            WHERE $customerScope $extraWhere
            ORDER BY c.name";
    $result = $conn->query($sql);
    $customers = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

$vendors = [];
if (in_array('vendor', $reportFilters, true)) {
    $result = $conn->query("SELECT DISTINCT v.id, v.name
                            FROM vendors v
                            JOIN purchase_orders po ON po.vendor_id = v.id
                            ORDER BY v.name");
    $vendors = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

$categories = [];
$categoryType = in_array('category_final', $reportFilters, true)
    ? 'final'
    : (in_array('category_material', $reportFilters, true) ? 'material' : '');
if ($categoryType !== '') {
    $categories = analysisRunQuery(
        $conn,
        'SELECT DISTINCT cat.id, cat.name FROM categories cat JOIN products p ON p.category_id = cat.id WHERE p.type = ? ORDER BY cat.name',
        's',
        [$categoryType]
    );
}

$inventories = [];
if (in_array('inventory', $reportFilters, true)) {
    $result = $conn->query("SELECT i.id, i.name FROM inventories i WHERE i.is_active = 1 AND $inventoryScope ORDER BY i.name");
    $inventories = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

$optionNames = [
    'customer' => array_column($customers, 'name', 'id'),
    'vendor' => array_column($vendors, 'name', 'id'),
    'category' => array_column($categories, 'name', 'id'),
    'inventory' => array_column($inventories, 'name', 'id'),
];
$activeFilters = [];
if ($hasDateFilter) {
    if ($filters['date_from'] !== '') {
        $activeFilters[] = 'From ' . $filters['date_from'];
    }
    if ($filters['date_to'] !== '') {
        $activeFilters[] = 'To ' . $filters['date_to'];
    }
}
foreach (['customer', 'vendor', 'category', 'inventory'] as $filterName) {
    $value = $filters[$filterName . '_id'];
    if ($value > 0) {
        $activeFilters[] = ucfirst($filterName) . ': ' . ($optionNames[$filterName][$value] ?? 'Selected');
    }
}

$summaries = [];
foreach ($report['summaries'] ?? [] as $summary) {
    if (!analysisRequirementAllowed($summary['requires'] ?? null)) {
        continue;
    }
    if ($summary['key'] === '__row_count') {
        $summaries[] = [
            'label' => $summary['label'],
            'value' => analysisFormatValue(count($rows), $summary['format']),
        ];
        continue;
    }
    if (isset($summary['currency_key'])) {
        $totals = [];
        foreach ($rows as $row) {
            $currency = (string)($row[$summary['currency_key']] ?? 'EGP');
            $totals[$currency] = ($totals[$currency] ?? 0) + (float)($row[$summary['key']] ?? 0);
        }
        foreach ($totals as $currency => $total) {
            $summaries[] = [
                'label' => $summary['label'] . ' (' . $currency . ')',
                'value' => analysisFormatValue($total, $summary['format'], ['currency' => $currency]),
            ];
        }
        continue;
    }
    $total = 0;
    foreach ($rows as $row) {
        $total += (float)($row[$summary['key']] ?? 0);
    }
    $summaries[] = [
        'label' => $summary['label'],
        'value' => analysisFormatValue($total, $summary['format']),
    ];
}

$chart = $report['chart'] ?? null;
$chartLabels = [];
$chartValues = [];
if ($chart && analysisRequirementAllowed($chart['requires'] ?? null)) {
    foreach (array_slice($rows, 0, 20) as $row) {
        $label = (string)($row[$chart['label_key']] ?? '');
        if (isset($row['currency']) && $chart['label_key'] === 'period') {
            $label .= ' (' . $row['currency'] . ')';
        }
        $chartLabels[] = $label;
        $chartValues[] = (float)($row[$chart['value_key']] ?? 0);
    }
}

$page_title = 'Reports - ' . $report['title'];
require_once '../../includes/header.php';
?>

<style>
    .report-summary-card { border-left: .25rem solid var(--bs-primary); }
    .report-chart-wrap { height: 320px; }
</style>

<main class="container mt-4 mb-5">
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb small mb-0">
            <li class="breadcrumb-item"><a href="index.php">Reports & Analytics</a></li>
            <li class="breadcrumb-item active" aria-current="page"><?= htmlspecialchars($report['title']) ?></li>
        </ol>
    </nav>

    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
            <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                <h2 class="mb-0"><?= htmlspecialchars($report['title']) ?></h2>
                <span class="badge text-bg-light border fw-normal"><?= htmlspecialchars($report['scope']) ?></span>
            </div>
            <p class="text-muted mb-0"><?= htmlspecialchars($report['description']) ?></p>
        </div>
        <div class="btn-group" role="group" aria-label="Report exports">
            <a class="btn btn-outline-secondary" href="index.php"><i class="fas fa-arrow-left me-1"></i>Back</a>
            <a class="btn btn-outline-primary" href="?<?= htmlspecialchars(http_build_query(array_merge($_GET, ['key' => $requestedKey, 'format' => 'csv']))) ?>">CSV</a>
            <a class="btn btn-outline-primary" href="?<?= htmlspecialchars(http_build_query(array_merge($_GET, ['key' => $requestedKey, 'format' => 'pdf']))) ?>">PDF</a>
        </div>
    </div>

    <?php if ($reportFilters): ?>
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span class="fw-semibold"><i class="fas fa-filter me-2"></i>Filters</span>
                <a class="btn btn-link btn-sm text-decoration-none" href="report.php?key=<?= urlencode($requestedKey) ?>">Reset</a>
            </div>
            <div class="card-body">
                <form method="get" class="row g-3 align-items-end">
                    <input type="hidden" name="key" value="<?= htmlspecialchars($requestedKey) ?>">
                    <?php if ($hasDateFilter): ?>
                        <div class="col-sm-6 col-lg-3">
                            <label class="form-label" for="date_from">From</label>
                            <input class="form-control" type="date" id="date_from" name="date_from" value="<?= htmlspecialchars($filters['date_from']) ?>">
                        </div>
                        <div class="col-sm-6 col-lg-3">
                            <label class="form-label" for="date_to">To</label>
                            <input class="form-control" type="date" id="date_to" name="date_to" value="<?= htmlspecialchars($filters['date_to']) ?>">
                        </div>
                    <?php endif; ?>
                    <?php if (in_array('customer', $reportFilters, true)): ?>
                        <div class="col-sm-6 col-lg-3">
                            <label class="form-label" for="customer_id">Customer</label>
                            <select class="form-select" id="customer_id" name="customer_id">
                                <option value="">All relevant customers</option>
                                <?php foreach ($customers as $customer): ?>
                                    <option value="<?= (int)$customer['id'] ?>" <?= $filters['customer_id'] === (int)$customer['id'] ? 'selected' : '' ?>><?= htmlspecialchars($customer['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>
                    <?php if (in_array('vendor', $reportFilters, true)): ?>
                        <div class="col-sm-6 col-lg-3">
                            <label class="form-label" for="vendor_id">Vendor</label>
                            <select class="form-select" id="vendor_id" name="vendor_id">
                                <option value="">All vendors with POs</option>
                                <?php foreach ($vendors as $vendor): ?>
                                    <option value="<?= (int)$vendor['id'] ?>" <?= $filters['vendor_id'] === (int)$vendor['id'] ? 'selected' : '' ?>><?= htmlspecialchars($vendor['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>
                    <?php if ($categoryType !== ''): ?>
                        <div class="col-sm-6 col-lg-3">
                            <label class="form-label" for="category_id"><?= $categoryType === 'final' ? 'Final-product category' : 'Raw-material category' ?></label>
                            <select class="form-select" id="category_id" name="category_id">
                                <option value="">All relevant categories</option>
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?= (int)$category['id'] ?>" <?= $filters['category_id'] === (int)$category['id'] ? 'selected' : '' ?>><?= htmlspecialchars($category['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>
                    <?php if (in_array('inventory', $reportFilters, true)): ?>
                        <div class="col-sm-6 col-lg-3">
                            <label class="form-label" for="inventory_id">Inventory</label>
                            <select class="form-select" id="inventory_id" name="inventory_id">
                                <option value="">All active inventories</option>
                                <?php foreach ($inventories as $inventory): ?>
                                    <option value="<?= (int)$inventory['id'] ?>" <?= $filters['inventory_id'] === (int)$inventory['id'] ? 'selected' : '' ?>><?= htmlspecialchars($inventory['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>
                    <div class="col-sm-6 col-lg-3">
                        <button class="btn btn-primary w-100" type="submit">Apply filters</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($dateRangeError): ?>
        <div class="alert alert-danger">The start date must be before the end date.</div>
    <?php elseif ($queryError !== ''): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($queryError) ?></div>
    <?php else: ?>
        <?php if ($activeFilters): ?>
            <div class="d-flex flex-wrap gap-2 mb-3" aria-label="Active filters">
                <?php foreach ($activeFilters as $activeFilter): ?>
                    <span class="badge rounded-pill text-bg-light border fw-normal"><?= htmlspecialchars($activeFilter) ?></span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($summaries): ?>
            <div class="row g-3 mb-4">
                <?php foreach ($summaries as $summary): ?>
                    <div class="col-6 col-lg-3">
                        <div class="card report-summary-card h-100">
                            <div class="card-body py-3">
                                <div class="small text-muted mb-1"><?= htmlspecialchars($summary['label']) ?></div>
                                <div class="h5 mb-0"><?= $summary['value'] ?></div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($chartLabels): ?>
            <div class="card mb-4">
                <div class="card-header fw-semibold"><?= htmlspecialchars($chart['label']) ?></div>
                <div class="card-body report-chart-wrap">
                    <canvas id="analysisChart"></canvas>
                </div>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span class="fw-semibold">Report details</span>
                <span class="text-muted small"><?= number_format(count($rows)) ?> result<?= count($rows) === 1 ? '' : 's' ?></span>
            </div>
            <?php if (!$rows): ?>
                <div class="card-body text-center py-5">
                    <i class="fas fa-table-list fa-2x text-muted mb-3"></i>
                    <h5>No matching data</h5>
                    <p class="text-muted mb-0">Try a wider date range or clear the selected filter.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table js-datatable table-striped table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <?php foreach ($visibleColumns as $column): ?>
                                    <th><?= htmlspecialchars($column['label']) ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $row): ?>
                                <tr>
                                    <?php foreach ($visibleColumns as $column): ?>
                                        <td><?= analysisFormatCell($column, $row) ?></td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</main>

<?php if ($chartLabels): ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <script>
        (function () {
            const canvas = document.getElementById('analysisChart');
            if (!canvas) return;
            new Chart(canvas, {
                type: 'bar',
                data: {
                    labels: <?= json_encode($chartLabels, JSON_UNESCAPED_UNICODE) ?>,
                    datasets: [{
                        label: <?= json_encode($chart['label'], JSON_UNESCAPED_UNICODE) ?>,
                        data: <?= json_encode($chartValues) ?>,
                        backgroundColor: 'rgba(13, 110, 253, .65)',
                        borderColor: '#0d6efd',
                        borderWidth: 1,
                        borderRadius: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: { y: { beginAtZero: true } }
                }
            });
        })();
    </script>
<?php endif; ?>

<?php require_once '../../includes/footer.php'; ?>

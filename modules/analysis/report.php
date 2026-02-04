<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../config/database.php';

$reportKey = $_GET['key'] ?? '';
if ($reportKey === '') {
    setAlert('danger', 'Please select a report.');
    redirect('index.php');
}

function normalizeDate($value) {
    if (!$value) return '';
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
}

function runQuery($conn, $sql, $types, $params) {
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('Failed to prepare query.');
    }
    if ($types !== '' && !empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $rows;
}

function addDateFilters(&$where, &$types, &$params, $field, $from, $to) {
    if ($from !== '') {
        $where[] = "$field >= ?";
        $types .= 's';
        $params[] = $from;
    }
    if ($to !== '') {
        $where[] = "$field <= ?";
        $types .= 's';
        $params[] = $to;
    }
}

function addIntFilter(&$where, &$types, &$params, $field, $value) {
    if ($value > 0) {
        $where[] = "$field = ?";
        $types .= 'i';
        $params[] = $value;
    }
}

function addTextFilter(&$where, &$types, &$params, $field, $value) {
    if ($value !== '') {
        $where[] = "$field = ?";
        $types .= 's';
        $params[] = $value;
    }
}

function formatMetricValue($value, $format) {
    if ($value === null || $value === '') {
        return 'N/A';
    }
    switch ($format) {
        case 'currency':
            return number_format((float)$value, 2);
        case 'number':
            return number_format((float)$value, 0);
        case 'percent':
            return number_format((float)$value, 2) . '%';
        default:
            return htmlspecialchars((string)$value);
    }
}

$filters = [
    'date_from' => normalizeDate($_GET['date_from'] ?? ''),
    'date_to' => normalizeDate($_GET['date_to'] ?? ''),
    'category_id' => isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0,
    'product_type' => in_array($_GET['product_type'] ?? '', ['final','material','primary'], true) ? $_GET['product_type'] : '',
    'customer_id' => isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0,
    'vendor_id' => isset($_GET['vendor_id']) ? (int)$_GET['vendor_id'] : 0,
];

$canViewFinalPrices = canViewProductPrice('final');
$canViewMaterialPrices = canViewProductPrice('material');
$canViewFinalCosts = canViewProductCost('final');
$canViewMaterialCosts = canViewProductCost('material');
$canViewAllPrices = $canViewFinalPrices && $canViewMaterialPrices;
$canViewAllCosts = $canViewFinalCosts && $canViewMaterialCosts;

$reports = [
    'sales_summary' => [
        'title' => 'Sales Summary',
        'description' => 'Monthly orders, sales, paid, and balance.',
        'columns' => [
            ['key' => 'period', 'label' => 'Period', 'format' => 'text'],
            ['key' => 'orders', 'label' => 'Orders', 'format' => 'number'],
            ['key' => 'total_sales', 'label' => 'Total Sales', 'format' => 'currency', 'requires' => 'prices'],
            ['key' => 'paid', 'label' => 'Paid', 'format' => 'currency', 'requires' => 'prices'],
            ['key' => 'balance', 'label' => 'Balance', 'format' => 'currency', 'requires' => 'prices'],
        ],
        'run' => function($conn, $filters) {
            $where = [];
            $types = '';
            $params = [];
            addDateFilters($where, $types, $params, 'o.order_date', $filters['date_from'], $filters['date_to']);
            addIntFilter($where, $types, $params, 'o.customer_id', $filters['customer_id']);
            $itemWhere = [];
            $itemTypes = '';
            $itemParams = [];
            addIntFilter($itemWhere, $itemTypes, $itemParams, 'p.category_id', $filters['category_id']);
            addTextFilter($itemWhere, $itemTypes, $itemParams, 'p.type', $filters['product_type']);
            if (!empty($itemWhere)) {
                $where[] = "EXISTS (
                    SELECT 1
                    FROM order_items oi
                    JOIN products p ON p.id = oi.product_id
                    WHERE oi.order_id = o.id AND " . implode(' AND ', $itemWhere) . "
                )";
                $types .= $itemTypes;
                $params = array_merge($params, $itemParams);
            }
            $sql = "SELECT DATE_FORMAT(o.order_date, '%Y-%m') AS period,
                           COUNT(*) AS orders,
                           SUM(o.total_amount) AS total_sales,
                           SUM(o.paid_amount) AS paid,
                           SUM(o.total_amount - o.paid_amount) AS balance
                    FROM orders o";
            if (!empty($where)) {
                $sql .= " WHERE " . implode(' AND ', $where);
            }
            $sql .= " GROUP BY DATE_FORMAT(o.order_date, '%Y-%m') ORDER BY period DESC";
            return runQuery($conn, $sql, $types, $params);
        }
    ],
    'gross_sales' => [
        'title' => 'Gross Sales',
        'description' => 'Total sales before discounts and returns.',
        'columns' => [
            ['key' => 'period', 'label' => 'Period', 'format' => 'text'],
            ['key' => 'orders', 'label' => 'Orders', 'format' => 'number'],
            ['key' => 'gross_sales', 'label' => 'Gross Sales', 'format' => 'currency', 'requires' => 'prices'],
        ],
        'run' => function($conn, $filters) {
            $where = [];
            $types = '';
            $params = [];
            addDateFilters($where, $types, $params, 'o.order_date', $filters['date_from'], $filters['date_to']);
            $sql = "SELECT DATE_FORMAT(o.order_date, '%Y-%m') AS period,
                           COUNT(DISTINCT o.id) AS orders,
                           SUM(oi.total_price) AS gross_sales
                    FROM order_items oi
                    JOIN orders o ON o.id = oi.order_id";
            if (!empty($where)) {
                $sql .= " WHERE " . implode(' AND ', $where);
            }
            $sql .= " GROUP BY DATE_FORMAT(o.order_date, '%Y-%m') ORDER BY period DESC";
            return runQuery($conn, $sql, $types, $params);
        }
    ],
    'net_sales' => [
        'title' => 'Net Sales',
        'description' => 'Sales after discounts and returns.',
        'columns' => [
            ['key' => 'period', 'label' => 'Period', 'format' => 'text'],
            ['key' => 'orders', 'label' => 'Orders', 'format' => 'number'],
            ['key' => 'net_sales', 'label' => 'Net Sales', 'format' => 'currency', 'requires' => 'prices'],
        ],
        'run' => function($conn, $filters) {
            $where = [];
            $types = '';
            $params = [];
            addDateFilters($where, $types, $params, 'o.order_date', $filters['date_from'], $filters['date_to']);
            $sql = "SELECT DATE_FORMAT(o.order_date, '%Y-%m') AS period,
                           COUNT(*) AS orders,
                           SUM(o.total_amount) AS net_sales
                    FROM orders o";
            if (!empty($where)) {
                $sql .= " WHERE " . implode(' AND ', $where);
            }
            $sql .= " GROUP BY DATE_FORMAT(o.order_date, '%Y-%m') ORDER BY period DESC";
            return runQuery($conn, $sql, $types, $params);
        }
    ],
    'sales_growth' => [
        'title' => 'Sales Growth',
        'description' => 'Period-over-period sales change.',
        'columns' => [
            ['key' => 'period', 'label' => 'Period', 'format' => 'text'],
            ['key' => 'total_sales', 'label' => 'Total Sales', 'format' => 'currency', 'requires' => 'prices'],
            ['key' => 'previous_sales', 'label' => 'Previous Period', 'format' => 'currency', 'requires' => 'prices'],
            ['key' => 'growth_pct', 'label' => 'Growth %', 'format' => 'number'],
        ],
        'run' => function($conn, $filters) {
            $where = [];
            $types = '';
            $params = [];
            addDateFilters($where, $types, $params, 'o.order_date', $filters['date_from'], $filters['date_to']);
            $baseSql = "SELECT DATE_FORMAT(o.order_date, '%Y-%m-01') AS period_start,
                               DATE_FORMAT(o.order_date, '%Y-%m') AS period,
                               SUM(o.total_amount) AS total_sales
                        FROM orders o";
            if (!empty($where)) {
                $baseSql .= " WHERE " . implode(' AND ', $where);
            }
            $baseSql .= " GROUP BY DATE_FORMAT(o.order_date, '%Y-%m-01')";
            $sql = "SELECT cur.period,
                           cur.total_sales,
                           prev.total_sales AS previous_sales,
                           CASE
                               WHEN prev.total_sales > 0
                               THEN ((cur.total_sales - prev.total_sales) / prev.total_sales) * 100
                               ELSE NULL
                           END AS growth_pct
                    FROM ($baseSql) cur
                    LEFT JOIN ($baseSql) prev
                      ON prev.period_start = DATE_SUB(cur.period_start, INTERVAL 1 MONTH)
                    ORDER BY cur.period_start DESC";
            return runQuery($conn, $sql, $types, $params);
        }
    ],
    'average_transaction_value' => [
        'title' => 'Average Transaction Value (ATV)',
        'description' => 'Average order value per transaction.',
        'columns' => [
            ['key' => 'period', 'label' => 'Period', 'format' => 'text'],
            ['key' => 'orders', 'label' => 'Orders', 'format' => 'number'],
            ['key' => 'avg_value', 'label' => 'Avg Transaction', 'format' => 'currency', 'requires' => 'prices'],
        ],
        'run' => function($conn, $filters) {
            $where = [];
            $types = '';
            $params = [];
            addDateFilters($where, $types, $params, 'o.order_date', $filters['date_from'], $filters['date_to']);
            $sql = "SELECT DATE_FORMAT(o.order_date, '%Y-%m') AS period,
                           COUNT(*) AS orders,
                           AVG(o.total_amount) AS avg_value
                    FROM orders o";
            if (!empty($where)) {
                $sql .= " WHERE " . implode(' AND ', $where);
            }
            $sql .= " GROUP BY DATE_FORMAT(o.order_date, '%Y-%m') ORDER BY period DESC";
            return runQuery($conn, $sql, $types, $params);
        }
    ],
    'items_per_transaction' => [
        'title' => 'Items per Transaction (IPT)',
        'description' => 'Average items per order.',
        'columns' => [
            ['key' => 'period', 'label' => 'Period', 'format' => 'text'],
            ['key' => 'orders', 'label' => 'Orders', 'format' => 'number'],
            ['key' => 'total_items', 'label' => 'Items', 'format' => 'number'],
            ['key' => 'avg_items', 'label' => 'Avg Items', 'format' => 'number'],
        ],
        'run' => function($conn, $filters) {
            $where = [];
            $types = '';
            $params = [];
            addDateFilters($where, $types, $params, 'o.order_date', $filters['date_from'], $filters['date_to']);
            $sql = "SELECT DATE_FORMAT(o.order_date, '%Y-%m') AS period,
                           COUNT(DISTINCT o.id) AS orders,
                           SUM(oi.quantity) AS total_items,
                           CASE
                               WHEN COUNT(DISTINCT o.id) > 0
                               THEN SUM(oi.quantity) / COUNT(DISTINCT o.id)
                               ELSE 0
                           END AS avg_items
                    FROM order_items oi
                    JOIN orders o ON o.id = oi.order_id";
            if (!empty($where)) {
                $sql .= " WHERE " . implode(' AND ', $where);
            }
            $sql .= " GROUP BY DATE_FORMAT(o.order_date, '%Y-%m') ORDER BY period DESC";
            return runQuery($conn, $sql, $types, $params);
        }
    ],
    'revenue_per_hour' => [
        'title' => 'Revenue per Hour',
        'description' => 'Hourly sales performance.',
        'columns' => [
            ['key' => 'hour', 'label' => 'Hour', 'format' => 'text'],
            ['key' => 'orders', 'label' => 'Orders', 'format' => 'number'],
            ['key' => 'total_sales', 'label' => 'Total Sales', 'format' => 'currency', 'requires' => 'prices'],
        ],
        'run' => function($conn, $filters) {
            $where = [];
            $types = '';
            $params = [];
            addDateFilters($where, $types, $params, 'DATE(o.created_at)', $filters['date_from'], $filters['date_to']);
            $sql = "SELECT DATE_FORMAT(o.created_at, '%Y-%m-%d %H:00') AS hour,
                           COUNT(*) AS orders,
                           SUM(o.total_amount) AS total_sales
                    FROM orders o";
            if (!empty($where)) {
                $sql .= " WHERE " . implode(' AND ', $where);
            }
            $sql .= " GROUP BY DATE_FORMAT(o.created_at, '%Y-%m-%d %H:00') ORDER BY hour DESC";
            return runQuery($conn, $sql, $types, $params);
        }
    ],
    'sales_per_day' => [
        'title' => 'Sales per Day',
        'description' => 'Daily sales totals.',
        'columns' => [
            ['key' => 'day', 'label' => 'Day', 'format' => 'text'],
            ['key' => 'orders', 'label' => 'Orders', 'format' => 'number'],
            ['key' => 'total_sales', 'label' => 'Total Sales', 'format' => 'currency', 'requires' => 'prices'],
        ],
        'run' => function($conn, $filters) {
            $where = [];
            $types = '';
            $params = [];
            addDateFilters($where, $types, $params, 'o.order_date', $filters['date_from'], $filters['date_to']);
            $sql = "SELECT o.order_date AS day,
                           COUNT(*) AS orders,
                           SUM(o.total_amount) AS total_sales
                    FROM orders o";
            if (!empty($where)) {
                $sql .= " WHERE " . implode(' AND ', $where);
            }
            $sql .= " GROUP BY o.order_date ORDER BY day DESC";
            return runQuery($conn, $sql, $types, $params);
        }
    ],
    'sales_by_product' => [
        'title' => 'Sales by Product',
        'description' => 'Top products by quantity and revenue.',
        'columns' => [
            ['key' => 'sku', 'label' => 'SKU', 'format' => 'text'],
            ['key' => 'product_name', 'label' => 'Product', 'format' => 'text'],
            ['key' => 'category_name', 'label' => 'Category', 'format' => 'text'],
            ['key' => 'quantity', 'label' => 'Qty Sold', 'format' => 'number'],
            ['key' => 'revenue', 'label' => 'Revenue', 'format' => 'currency', 'requires' => 'prices'],
            ['key' => 'avg_price', 'label' => 'Avg Price', 'format' => 'currency', 'requires' => 'prices'],
        ],
        'run' => function($conn, $filters) {
            $where = [];
            $types = '';
            $params = [];
            addDateFilters($where, $types, $params, 'o.order_date', $filters['date_from'], $filters['date_to']);
            addIntFilter($where, $types, $params, 'p.category_id', $filters['category_id']);
            addTextFilter($where, $types, $params, 'p.type', $filters['product_type']);
            $sql = "SELECT p.sku, p.name AS product_name, c.name AS category_name,
                           SUM(oi.quantity) AS quantity,
                           SUM(oi.total_price) AS revenue,
                           AVG(oi.unit_price) AS avg_price
                    FROM order_items oi
                    JOIN orders o ON o.id = oi.order_id
                    JOIN products p ON p.id = oi.product_id
                    LEFT JOIN categories c ON c.id = p.category_id";
            if (!empty($where)) {
                $sql .= " WHERE " . implode(' AND ', $where);
            }
            $sql .= " GROUP BY p.id ORDER BY revenue DESC LIMIT 50";
            return runQuery($conn, $sql, $types, $params);
        }
    ],
    'discount_rate' => [
        'title' => 'Discount Rate',
        'description' => 'Average discount percentage applied.',
        'columns' => [
            ['key' => 'period', 'label' => 'Period', 'format' => 'text'],
            ['key' => 'orders', 'label' => 'Orders', 'format' => 'number'],
            ['key' => 'avg_discount_pct', 'label' => 'Avg Discount %', 'format' => 'number'],
            ['key' => 'total_discount', 'label' => 'Total Discount', 'format' => 'currency', 'requires' => 'prices'],
        ],
        'run' => function($conn, $filters) {
            $where = [];
            $types = '';
            $params = [];
            addDateFilters($where, $types, $params, 'o.order_date', $filters['date_from'], $filters['date_to']);
            $sql = "SELECT DATE_FORMAT(o.order_date, '%Y-%m') AS period,
                           COUNT(*) AS orders,
                           AVG(o.discount_percentage) AS avg_discount_pct,
                           SUM(o.discount_amount) AS total_discount
                    FROM orders o";
            if (!empty($where)) {
                $sql .= " WHERE " . implode(' AND ', $where);
            }
            $sql .= " GROUP BY DATE_FORMAT(o.order_date, '%Y-%m') ORDER BY period DESC";
            return runQuery($conn, $sql, $types, $params);
        }
    ],
    'discount_impact' => [
        'title' => 'Discount Impact',
        'description' => 'Discount value and effect on revenue.',
        'columns' => [
            ['key' => 'period', 'label' => 'Period', 'format' => 'text'],
            ['key' => 'gross_sales', 'label' => 'Gross Sales', 'format' => 'currency', 'requires' => 'prices'],
            ['key' => 'total_discount', 'label' => 'Total Discount', 'format' => 'currency', 'requires' => 'prices'],
            ['key' => 'net_sales', 'label' => 'Net Sales', 'format' => 'currency', 'requires' => 'prices'],
        ],
        'run' => function($conn, $filters) {
            $where = [];
            $types = '';
            $params = [];
            addDateFilters($where, $types, $params, 'o.order_date', $filters['date_from'], $filters['date_to']);
            $sql = "SELECT DATE_FORMAT(o.order_date, '%Y-%m') AS period,
                           SUM(oi.total_price) AS gross_sales,
                           SUM(o.discount_amount) AS total_discount,
                           SUM(o.total_amount) AS net_sales
                    FROM orders o
                    JOIN order_items oi ON oi.order_id = o.id";
            if (!empty($where)) {
                $sql .= " WHERE " . implode(' AND ', $where);
            }
            $sql .= " GROUP BY DATE_FORMAT(o.order_date, '%Y-%m') ORDER BY period DESC";
            return runQuery($conn, $sql, $types, $params);
        }
    ],
    'total_sales_today' => [
        'title' => 'Total Sales Today',
        'description' => 'Today\'s sales total.',
        'columns' => [
            ['key' => 'day', 'label' => 'Day', 'format' => 'text'],
            ['key' => 'orders', 'label' => 'Orders', 'format' => 'number'],
            ['key' => 'total_sales', 'label' => 'Total Sales', 'format' => 'currency', 'requires' => 'prices'],
        ],
        'run' => function($conn, $filters) {
            $where = [];
            $types = '';
            $params = [];
            if ($filters['date_from'] === '' && $filters['date_to'] === '') {
                $where[] = "o.order_date = CURDATE()";
            } else {
                addDateFilters($where, $types, $params, 'o.order_date', $filters['date_from'], $filters['date_to']);
            }
            $sql = "SELECT o.order_date AS day,
                           COUNT(*) AS orders,
                           SUM(o.total_amount) AS total_sales
                    FROM orders o";
            if (!empty($where)) {
                $sql .= " WHERE " . implode(' AND ', $where);
            }
            $sql .= " GROUP BY o.order_date ORDER BY day DESC";
            return runQuery($conn, $sql, $types, $params);
        }
    ],
    'total_sales_this_month' => [
        'title' => 'Total Sales This Month',
        'description' => 'Month-to-date sales total.',
        'columns' => [
            ['key' => 'period', 'label' => 'Period', 'format' => 'text'],
            ['key' => 'orders', 'label' => 'Orders', 'format' => 'number'],
            ['key' => 'total_sales', 'label' => 'Total Sales', 'format' => 'currency', 'requires' => 'prices'],
        ],
        'run' => function($conn, $filters) {
            $where = [];
            $types = '';
            $params = [];
            if ($filters['date_from'] === '' && $filters['date_to'] === '') {
                $where[] = "o.order_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')";
                $where[] = "o.order_date <= LAST_DAY(CURDATE())";
            } else {
                addDateFilters($where, $types, $params, 'o.order_date', $filters['date_from'], $filters['date_to']);
            }
            $sql = "SELECT DATE_FORMAT(o.order_date, '%Y-%m') AS period,
                           COUNT(*) AS orders,
                           SUM(o.total_amount) AS total_sales
                    FROM orders o";
            if (!empty($where)) {
                $sql .= " WHERE " . implode(' AND ', $where);
            }
            $sql .= " GROUP BY DATE_FORMAT(o.order_date, '%Y-%m') ORDER BY period DESC";
            return runQuery($conn, $sql, $types, $params);
        }
    ],
    'top_products_overview' => [
        'title' => 'Top Products Overview',
        'description' => 'Best-selling products snapshot.',
        'columns' => [
            ['key' => 'product_name', 'label' => 'Product', 'format' => 'text'],
            ['key' => 'quantity', 'label' => 'Qty Sold', 'format' => 'number'],
            ['key' => 'revenue', 'label' => 'Revenue', 'format' => 'currency', 'requires' => 'prices'],
        ],
        'run' => function($conn, $filters) {
            $where = [];
            $types = '';
            $params = [];
            addDateFilters($where, $types, $params, 'o.order_date', $filters['date_from'], $filters['date_to']);
            $sql = "SELECT p.name AS product_name,
                           SUM(oi.quantity) AS quantity,
                           SUM(oi.total_price) AS revenue
                    FROM order_items oi
                    JOIN orders o ON o.id = oi.order_id
                    JOIN products p ON p.id = oi.product_id";
            if (!empty($where)) {
                $sql .= " WHERE " . implode(' AND ', $where);
            }
            $sql .= " GROUP BY p.id ORDER BY revenue DESC LIMIT 10";
            return runQuery($conn, $sql, $types, $params);
        }
    ],
    'top_categories_overview' => [
        'title' => 'Top Categories Overview',
        'description' => 'Best-performing categories snapshot.',
        'columns' => [
            ['key' => 'category_name', 'label' => 'Category', 'format' => 'text'],
            ['key' => 'quantity', 'label' => 'Qty Sold', 'format' => 'number'],
            ['key' => 'revenue', 'label' => 'Revenue', 'format' => 'currency', 'requires' => 'prices'],
        ],
        'run' => function($conn, $filters) {
            $where = [];
            $types = '';
            $params = [];
            addDateFilters($where, $types, $params, 'o.order_date', $filters['date_from'], $filters['date_to']);
            $sql = "SELECT c.name AS category_name,
                           SUM(oi.quantity) AS quantity,
                           SUM(oi.total_price) AS revenue
                    FROM order_items oi
                    JOIN orders o ON o.id = oi.order_id
                    JOIN products p ON p.id = oi.product_id
                    LEFT JOIN categories c ON c.id = p.category_id";
            if (!empty($where)) {
                $sql .= " WHERE " . implode(' AND ', $where);
            }
            $sql .= " GROUP BY c.id ORDER BY revenue DESC LIMIT 10";
            return runQuery($conn, $sql, $types, $params);
        }
    ],
    'customer_lifetime_value' => [
        'title' => 'Customer Lifetime Value (CLV)',
        'description' => 'Total value per customer over time.',
        'columns' => [
            ['key' => 'customer_name', 'label' => 'Customer', 'format' => 'text'],
            ['key' => 'orders', 'label' => 'Orders', 'format' => 'number'],
            ['key' => 'total_sales', 'label' => 'Total Sales', 'format' => 'currency', 'requires' => 'prices'],
        ],
        'run' => function($conn, $filters) {
            $where = [];
            $types = '';
            $params = [];
            addDateFilters($where, $types, $params, 'o.order_date', $filters['date_from'], $filters['date_to']);
            addIntFilter($where, $types, $params, 'o.customer_id', $filters['customer_id']);
            $sql = "SELECT c.name AS customer_name,
                           COUNT(*) AS orders,
                           SUM(o.total_amount) AS total_sales
                    FROM orders o
                    JOIN customers c ON c.id = o.customer_id";
            if (!empty($where)) {
                $sql .= " WHERE " . implode(' AND ', $where);
            }
            $sql .= " GROUP BY o.customer_id ORDER BY total_sales DESC";
            return runQuery($conn, $sql, $types, $params);
        }
    ],
    'repeat_purchase_rate' => [
        'title' => 'Repeat Purchase Rate',
        'description' => 'Share of customers who buy again.',
        'columns' => [
            ['key' => 'total_customers', 'label' => 'Customers', 'format' => 'number'],
            ['key' => 'repeat_customers', 'label' => 'Repeat Customers', 'format' => 'number'],
            ['key' => 'repeat_rate', 'label' => 'Repeat Rate %', 'format' => 'number'],
        ],
        'run' => function($conn, $filters) {
            $where = [];
            $types = '';
            $params = [];
            addDateFilters($where, $types, $params, 'o.order_date', $filters['date_from'], $filters['date_to']);
            $sql = "SELECT COUNT(*) AS total_customers,
                           SUM(CASE WHEN order_count > 1 THEN 1 ELSE 0 END) AS repeat_customers,
                           CASE
                               WHEN COUNT(*) > 0
                               THEN (SUM(CASE WHEN order_count > 1 THEN 1 ELSE 0 END) / COUNT(*)) * 100
                               ELSE 0
                           END AS repeat_rate
                    FROM (
                        SELECT o.customer_id, COUNT(*) AS order_count
                        FROM orders o";
            if (!empty($where)) {
                $sql .= " WHERE " . implode(' AND ', $where);
            }
            $sql .= " GROUP BY o.customer_id
                    ) t";
            return runQuery($conn, $sql, $types, $params);
        }
    ],
    'customer_purchase_frequency' => [
        'title' => 'Customer Purchase Frequency',
        'description' => 'Average orders per customer.',
        'columns' => [
            ['key' => 'total_customers', 'label' => 'Customers', 'format' => 'number'],
            ['key' => 'total_orders', 'label' => 'Orders', 'format' => 'number'],
            ['key' => 'avg_orders_per_customer', 'label' => 'Avg Orders', 'format' => 'number'],
        ],
        'run' => function($conn, $filters) {
            $where = [];
            $types = '';
            $params = [];
            addDateFilters($where, $types, $params, 'o.order_date', $filters['date_from'], $filters['date_to']);
            $sql = "SELECT COUNT(DISTINCT o.customer_id) AS total_customers,
                           COUNT(*) AS total_orders,
                           CASE
                               WHEN COUNT(DISTINCT o.customer_id) > 0
                               THEN COUNT(*) / COUNT(DISTINCT o.customer_id)
                               ELSE 0
                           END AS avg_orders_per_customer
                    FROM orders o";
            if (!empty($where)) {
                $sql .= " WHERE " . implode(' AND ', $where);
            }
            return runQuery($conn, $sql, $types, $params);
        }
    ],
    'average_basket_size' => [
        'title' => 'Average Basket Size',
        'description' => 'Average items per order.',
        'columns' => [
            ['key' => 'total_orders', 'label' => 'Orders', 'format' => 'number'],
            ['key' => 'total_items', 'label' => 'Items', 'format' => 'number'],
            ['key' => 'avg_items_per_order', 'label' => 'Avg Items', 'format' => 'number'],
        ],
        'run' => function($conn, $filters) {
            $where = [];
            $types = '';
            $params = [];
            addDateFilters($where, $types, $params, 'o.order_date', $filters['date_from'], $filters['date_to']);
            $sql = "SELECT COUNT(DISTINCT o.id) AS total_orders,
                           SUM(oi.quantity) AS total_items,
                           CASE
                               WHEN COUNT(DISTINCT o.id) > 0
                               THEN SUM(oi.quantity) / COUNT(DISTINCT o.id)
                               ELSE 0
                           END AS avg_items_per_order
                    FROM order_items oi
                    JOIN orders o ON o.id = oi.order_id";
            if (!empty($where)) {
                $sql .= " WHERE " . implode(' AND ', $where);
            }
            return runQuery($conn, $sql, $types, $params);
        }
    ],
    'new_vs_returning_customers' => [
        'title' => 'New vs Returning Customers',
        'description' => 'Sales split by new and returning.',
        'columns' => [
            ['key' => 'segment', 'label' => 'Segment', 'format' => 'text'],
            ['key' => 'customers', 'label' => 'Customers', 'format' => 'number'],
            ['key' => 'orders', 'label' => 'Orders', 'format' => 'number'],
            ['key' => 'total_sales', 'label' => 'Total Sales', 'format' => 'currency', 'requires' => 'prices'],
        ],
        'run' => function($conn, $filters) {
            $from = $filters['date_from'] !== '' ? $filters['date_from'] : date('Y-m-01');
            $to = $filters['date_to'] !== '' ? $filters['date_to'] : date('Y-m-t');
            $types = 'ss';
            $params = [$from, $to];
            $sql = "SELECT segment,
                           COUNT(DISTINCT customer_id) AS customers,
                           COUNT(*) AS orders,
                           SUM(total_amount) AS total_sales
                    FROM (
                        SELECT o.customer_id,
                               o.total_amount,
                               CASE
                                   WHEN first_order_date BETWEEN ? AND ? THEN 'New'
                                   ELSE 'Returning'
                               END AS segment
                        FROM orders o
                        JOIN (
                            SELECT customer_id, MIN(order_date) AS first_order_date
                            FROM orders
                            GROUP BY customer_id
                        ) f ON f.customer_id = o.customer_id
                        WHERE o.order_date BETWEEN ? AND ?
                    ) t
                    GROUP BY segment";
            $types .= 'ss';
            $params[] = $from;
            $params[] = $to;
            return runQuery($conn, $sql, $types, $params);
        }
    ],
    'inactive_customers' => [
        'title' => 'Inactive Customers',
        'description' => 'Customers without recent orders.',
        'columns' => [
            ['key' => 'customer_name', 'label' => 'Customer', 'format' => 'text'],
            ['key' => 'last_order_date', 'label' => 'Last Order', 'format' => 'text'],
            ['key' => 'days_since_last', 'label' => 'Days Since Last', 'format' => 'number'],
        ],
        'run' => function($conn, $filters) {
            $referenceDate = $filters['date_to'] !== '' ? $filters['date_to'] : date('Y-m-d');
            $types = 'ss';
            $params = [$referenceDate, $referenceDate];
            $sql = "SELECT c.name AS customer_name,
                           MAX(o.order_date) AS last_order_date,
                           DATEDIFF(?, MAX(o.order_date)) AS days_since_last
                    FROM customers c
                    LEFT JOIN orders o ON o.customer_id = c.id
                    GROUP BY c.id
                    HAVING last_order_date IS NULL OR last_order_date < DATE_SUB(?, INTERVAL 90 DAY)
                    ORDER BY last_order_date IS NULL DESC, last_order_date ASC";
            return runQuery($conn, $sql, $types, $params);
        }
    ],
    'top_customers' => [
        'title' => 'Top Customers',
        'description' => 'Customers ranked by total sales.',
        'columns' => [
            ['key' => 'customer_name', 'label' => 'Customer', 'format' => 'text'],
            ['key' => 'orders', 'label' => 'Orders', 'format' => 'number'],
            ['key' => 'total_sales', 'label' => 'Total Sales', 'format' => 'currency', 'requires' => 'prices'],
            ['key' => 'paid', 'label' => 'Paid', 'format' => 'currency', 'requires' => 'prices'],
            ['key' => 'balance', 'label' => 'Balance', 'format' => 'currency', 'requires' => 'prices'],
        ],
        'run' => function($conn, $filters) {
            $where = [];
            $types = '';
            $params = [];
            addDateFilters($where, $types, $params, 'o.order_date', $filters['date_from'], $filters['date_to']);
            addIntFilter($where, $types, $params, 'o.customer_id', $filters['customer_id']);
            $sql = "SELECT c.name AS customer_name,
                           COUNT(*) AS orders,
                           SUM(o.total_amount) AS total_sales,
                           SUM(o.paid_amount) AS paid,
                           SUM(o.total_amount - o.paid_amount) AS balance
                    FROM orders o
                    JOIN customers c ON c.id = o.customer_id";
            if (!empty($where)) {
                $sql .= " WHERE " . implode(' AND ', $where);
            }
            $sql .= " GROUP BY o.customer_id ORDER BY total_sales DESC LIMIT 50";
            return runQuery($conn, $sql, $types, $params);
        }
    ],
    'cash_sales' => [
        'title' => 'Cash Sales',
        'description' => 'Sales paid by cash.',
        'columns' => [
            ['key' => 'period', 'label' => 'Period', 'format' => 'text'],
            ['key' => 'transactions', 'label' => 'Transactions', 'format' => 'number'],
            ['key' => 'total_amount', 'label' => 'Total Cash', 'format' => 'currency', 'requires' => 'prices'],
        ],
        'run' => function($conn, $filters) {
            $where = ["op.payment_method = 'cash'"];
            $types = '';
            $params = [];
            addDateFilters($where, $types, $params, 'o.order_date', $filters['date_from'], $filters['date_to']);
            $sql = "SELECT DATE_FORMAT(o.order_date, '%Y-%m') AS period,
                           COUNT(*) AS transactions,
                           SUM(op.amount) AS total_amount
                    FROM order_payments op
                    JOIN orders o ON o.id = op.order_id";
            if (!empty($where)) {
                $sql .= " WHERE " . implode(' AND ', $where);
            }
            $sql .= " GROUP BY DATE_FORMAT(o.order_date, '%Y-%m') ORDER BY period DESC";
            return runQuery($conn, $sql, $types, $params);
        }
    ],
    'credit_sales' => [
        'title' => 'Credit Sales',
        'description' => 'Sales on credit or unpaid balance.',
        'columns' => [
            ['key' => 'period', 'label' => 'Period', 'format' => 'text'],
            ['key' => 'orders', 'label' => 'Orders', 'format' => 'number'],
            ['key' => 'credit_amount', 'label' => 'Credit Amount', 'format' => 'currency', 'requires' => 'prices'],
        ],
        'run' => function($conn, $filters) {
            $where = ["(o.total_amount - o.paid_amount) > 0"];
            $types = '';
            $params = [];
            addDateFilters($where, $types, $params, 'o.order_date', $filters['date_from'], $filters['date_to']);
            $sql = "SELECT DATE_FORMAT(o.order_date, '%Y-%m') AS period,
                           COUNT(*) AS orders,
                           SUM(o.total_amount - o.paid_amount) AS credit_amount
                    FROM orders o";
            if (!empty($where)) {
                $sql .= " WHERE " . implode(' AND ', $where);
            }
            $sql .= " GROUP BY DATE_FORMAT(o.order_date, '%Y-%m') ORDER BY period DESC";
            return runQuery($conn, $sql, $types, $params);
        }
    ],
    'accounts_receivable' => [
        'title' => 'Accounts Receivable',
        'description' => 'Open customer balances.',
        'columns' => [
            ['key' => 'customer_name', 'label' => 'Customer', 'format' => 'text'],
            ['key' => 'balance', 'label' => 'Balance', 'format' => 'currency', 'requires' => 'prices'],
        ],
        'run' => function($conn, $filters) {
            $where = ["(o.total_amount - o.paid_amount) > 0"];
            $types = '';
            $params = [];
            addDateFilters($where, $types, $params, 'o.order_date', $filters['date_from'], $filters['date_to']);
            addIntFilter($where, $types, $params, 'o.customer_id', $filters['customer_id']);
            $sql = "SELECT c.name AS customer_name,
                           SUM(o.total_amount - o.paid_amount) AS balance
                    FROM orders o
                    JOIN customers c ON c.id = o.customer_id";
            if (!empty($where)) {
                $sql .= " WHERE " . implode(' AND ', $where);
            }
            $sql .= " GROUP BY o.customer_id ORDER BY balance DESC";
            return runQuery($conn, $sql, $types, $params);
        }
    ],
    'accounts_payable' => [
        'title' => 'Accounts Payable',
        'description' => 'Open vendor balances.',
        'columns' => [
            ['key' => 'vendor_name', 'label' => 'Vendor', 'format' => 'text'],
            ['key' => 'balance', 'label' => 'Balance', 'format' => 'currency'],
        ],
        'run' => function($conn, $filters) {
            $where = ["(po.total_amount - po.paid_amount) > 0"];
            $types = '';
            $params = [];
            addDateFilters($where, $types, $params, 'po.order_date', $filters['date_from'], $filters['date_to']);
            addIntFilter($where, $types, $params, 'po.vendor_id', $filters['vendor_id']);
            $sql = "SELECT v.name AS vendor_name,
                           SUM(po.total_amount - po.paid_amount) AS balance
                    FROM purchase_orders po
                    JOIN vendors v ON v.id = po.vendor_id";
            if (!empty($where)) {
                $sql .= " WHERE " . implode(' AND ', $where);
            }
            $sql .= " GROUP BY po.vendor_id ORDER BY balance DESC";
            return runQuery($conn, $sql, $types, $params);
        }
    ],
    'customer_balances' => [
        'title' => 'Customer Wallets',
        'description' => 'Wallet balances by customer.',
        'columns' => [
            ['key' => 'customer_name', 'label' => 'Customer', 'format' => 'text'],
            ['key' => 'wallet_balance', 'label' => 'Wallet Balance', 'format' => 'currency'],
        ],
        'run' => function($conn, $filters) {
            $where = [];
            $types = '';
            $params = [];
            addIntFilter($where, $types, $params, 'c.id', $filters['customer_id']);
            $sql = "SELECT c.name AS customer_name, c.wallet_balance
                    FROM customers c";
            if (!empty($where)) {
                $sql .= " WHERE " . implode(' AND ', $where);
            }
            $sql .= " ORDER BY c.wallet_balance DESC, c.name";
            return runQuery($conn, $sql, $types, $params);
        }
    ],
    'inventory_levels' => [
        'title' => 'Inventory Levels',
        'description' => 'Stock by inventory and product.',
        'columns' => [
            ['key' => 'inventory_name', 'label' => 'Inventory', 'format' => 'text'],
            ['key' => 'product_name', 'label' => 'Product', 'format' => 'text'],
            ['key' => 'sku', 'label' => 'SKU', 'format' => 'text'],
            ['key' => 'category_name', 'label' => 'Category', 'format' => 'text'],
            ['key' => 'quantity', 'label' => 'Quantity', 'format' => 'number'],
        ],
        'run' => function($conn, $filters) {
            $where = [];
            $types = '';
            $params = [];
            addIntFilter($where, $types, $params, 'p.category_id', $filters['category_id']);
            addTextFilter($where, $types, $params, 'p.type', $filters['product_type']);
            $sql = "SELECT i.name AS inventory_name, p.name AS product_name, p.sku,
                           c.name AS category_name, ip.quantity
                    FROM inventory_products ip
                    JOIN inventories i ON i.id = ip.inventory_id
                    JOIN products p ON p.id = ip.product_id
                    LEFT JOIN categories c ON c.id = p.category_id";
            if (!empty($where)) {
                $sql .= " WHERE " . implode(' AND ', $where);
            }
            $sql .= " ORDER BY i.name, p.name";
            return runQuery($conn, $sql, $types, $params);
        }
    ],
    'low_stock' => [
        'title' => 'Low Stock',
        'description' => 'Products below minimum stock.',
        'columns' => [
            ['key' => 'product_name', 'label' => 'Product', 'format' => 'text'],
            ['key' => 'sku', 'label' => 'SKU', 'format' => 'text'],
            ['key' => 'category_name', 'label' => 'Category', 'format' => 'text'],
            ['key' => 'min_stock_level', 'label' => 'Min Stock', 'format' => 'number'],
            ['key' => 'total_qty', 'label' => 'Total Qty', 'format' => 'number'],
        ],
        'run' => function($conn, $filters) {
            $where = ["p.min_stock_level > 0"];
            $types = '';
            $params = [];
            addIntFilter($where, $types, $params, 'p.category_id', $filters['category_id']);
            addTextFilter($where, $types, $params, 'p.type', $filters['product_type']);
            $sql = "SELECT p.name AS product_name, p.sku, c.name AS category_name,
                           p.min_stock_level,
                           COALESCE(SUM(ip.quantity), 0) AS total_qty
                    FROM products p
                    LEFT JOIN inventory_products ip ON ip.product_id = p.id
                    LEFT JOIN categories c ON c.id = p.category_id";
            if (!empty($where)) {
                $sql .= " WHERE " . implode(' AND ', $where);
            }
            $sql .= " GROUP BY p.id HAVING COALESCE(SUM(ip.quantity), 0) < p.min_stock_level
                      ORDER BY total_qty ASC";
            return runQuery($conn, $sql, $types, $params);
        }
    ],
    'purchase_summary' => [
        'title' => 'Purchase Summary',
        'description' => 'Monthly PO totals and balances.',
        'columns' => [
            ['key' => 'period', 'label' => 'Period', 'format' => 'text'],
            ['key' => 'orders', 'label' => 'PO Count', 'format' => 'number'],
            ['key' => 'total_amount', 'label' => 'Total', 'format' => 'currency'],
            ['key' => 'paid', 'label' => 'Paid', 'format' => 'currency'],
            ['key' => 'balance', 'label' => 'Balance', 'format' => 'currency'],
        ],
        'run' => function($conn, $filters) {
            $where = [];
            $types = '';
            $params = [];
            addDateFilters($where, $types, $params, 'po.order_date', $filters['date_from'], $filters['date_to']);
            addIntFilter($where, $types, $params, 'po.vendor_id', $filters['vendor_id']);
            $sql = "SELECT DATE_FORMAT(po.order_date, '%Y-%m') AS period,
                           COUNT(*) AS orders,
                           SUM(po.total_amount) AS total_amount,
                           SUM(po.paid_amount) AS paid,
                           SUM(po.total_amount - po.paid_amount) AS balance
                    FROM purchase_orders po";
            if (!empty($where)) {
                $sql .= " WHERE " . implode(' AND ', $where);
            }
            $sql .= " GROUP BY DATE_FORMAT(po.order_date, '%Y-%m') ORDER BY period DESC";
            return runQuery($conn, $sql, $types, $params);
        }
    ],
    'purchase_cost' => [
        'title' => 'Purchase Cost',
        'description' => 'Total cost of purchased goods.',
        'columns' => [
            ['key' => 'period', 'label' => 'Period', 'format' => 'text'],
            ['key' => 'total_cost', 'label' => 'Total Cost', 'format' => 'currency'],
        ],
        'run' => function($conn, $filters) {
            $where = [];
            $types = '';
            $params = [];
            addDateFilters($where, $types, $params, 'po.order_date', $filters['date_from'], $filters['date_to']);
            addIntFilter($where, $types, $params, 'po.vendor_id', $filters['vendor_id']);
            $sql = "SELECT DATE_FORMAT(po.order_date, '%Y-%m') AS period,
                           SUM(poi.total_price) AS total_cost
                    FROM purchase_order_items poi
                    JOIN purchase_orders po ON po.id = poi.purchase_order_id";
            if (!empty($where)) {
                $sql .= " WHERE " . implode(' AND ', $where);
            }
            $sql .= " GROUP BY DATE_FORMAT(po.order_date, '%Y-%m') ORDER BY period DESC";
            return runQuery($conn, $sql, $types, $params);
        }
    ],
    'purchase_volume' => [
        'title' => 'Purchase Volume',
        'description' => 'Quantity purchased over time.',
        'columns' => [
            ['key' => 'period', 'label' => 'Period', 'format' => 'text'],
            ['key' => 'total_quantity', 'label' => 'Total Qty', 'format' => 'number'],
        ],
        'run' => function($conn, $filters) {
            $where = [];
            $types = '';
            $params = [];
            addDateFilters($where, $types, $params, 'po.order_date', $filters['date_from'], $filters['date_to']);
            addIntFilter($where, $types, $params, 'po.vendor_id', $filters['vendor_id']);
            $sql = "SELECT DATE_FORMAT(po.order_date, '%Y-%m') AS period,
                           SUM(poi.quantity) AS total_quantity
                    FROM purchase_order_items poi
                    JOIN purchase_orders po ON po.id = poi.purchase_order_id";
            if (!empty($where)) {
                $sql .= " WHERE " . implode(' AND ', $where);
            }
            $sql .= " GROUP BY DATE_FORMAT(po.order_date, '%Y-%m') ORDER BY period DESC";
            return runQuery($conn, $sql, $types, $params);
        }
    ],
    'purchases_by_supplier' => [
        'title' => 'Purchases by Supplier',
        'description' => 'Purchases grouped by vendor.',
        'columns' => [
            ['key' => 'vendor_name', 'label' => 'Vendor', 'format' => 'text'],
            ['key' => 'orders', 'label' => 'POs', 'format' => 'number'],
            ['key' => 'total_amount', 'label' => 'Total', 'format' => 'currency'],
            ['key' => 'total_quantity', 'label' => 'Total Qty', 'format' => 'number'],
        ],
        'run' => function($conn, $filters) {
            $where = [];
            $types = '';
            $params = [];
            addDateFilters($where, $types, $params, 'po.order_date', $filters['date_from'], $filters['date_to']);
            addIntFilter($where, $types, $params, 'po.vendor_id', $filters['vendor_id']);
            $sql = "SELECT v.name AS vendor_name,
                           COUNT(DISTINCT po.id) AS orders,
                           SUM(po.total_amount) AS total_amount,
                           SUM(poi.quantity) AS total_quantity
                    FROM purchase_orders po
                    JOIN vendors v ON v.id = po.vendor_id
                    LEFT JOIN purchase_order_items poi ON poi.purchase_order_id = po.id";
            if (!empty($where)) {
                $sql .= " WHERE " . implode(' AND ', $where);
            }
            $sql .= " GROUP BY po.vendor_id ORDER BY total_amount DESC";
            return runQuery($conn, $sql, $types, $params);
        }
    ],
    'outstanding_purchase_orders' => [
        'title' => 'Outstanding Purchase Orders',
        'description' => 'Open purchase orders and balances.',
        'columns' => [
            ['key' => 'po_id', 'label' => 'PO #', 'format' => 'number'],
            ['key' => 'vendor_name', 'label' => 'Vendor', 'format' => 'text'],
            ['key' => 'status', 'label' => 'Status', 'format' => 'text'],
            ['key' => 'total_amount', 'label' => 'Total', 'format' => 'currency'],
            ['key' => 'paid_amount', 'label' => 'Paid', 'format' => 'currency'],
            ['key' => 'balance', 'label' => 'Balance', 'format' => 'currency'],
        ],
        'run' => function($conn, $filters) {
            $where = ["po.status IN ('new','ordered','partially-received')"];
            $types = '';
            $params = [];
            addDateFilters($where, $types, $params, 'po.order_date', $filters['date_from'], $filters['date_to']);
            addIntFilter($where, $types, $params, 'po.vendor_id', $filters['vendor_id']);
            $sql = "SELECT po.id AS po_id,
                           v.name AS vendor_name,
                           po.status,
                           po.total_amount,
                           po.paid_amount,
                           (po.total_amount - po.paid_amount) AS balance
                    FROM purchase_orders po
                    JOIN vendors v ON v.id = po.vendor_id";
            if (!empty($where)) {
                $sql .= " WHERE " . implode(' AND ', $where);
            }
            $sql .= " ORDER BY po.order_date DESC";
            return runQuery($conn, $sql, $types, $params);
        }
    ],
    'vendor_balances' => [
        'title' => 'Vendor Wallets',
        'description' => 'Wallet balances by vendor.',
        'columns' => [
            ['key' => 'vendor_name', 'label' => 'Vendor', 'format' => 'text'],
            ['key' => 'wallet_balance', 'label' => 'Wallet Balance', 'format' => 'currency'],
        ],
        'run' => function($conn, $filters) {
            $where = [];
            $types = '';
            $params = [];
            addIntFilter($where, $types, $params, 'v.id', $filters['vendor_id']);
            $sql = "SELECT v.name AS vendor_name, v.wallet_balance
                    FROM vendors v";
            if (!empty($where)) {
                $sql .= " WHERE " . implode(' AND ', $where);
            }
            $sql .= " ORDER BY v.wallet_balance DESC, v.name";
            return runQuery($conn, $sql, $types, $params);
        }
    ],
    'sales_per_employee' => [
        'title' => 'Sales per Employee',
        'description' => 'Sales attributed to each staff member.',
        'columns' => [
            ['key' => 'employee_name', 'label' => 'Employee', 'format' => 'text'],
            ['key' => 'orders', 'label' => 'Orders', 'format' => 'number'],
            ['key' => 'total_sales', 'label' => 'Total Sales', 'format' => 'currency', 'requires' => 'prices'],
        ],
        'run' => function($conn, $filters) {
            $where = [];
            $types = '';
            $params = [];
            addDateFilters($where, $types, $params, 'o.order_date', $filters['date_from'], $filters['date_to']);
            $sql = "SELECT u.name AS employee_name,
                           COUNT(*) AS orders,
                           SUM(o.total_amount) AS total_sales
                    FROM orders o
                    JOIN users u ON u.id = o.created_by";
            if (!empty($where)) {
                $sql .= " WHERE " . implode(' AND ', $where);
            }
            $sql .= " GROUP BY o.created_by ORDER BY total_sales DESC";
            return runQuery($conn, $sql, $types, $params);
        }
    ],
    'returns_summary' => [
        'title' => 'Returns Summary',
        'description' => 'Returned quantities by product.',
        'columns' => [
            ['key' => 'product_name', 'label' => 'Product', 'format' => 'text'],
            ['key' => 'sku', 'label' => 'SKU', 'format' => 'text'],
            ['key' => 'returns_count', 'label' => 'Returns', 'format' => 'number'],
            ['key' => 'returned_qty', 'label' => 'Returned Qty', 'format' => 'number'],
        ],
        'run' => function($conn, $filters) {
            $where = [];
            $types = '';
            $params = [];
            addDateFilters($where, $types, $params, 'o.order_date', $filters['date_from'], $filters['date_to']);
            addIntFilter($where, $types, $params, 'p.category_id', $filters['category_id']);
            addTextFilter($where, $types, $params, 'p.type', $filters['product_type']);
            $sql = "SELECT p.name AS product_name, p.sku,
                           COUNT(r.id) AS returns_count,
                           SUM(r.returned_quantity) AS returned_qty
                    FROM order_returns r
                    JOIN orders o ON o.id = r.order_id
                    JOIN products p ON p.id = r.product_id";
            if (!empty($where)) {
                $sql .= " WHERE " . implode(' AND ', $where);
            }
            $sql .= " GROUP BY r.product_id ORDER BY returned_qty DESC";
            return runQuery($conn, $sql, $types, $params);
        }
    ],
    'product_margin' => [
        'title' => 'Product Margin',
        'description' => 'Revenue, estimated cost, and margin by product.',
        'columns' => [
            ['key' => 'sku', 'label' => 'SKU', 'format' => 'text'],
            ['key' => 'product_name', 'label' => 'Product', 'format' => 'text'],
            ['key' => 'category_name', 'label' => 'Category', 'format' => 'text'],
            ['key' => 'quantity', 'label' => 'Qty Sold', 'format' => 'number'],
            ['key' => 'revenue', 'label' => 'Revenue', 'format' => 'currency', 'requires' => 'prices_final'],
            ['key' => 'estimated_cost', 'label' => 'Est. Cost', 'format' => 'currency', 'requires' => 'costs_final'],
            ['key' => 'margin', 'label' => 'Margin', 'format' => 'currency', 'requires' => 'costs_final'],
            ['key' => 'margin_pct', 'label' => 'Margin %', 'format' => 'number', 'requires' => 'costs_final'],
        ],
        'run' => function($conn, $filters) {
            $where = ["p.type = 'final'"];
            $types = '';
            $params = [];
            addDateFilters($where, $types, $params, 'o.order_date', $filters['date_from'], $filters['date_to']);
            addIntFilter($where, $types, $params, 'p.category_id', $filters['category_id']);
            $sql = "SELECT p.sku, p.name AS product_name, c.name AS category_name,
                           SUM(oi.quantity) AS quantity,
                           SUM(oi.total_price) AS revenue,
                           SUM(oi.quantity * COALESCE(p.cost_price, 0)) AS estimated_cost,
                           SUM(oi.total_price) - SUM(oi.quantity * COALESCE(p.cost_price, 0)) AS margin,
                           CASE
                               WHEN SUM(oi.total_price) > 0
                               THEN ((SUM(oi.total_price) - SUM(oi.quantity * COALESCE(p.cost_price, 0))) / SUM(oi.total_price)) * 100
                               ELSE 0
                           END AS margin_pct
                    FROM order_items oi
                    JOIN orders o ON o.id = oi.order_id
                    JOIN products p ON p.id = oi.product_id
                    LEFT JOIN categories c ON c.id = p.category_id";
            if (!empty($where)) {
                $sql .= " WHERE " . implode(' AND ', $where);
            }
            $sql .= " GROUP BY p.id ORDER BY margin DESC";
            return runQuery($conn, $sql, $types, $params);
        }
    ],
    'ar_aging' => [
        'title' => 'Accounts Receivable Aging',
        'description' => 'Outstanding balances grouped by aging buckets.',
        'columns' => [
            ['key' => 'customer_name', 'label' => 'Customer', 'format' => 'text'],
            ['key' => 'current', 'label' => '0-30 Days', 'format' => 'currency', 'requires' => 'prices_final'],
            ['key' => 'bucket_31_60', 'label' => '31-60 Days', 'format' => 'currency', 'requires' => 'prices_final'],
            ['key' => 'bucket_61_90', 'label' => '61-90 Days', 'format' => 'currency', 'requires' => 'prices_final'],
            ['key' => 'over_90', 'label' => '90+ Days', 'format' => 'currency', 'requires' => 'prices_final'],
            ['key' => 'total_balance', 'label' => 'Total Balance', 'format' => 'currency', 'requires' => 'prices_final'],
        ],
        'run' => function($conn, $filters) {
            $where = ["(o.total_amount - o.paid_amount) > 0"];
            $types = '';
            $params = [];
            addDateFilters($where, $types, $params, 'o.order_date', $filters['date_from'], $filters['date_to']);
            addIntFilter($where, $types, $params, 'o.customer_id', $filters['customer_id']);
            $sql = "SELECT c.name AS customer_name,
                           SUM(CASE WHEN DATEDIFF(CURDATE(), o.order_date) <= 30 THEN (o.total_amount - o.paid_amount) ELSE 0 END) AS current,
                           SUM(CASE WHEN DATEDIFF(CURDATE(), o.order_date) BETWEEN 31 AND 60 THEN (o.total_amount - o.paid_amount) ELSE 0 END) AS bucket_31_60,
                           SUM(CASE WHEN DATEDIFF(CURDATE(), o.order_date) BETWEEN 61 AND 90 THEN (o.total_amount - o.paid_amount) ELSE 0 END) AS bucket_61_90,
                           SUM(CASE WHEN DATEDIFF(CURDATE(), o.order_date) > 90 THEN (o.total_amount - o.paid_amount) ELSE 0 END) AS over_90,
                           SUM(o.total_amount - o.paid_amount) AS total_balance
                    FROM orders o
                    JOIN customers c ON c.id = o.customer_id";
            if (!empty($where)) {
                $sql .= " WHERE " . implode(' AND ', $where);
            }
            $sql .= " GROUP BY o.customer_id ORDER BY total_balance DESC";
            return runQuery($conn, $sql, $types, $params);
        }
    ],
    'ap_aging' => [
        'title' => 'Accounts Payable Aging',
        'description' => 'Vendor balances grouped by aging buckets.',
        'columns' => [
            ['key' => 'vendor_name', 'label' => 'Vendor', 'format' => 'text'],
            ['key' => 'current', 'label' => '0-30 Days', 'format' => 'currency'],
            ['key' => 'bucket_31_60', 'label' => '31-60 Days', 'format' => 'currency'],
            ['key' => 'bucket_61_90', 'label' => '61-90 Days', 'format' => 'currency'],
            ['key' => 'over_90', 'label' => '90+ Days', 'format' => 'currency'],
            ['key' => 'total_balance', 'label' => 'Total Balance', 'format' => 'currency'],
        ],
        'run' => function($conn, $filters) {
            $where = ["(po.total_amount - po.paid_amount) > 0"];
            $types = '';
            $params = [];
            addDateFilters($where, $types, $params, 'po.order_date', $filters['date_from'], $filters['date_to']);
            addIntFilter($where, $types, $params, 'po.vendor_id', $filters['vendor_id']);
            $sql = "SELECT v.name AS vendor_name,
                           SUM(CASE WHEN DATEDIFF(CURDATE(), po.order_date) <= 30 THEN (po.total_amount - po.paid_amount) ELSE 0 END) AS current,
                           SUM(CASE WHEN DATEDIFF(CURDATE(), po.order_date) BETWEEN 31 AND 60 THEN (po.total_amount - po.paid_amount) ELSE 0 END) AS bucket_31_60,
                           SUM(CASE WHEN DATEDIFF(CURDATE(), po.order_date) BETWEEN 61 AND 90 THEN (po.total_amount - po.paid_amount) ELSE 0 END) AS bucket_61_90,
                           SUM(CASE WHEN DATEDIFF(CURDATE(), po.order_date) > 90 THEN (po.total_amount - po.paid_amount) ELSE 0 END) AS over_90,
                           SUM(po.total_amount - po.paid_amount) AS total_balance
                    FROM purchase_orders po
                    JOIN vendors v ON v.id = po.vendor_id";
            if (!empty($where)) {
                $sql .= " WHERE " . implode(' AND ', $where);
            }
            $sql .= " GROUP BY po.vendor_id ORDER BY total_balance DESC";
            return runQuery($conn, $sql, $types, $params);
        }
    ],
    'cashflow_summary' => [
        'title' => 'Cashflow Transfers',
        'description' => 'Transfers between safes, banks, and personal.',
        'columns' => [
            ['key' => 'period', 'label' => 'Period', 'format' => 'text'],
            ['key' => 'from_type', 'label' => 'From', 'format' => 'text'],
            ['key' => 'to_type', 'label' => 'To', 'format' => 'text'],
            ['key' => 'transfers', 'label' => 'Transfers', 'format' => 'number'],
            ['key' => 'total_amount', 'label' => 'Total Amount', 'format' => 'currency'],
        ],
        'run' => function($conn, $filters) {
            $where = [];
            $types = '';
            $params = [];
            addDateFilters($where, $types, $params, 'ft.created_at', $filters['date_from'], $filters['date_to']);
            $sql = "SELECT DATE_FORMAT(ft.created_at, '%Y-%m') AS period,
                           ft.from_type,
                           ft.to_type,
                           COUNT(*) AS transfers,
                           SUM(ft.amount) AS total_amount
                    FROM finance_transfers ft";
            if (!empty($where)) {
                $sql .= " WHERE " . implode(' AND ', $where);
            }
            $sql .= " GROUP BY DATE_FORMAT(ft.created_at, '%Y-%m'), ft.from_type, ft.to_type
                      ORDER BY period DESC, total_amount DESC";
            return runQuery($conn, $sql, $types, $params);
        }
    ],
    'payments_by_method' => [
        'title' => 'Payments by Method',
        'description' => 'Incoming and outgoing payments by method.',
        'columns' => [
            ['key' => 'period', 'label' => 'Period', 'format' => 'text'],
            ['key' => 'flow_type', 'label' => 'Flow', 'format' => 'text'],
            ['key' => 'method', 'label' => 'Method', 'format' => 'text'],
            ['key' => 'payments', 'label' => 'Payments', 'format' => 'number'],
            ['key' => 'total_amount', 'label' => 'Total Amount', 'format' => 'currency'],
        ],
        'run' => function($conn, $filters) {
            $whereSales = [];
            $wherePurch = [];
            $types = '';
            $params = [];
            addDateFilters($whereSales, $types, $params, 'op.created_at', $filters['date_from'], $filters['date_to']);
            addDateFilters($wherePurch, $types, $params, 'pop.created_at', $filters['date_from'], $filters['date_to']);
            $salesWhere = !empty($whereSales) ? "WHERE " . implode(' AND ', $whereSales) : '';
            $purchWhere = !empty($wherePurch) ? "WHERE " . implode(' AND ', $wherePurch) : '';
            $sql = "SELECT DATE_FORMAT(op.created_at, '%Y-%m') AS period,
                           'Sales' AS flow_type,
                           op.payment_method AS method,
                           COUNT(*) AS payments,
                           SUM(op.amount) AS total_amount
                    FROM order_payments op
                    $salesWhere
                    GROUP BY DATE_FORMAT(op.created_at, '%Y-%m'), op.payment_method
                    UNION ALL
                    SELECT DATE_FORMAT(pop.created_at, '%Y-%m') AS period,
                           'Purchases' AS flow_type,
                           pop.payment_method AS method,
                           COUNT(*) AS payments,
                           SUM(pop.amount) AS total_amount
                    FROM purchase_order_payments pop
                    $purchWhere
                    GROUP BY DATE_FORMAT(pop.created_at, '%Y-%m'), pop.payment_method
                    ORDER BY period DESC, flow_type";
            return runQuery($conn, $sql, $types, $params);
        }
    ],
    'inventory_valuation' => [
        'title' => 'Inventory Valuation',
        'description' => 'Estimated stock value by inventory.',
        'columns' => [
            ['key' => 'inventory_name', 'label' => 'Inventory', 'format' => 'text'],
            ['key' => 'sku_count', 'label' => 'SKU Count', 'format' => 'number'],
            ['key' => 'total_qty', 'label' => 'Total Qty', 'format' => 'number'],
            ['key' => 'total_value', 'label' => 'Est. Value', 'format' => 'currency', 'requires' => 'costs_all'],
        ],
        'run' => function($conn, $filters) {
            $where = [];
            $types = '';
            $params = [];
            addIntFilter($where, $types, $params, 'p.category_id', $filters['category_id']);
            addTextFilter($where, $types, $params, 'p.type', $filters['product_type']);
            $sql = "SELECT i.name AS inventory_name,
                           COUNT(DISTINCT p.id) AS sku_count,
                           SUM(ip.quantity) AS total_qty,
                           SUM(ip.quantity * COALESCE(p.cost_price, 0)) AS total_value
                    FROM inventory_products ip
                    JOIN inventories i ON i.id = ip.inventory_id
                    JOIN products p ON p.id = ip.product_id";
            if (!empty($where)) {
                $sql .= " WHERE " . implode(' AND ', $where);
            }
            $sql .= " GROUP BY i.id ORDER BY total_value DESC";
            return runQuery($conn, $sql, $types, $params);
        }
    ],
    'quotation_pipeline' => [
        'title' => 'Quotation Pipeline',
        'description' => 'Quotation totals grouped by status.',
        'columns' => [
            ['key' => 'status', 'label' => 'Status', 'format' => 'text'],
            ['key' => 'count', 'label' => 'Count', 'format' => 'number'],
            ['key' => 'total_amount', 'label' => 'Total Amount', 'format' => 'currency', 'requires' => 'prices_final'],
        ],
        'run' => function($conn, $filters) {
            $where = [];
            $types = '';
            $params = [];
            addDateFilters($where, $types, $params, 'q.quotation_date', $filters['date_from'], $filters['date_to']);
            addIntFilter($where, $types, $params, 'q.customer_id', $filters['customer_id']);
            $sql = "SELECT q.status,
                           COUNT(*) AS count,
                           SUM(q.total_amount) AS total_amount
                    FROM quotations q";
            if (!empty($where)) {
                $sql .= " WHERE " . implode(' AND ', $where);
            }
            $sql .= " GROUP BY q.status ORDER BY count DESC";
            return runQuery($conn, $sql, $types, $params);
        }
    ],
    'sales_by_category' => [
        'title' => 'Sales by Category',
        'description' => 'Revenue and volume grouped by category.',
        'columns' => [
            ['key' => 'category_name', 'label' => 'Category', 'format' => 'text'],
            ['key' => 'quantity', 'label' => 'Qty Sold', 'format' => 'number'],
            ['key' => 'revenue', 'label' => 'Revenue', 'format' => 'currency', 'requires' => 'prices_final'],
            ['key' => 'avg_price', 'label' => 'Avg Price', 'format' => 'currency', 'requires' => 'prices_final'],
        ],
        'run' => function($conn, $filters) {
            $where = ["p.type = 'final'"];
            $types = '';
            $params = [];
            addDateFilters($where, $types, $params, 'o.order_date', $filters['date_from'], $filters['date_to']);
            addIntFilter($where, $types, $params, 'p.category_id', $filters['category_id']);
            $sql = "SELECT COALESCE(c.name, 'Uncategorized') AS category_name,
                           SUM(oi.quantity) AS quantity,
                           SUM(oi.total_price) AS revenue,
                           AVG(oi.unit_price) AS avg_price
                    FROM order_items oi
                    JOIN orders o ON o.id = oi.order_id
                    JOIN products p ON p.id = oi.product_id
                    LEFT JOIN categories c ON c.id = p.category_id";
            if (!empty($where)) {
                $sql .= " WHERE " . implode(' AND ', $where);
            }
            $sql .= " GROUP BY p.category_id ORDER BY revenue DESC";
            return runQuery($conn, $sql, $types, $params);
        }
    ],
    'sales_by_customer_type' => [
        'title' => 'Sales by Customer Type',
        'description' => 'Sales split by customer type.',
        'columns' => [
            ['key' => 'customer_type', 'label' => 'Customer Type', 'format' => 'text'],
            ['key' => 'orders', 'label' => 'Orders', 'format' => 'number'],
            ['key' => 'total_sales', 'label' => 'Total Sales', 'format' => 'currency', 'requires' => 'prices_final'],
        ],
        'run' => function($conn, $filters) {
            $where = [];
            $types = '';
            $params = [];
            addDateFilters($where, $types, $params, 'o.order_date', $filters['date_from'], $filters['date_to']);
            $sql = "SELECT c.type AS customer_type,
                           COUNT(*) AS orders,
                           SUM(o.total_amount) AS total_sales
                    FROM orders o
                    JOIN customers c ON c.id = o.customer_id";
            if (!empty($where)) {
                $sql .= " WHERE " . implode(' AND ', $where);
            }
            $sql .= " GROUP BY c.type ORDER BY total_sales DESC";
            return runQuery($conn, $sql, $types, $params);
        }
    ],
    'order_status_mix' => [
        'title' => 'Order Status Mix',
        'description' => 'Orders grouped by status.',
        'columns' => [
            ['key' => 'status', 'label' => 'Status', 'format' => 'text'],
            ['key' => 'count', 'label' => 'Orders', 'format' => 'number'],
            ['key' => 'total_amount', 'label' => 'Total Amount', 'format' => 'currency', 'requires' => 'prices_final'],
        ],
        'run' => function($conn, $filters) {
            $where = [];
            $types = '';
            $params = [];
            addDateFilters($where, $types, $params, 'o.order_date', $filters['date_from'], $filters['date_to']);
            $sql = "SELECT o.status,
                           COUNT(*) AS count,
                           SUM(o.total_amount) AS total_amount
                    FROM orders o";
            if (!empty($where)) {
                $sql .= " WHERE " . implode(' AND ', $where);
            }
            $sql .= " GROUP BY o.status ORDER BY count DESC";
            return runQuery($conn, $sql, $types, $params);
        }
    ],
    'po_status_mix' => [
        'title' => 'PO Status Mix',
        'description' => 'Purchase orders grouped by status.',
        'columns' => [
            ['key' => 'status', 'label' => 'Status', 'format' => 'text'],
            ['key' => 'count', 'label' => 'POs', 'format' => 'number'],
            ['key' => 'total_amount', 'label' => 'Total Amount', 'format' => 'currency'],
        ],
        'run' => function($conn, $filters) {
            $where = [];
            $types = '';
            $params = [];
            addDateFilters($where, $types, $params, 'po.order_date', $filters['date_from'], $filters['date_to']);
            $sql = "SELECT po.status,
                           COUNT(*) AS count,
                           SUM(po.total_amount) AS total_amount
                    FROM purchase_orders po";
            if (!empty($where)) {
                $sql .= " WHERE " . implode(' AND ', $where);
            }
            $sql .= " GROUP BY po.status ORDER BY count DESC";
            return runQuery($conn, $sql, $types, $params);
        }
    ],
    'inventory_transfers_summary' => [
        'title' => 'Inventory Transfers',
        'description' => 'Transfers volume by status and period.',
        'columns' => [
            ['key' => 'period', 'label' => 'Period', 'format' => 'text'],
            ['key' => 'status', 'label' => 'Status', 'format' => 'text'],
            ['key' => 'transfers', 'label' => 'Transfers', 'format' => 'number'],
            ['key' => 'total_qty', 'label' => 'Total Qty', 'format' => 'number'],
        ],
        'run' => function($conn, $filters) {
            $where = [];
            $types = '';
            $params = [];
            addDateFilters($where, $types, $params, 'it.created_at', $filters['date_from'], $filters['date_to']);
            $sql = "SELECT DATE_FORMAT(it.created_at, '%Y-%m') AS period,
                           it.status,
                           COUNT(DISTINCT it.id) AS transfers,
                           SUM(COALESCE(ti.quantity, 0)) AS total_qty
                    FROM inventory_transfers it
                    LEFT JOIN transfer_items ti ON ti.transfer_id = it.id";
            if (!empty($where)) {
                $sql .= " WHERE " . implode(' AND ', $where);
            }
            $sql .= " GROUP BY DATE_FORMAT(it.created_at, '%Y-%m'), it.status
                      ORDER BY period DESC";
            return runQuery($conn, $sql, $types, $params);
        }
    ],
    'inter_branch_transfers' => [
        'title' => 'Inter-Branch Transfers',
        'description' => 'Transfers volume by status and period.',
        'columns' => [
            ['key' => 'period', 'label' => 'Period', 'format' => 'text'],
            ['key' => 'status', 'label' => 'Status', 'format' => 'text'],
            ['key' => 'transfers', 'label' => 'Transfers', 'format' => 'number'],
            ['key' => 'total_qty', 'label' => 'Total Qty', 'format' => 'number'],
        ],
        'run' => function($conn, $filters) {
            $where = [];
            $types = '';
            $params = [];
            addDateFilters($where, $types, $params, 'it.created_at', $filters['date_from'], $filters['date_to']);
            $sql = "SELECT DATE_FORMAT(it.created_at, '%Y-%m') AS period,
                           it.status,
                           COUNT(DISTINCT it.id) AS transfers,
                           SUM(COALESCE(ti.quantity, 0)) AS total_qty
                    FROM inventory_transfers it
                    LEFT JOIN transfer_items ti ON ti.transfer_id = it.id";
            if (!empty($where)) {
                $sql .= " WHERE " . implode(' AND ', $where);
            }
            $sql .= " GROUP BY DATE_FORMAT(it.created_at, '%Y-%m'), it.status
                      ORDER BY period DESC";
            return runQuery($conn, $sql, $types, $params);
        }
    ],
    'cash_positions' => [
        'title' => 'Cash Positions',
        'description' => 'Balances across safes, banks, and personal.',
        'columns' => [
            ['key' => 'source_type', 'label' => 'Source', 'format' => 'text'],
            ['key' => 'label', 'label' => 'Name', 'format' => 'text'],
            ['key' => 'balance', 'label' => 'Balance', 'format' => 'currency'],
        ],
        'run' => function($conn) {
            $rows = [];
            $safes = $conn->query("SELECT 'Safe' AS source_type, name AS label, balance FROM safes")->fetch_all(MYSQLI_ASSOC);
            $banks = $conn->query("SELECT 'Bank' AS source_type, bank_name AS label, balance FROM bank_accounts")->fetch_all(MYSQLI_ASSOC);
            $personal = $conn->query("SELECT 'Personal' AS source_type, name AS label, personal_balance AS balance FROM users WHERE is_active=1")->fetch_all(MYSQLI_ASSOC);
            $rows = array_merge($safes, $banks, $personal);
            usort($rows, function($a, $b) {
                return (float)$b['balance'] <=> (float)$a['balance'];
            });
            return $rows;
        }
    ],
    'customer_wallet_activity' => [
        'title' => 'Customer Wallet Activity',
        'description' => 'Wallet transactions by type and period.',
        'columns' => [
            ['key' => 'period', 'label' => 'Period', 'format' => 'text'],
            ['key' => 'type', 'label' => 'Type', 'format' => 'text'],
            ['key' => 'transactions', 'label' => 'Transactions', 'format' => 'number'],
            ['key' => 'total_amount', 'label' => 'Total Amount', 'format' => 'currency'],
        ],
        'run' => function($conn, $filters) {
            $where = [];
            $types = '';
            $params = [];
            addDateFilters($where, $types, $params, 'cwt.created_at', $filters['date_from'], $filters['date_to']);
            addIntFilter($where, $types, $params, 'cwt.customer_id', $filters['customer_id']);
            $sql = "SELECT DATE_FORMAT(cwt.created_at, '%Y-%m') AS period,
                           cwt.type,
                           COUNT(*) AS transactions,
                           SUM(cwt.amount) AS total_amount
                    FROM customer_wallet_transactions cwt";
            if (!empty($where)) {
                $sql .= " WHERE " . implode(' AND ', $where);
            }
            $sql .= " GROUP BY DATE_FORMAT(cwt.created_at, '%Y-%m'), cwt.type
                      ORDER BY period DESC";
            return runQuery($conn, $sql, $types, $params);
        }
    ],
    'user_activity_log' => [
        'title' => 'User Activity Log',
        'description' => 'System actions by user and time.',
        'columns' => [
            ['key' => 'created_at', 'label' => 'Date', 'format' => 'text'],
            ['key' => 'user_name', 'label' => 'User', 'format' => 'text'],
            ['key' => 'action', 'label' => 'Action', 'format' => 'text'],
            ['key' => 'details', 'label' => 'Details', 'format' => 'text'],
            ['key' => 'ip_address', 'label' => 'IP', 'format' => 'text'],
        ],
        'run' => function($conn, $filters) {
            $where = [];
            $types = '';
            $params = [];
            addDateFilters($where, $types, $params, 'al.created_at', $filters['date_from'], $filters['date_to']);
            $sql = "SELECT al.created_at,
                           u.name AS user_name,
                           al.action,
                           al.details,
                           al.ip_address
                    FROM activity_logs al
                    LEFT JOIN users u ON u.id = al.user_id";
            if (!empty($where)) {
                $sql .= " WHERE " . implode(' AND ', $where);
            }
            $sql .= " ORDER BY al.created_at DESC";
            return runQuery($conn, $sql, $types, $params);
        }
    ],
    'vendor_wallet_activity' => [
        'title' => 'Vendor Wallet Activity',
        'description' => 'Vendor transactions by type and period.',
        'columns' => [
            ['key' => 'period', 'label' => 'Period', 'format' => 'text'],
            ['key' => 'type', 'label' => 'Type', 'format' => 'text'],
            ['key' => 'transactions', 'label' => 'Transactions', 'format' => 'number'],
            ['key' => 'total_amount', 'label' => 'Total Amount', 'format' => 'currency'],
        ],
        'run' => function($conn, $filters) {
            $where = [];
            $types = '';
            $params = [];
            addDateFilters($where, $types, $params, 'vwt.created_at', $filters['date_from'], $filters['date_to']);
            addIntFilter($where, $types, $params, 'vwt.vendor_id', $filters['vendor_id']);
            $sql = "SELECT DATE_FORMAT(vwt.created_at, '%Y-%m') AS period,
                           vwt.type,
                           COUNT(*) AS transactions,
                           SUM(vwt.amount) AS total_amount
                    FROM vendor_wallet_transactions vwt";
            if (!empty($where)) {
                $sql .= " WHERE " . implode(' AND ', $where);
            }
            $sql .= " GROUP BY DATE_FORMAT(vwt.created_at, '%Y-%m'), vwt.type
                      ORDER BY period DESC";
            return runQuery($conn, $sql, $types, $params);
        }
    ],
];

$reportFilterMap = [
    'sales_summary' => ['date','category','product_type','customer'],
    'gross_sales' => ['date'],
    'net_sales' => ['date'],
    'sales_growth' => ['date'],
    'average_transaction_value' => ['date'],
    'items_per_transaction' => ['date'],
    'revenue_per_hour' => ['date'],
    'sales_per_day' => ['date'],
    'sales_by_product' => ['date','category','product_type'],
    'discount_rate' => ['date'],
    'discount_impact' => ['date'],
    'total_sales_today' => ['date'],
    'total_sales_this_month' => ['date'],
    'top_products_overview' => ['date'],
    'top_categories_overview' => ['date'],
    'customer_lifetime_value' => ['date','customer'],
    'repeat_purchase_rate' => ['date'],
    'customer_purchase_frequency' => ['date'],
    'average_basket_size' => ['date'],
    'new_vs_returning_customers' => ['date'],
    'inactive_customers' => ['date'],
    'top_customers' => ['date','customer'],
    'cash_sales' => ['date'],
    'credit_sales' => ['date'],
    'accounts_receivable' => ['date','customer'],
    'accounts_payable' => ['date','vendor'],
    'customer_balances' => ['customer'],
    'inventory_levels' => ['category','product_type'],
    'low_stock' => ['category','product_type'],
    'purchase_summary' => ['date','vendor'],
    'purchase_cost' => ['date','vendor'],
    'purchase_volume' => ['date','vendor'],
    'purchases_by_supplier' => ['date','vendor'],
    'outstanding_purchase_orders' => ['date','vendor'],
    'vendor_balances' => ['vendor'],
    'sales_per_employee' => ['date'],
    'returns_summary' => ['date','category','product_type'],
    'product_margin' => ['date','category'],
    'ar_aging' => ['date','customer'],
    'ap_aging' => ['date','vendor'],
    'cashflow_summary' => ['date'],
    'payments_by_method' => ['date'],
    'inventory_valuation' => ['category','product_type'],
    'quotation_pipeline' => ['date','customer'],
    'sales_by_category' => ['date','category'],
    'sales_by_customer_type' => ['date'],
    'order_status_mix' => ['date'],
    'po_status_mix' => ['date'],
    'inventory_transfers_summary' => ['date'],
    'inter_branch_transfers' => ['date'],
    'cash_positions' => [],
    'customer_wallet_activity' => ['date','customer'],
    'vendor_wallet_activity' => ['date','vendor'],
    'user_activity_log' => ['date'],
];

$reportViewMap = [
    'sales_summary' => [
        'table' => false,
    ],
    'gross_sales' => [
        'table' => false,
    ],
    'net_sales' => [
        'table' => false,
    ],
    'sales_growth' => [
        'table' => false,
    ],
    'average_transaction_value' => [
        'table' => false,
    ],
    'items_per_transaction' => [
        'table' => false,
    ],
    'revenue_per_hour' => [
        'table' => false,
    ],
    'sales_per_day' => [
        'table' => false,
    ],
    'discount_rate' => [
        'table' => false,
    ],
    'discount_impact' => [
        'table' => false,
    ],
    'total_sales_today' => [
        'table' => false,
        'kpis' => [
            ['label' => 'Total Sales', 'key' => 'total_sales', 'format' => 'currency'],
            ['label' => 'Orders', 'key' => 'orders', 'format' => 'number'],
        ],
    ],
    'total_sales_this_month' => [
        'table' => false,
        'kpis' => [
            ['label' => 'Total Sales', 'key' => 'total_sales', 'format' => 'currency'],
            ['label' => 'Orders', 'key' => 'orders', 'format' => 'number'],
        ],
    ],
    'top_products_overview' => [
        'table' => false,
    ],
    'top_categories_overview' => [
        'table' => false,
    ],
    'customer_lifetime_value' => [
        'table' => false,
    ],
    'repeat_purchase_rate' => [
        'table' => false,
        'chart' => false,
        'kpis' => [
            ['label' => 'Customers', 'key' => 'total_customers', 'format' => 'number'],
            ['label' => 'Repeat Customers', 'key' => 'repeat_customers', 'format' => 'number'],
            ['label' => 'Repeat Rate', 'key' => 'repeat_rate', 'format' => 'percent'],
        ],
    ],
    'customer_purchase_frequency' => [
        'table' => false,
        'chart' => false,
        'kpis' => [
            ['label' => 'Customers', 'key' => 'total_customers', 'format' => 'number'],
            ['label' => 'Orders', 'key' => 'total_orders', 'format' => 'number'],
            ['label' => 'Avg Orders', 'key' => 'avg_orders_per_customer', 'format' => 'number'],
        ],
    ],
    'average_basket_size' => [
        'table' => false,
        'chart' => false,
        'kpis' => [
            ['label' => 'Orders', 'key' => 'total_orders', 'format' => 'number'],
            ['label' => 'Items', 'key' => 'total_items', 'format' => 'number'],
            ['label' => 'Avg Items', 'key' => 'avg_items_per_order', 'format' => 'number'],
        ],
    ],
    'new_vs_returning_customers' => [
        'table' => false,
    ],
    'top_customers' => [
        'table' => false,
    ],
    'cash_sales' => [
        'table' => false,
    ],
    'credit_sales' => [
        'table' => false,
    ],
    'purchase_summary' => [
        'table' => false,
    ],
    'purchase_cost' => [
        'table' => false,
    ],
    'purchase_volume' => [
        'table' => false,
    ],
    'purchases_by_supplier' => [
        'table' => false,
    ],
    'sales_per_employee' => [
        'table' => false,
    ],
    'order_status_mix' => [
        'table' => false,
    ],
    'po_status_mix' => [
        'table' => false,
    ],
    'inventory_transfers_summary' => [
        'table' => false,
    ],
    'inter_branch_transfers' => [
        'table' => false,
    ],
    'cash_positions' => [
        'table' => false,
    ],
    'customer_wallet_activity' => [
        'table' => false,
    ],
    'vendor_wallet_activity' => [
        'table' => false,
    ],
];

if (!isset($reports[$reportKey])) {
    setAlert('danger', 'Report not found.');
    redirect('index.php');
}

$report = $reports[$reportKey];
$reportFilters = $reportFilterMap[$reportKey] ?? ['date'];
$reportView = array_merge(
    ['table' => true, 'chart' => true, 'kpis' => []],
    $reportViewMap[$reportKey] ?? []
);
$rows = $report['run']($conn, $filters);

$reportKpis = [];
if (!empty($reportView['kpis'])) {
    $kpiSource = $rows[0] ?? [];
    foreach ($reportView['kpis'] as $kpi) {
        $value = $kpiSource[$kpi['key']] ?? 0;
        $reportKpis[] = [
            'label' => $kpi['label'],
            'value' => formatMetricValue($value, $kpi['format']),
        ];
    }
}

$columns = [];
foreach ($report['columns'] as $col) {
    if (!empty($col['requires']) && $col['requires'] === 'prices' && !$canViewFinalPrices) {
        $columns[] = [
            'key' => $col['key'],
            'label' => $col['label'],
            'format' => $col['format'],
            'hidden' => true
        ];
        continue;
    }
    if (!empty($col['requires']) && $col['requires'] === 'prices_final' && !$canViewFinalPrices) {
        $columns[] = [
            'key' => $col['key'],
            'label' => $col['label'],
            'format' => $col['format'],
            'hidden' => true
        ];
        continue;
    }
    if (!empty($col['requires']) && $col['requires'] === 'prices_material' && !$canViewMaterialPrices) {
        $columns[] = [
            'key' => $col['key'],
            'label' => $col['label'],
            'format' => $col['format'],
            'hidden' => true
        ];
        continue;
    }
    if (!empty($col['requires']) && $col['requires'] === 'prices_all' && !$canViewAllPrices) {
        $columns[] = [
            'key' => $col['key'],
            'label' => $col['label'],
            'format' => $col['format'],
            'hidden' => true
        ];
        continue;
    }
    if (!empty($col['requires']) && $col['requires'] === 'costs' && !$canViewMaterialCosts) {
        $columns[] = [
            'key' => $col['key'],
            'label' => $col['label'],
            'format' => $col['format'],
            'hidden' => true
        ];
        continue;
    }
    if (!empty($col['requires']) && $col['requires'] === 'costs_final' && !$canViewFinalCosts) {
        $columns[] = [
            'key' => $col['key'],
            'label' => $col['label'],
            'format' => $col['format'],
            'hidden' => true
        ];
        continue;
    }
    if (!empty($col['requires']) && $col['requires'] === 'costs_material' && !$canViewMaterialCosts) {
        $columns[] = [
            'key' => $col['key'],
            'label' => $col['label'],
            'format' => $col['format'],
            'hidden' => true
        ];
        continue;
    }
    if (!empty($col['requires']) && $col['requires'] === 'costs_all' && !$canViewAllCosts) {
        $columns[] = [
            'key' => $col['key'],
            'label' => $col['label'],
            'format' => $col['format'],
            'hidden' => true
        ];
        continue;
    }
    $columns[] = $col;
}

$rowCount = count($rows);
$chartConfig = [
    'type' => 'bar',
    'label_key' => '',
    'value_key' => ''
];
$chartMap = [
    'sales_summary' => ['type' => 'line', 'label_key' => 'period', 'value_key' => 'total_sales'],
    'gross_sales' => ['type' => 'line', 'label_key' => 'period', 'value_key' => 'gross_sales'],
    'net_sales' => ['type' => 'line', 'label_key' => 'period', 'value_key' => 'net_sales'],
    'sales_growth' => ['type' => 'line', 'label_key' => 'period', 'value_key' => 'growth_pct'],
    'average_transaction_value' => ['type' => 'line', 'label_key' => 'period', 'value_key' => 'avg_value'],
    'items_per_transaction' => ['type' => 'line', 'label_key' => 'period', 'value_key' => 'avg_items'],
    'revenue_per_hour' => ['type' => 'bar', 'label_key' => 'hour', 'value_key' => 'total_sales'],
    'sales_per_day' => ['type' => 'line', 'label_key' => 'day', 'value_key' => 'total_sales'],
    'sales_by_product' => ['type' => 'bar', 'label_key' => 'product_name', 'value_key' => 'revenue'],
    'top_customers' => ['type' => 'bar', 'label_key' => 'customer_name', 'value_key' => 'total_sales'],
    'purchase_summary' => ['type' => 'line', 'label_key' => 'period', 'value_key' => 'total_amount'],
    'returns_summary' => ['type' => 'bar', 'label_key' => 'product_name', 'value_key' => 'returned_qty'],
    'quotation_pipeline' => ['type' => 'doughnut', 'label_key' => 'status', 'value_key' => 'total_amount'],
    'payments_by_method' => ['type' => 'bar', 'label_key' => 'method', 'value_key' => 'total_amount'],
    'inventory_valuation' => ['type' => 'bar', 'label_key' => 'inventory_name', 'value_key' => 'total_value'],
    'product_margin' => ['type' => 'bar', 'label_key' => 'product_name', 'value_key' => 'margin'],
    'ar_aging' => ['type' => 'bar', 'label_key' => 'customer_name', 'value_key' => 'total_balance'],
    'ap_aging' => ['type' => 'bar', 'label_key' => 'vendor_name', 'value_key' => 'total_balance'],
    'cashflow_summary' => ['type' => 'line', 'label_key' => 'period', 'value_key' => 'total_amount'],
    'sales_by_category' => ['type' => 'bar', 'label_key' => 'category_name', 'value_key' => 'revenue'],
    'sales_by_customer_type' => ['type' => 'doughnut', 'label_key' => 'customer_type', 'value_key' => 'total_sales'],
    'order_status_mix' => ['type' => 'doughnut', 'label_key' => 'status', 'value_key' => 'count'],
    'po_status_mix' => ['type' => 'doughnut', 'label_key' => 'status', 'value_key' => 'count'],
    'inventory_transfers_summary' => ['type' => 'line', 'label_key' => 'period', 'value_key' => 'total_qty'],
    'inter_branch_transfers' => ['type' => 'line', 'label_key' => 'period', 'value_key' => 'total_qty'],
    'cash_positions' => ['type' => 'bar', 'label_key' => 'label', 'value_key' => 'balance'],
    'customer_wallet_activity' => ['type' => 'bar', 'label_key' => 'type', 'value_key' => 'total_amount'],
    'vendor_wallet_activity' => ['type' => 'bar', 'label_key' => 'type', 'value_key' => 'total_amount'],
    'discount_rate' => ['type' => 'line', 'label_key' => 'period', 'value_key' => 'avg_discount_pct'],
    'discount_impact' => ['type' => 'line', 'label_key' => 'period', 'value_key' => 'total_discount'],
    'total_sales_today' => ['type' => 'bar', 'label_key' => 'day', 'value_key' => 'total_sales'],
    'total_sales_this_month' => ['type' => 'line', 'label_key' => 'period', 'value_key' => 'total_sales'],
    'top_products_overview' => ['type' => 'bar', 'label_key' => 'product_name', 'value_key' => 'revenue'],
    'top_categories_overview' => ['type' => 'bar', 'label_key' => 'category_name', 'value_key' => 'revenue'],
    'customer_lifetime_value' => ['type' => 'bar', 'label_key' => 'customer_name', 'value_key' => 'total_sales'],
    'new_vs_returning_customers' => ['type' => 'doughnut', 'label_key' => 'segment', 'value_key' => 'total_sales'],
    'cash_sales' => ['type' => 'line', 'label_key' => 'period', 'value_key' => 'total_amount'],
    'credit_sales' => ['type' => 'line', 'label_key' => 'period', 'value_key' => 'credit_amount'],
    'accounts_receivable' => ['type' => 'bar', 'label_key' => 'customer_name', 'value_key' => 'balance'],
    'accounts_payable' => ['type' => 'bar', 'label_key' => 'vendor_name', 'value_key' => 'balance'],
    'purchase_cost' => ['type' => 'line', 'label_key' => 'period', 'value_key' => 'total_cost'],
    'purchase_volume' => ['type' => 'line', 'label_key' => 'period', 'value_key' => 'total_quantity'],
    'purchases_by_supplier' => ['type' => 'bar', 'label_key' => 'vendor_name', 'value_key' => 'total_amount'],
    'sales_per_employee' => ['type' => 'bar', 'label_key' => 'employee_name', 'value_key' => 'total_sales'],
];
if (isset($chartMap[$reportKey])) {
    $chartConfig = $chartMap[$reportKey];
}
$aggregateKey = null;
$aggregateLabel = null;
$aggregateValue = 0;
$aggregateCandidates = ['total_sales','revenue','total_amount','paid','balance','wallet_balance','quantity','returned_qty','avg_price'];
foreach ($aggregateCandidates as $candidate) {
    foreach ($columns as $col) {
        if ($col['key'] === $candidate && empty($col['hidden'])) {
            $aggregateKey = $candidate;
            $aggregateLabel = $col['label'];
            break 2;
        }
    }
}
if ($aggregateKey !== null) {
    foreach ($rows as $row) {
        $aggregateValue += (float)($row[$aggregateKey] ?? 0);
    }
}

$format = $_GET['format'] ?? '';
if ($format === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="analysis_' . $reportKey . '.csv"');
    $out = fopen('php://output', 'w');
    $header = [];
    foreach ($columns as $col) {
        $header[] = $col['label'];
    }
    fputcsv($out, $header);
    foreach ($rows as $row) {
        $line = [];
        foreach ($columns as $col) {
            $value = $row[$col['key']] ?? '';
            if (!empty($col['hidden'])) {
                $value = 'Hidden';
            }
            $line[] = $value;
        }
        fputcsv($out, $line);
    }
    fclose($out);
    exit;
}

if ($format === 'pdf') {
    require_once '../../tcpdf/tcpdf.php';
    $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    $pdf->SetCreator(PDF_CREATOR);
    $pdf->SetAuthor('GammaVet');
    $pdf->SetTitle($report['title']);
    $pdf->SetMargins(10, 15, 10);
    $pdf->AddPage();
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 10, $report['title'], 0, 1, 'L');
    $pdf->SetFont('helvetica', '', 9);

    $tbl = '<table border="1" cellpadding="4">';
    $tbl .= '<tr style="font-weight:bold;">';
    foreach ($columns as $col) {
        $tbl .= '<th>' . htmlspecialchars($col['label']) . '</th>';
    }
    $tbl .= '</tr>';
    foreach ($rows as $row) {
        $tbl .= '<tr>';
        foreach ($columns as $col) {
            $value = $row[$col['key']] ?? '';
            if (!empty($col['hidden'])) {
                $value = 'Hidden';
            }
            $tbl .= '<td>' . htmlspecialchars((string)$value) . '</td>';
        }
        $tbl .= '</tr>';
    }
    $tbl .= '</table>';
    $pdf->writeHTML($tbl, true, false, false, false, '');
    $pdf->Output('analysis_' . $reportKey . '.pdf', 'D');
    exit;
}

$categories = [];
$customers = [];
$vendors = [];
if (in_array('category', $reportFilters, true)) {
    $categories = $conn->query("SELECT id, name FROM categories WHERE parent_id IS NULL ORDER BY name")->fetch_all(MYSQLI_ASSOC);
}
if (in_array('customer', $reportFilters, true)) {
    $customers = $conn->query("SELECT id, name FROM customers ORDER BY name")->fetch_all(MYSQLI_ASSOC);
}
if (in_array('vendor', $reportFilters, true)) {
    $vendors = $conn->query("SELECT id, name FROM vendors ORDER BY name")->fetch_all(MYSQLI_ASSOC);
}

$categoryMap = [];
foreach ($categories as $cat) {
    $categoryMap[(int)$cat['id']] = $cat['name'];
}
$customerMap = [];
foreach ($customers as $customer) {
    $customerMap[(int)$customer['id']] = $customer['name'];
}
$vendorMap = [];
foreach ($vendors as $vendor) {
    $vendorMap[(int)$vendor['id']] = $vendor['name'];
}
$activeFilters = [];
if (in_array('date', $reportFilters, true)) {
    if ($filters['date_from'] !== '') {
        $activeFilters[] = 'From ' . $filters['date_from'];
    }
    if ($filters['date_to'] !== '') {
        $activeFilters[] = 'To ' . $filters['date_to'];
    }
}
if (in_array('category', $reportFilters, true) && $filters['category_id'] > 0) {
    $activeFilters[] = 'Category: ' . ($categoryMap[$filters['category_id']] ?? 'Selected');
}
if (in_array('product_type', $reportFilters, true) && $filters['product_type'] !== '') {
    $activeFilters[] = 'Type: ' . ucfirst($filters['product_type']);
}
if (in_array('customer', $reportFilters, true) && $filters['customer_id'] > 0) {
    $activeFilters[] = 'Customer: ' . ($customerMap[$filters['customer_id']] ?? 'Selected');
}
if (in_array('vendor', $reportFilters, true) && $filters['vendor_id'] > 0) {
    $activeFilters[] = 'Vendor: ' . ($vendorMap[$filters['vendor_id']] ?? 'Selected');
}

$page_title = 'Analysis - ' . $report['title'];
require_once '../../includes/header.php';
?>

<style>
    @import url('https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&display=swap');
    :root{
        --analysis-ink:#0f172a;
        --analysis-slate:#475569;
        --analysis-soft:#e2e8f0;
        --analysis-brand:#0ea5e9;
        --analysis-bg1:#f8fafc;
        --analysis-bg2:#ecfeff;
    }
    .analysis-shell{
        font-family:'Space Grotesk', system-ui, sans-serif;
        color:var(--analysis-ink);
    }
    .analysis-header{
        background:linear-gradient(135deg, var(--analysis-bg2), var(--analysis-bg1));
        border:1px solid var(--analysis-soft);
        border-radius:18px;
        padding:22px;
        margin-bottom:18px;
    }
    .analysis-header h2{font-size:26px;font-weight:700;margin-bottom:6px;}
    .analysis-header p{color:var(--analysis-slate);margin:0;}
    .analysis-actions .btn{border-radius:10px;}
    .analysis-kpis{display:flex;gap:12px;flex-wrap:wrap;margin-top:16px;}
    .analysis-kpi{
        background:#fff;border:1px solid var(--analysis-soft);
        border-radius:14px;padding:10px 14px;min-width:160px;
        box-shadow:0 6px 16px rgba(15,23,42,0.06);
    }
    .analysis-kpi .label{font-size:11px;letter-spacing:.08em;color:var(--analysis-slate);text-transform:uppercase;}
    .analysis-kpi .value{font-size:18px;font-weight:600;margin-top:4px;}
    .analysis-filter-card{
        border:1px solid var(--analysis-soft);
        border-radius:16px;
        box-shadow:0 8px 20px rgba(15,23,42,0.06);
    }
    .analysis-filter-card .form-label{font-size:12px;color:var(--analysis-slate);text-transform:uppercase;letter-spacing:.08em;}
    .analysis-chip{
        display:inline-flex;align-items:center;gap:6px;
        padding:4px 10px;border-radius:999px;
        background:rgba(15,23,42,0.06);color:var(--analysis-slate);
        font-size:12px;
    }
    .analysis-table-card{
        border-radius:16px;
        border:1px solid var(--analysis-soft);
        box-shadow:0 10px 24px rgba(15,23,42,0.06);
    }
</style>

<div class="analysis-shell">
    <div class="analysis-header">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
            <div>
                <div class="text-uppercase small text-muted">Analysis Report</div>
                <h2><?= htmlspecialchars($report['title']) ?></h2>
                <p><?= htmlspecialchars($report['description']) ?></p>
            </div>
            <div class="analysis-actions d-flex gap-2">
                <a class="btn btn-outline-secondary" href="index.php">Back</a>
                <a class="btn btn-outline-primary" href="?<?= http_build_query(array_merge($_GET, ['format' => 'csv'])) ?>">Download CSV</a>
                <a class="btn btn-outline-primary" href="?<?= http_build_query(array_merge($_GET, ['format' => 'pdf'])) ?>">Download PDF</a>
            </div>
        </div>
        <div class="analysis-kpis">
            <div class="analysis-kpi">
                <div class="label">Rows</div>
                <div class="value"><?= number_format($rowCount) ?></div>
            </div>
            <div class="analysis-kpi">
                <div class="label">Filters</div>
                <div class="value"><?= count($activeFilters) > 0 ? count($activeFilters) : 'All' ?></div>
            </div>
            <div class="analysis-kpi">
                <div class="label">Aggregate</div>
                <div class="value">
                    <?php if ($aggregateKey !== null): ?>
                        <?= number_format($aggregateValue, 2) ?>
                    <?php else: ?>
                        N/A
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php if (!empty($reportKpis)): ?>
            <div class="analysis-kpis">
                <?php foreach ($reportKpis as $kpi): ?>
                    <div class="analysis-kpi">
                        <div class="label"><?= htmlspecialchars($kpi['label']) ?></div>
                        <div class="value"><?= htmlspecialchars($kpi['value']) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php if (!empty($activeFilters)): ?>
            <div class="d-flex flex-wrap gap-2 mt-3">
                <?php foreach ($activeFilters as $filter): ?>
                    <span class="analysis-chip"><?= htmlspecialchars($filter) ?></span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if (!empty($reportFilters)): ?>
    <div class="card mb-3 analysis-filter-card">
        <div class="card-body">
            <form method="get" class="row g-3">
            <input type="hidden" name="key" value="<?= htmlspecialchars($reportKey) ?>">
            <?php if (in_array('date', $reportFilters, true)): ?>
            <div class="col-md-3">
                <label class="form-label">Date From</label>
                <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($filters['date_from']) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Date To</label>
                <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($filters['date_to']) ?>">
            </div>
            <?php endif; ?>
            <?php if (in_array('category', $reportFilters, true)): ?>
            <div class="col-md-3">
                <label class="form-label">Category</label>
                <select name="category_id" class="form-select">
                    <option value="">All</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= (int)$cat['id'] ?>" <?= $filters['category_id'] === (int)$cat['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($cat['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <?php if (in_array('product_type', $reportFilters, true)): ?>
            <div class="col-md-3">
                <label class="form-label">Product Type</label>
                <select name="product_type" class="form-select">
                    <option value="">All</option>
                    <option value="final" <?= $filters['product_type'] === 'final' ? 'selected' : '' ?>>Final</option>
                    <option value="material" <?= $filters['product_type'] === 'material' ? 'selected' : '' ?>>Material</option>
                    <option value="primary" <?= $filters['product_type'] === 'primary' ? 'selected' : '' ?>>Primary</option>
                </select>
            </div>
            <?php endif; ?>
            <?php if (in_array('customer', $reportFilters, true)): ?>
            <div class="col-md-4">
                <label class="form-label">Customer</label>
                <select name="customer_id" class="form-select">
                    <option value="">All</option>
                    <?php foreach ($customers as $customer): ?>
                        <option value="<?= (int)$customer['id'] ?>" <?= $filters['customer_id'] === (int)$customer['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($customer['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <?php if (in_array('vendor', $reportFilters, true)): ?>
            <div class="col-md-4">
                <label class="form-label">Vendor</label>
                <select name="vendor_id" class="form-select">
                    <option value="">All</option>
                    <?php foreach ($vendors as $vendor): ?>
                        <option value="<?= (int)$vendor['id'] ?>" <?= $filters['vendor_id'] === (int)$vendor['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($vendor['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="col-md-4 d-flex align-items-end">
                <button type="submit" class="btn btn-primary w-100">Apply Filters</button>
            </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <?php
$chartLabels = [];
$chartValues = [];
$hasChartPermission = true;
$chartValueKey = $chartConfig['value_key'] ?? '';
if (!$reportView['chart']) {
    $hasChartPermission = false;
}
foreach ($columns as $col) {
        if ($col['key'] === $chartValueKey && !empty($col['hidden'])) {
            $hasChartPermission = false;
            break;
        }
    }
    if (!empty($chartValueKey) && $hasChartPermission) {
        foreach ($rows as $row) {
            $chartLabels[] = $row[$chartConfig['label_key']] ?? '';
            $chartValues[] = (float)($row[$chartValueKey] ?? 0);
        }
    }
    ?>

    <?php if (!empty($chartLabels) && !empty($chartValues) && $hasChartPermission): ?>
        <div class="card analysis-table-card mb-3">
            <div class="card-body">
                <div style="min-height:280px;">
                    <canvas id="analysisChart" height="110"></canvas>
                </div>
            </div>
        </div>
    <?php elseif ($reportView['chart']): ?>
        <div class="card analysis-table-card mb-3">
            <div class="card-body text-center text-muted py-4">
                No data available for chart visualization.
            </div>
        </div>
    <?php endif; ?>

    <?php if (!$reportView['table'] && !$reportView['chart'] && empty($rows)): ?>
        <div class="card analysis-table-card">
            <div class="card-body text-center text-muted py-4">
                No data available for this report.
            </div>
        </div>
    <?php endif; ?>

    <?php if ($reportView['table']): ?>
    <div class="card analysis-table-card">
        <div class="table-responsive">
            <table class="table js-datatable table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <?php foreach ($columns as $col): ?>
                            <th><?= htmlspecialchars($col['label']) ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr>
                            <?php foreach ($columns as $index => $col): ?>
                                <td class="<?= $index === 0 ? 'text-center text-muted py-4' : '' ?>">
                                    <?= $index === 0 ? 'No data for this report.' : '' ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <?php foreach ($columns as $col): ?>
                                    <?php
                                    $value = $row[$col['key']] ?? '';
                                    if (!empty($col['hidden'])) {
                                        $display = '<span class="text-muted">Hidden</span>';
                                    } else {
                                        if ($col['format'] === 'currency') {
                                            $display = number_format((float)$value, 2);
                                        } elseif ($col['format'] === 'number') {
                                            $display = is_numeric($value) ? number_format((float)$value, 0) : $value;
                                        } else {
                                            $display = htmlspecialchars((string)$value);
                                        }
                                    }
                                    ?>
                                    <td><?= $display ?></td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php if (!empty($chartLabels) && !empty($chartValues) && $hasChartPermission): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
    (function() {
        const ctx = document.getElementById('analysisChart');
        if (!ctx) return;
        const labels = <?= json_encode($chartLabels) ?>;
        const values = <?= json_encode($chartValues) ?>;
        const type = <?= json_encode($chartConfig['type']) ?>;
        const data = {
            labels,
            datasets: [{
                label: <?= json_encode($report['title']) ?>,
                data: values,
                borderColor: '#0ea5e9',
                backgroundColor: type === 'line' ? 'rgba(14,165,233,0.15)' : 'rgba(14,165,233,0.6)',
                borderWidth: 2,
                fill: type === 'line'
            }]
        };
        new Chart(ctx, {
            type,
            data,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: type === 'doughnut' ? {} : {
                    x: { ticks: { color: '#475569' } },
                    y: { ticks: { color: '#475569' } }
                },
                plugins: {
                    legend: { display: type === 'doughnut' },
                    tooltip: { enabled: true }
                }
            }
        });
    })();
</script>
<?php endif; ?>

<?php require_once '../../includes/footer.php'; ?>

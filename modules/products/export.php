<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

if (!hasPermission('products.view')) {
    setAlert('danger', 'You do not have permission to access this page.');
    redirect('../../dashboard.php');
}

$filterType = null;
if (isset($_GET['type']) && in_array($_GET['type'], ['material', 'final'], true)) {
    $filterType = $_GET['type'];
}

$customerFilter = null;
if (isset($_GET['customer_id']) && is_numeric($_GET['customer_id']) && (int)$_GET['customer_id'] > 0) {
    $customerFilter = (int)$_GET['customer_id'];
}

$categoryFilter = null;
if (isset($_GET['category_id']) && is_numeric($_GET['category_id']) && (int)$_GET['category_id'] > 0) {
    $categoryFilter = (int)$_GET['category_id'];
}

$subcategoryFilter = null;
if (isset($_GET['subcategory_id']) && is_numeric($_GET['subcategory_id']) && (int)$_GET['subcategory_id'] > 0) {
    $subcategoryFilter = (int)$_GET['subcategory_id'];
}

$searchFilter = null;
if (isset($_GET['search']) && !empty(trim($_GET['search']))) {
    $searchFilter = trim($_GET['search']);
}

$whereClauses = [];
$paramTypes = '';
$paramValues = [];

if ($filterType !== null) {
    $whereClauses[] = 'p.type = ?';
    $paramTypes .= 's';
    $paramValues[] = $filterType;
}

if ($customerFilter !== null) {
    $whereClauses[] = 'p.customer_id = ?';
    $paramTypes .= 'i';
    $paramValues[] = $customerFilter;
}

if ($categoryFilter !== null) {
    $whereClauses[] = 'p.category_id = ?';
    $paramTypes .= 'i';
    $paramValues[] = $categoryFilter;
}

if ($subcategoryFilter !== null) {
    $whereClauses[] = 'p.subcategory_id = ?';
    $paramTypes .= 'i';
    $paramValues[] = $subcategoryFilter;
}

if ($searchFilter !== null) {
    $whereClauses[] = '(p.name LIKE ? OR p.sku LIKE ? OR p.barcode LIKE ? OR p.description LIKE ?)';
    $paramTypes .= 'ssss';
    $likeSearch = '%' . $searchFilter . '%';
    $paramValues[] = $likeSearch;
    $paramValues[] = $likeSearch;
    $paramValues[] = $likeSearch;
    $paramValues[] = $likeSearch;
}

$sql = "SELECT p.*, c1.name as category_name, c2.name as subcategory_name, cust.name as customer_name
        FROM products p
        LEFT JOIN categories c1 ON p.category_id = c1.id
        LEFT JOIN categories c2 ON p.subcategory_id = c2.id
        LEFT JOIN customers cust ON p.customer_id = cust.id";

if (!empty($whereClauses)) {
    $sql .= ' WHERE ' . implode(' AND ', $whereClauses);
}

$sql .= " ORDER BY p.name";

if (!empty($whereClauses)) {
    $stmt = $conn->prepare($sql);
    $bindParams = array_merge([$paramTypes], $paramValues);
    $bindRefs = [];
    foreach ($bindParams as $key => $value) {
        $bindRefs[$key] = &$bindParams[$key];
    }
    call_user_func_array([$stmt, 'bind_param'], $bindRefs);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $conn->query($sql);
}

// Generate CSV
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=products_export_' . date('Y-m-d_His') . '.csv');

$output = fopen('php://output', 'w');

// Header row
$headers = ['SKU', 'Barcode', 'Name', 'Description', 'Type', 'Category', 'Subcategory', 'Customer'];

if (hasExplicitPermission('products.final.price.view') || hasExplicitPermission('products.material.price.view')) {
    $headers[] = 'Unit Price';
}
if (hasExplicitPermission('products.final.cost.view') || hasExplicitPermission('products.material.cost.view')) {
    $headers[] = 'Cost Price';
}
$headers[] = 'Min Stock Level';

fputcsv($output, $headers);

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $csvRow = [
            $row['sku'],
            $row['barcode'],
            $row['name'],
            $row['description'],
            ucfirst($row['type']),
            $row['category_name'],
            $row['subcategory_name'],
            $row['customer_name']
        ];

        // View logic for prices
        if (hasExplicitPermission('products.final.price.view') || hasExplicitPermission('products.material.price.view')) {
            $csvRow[] = canViewProductPrice($row['type']) ? $row['unit_price'] : 'N/A';
        }
        if (hasExplicitPermission('products.final.cost.view') || hasExplicitPermission('products.material.cost.view')) {
            $csvRow[] = canViewProductCost($row['type']) ? $row['cost_price'] : 'N/A';
        }
        $csvRow[] = $row['min_stock_level'];

        fputcsv($output, $csvRow);
    }
}

fclose($output);
exit();

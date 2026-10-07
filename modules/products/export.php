<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../includes/export_helpers.php';

if (!hasPermission('products.view')) {
    setAlert('danger', 'You do not have permission to access this page.');
    redirect('../../dashboard.php');
}

$filterType = null;
if (isset($_GET['type']) && in_array($_GET['type'], ['material', 'final'], true)) {
    $filterType = $_GET['type'];
}
if (isSalesPersonUser()) {
    $filterType = 'final';
}
$showArchived = productsSupportArchiving() && (($_GET['view'] ?? '') === 'archived');

$customerFilters = [];
if (isset($_GET['customer_ids']) && is_array($_GET['customer_ids'])) {
    foreach ($_GET['customer_ids'] as $id) {
        if (is_numeric($id) && (int)$id > 0) $customerFilters[] = (int)$id;
    }
} elseif (isset($_GET['customer_id']) && is_numeric($_GET['customer_id']) && (int)$_GET['customer_id'] > 0) {
    $customerFilters[] = (int)$_GET['customer_id'];
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
$whereClauses[] = getProductChannelScopeSql('p', 'cust', 'customer_factory');
if (productsSupportArchiving()) {
    $whereClauses[] = $showArchived ? 'p.is_active = 0' : 'p.is_active = 1';
}

if ($filterType !== null) {
    $whereClauses[] = 'p.type = ?';
    $paramTypes .= 's';
    $paramValues[] = $filterType;
}

if ($customerFilters) {
    $whereClauses[] = 'p.customer_id IN (' . implode(',', array_fill(0, count($customerFilters), '?')) . ')';
    $paramTypes .= str_repeat('i', count($customerFilters));
    $paramValues = array_merge($paramValues, $customerFilters);
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

$sql = "SELECT p.*, c1.name as category_name, c2.name as subcategory_name, cust.name as customer_name,
               COALESCE((SELECT SUM(ip.quantity) FROM inventory_products ip JOIN inventories inv ON ip.inventory_id = inv.id WHERE ip.product_id = p.id AND inv.is_active = 1), 0) AS total_quantity
        FROM products p
        LEFT JOIN categories c1 ON p.category_id = c1.id
        LEFT JOIN categories c2 ON p.subcategory_id = c2.id
        LEFT JOIN customers cust ON p.customer_id = cust.id
        LEFT JOIN factories customer_factory ON customer_factory.id = cust.factory_id";

if (!empty($whereClauses)) {
    $sql .= ' WHERE ' . implode(' AND ', $whereClauses);
}

$sql .= " ORDER BY p.name";

if ($paramTypes !== '') {
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
$exportRows = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $exportRows[] = $row;
    }
}
$exportCostDetails = getCalculatedProductCostDetails(array_column($exportRows, 'id'));

// Both formats use the same columns and permission checks.
$headers = ['Product ID', 'SKU', 'Barcode', 'Product Name', 'Description', 'Product Type', 'Unit', 'Category', 'Subcategory', 'Customer', 'Status'];
$showPrice = hasExplicitPermission('products.final.price.view');
$showCost = hasExplicitPermission('products.final.cost.view') || hasExplicitPermission('products.material.cost.view');
if ($showPrice) $headers[] = 'Selling Price';
if ($showCost) $headers[] = 'Calculated Unit Cost';
$headers = array_merge($headers, ['Minimum Stock Level', 'Stock Quantity', 'Low Stock', 'Created At', 'Updated At']);
$data = [$headers];
foreach ($exportRows as $row) {
    $values = [
        (int)$row['id'], $row['sku'], $row['barcode'], $row['name'], $row['description'],
        $row['type'] === 'final' ? 'Finished Product' : ucfirst($row['type'] ?? ''),
        getProductUnitLabel($row['unit'] ?? ''), $row['category_name'], $row['subcategory_name'],
        $row['customer_name'], $showArchived ? 'Archived' : 'Active',
    ];
    if ($showPrice) $values[] = canViewProductPrice($row['type']) ? (float)$row['unit_price'] : 'Hidden';
    if ($showCost) $values[] = canViewProductCost($row['type']) ? (float)($exportCostDetails[(int)$row['id']]['value'] ?? $row['cost_price']) : 'Hidden';
    $values = array_merge($values, [
        (float)$row['min_stock_level'], (float)$row['total_quantity'],
        $row['min_stock_level'] > 0 && $row['total_quantity'] <= $row['min_stock_level'] ? 'Yes' : 'No',
        $row['created_at'] ?? '', $row['updated_at'] ?? '',
    ]);
    $data[] = $values;
}
$filename = 'products_' . ($showArchived ? 'archived_' : '') . date('Y-m-d_His');
if (($_GET['format'] ?? '') === 'excel') {
    require_once '../../includes/libs/SimpleXLSXGen.php';
    foreach ($data as $rowIndex => &$values) {
        if ($rowIndex === 0) continue;
        foreach ($values as &$value) {
            if (is_string($value)) $value = exportText($value);
        }
        unset($value);
    }
    unset($values);
    $xlsx = Shuchkin\SimpleXLSXGen::create('Products');
    exportWorkbookSheet($xlsx, $data, 'Products');
    $xlsx->downloadAs($filename . '.xlsx');
} else {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    $output = fopen('php://output', 'w');
    fwrite($output, "\xEF\xBB\xBF");
    foreach ($data as $values) exportCsvRow($output, $values);
    fclose($output);
}
exit;

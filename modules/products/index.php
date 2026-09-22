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
if (isSalesPersonUser()) {
    $filterType = 'final';
}
$showArchived = productsSupportArchiving() && (($_GET['view'] ?? '') === 'archived');

$customerFilters = [];
if (isset($_GET['customer_ids']) && is_array($_GET['customer_ids'])) {
    foreach ($_GET['customer_ids'] as $cid) {
        if (is_numeric($cid) && (int)$cid > 0) {
            $customerFilters[] = (int)$cid;
        }
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

$customers = [];
$customerSql = "SELECT c.id, c.name FROM customers c LEFT JOIN factories f ON f.id = c.factory_id";
$customerSql .= " WHERE " . getCustomerChannelScopeSql('c', 'f');
$customerResult = $conn->query($customerSql . " ORDER BY c.name");
if ($customerResult) {
    while ($customerRow = $customerResult->fetch_assoc()) {
        $customers[] = $customerRow;
    }
}

$categoriesList = [];
$catResult = $conn->query("SELECT id, name FROM categories WHERE parent_id IS NULL ORDER BY name");
if ($catResult) {
    while ($catRow = $catResult->fetch_assoc()) {
        $categoriesList[] = $catRow;
    }
}

$subcategoriesList = [];
if ($categoryFilter) {
    $stmt = $conn->prepare("SELECT id, name FROM categories WHERE parent_id = ? ORDER BY name");
    $stmt->bind_param("i", $categoryFilter);
    $stmt->execute();
    $subResult = $stmt->get_result();
    while ($subRow = $subResult->fetch_assoc()) {
        $subcategoriesList[] = $subRow;
    }
    $stmt->close();
}

$page_title = ($showArchived ? 'Archived ' : '') . ($filterType === 'material'
    ? 'Raw Materials'
    : ($filterType === 'final' ? 'Final Products' : 'Products Management'));
require_once '../../includes/header.php';

if (isset($_GET['restore']) && is_numeric($_GET['restore']) && productsSupportArchiving()) {
    $id = (int)$_GET['restore'];
    $returnUrl = getSafeProductReturnUrl($_GET['return_to'] ?? '', 'index.php?view=archived');
    if (!hasPermission('products.edit') || !canAccessProduct($id)) {
        setAlert('danger', 'You do not have permission to restore this product.');
    } else {
        $restoreStmt = $conn->prepare('UPDATE products SET is_active = 1 WHERE id = ?');
        $restoreStmt->bind_param('i', $id);
        $restoreStmt->execute();
        $restoreStmt->close();
        setAlert('success', 'Product restored successfully.');
        logActivity("Restored product ID: $id");
    }
    redirect($returnUrl);
}

// Handle delete request
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $returnUrl = getSafeProductReturnUrl($_GET['return_to'] ?? '', $filterType ? 'index.php?type=' . urlencode($filterType) : 'index.php');

    if (!hasPermission('products.delete') || !canAccessProduct($id)) {
        setAlert('danger', 'You do not have permission to delete this product.');
        redirect($returnUrl);
    }

    $usageReasons = getProductUsageReasons($id);
    if (!empty($usageReasons) && productsSupportArchiving()) {
        $archiveStmt = $conn->prepare('UPDATE products SET is_active = 0 WHERE id = ?');
        $archiveStmt->bind_param('i', $id);
        $archiveStmt->execute();
        $archiveStmt->close();
        setAlert('success', 'Product archived because it has ' . implode(', ', $usageReasons) . '. Its history and stock were preserved.');
        logActivity("Archived product ID: $id", ['reasons' => $usageReasons]);
    } elseif (!empty($usageReasons)) {
        setAlert('danger', 'Cannot delete this product because it has ' . implode(', ', $usageReasons) . '. Apply the product-archiving migration first.');
    } else {
        $deleteStmt = $conn->prepare('DELETE FROM products WHERE id = ?');
        $deleteStmt->bind_param('i', $id);
        if ($deleteStmt->execute()) {
            setAlert('success', 'Unused product deleted permanently.');
            logActivity("Deleted product ID: $id");
        } else {
            setAlert('danger', 'Error deleting product: ' . $deleteStmt->error);
        }
        $deleteStmt->close();
    }
    redirect($returnUrl);
}

// Fetch all products with category and customer info
$whereClauses = [];
$paramTypes = '';
$paramValues = [];
$loginRegion = $_SESSION['login_region'] ?? 'factory';
$whereClauses[] = getProductChannelScopeSql('p', 'cust', 'customer_factory');
if (productsSupportArchiving()) {
    $whereClauses[] = $showArchived ? 'p.is_active = 0' : 'p.is_active = 1';
}

if ($filterType !== null) {
    $whereClauses[] = 'p.type = ?';
    $paramTypes .= 's';
    $paramValues[] = $filterType;
}

if (!empty($customerFilters)) {
    $placeholders = implode(',', array_fill(0, count($customerFilters), '?'));
    $whereClauses[] = "p.customer_id IN ($placeholders)";
    $paramTypes .= str_repeat('i', count($customerFilters));
    foreach ($customerFilters as $cid) {
        $paramValues[] = $cid;
    }
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

$products = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $products[] = $row;
    }
}
$productCostDetails = getCalculatedProductCostDetails(array_column($products, 'id'));

$canViewAnySellingPrice = hasExplicitPermission('products.final.price.view');
$canViewAnyCostPrice = hasExplicitPermission('products.final.cost.view') || hasExplicitPermission('products.material.cost.view');
$showSellingPriceColumn = false;
$showCostPriceColumn = false;
$showUnitColumn = false;
foreach ($products as $productRow) {
    if ($productRow['type'] === 'material') {
        $showUnitColumn = true;
    }
    if (!$showSellingPriceColumn && $canViewAnySellingPrice && canViewProductPrice($productRow['type'])) {
        $showSellingPriceColumn = true;
    }
    if (!$showCostPriceColumn && $canViewAnyCostPrice && canViewProductCost($productRow['type'])) {
        $showCostPriceColumn = true;
    }
    if ($showSellingPriceColumn && $showCostPriceColumn) {
        break;
    }
}
$productsTableColspan = 8 + ($showUnitColumn ? 1 : 0) + ($showSellingPriceColumn ? 1 : 0) + ($showCostPriceColumn ? 1 : 0);

// Fetch all active inventories (1 query)
$inventories = [];
$invResult = $conn->query("SELECT id, name FROM inventories WHERE is_active = 1 ORDER BY name");
if ($invResult) {
    while ($invRow = $invResult->fetch_assoc()) {
        $inventories[] = $invRow;
    }
}

// Build a stock lookup map: $stockMap[product_id][inventory_id] = quantity (1 query)
$stockMap = [];
if (!empty($inventories) && !empty($products)) {
    $productIds = array_column($products, 'id');
    $invIds = array_column($inventories, 'id');
    $pidPlaceholders = implode(',', array_fill(0, count($productIds), '?'));
    $invPlaceholders = implode(',', array_fill(0, count($invIds), '?'));
    $stockSql = "SELECT product_id, inventory_id, quantity FROM inventory_products
                 WHERE product_id IN ($pidPlaceholders) AND inventory_id IN ($invPlaceholders)";
    $stockStmt = $conn->prepare($stockSql);
    $types = str_repeat('i', count($productIds) + count($invIds));
    $allIds = array_merge($productIds, $invIds);
    $refs = [];
    $refs[] = $types;
    foreach ($allIds as $k => $v) {
        $allIds[$k] = $v;
        $refs[] = &$allIds[$k];
    }
    call_user_func_array([$stockStmt, 'bind_param'], $refs);
    $stockStmt->execute();
    $stockResult = $stockStmt->get_result();
    while ($stockRow = $stockResult->fetch_assoc()) {
        $stockMap[(int)$stockRow['product_id']][(int)$stockRow['inventory_id']] = $stockRow['quantity'];
    }
    $stockStmt->close();
}

$productsTableColspan += 1;
$returnQuery = $_GET;
unset($returnQuery['delete'], $returnQuery['restore'], $returnQuery['return_to']);
$currentReturnUrl = 'index.php' . (!empty($returnQuery) ? '?' . http_build_query($returnQuery) : '') . '#productsTable';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>
        <?php if ($filterType === 'material'): ?>
            <?= $showArchived ? 'Archived ' : '' ?>Raw Materials
        <?php elseif ($filterType === 'final'): ?>
            <?= $showArchived ? 'Archived ' : '' ?>Final Products
        <?php else: ?>
            <?= $showArchived ? 'Archived ' : '' ?>Products
        <?php endif; ?>
    </h2>
    <div>
        <a href="upload.php" class="btn btn-info me-2">
            <i class="fas fa-upload"></i> Bulk Upload
        </a>
        <a href="export.php<?php echo '?' . http_build_query(array_merge($_GET, ['format' => 'excel'])); ?>" class="btn btn-success me-2">
            <i class="fas fa-file-excel"></i> Export Excel
        </a>
        <?php if (productsSupportArchiving()): ?>
            <?php
            $archiveToggleQuery = $_GET;
            if ($showArchived) {
                unset($archiveToggleQuery['view']);
            } else {
                $archiveToggleQuery['view'] = 'archived';
            }
            ?>
            <a href="index.php<?= !empty($archiveToggleQuery) ? '?' . htmlspecialchars(http_build_query($archiveToggleQuery)) : '' ?>" class="btn btn-outline-secondary me-2">
                <i class="fas <?= $showArchived ? 'fa-box-open' : 'fa-archive' ?>"></i>
                <?= $showArchived ? 'Active Products' : 'Archived' ?>
            </a>
        <?php endif; ?>
        <?php if (!$showArchived): ?>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addProductModal">
            <i class="fas fa-plus"></i> Add Product
        </button>
        <?php endif; ?>
    </div>
</div>

<div class="mb-3">
    <form class="d-flex flex-wrap gap-2 align-items-end" method="get">
        <?php if ($filterType !== null): ?>
            <input type="hidden" name="type" value="<?php echo htmlspecialchars($filterType); ?>">
        <?php endif; ?>
        <?php if ($showArchived): ?>
            <input type="hidden" name="view" value="archived">
        <?php endif; ?>
        <div class="me-1">
            <label class="form-label mb-1 small text-muted">Customer(s)</label>
            <select class="form-select form-select-sm js-searchable-select" name="customer_ids[]" multiple style="min-width: 200px;">
                <?php foreach ($customers as $customer): ?>
                    <option value="<?php echo (int)$customer['id']; ?>" <?php echo (in_array((int)$customer['id'], $customerFilters)) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($customer['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="me-1">
            <label class="form-label mb-1 small text-muted">Category</label>
            <select class="form-select form-select-sm js-searchable-select" name="category_id" id="filter_category_id" style="min-width: 150px;">
                <option value="">All Categories</option>
                <?php foreach ($categoriesList as $cat): ?>
                    <option value="<?php echo $cat['id']; ?>" <?php echo ($categoryFilter === (int)$cat['id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($cat['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="me-1">
            <label class="form-label mb-1 small text-muted">Subcategory</label>
            <select class="form-select form-select-sm js-searchable-select" name="subcategory_id" id="filter_subcategory_id" style="min-width: 150px;">
                <option value="">All Subcategories</option>
                <?php foreach ($subcategoriesList as $sub): ?>
                    <option value="<?php echo $sub['id']; ?>" <?php echo ($subcategoryFilter === (int)$sub['id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($sub['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="me-1">
            <label class="form-label mb-1 small text-muted">Search (Name/SKU/Barcode)</label>
            <input type="text" name="search" class="form-control form-control-sm" value="<?php echo htmlspecialchars($searchFilter ?? ''); ?>" placeholder="Search...">
        </div>
        <button type="submit" class="btn btn-sm btn-outline-primary">Filter</button>
        <?php if (!empty($customerFilters) || $categoryFilter !== null || $subcategoryFilter !== null || $searchFilter !== null): ?>
            <?php
            $resetParams = [];
            if ($filterType !== null) $resetParams['type'] = $filterType;
            if ($showArchived) $resetParams['view'] = 'archived';
            ?>
            <a href="index.php<?= !empty($resetParams) ? '?' . htmlspecialchars(http_build_query($resetParams)) : '' ?>" class="btn btn-sm btn-outline-secondary">
                Reset
            </a>
        <?php endif; ?>
        <?php if (!$showArchived && (hasPermission('products.delete') || ($filterType === 'material' && hasPermission('products.edit')))): ?>
        <div id="bulkActions" class="d-none ms-auto">
            <?php if (!$showArchived && $filterType === 'material' && hasPermission('products.edit')): ?>
            <button type="button" class="btn btn-sm btn-outline-primary" id="btnBulkUnit" data-bs-toggle="modal" data-bs-target="#bulkUnitModal">
                <i class="fas fa-balance-scale me-1"></i> Set Unit (<span class="selected-count">0</span>)
            </button>
            <?php endif; ?>
            <?php if (!$showArchived && hasPermission('products.delete')): ?>
            <button type="button" class="btn btn-sm btn-danger" id="btnBulkDelete">
                <i class="fas fa-trash me-1"></i> Remove (<span id="selectedCount">0</span>)
            </button>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </form>
</div>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table js-datatable table-hover" id="productsTable" data-table-state-save="true">
                <thead>
                    <tr>
                        <th width="40"><input type="checkbox" class="form-check-input" id="selectAll"></th>
                        <th>SKU</th>
                        <th>Image</th>
                        <th>Name</th>
                        <th>Type</th>
                        <th>Category</th>
                        <th>Subcategory</th>
                        <th>Customer</th>
                        <?php if ($showUnitColumn): ?>
                        <th>Unit</th>
                        <?php endif; ?>
                        <?php if ($showSellingPriceColumn): ?>
                        <th>Selling Price</th>
                        <?php endif; ?>
                        <?php if ($showCostPriceColumn): ?>
                        <th>Cost Price</th>
                        <?php endif; ?>
                        <th>Quantity</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($products) > 0): ?>
                        <?php foreach ($products as $row): ?>
                            <?php $costDetail = $productCostDetails[(int)$row['id']] ?? ['value' => null, 'source' => 'missing']; ?>
                            <tr data-id="<?php echo $row['id']; ?>">
                                <td><input type="checkbox" class="form-check-input row-select" name="product_ids[]" value="<?php echo $row['id']; ?>"></td>
                                <?php
                                $imageName = $row['image'] ?? '';
                                $productImagePath = $imageName ? __DIR__ . '/../../assets/uploads/products/' . $imageName : '';
                                $hasProductImage = $imageName && file_exists($productImagePath);
                                $productImageUrl = $hasProductImage ? '../../assets/uploads/products/' . rawurlencode($imageName) : '';
                                ?>
                                <td><?php echo htmlspecialchars($row['sku']); ?></td>
                                <td class="text-center align-middle">
                                    <?php if ($hasProductImage): ?>
                                        <img src="<?php echo $productImageUrl; ?>"
                                             alt="<?php echo htmlspecialchars($row['name']); ?> image"
                                             style="width: 52px; height: 52px; object-fit: cover; border-radius: 6px; border: 1px solid #dee2e6;">
                                    <?php else: ?>
                                        <span class="text-muted small">No image</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($row['name']); ?></td>
                                <td>
                                    <span class="badge bg-<?php echo getProductTypeColor($row['type']); ?>">
                                        <?php echo ucfirst($row['type']); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($row['category_name']); ?></td>
                                <td><?php echo $row['subcategory_name'] ? htmlspecialchars($row['subcategory_name']) : '-'; ?></td>
                                <td><?php echo $row['customer_name'] ? htmlspecialchars($row['customer_name']) : '-'; ?></td>
                                <?php if ($showUnitColumn): ?>
                                <td><?= $row['type'] === 'material' ? htmlspecialchars(getProductUnitLabel($row['unit'] ?? '') ?: 'Not set') : '-' ?></td>
                                <?php endif; ?>
                                <?php if ($showSellingPriceColumn): ?>
                                <td>
                                    <?php if (canViewProductPrice($row['type'])): ?>
                                            <?php echo isset($row['unit_price']) && $row['unit_price'] !== '' ? number_format((float)$row['unit_price'], 2) : '-'; ?>
                                        <?php endif; ?>
                                </td>
                                <?php endif; ?>
                                <?php if ($showCostPriceColumn): ?>
                                <td>
                                    <?php if (canViewProductCost($row['type'])): ?>
                                        <?php if ($costDetail['value'] !== null): ?>
                                            <span title="<?= htmlspecialchars(
                                                $costDetail['source'] === 'received_average'
                                                    ? 'Weighted average of quantities actually received'
                                                    : ($costDetail['source'] === 'formula'
                                                        ? 'Calculated from formula: ' . ($costDetail['formula_name'] ?? '')
                                                        : 'Manually entered cost')
                                            ) ?>"><?= number_format((float)$costDetail['value'], 2) ?></span>
                                            <?php if ($costDetail['source'] === 'received_average'): ?>
                                                <div class="small text-muted">received avg<?= !empty($costDetail['basis_unit']) ? ' / ' . htmlspecialchars($costDetail['basis_unit']) : '' ?></div>
                                            <?php elseif ($costDetail['source'] === 'formula'): ?>
                                                <div class="small text-muted">formula<?= !empty($costDetail['basis_unit']) ? ' / ' . htmlspecialchars($costDetail['basis_unit']) : '' ?></div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <?php $missingCostTitle = !empty($costDetail['missing_components']) ? 'Missing component costs: ' . implode(', ', $costDetail['missing_components']) : 'No cost available'; ?>
                                            <span title="<?= htmlspecialchars($missingCostTitle) ?>">-</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <?php endif; ?>
                                <td>
                                    <?php
                                    $stockLines = [];
                                    $rowTotal = 0;
                                    foreach ($inventories as $inv) {
                                        $qty = $stockMap[$row['id']][$inv['id']] ?? 0;
                                        if ($qty > 0) {
                                            $rowTotal += $qty;
                                            $qtyFormatted = ($qty == floor($qty)) ? (int)$qty : number_format((float)$qty, 2);
                                            $unitSuffix = $row['type'] === 'material' ? ' ' . getProductUnitLabel($row['unit'] ?? '') : '';
                                            $stockLines[] = '<span class="text-muted small">' . htmlspecialchars($inv['name']) . ':</span> <strong>' . $qtyFormatted . htmlspecialchars($unitSuffix) . '</strong>';
                                        }
                                    }
                                    if (!empty($stockLines)) {
                                        $totalFormatted = ($rowTotal == floor($rowTotal)) ? (int)$rowTotal : number_format((float)$rowTotal, 2);
                                        echo implode('<br>', $stockLines);
                                        echo '<hr class="my-1">';
                                        $unitSuffix = $row['type'] === 'material' ? ' ' . getProductUnitLabel($row['unit'] ?? '') : '';
                                        echo '<span class="text-muted small">Total:</span> <strong>' . $totalFormatted . htmlspecialchars($unitSuffix) . '</strong>';
                                    } else {
                                        echo '<span class="text-muted small">—</span>';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <a href="view.php?id=<?php echo $row['id']; ?>" class="btn btn-sm btn-outline-primary">
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                    <?php if ($showArchived): ?>
                                    <?php if (hasPermission('products.edit')): ?>
                                    <a href="index.php?restore=<?= (int)$row['id'] ?>&amp;return_to=<?= urlencode($currentReturnUrl) ?>" class="btn btn-sm btn-outline-success"
                                       onclick="return confirm('Restore this product to active lists?')">
                                        <i class="fas fa-undo"></i> Restore
                                    </a>
                                    <?php endif; ?>
                                    <?php else: ?>
                                    <?php if (hasPermission('products.edit')): ?>
                                    <button class="btn btn-sm btn-outline-warning edit-product"
                                        data-id="<?php echo $row['id']; ?>"
                                        data-name="<?php echo htmlspecialchars($row['name']); ?>"
                                        data-sku="<?php echo htmlspecialchars($row['sku']); ?>"
                                        data-barcode="<?php echo htmlspecialchars($row['barcode'] ?? ''); ?>"
                                        data-type="<?php echo htmlspecialchars($row['type']); ?>"
                                        data-category="<?php echo $row['category_id'] ?? ''; ?>"
                                        data-subcategory="<?php echo $row['subcategory_id'] ?? ''; ?>"
                                        data-customer="<?php echo isset($row['customer_id']) ? (int)$row['customer_id'] : ''; ?>"
                                        data-unit="<?php echo htmlspecialchars($row['unit'] ?? ''); ?>"
                                        data-unit_price="<?php echo canViewProductPrice($row['type']) ? $row['unit_price'] : ''; ?>"
                                        data-cost_price="<?php echo canViewProductCost($row['type']) ? ($row['cost_price'] ?? '') : ''; ?>"
                                        data-min_stock="<?php echo $row['min_stock_level'] ?? 0; ?>"
                                        data-description="<?php echo htmlspecialchars($row['description'] ?? ''); ?>">
                                        <i class="fas fa-edit"></i> Edit
                                    </button>
                                    <?php endif; ?>

                                    <?php if (hasPermission('products.delete')): ?>
                                    <a href="index.php?delete=<?php echo $row['id']; ?>&amp;return_to=<?= urlencode($currentReturnUrl) ?>" class="btn btn-sm btn-outline-danger"
                                        onclick="return confirm('Remove this product? Products with stock or history will be archived safely; only unused products are deleted permanently.')">
                                        <i class="fas fa-trash"></i> Remove
                                    </a>
                                    <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if (count($products) === 0): ?>
            <div class="text-center text-muted py-3">No products found</div>
        <?php endif; ?>
    </div>
</div>

<!-- Add Product Modal -->
<div class="modal fade" id="addProductModal" tabindex="-1" aria-labelledby="addProductModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form action="create.php" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="return_to" value="<?= htmlspecialchars($currentReturnUrl) ?>">
                <div class="modal-header">
                    <h5 class="modal-title" id="addProductModalLabel">Add New Product</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="name" class="form-label">Product Name</label>
                            <input type="text" class="form-control" id="name" name="name" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="sku" class="form-label">SKU</label>
                            <input type="text" class="form-control" id="sku" name="sku" placeholder="Auto-generated" readonly>
                            <small class="text-muted">SKU is generated automatically.</small>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="barcode" class="form-label">Barcode</label>
                            <input type="text" class="form-control" id="barcode" name="barcode" placeholder="Auto-generated" readonly>
                            <small class="text-muted">Barcode is generated automatically.</small>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="type" class="form-label">Product Type</label>
                            <select class="form-select js-product-type" id="type" name="type" required>
                                <option value="">-- Select Type --</option>
                                <!-- <option value="primary">Primary Product</option> -->
                                <option value="final">Final Product</option>
                                <?php if ($loginRegion === 'factory' && !isSalesPersonUser()): ?>
                                <option value="material">Material</option>
                                <?php endif; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="category_id" class="form-label">Category</label>
                            <select class="form-select" id="category_id" name="category_id" required>
                                <option value="">-- Select Category --</option>
                                <?php
                                $categories = $conn->query("SELECT id, name FROM categories WHERE parent_id IS NULL ORDER BY name");
                                while ($cat = $categories->fetch_assoc()) {
                                    echo '<option value="' . $cat['id'] . '">' . htmlspecialchars($cat['name']) . '</option>';
                                }
                                ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="subcategory_id" class="form-label">Subcategory</label>
                            <select class="form-select" id="subcategory_id" name="subcategory_id">
                                <option value="">-- Select Subcategory --</option>
                            </select>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-12 mb-3" data-customer-group="customer">
                            <label for="customer_id" class="form-label">Customer <span class="text-danger">*</span></label>
                            <select class="form-select js-searchable-select" id="customer_id" name="customer_id" required>
                                <option value="">-- Select Customer --</option>
                                <?php foreach ($customers as $customer): ?>
                                    <option value="<?php echo (int)$customer['id']; ?>">
                                        <?php echo htmlspecialchars($customer['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3" data-unit-group>
                            <label for="unit" class="form-label">Unit <span class="text-danger">*</span></label>
                            <select class="form-select" id="unit" name="unit" data-role="product-unit">
                                <option value="">-- Select Unit --</option>
                                <?php foreach (getProductUnitOptions() as $unitValue => $unitLabel): ?>
                                    <option value="<?= htmlspecialchars($unitValue) ?>"><?= htmlspecialchars($unitLabel) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Weight: 1 kg = 1,000 g. Volume: 1 L = 1,000 ml. Each (pcs) is a count.</small>
                        </div>
                        <div class="col-md-6 mb-3" data-pricing-group="unit">
                            <label for="unit_price" class="form-label">Selling Price</label>
                            <input type="number" class="form-control" id="unit_price" name="unit_price" min="0" step="0.01" data-role="unit-price">
                        </div>
                        <div class="col-md-6 mb-3" data-pricing-group="cost">
                            <label for="cost_price" class="form-label">Cost Price</label>
                            <input type="number" class="form-control" id="cost_price" name="cost_price" min="0" step="0.01" data-role="cost-price">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="min_stock_level" class="form-label">Minimum Stock Level</label>
                            <input type="number" class="form-control" id="min_stock_level" name="min_stock_level" min="0" value="0">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="image" class="form-label">Product Image</label>
                            <input type="file" class="form-control" id="image" name="image" accept="image/*">
                            <div id="image_preview" class="mt-2"></div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="description" class="form-label">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary">Save Product</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Product Modal -->
<div class="modal fade" id="editProductModal" tabindex="-1" aria-labelledby="editProductModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form action="edit.php" method="POST" enctype="multipart/form-data">
                <input type="hidden" id="edit_id" name="id">
                <input type="hidden" name="return_to" value="<?= htmlspecialchars($currentReturnUrl) ?>">
                <div class="modal-header">
                    <h5 class="modal-title" id="editProductModalLabel">Edit Product</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="edit_name" class="form-label">Product Name</label>
                            <input type="text" class="form-control" id="edit_name" name="name" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="edit_sku" class="form-label">SKU</label>
                            <input type="text" class="form-control" id="edit_sku" name="sku" required readonly>
                            <small class="text-muted">SKU is auto-generated and cannot be changed here.</small>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="edit_barcode" class="form-label">Barcode</label>
                            <input type="text" class="form-control" id="edit_barcode" name="barcode">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="edit_type" class="form-label">Product Type</label>
                            <select class="form-select js-product-type" id="edit_type" name="type" required>
                                <!-- <option value="primary">Primary Product</option> -->
                                <option value="final">Final Product</option>
                                <?php if ($loginRegion === 'factory' && !isSalesPersonUser()): ?>
                                <option value="material">Material</option>
                                <?php endif; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="edit_category_id" class="form-label">Category</label>
                            <select class="form-select" id="edit_category_id" name="category_id" required>
                                <option value="">-- Select Category --</option>
                                <?php
                                $categories = $conn->query("SELECT id, name FROM categories WHERE parent_id IS NULL ORDER BY name");
                                while ($cat = $categories->fetch_assoc()) {
                                    echo '<option value="' . $cat['id'] . '">' . htmlspecialchars($cat['name']) . '</option>';
                                }
                                ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="edit_subcategory_id" class="form-label">Subcategory</label>
                            <select class="form-select" id="edit_subcategory_id" name="subcategory_id">
                                <option value="">-- Select Subcategory --</option>
                            </select>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-12 mb-3" data-customer-group="customer">
                            <label for="edit_customer_id" class="form-label">Customer <span class="text-danger">*</span></label>
                            <select class="form-select js-searchable-select" id="edit_customer_id" name="customer_id" required>
                                <option value="">-- Select Customer --</option>
                                <?php foreach ($customers as $customer): ?>
                                    <option value="<?php echo (int)$customer['id']; ?>">
                                        <?php echo htmlspecialchars($customer['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3" data-unit-group>
                            <label for="edit_unit" class="form-label">Unit <span class="text-danger">*</span></label>
                            <select class="form-select" id="edit_unit" name="unit" data-role="product-unit">
                                <option value="">-- Select Unit --</option>
                                <?php foreach (getProductUnitOptions() as $unitValue => $unitLabel): ?>
                                    <option value="<?= htmlspecialchars($unitValue) ?>"><?= htmlspecialchars($unitLabel) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Weight: 1 kg = 1,000 g. Volume: 1 L = 1,000 ml. Each (pcs) is a count.</small>
                        </div>
                        <div class="col-md-6 mb-3" data-pricing-group="unit">
                            <label for="edit_unit_price" class="form-label">Selling Price</label>
                            <input type="number" class="form-control" id="edit_unit_price" name="unit_price" min="0" step="0.01" data-role="unit-price">
                        </div>
                        <div class="col-md-6 mb-3" data-pricing-group="cost">
                            <label for="edit_cost_price" class="form-label">Cost Price</label>
                            <input type="number" class="form-control" id="edit_cost_price" name="cost_price" min="0" step="0.01" data-role="cost-price">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="edit_min_stock_level" class="form-label">Minimum Stock Level</label>
                            <input type="number" class="form-control" id="edit_min_stock_level" name="min_stock_level" min="0">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="edit_image" class="form-label">Product Image</label>
                            <input type="file" class="form-control" id="edit_image" name="image" accept="image/*">
                            <div id="current_image" class="mt-2"></div>
                            <div class="form-check mt-2 d-none" id="edit_delete_image_wrapper">
                                <input class="form-check-input" type="checkbox" id="edit_delete_image" name="delete_image" value="1">
                                <label class="form-check-label" for="edit_delete_image">Delete current image</label>
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="edit_description" class="form-label">Description</label>
                        <textarea class="form-control" id="edit_description" name="description" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary">Update Product</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if (!$showArchived && $filterType === 'material' && hasPermission('products.edit')): ?>
<div class="modal fade" id="bulkUnitModal" tabindex="-1" aria-labelledby="bulkUnitModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="bulk_update_unit.php" method="POST" id="bulkUnitForm">
                <input type="hidden" name="return_to" value="<?= htmlspecialchars($currentReturnUrl) ?>">
                <div id="bulkUnitProductIds"></div>
                <div class="modal-header">
                    <h5 class="modal-title" id="bulkUnitModalLabel">Set Raw Material Unit</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted">The selected unit will be applied to <strong><span class="selected-count">0</span></strong> raw material(s). Blank units on their historical purchase-order lines will also be filled.</p>
                    <label for="bulk_unit" class="form-label">Unit</label>
                    <select class="form-select" id="bulk_unit" name="unit" required>
                        <option value="">-- Select Unit --</option>
                        <?php foreach (getProductUnitOptions() as $unitValue => $unitLabel): ?>
                            <option value="<?= htmlspecialchars($unitValue) ?>"><?= htmlspecialchars($unitLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Selected Materials</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once '../../includes/footer.php'; ?>

<script>
    const generateSkuPreview = () => {
        const chars = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        let randomPart = '';
        for (let i = 0; i < 6; i++) {
            randomPart += chars.charAt(Math.floor(Math.random() * chars.length));
        }
        const now = new Date();
        const yy = String(now.getFullYear()).slice(-2);
        const mm = String(now.getMonth() + 1).padStart(2, '0');
        const dd = String(now.getDate()).padStart(2, '0');
        return `SKU-${yy}${mm}${dd}-${randomPart}`;
    };

    const generateBarcodePreview = () => {
        let result = '';
        for (let i = 0; i < 12; i++) {
            result += Math.floor(Math.random() * 10).toString();
        }
        return result;
    };

    const toggleProductPricingGroups = (selectEl) => {
        const type = selectEl.value;
        const form = selectEl.closest('form');
        if (!form) return;
        const showUnit = type !== 'material';
        const showCost = type !== 'final';
        const showCustomer = type !== 'material';
        const showMeasurementUnit = type === 'final' || type === 'material';
        const unitGroup = form.querySelector('[data-pricing-group="unit"]');
        const costGroup = form.querySelector('[data-pricing-group="cost"]');
        const unitInput = form.querySelector('[data-role="unit-price"]');
        const costInput = form.querySelector('[data-role="cost-price"]');
        const measurementUnitGroup = form.querySelector('[data-unit-group]');
        const measurementUnitInput = form.querySelector('[data-role="product-unit"]');
        if (unitGroup) unitGroup.classList.toggle('d-none', !showUnit);
        if (costGroup) costGroup.classList.toggle('d-none', !showCost);
        if (measurementUnitGroup) measurementUnitGroup.classList.toggle('d-none', !showMeasurementUnit);
        const customerGroup = form.querySelector('[data-customer-group="customer"]');
        const customerInput = form.querySelector('#customer_id, #edit_customer_id');
        if (customerGroup) customerGroup.classList.toggle('d-none', !showCustomer);
        if (customerInput) customerInput.required = showCustomer;
        if (unitInput) unitInput.required = showUnit;
        if (costInput) costInput.required = showCost;
        if (measurementUnitInput) measurementUnitInput.required = showMeasurementUnit;
    };

    const initProductPricingControls = () => {
        document.querySelectorAll('.js-product-type').forEach(select => {
            toggleProductPricingGroups(select);
            select.addEventListener('change', function () {
                toggleProductPricingGroups(this);
            });
        });
    };

    const initSearchableSelects = () => {
        if (typeof jQuery === 'undefined' || typeof jQuery.fn.select2 === 'undefined') {
            return;
        }

        $('.js-searchable-select').each(function () {
            const $select = $(this);
            if ($select.hasClass('select2-hidden-accessible')) {
                return;
            }

            const $modal = $select.closest('.modal');
            $select.select2({
                width: '100%',
                dropdownParent: $modal.length ? $modal : $(document.body)
            });
        });
    };

    const renderProductImagePreview = (targetSelector, imageUrl, emptyText = 'No image uploaded') => {
        const preview = document.querySelector(targetSelector);
        if (!preview) {
            return;
        }

        if (imageUrl) {
            preview.innerHTML = `<img src="${imageUrl}" alt="Product image preview" class="img-thumbnail" style="max-height: 100px; object-fit: cover;">`;
            return;
        }

        preview.innerHTML = `<p class="text-muted mb-0">${emptyText}</p>`;
    };

    const toggleEditImageDeleteOption = (show) => {
        const wrapper = document.getElementById('edit_delete_image_wrapper');
        const checkbox = document.getElementById('edit_delete_image');
        if (!wrapper || !checkbox) {
            return;
        }

        wrapper.classList.toggle('d-none', !show);
        if (!show) {
            checkbox.checked = false;
        }
    };

    const bindLocalImagePreview = (inputSelector, previewSelector, deleteOptionSelector = null) => {
        const input = document.querySelector(inputSelector);
        if (!input) {
            return;
        }

        input.addEventListener('change', function () {
            const file = this.files && this.files[0];
            if (!file) {
                renderProductImagePreview(previewSelector, null);
                return;
            }

            if (deleteOptionSelector) {
                const deleteCheckbox = document.querySelector(deleteOptionSelector);
                if (deleteCheckbox) {
                    deleteCheckbox.checked = false;
                }
                toggleEditImageDeleteOption(false);
            }
            renderProductImagePreview(previewSelector, URL.createObjectURL(file));
        });
    };

    $(document).ready(function() {
        if ($.fn.DataTable && $('#productsTable').length && !$.fn.DataTable.isDataTable('#productsTable')) {
            $('#productsTable').DataTable({
                order: [],
                pageLength: 25,
                lengthMenu: [10, 25, 50, 100],
                stateSave: true
            });
        }

        initProductPricingControls();
        initSearchableSelects();
        bindLocalImagePreview('#image', '#image_preview');
        bindLocalImagePreview('#edit_image', '#current_image', '#edit_delete_image');

        const editDeleteImage = document.getElementById('edit_delete_image');
        if (editDeleteImage) {
            editDeleteImage.addEventListener('change', function () {
                if (!this.checked) {
                    return;
                }

                const editImageInput = document.getElementById('edit_image');
                if (editImageInput) {
                    editImageInput.value = '';
                }
                renderProductImagePreview('#current_image', null, 'Current image will be deleted');
            });
        }

        const addProductModal = document.getElementById('addProductModal');
        if (addProductModal) {
            addProductModal.addEventListener('shown.bs.modal', () => {
                const skuInput = document.getElementById('sku');
                const barcodeInput = document.getElementById('barcode');
                if (skuInput && !skuInput.value) {
                    skuInput.value = generateSkuPreview();
                }
                if (barcodeInput && !barcodeInput.value) {
                    barcodeInput.value = generateBarcodePreview();
                }
            });
            addProductModal.addEventListener('hidden.bs.modal', () => {
                const skuInput = document.getElementById('sku');
                const barcodeInput = document.getElementById('barcode');
                if (skuInput) skuInput.value = '';
                if (barcodeInput) barcodeInput.value = '';
                const imageInput = document.getElementById('image');
                if (imageInput) imageInput.value = '';
                renderProductImagePreview('#image_preview', null);
            });
        }

        // Load subcategories when category changes
        $('#category_id, #filter_category_id').change(function() {
            var category_id = $(this).val();
            var target = $(this).attr('id') === 'filter_category_id' ? '#filter_subcategory_id' : '#subcategory_id';
            var default_text = $(this).attr('id') === 'filter_category_id' ? 'All Subcategories' : '-- Select Subcategory --';
            if (category_id) {
                $.ajax({
                    url: '../../ajax/get_subcategories.php',
                    type: 'GET',
                    data: {
                        category_id: category_id
                    },
                    dataType: 'json',
                    success: function(response) {
                        var options = '<option value="">' + default_text + '</option>';
                        $.each(response.subcategories, function(index, subcategory) {
                            options += '<option value="' + subcategory.id + '">' + subcategory.name + '</option>';
                        });
                        $(target).html(options);
                    }
                });
            } else {
                $(target).html('<option value="">' + default_text + '</option>');
            }
        });

        // Handle edit button click using event delegation
        $(document).on('click', '.edit-product', function() {

            const id = $(this).data('id');
            const name = $(this).data('name');
            const sku = $(this).data('sku');
            const barcode = $(this).data('barcode');
            const type = $(this).data('type');
            const category = $(this).data('category');
            const subcategory = $(this).data('subcategory');
            const unit = $(this).data('unit');
            const unit_price = $(this).data('unit_price');
            const cost_price = $(this).data('cost_price');
            const min_stock = $(this).data('min_stock') || $(this).data('min_stock_level') || 0;
            const description = $(this).data('description');

            $('#edit_id').val(id);
            $('#edit_name').val(name);
            $('#edit_sku').val(sku);
            $('#edit_barcode').val(barcode);
            $('#edit_type').val(type);
            $('#edit_unit').val(unit);
            $('#edit_unit_price').val(unit_price);
            $('#edit_cost_price').val(cost_price);
            $('#edit_min_stock_level').val(min_stock);
            $('#edit_description').val(description);
            const customer = $(this).data('customer') || '';
            $('#edit_customer_id').val(customer).trigger('change');
            $('#edit_category_id').val(category).trigger('change');

            const editTypeField = document.getElementById('edit_type');
            if (editTypeField) {
                toggleProductPricingGroups(editTypeField);
            }

            setTimeout(function() {
                $('#edit_subcategory_id').val(subcategory);
            }, 500);

            const editImageInput = document.getElementById('edit_image');
            if (editImageInput) {
                editImageInput.value = '';
            }
            toggleEditImageDeleteOption(false);

            // Load product image via AJAX
            $.ajax({
                url: '../../ajax/get_product_image.php',
                type: 'GET',
                data: {
                    product_id: id
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success && response.image_url) {
                        renderProductImagePreview('#current_image', response.image_url);
                        toggleEditImageDeleteOption(true);
                    } else {
                        renderProductImagePreview('#current_image', null);
                        toggleEditImageDeleteOption(false);
                    }
                },
                error: function() {
                    renderProductImagePreview('#current_image', null);
                    toggleEditImageDeleteOption(false);
                }
            });

            // Show the modal properly with Bootstrap 5
            const editModal = new bootstrap.Modal(document.getElementById('editProductModal'));
            editModal.show();
        });

        // Bulk selection and action logic
        const selectAll = document.getElementById('selectAll');
        const bulkActions = document.getElementById('bulkActions');
        const selectedCount = document.getElementById('selectedCount');
        const btnBulkDelete = document.getElementById('btnBulkDelete');
        const btnBulkUnit = document.getElementById('btnBulkUnit');

        const updateBulkActions = () => {
            const checkedCount = document.querySelectorAll('.row-select:checked').length;
            if (selectedCount) selectedCount.textContent = checkedCount;
            document.querySelectorAll('.selected-count').forEach(el => {
                el.textContent = checkedCount;
            });
            if (bulkActions) {
                if (checkedCount > 0) {
                    bulkActions.classList.remove('d-none');
                } else {
                    bulkActions.classList.add('d-none');
                }
            }
        };

        if (selectAll) {
            selectAll.addEventListener('change', function() {
                // Dynamically find all row checkboxes because DataTables can add/remove them
                const rowCheckboxes = document.querySelectorAll('.row-select');
                rowCheckboxes.forEach(cb => {
                    cb.checked = this.checked;
                });
                updateBulkActions();
            });
        }

        // Use event delegation for individual row checkboxes to support DataTables paging/re-rendering
        document.addEventListener('change', function(e) {
            if (e.target && e.target.classList.contains('row-select')) {
                updateBulkActions();
                
                // Update selectAll state if one is unchecked
                if (!e.target.checked && selectAll) {
                    selectAll.checked = false;
                } else if (selectAll) {
                    const allChecked = document.querySelectorAll('.row-select:not(:checked)').length === 0;
                    selectAll.checked = allChecked;
                }
            }
        });

        if (btnBulkDelete) {
            btnBulkDelete.addEventListener('click', function() {
                const selectedIds = Array.from(document.querySelectorAll('.row-select:checked')).map(cb => cb.value);
                if (selectedIds.length === 0) return;

                if (confirm(`Remove ${selectedIds.length} selected products? Used products will be archived safely; only completely unused products are deleted permanently.`)) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = 'bulk_delete.php';

                    const returnInput = document.createElement('input');
                    returnInput.type = 'hidden';
                    returnInput.name = 'return_to';
                    returnInput.value = <?= json_encode($currentReturnUrl) ?>;
                    form.appendChild(returnInput);
                    
                    selectedIds.forEach(id => {
                        const input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = 'product_ids[]';
                        input.value = id;
                        form.appendChild(input);
                    });

                    document.body.appendChild(form);
                    form.submit();
                }
            });
        }

        if (btnBulkUnit) {
            btnBulkUnit.addEventListener('click', function() {
                const selectedIds = Array.from(document.querySelectorAll('.row-select:checked')).map(cb => cb.value);
                const container = document.getElementById('bulkUnitProductIds');
                if (!container) return;
                container.innerHTML = '';
                selectedIds.forEach(id => {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'product_ids[]';
                    input.value = id;
                    container.appendChild(input);
                });
            });
        }


        // Also handle subcategory loading for edit modal
            $('#edit_category_id').change(function() {
                var category_id = $(this).val();
                if (category_id) {
                $.ajax({
                    url: '../../ajax/get_subcategories.php',
                    type: 'GET',
                    data: {
                        category_id: category_id
                    },
                    dataType: 'json',
                    success: function(response) {
                        var options = '<option value="">-- Select Subcategory --</option>';
                        $.each(response.subcategories, function(index, subcategory) {
                            options += '<option value="' + subcategory.id + '">' + subcategory.name + '</option>';
                        });
                        $('#edit_subcategory_id').html(options);
                    }
                });
            } else {
                $('#edit_subcategory_id').html('<option value="">-- Select Subcategory --</option>');
            }
        });
        $('#addProductModal, #editProductModal').on('shown.bs.modal', function () {
            initSearchableSelects();
        });
    });
</script>

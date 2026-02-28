<?php
// modules/products/view.php
// require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/functions.php';

// Check if product ID is provided
if (!isset($_GET['id'])) {
    header("Location: ../products/");
    exit();
}

$product_id = intval($_GET['id']);

// Fetch product details
$product = getProductById($product_id);
if (!$product) {
    header("Location: ../products/");
    exit();
}

// Fetch category and subcategory names
$category = getCategoryById($product['category_id']);
$subcategory = $product['subcategory_id'] ? getCategoryById($product['subcategory_id']) : null;

$customerName = null;
if (!empty($product['customer_id'])) {
    $customerStmt = $conn->prepare("SELECT name FROM customers WHERE id = ?");
    $customerStmt->bind_param("i", $product['customer_id']);
    $customerStmt->execute();
    $customerResult = $customerStmt->get_result();
    $customerRow = $customerResult->fetch_assoc();
    $customerName = $customerRow['name'] ?? null;
    $customerStmt->close();
}

// Fetch inventory quantities
$inventoryQuantities = getInventoryQuantitiesForProduct($product_id);

// Fetch product components if this is a final product
$components = [];
if ($product['type'] == 'final') {
    $components = getProductComponents($product_id);
}

$normalizedDescription = $product['description'] ?? '';
if (!empty($normalizedDescription)) {
    $normalizedDescription = str_replace(["\\r\\n", "\\n", "\\r"], "\n", $normalizedDescription);
}

$uploadDir = __DIR__ . '/../../assets/uploads/products/';
$productImageName = $product['image'] ?? '';
$productImageFilePath = $productImageName ? $uploadDir . $productImageName : '';
$productImageUrl = ($productImageName && file_exists($productImageFilePath))
    ? '../../assets/uploads/products/' . rawurlencode($productImageName)
    : null;

// Set page title
$pageTitle = "View Product: " . htmlspecialchars($product['name']);

// Include header
include '../../includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="row">
        <div class="col-12">
            <div class="card mb-4">
                <div class="card-header pb-0 d-flex justify-content-between align-items-center">
                    <h6>Product Details</h6>
                    <div>
                        <a href="edit.php?id=<?= $product['id'] ?>" class="btn btn-primary btn-sm">Edit</a>
                        <a href="../products/" class="btn btn-secondary btn-sm">Back to List</a>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="table-responsive">
                                <table class="table table-striped table-bordered">
                                    <tbody>
                                        <tr>
                                            <th>Name</th>
                                            <td><?= htmlspecialchars($product['name']) ?></td>
                                        </tr>
                                        <tr>
                                            <th>SKU</th>
                                            <td><?= htmlspecialchars($product['sku'] ?? 'N/A') ?></td>
                                        </tr>
                                        <tr>
                                            <th>Barcode</th>
                                            <td><?= htmlspecialchars($product['barcode'] ?? 'N/A') ?></td>
                                        </tr>
                                        <tr>
                                            <th>Category</th>
                                            <td><?= htmlspecialchars($category['name']) ?></td>
                                        </tr>
                                        <tr>
                                            <th>Subcategory</th>
                                            <td><?= $subcategory ? htmlspecialchars($subcategory['name']) : 'N/A' ?></td>
                                        </tr>
                                        <tr>
                                            <th>Customer</th>
                                            <td><?= $customerName ? htmlspecialchars($customerName) : 'All Customers' ?></td>
                                        </tr>
                                        <tr>
                                            <th>Type</th>
                                            <td><?= ucfirst($product['type']) ?></td>
                                        </tr>
                                        <?php if (canViewProductPrice($product['type'])): ?>
                                        <tr>
                                            <th>Selling Price</th>
                                            <td><?= formatCurrency($product['unit_price']) ?></td>
                                        </tr>
                                        <?php endif; ?>
                                        <?php if (canViewProductCost($product['type'])): ?>
                                        <tr>
                                            <th>Cost Price</th>
                                            <td><?= $product['cost_price'] ? formatCurrency($product['cost_price']) : 'N/A' ?></td>
                                        </tr>
                                        <?php endif; ?>
                                        <tr>
                                            <th>Min Stock Level</th>
                                            <td><?= $product['min_stock_level'] ?? 'Not set' ?></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <?php if ($productImageUrl): ?>
                                <div class="mb-4 text-center">
                                    <img src="<?php echo $productImageUrl; ?>"
                                         alt="<?php echo htmlspecialchars($product['name']); ?> image"
                                         class="img-fluid rounded"
                                         style="max-height: 320px; width: auto; object-fit: contain; border: 1px solid #dee2e6;">
                                </div>
                            <?php else: ?>
                                <div class="mb-4 text-center text-muted">
                                    <small>No image uploaded</small>
                                </div>
                            <?php endif; ?>
                            <h6>Description</h6>
                            <p><?= $normalizedDescription ? nl2br(htmlspecialchars($normalizedDescription)) : 'No description provided.' ?></p>
                            
                            <?php if ($product['type'] == 'final' && !empty($components)): ?>
                            <h6 class="mt-4">Product Components</h6>
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead>
                                        <tr>
                                            <th>Component</th>
                                            <th>Quantity</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($components as $component): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($component['component_name']) ?></td>
                                            <td><?= $component['quantity'] ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="row mt-4">
                        <div class="col-12">
                            <h6>Inventory Quantities</h6>
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead>
                                        <tr>
                                            <th>Inventory</th>
                                            <th>Location</th>
                                            <th>Quantity</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($inventoryQuantities)): ?>
                                            <?php foreach ($inventoryQuantities as $item): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($item['inventory_name']) ?></td>
                                                <td><?= htmlspecialchars($item['location']) ?></td>
                                                <td><?= $item['quantity'] ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="3" class="text-center">No inventory records found</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>

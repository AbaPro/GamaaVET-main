<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

if (!hasPermission('products.delete')) {
    setAlert('danger', "You don't have permission to remove products.");
    redirect('index.php');
}

$returnUrl = getSafeProductReturnUrl($_POST['return_to'] ?? '', 'index.php');
$productIds = array_values(array_unique(array_filter(array_map('intval', $_POST['product_ids'] ?? []), function ($id) {
    return $id > 0;
})));

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($productIds)) {
    setAlert('warning', 'Select at least one product to remove.');
    redirect($returnUrl);
}

$deleted = [];
$archived = [];
$errors = [];

foreach ($productIds as $productId) {
    if (!canAccessProduct($productId)) {
        $errors[] = "Product ID $productId is outside your access.";
        continue;
    }

    $infoStmt = $conn->prepare('SELECT name, sku FROM products WHERE id = ?');
    $infoStmt->bind_param('i', $productId);
    $infoStmt->execute();
    $product = $infoStmt->get_result()->fetch_assoc();
    $infoStmt->close();
    $label = $product
        ? trim(($product['sku'] ? $product['sku'] . ' - ' : '') . $product['name'])
        : "ID $productId";

    try {
        $usageReasons = getProductUsageReasons($productId);
        if (!empty($usageReasons)) {
            if (!productsSupportArchiving()) {
                $errors[] = "$label could not be removed because it has " . implode(', ', $usageReasons) . '.';
                continue;
            }
            $stmt = $conn->prepare('UPDATE products SET is_active = 0 WHERE id = ?');
            $stmt->bind_param('i', $productId);
            $stmt->execute();
            $stmt->close();
            $archived[] = $label;
            continue;
        }

        $stmt = $conn->prepare('DELETE FROM products WHERE id = ?');
        $stmt->bind_param('i', $productId);
        $stmt->execute();
        $stmt->close();
        $deleted[] = $label;
    } catch (Throwable $e) {
        $errors[] = "$label: " . $e->getMessage();
    }
}

$messages = [];
if (!empty($deleted)) {
    $messages[] = count($deleted) . ' unused product(s) deleted permanently.';
}
if (!empty($archived)) {
    $messages[] = count($archived) . ' used product(s) archived with their stock and history preserved.';
}
if (!empty($errors)) {
    $messages[] = 'Skipped: ' . implode(' ', $errors);
}

setAlert(!empty($errors) ? 'warning' : 'success', implode(' ', $messages));
logActivity('Bulk removed products', [
    'deleted' => $deleted,
    'archived' => $archived,
]);

redirect($returnUrl);

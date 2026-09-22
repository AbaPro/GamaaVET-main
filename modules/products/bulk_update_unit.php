<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

if (!hasPermission('products.edit')) {
    setAlert('danger', 'You do not have permission to edit products.');
    redirect('../../dashboard.php');
}

$returnUrl = getSafeProductReturnUrl($_POST['return_to'] ?? '', 'index.php?type=material');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect($returnUrl);
}

$unit = normalizeProductUnit($_POST['unit'] ?? '');
$productIds = array_values(array_unique(array_filter(array_map('intval', $_POST['product_ids'] ?? []), function ($id) {
    return $id > 0;
})));

if ($unit === null || empty($productIds)) {
    setAlert('danger', 'Select at least one raw material and a valid unit.');
    redirect($returnUrl);
}

$updated = 0;
$skipped = [];
$conn->begin_transaction();
try {
    $updateProduct = $conn->prepare("UPDATE products SET unit = ? WHERE id = ? AND type = 'material'");
    $backfillPoUnit = tableHasColumn('purchase_order_items', 'unit')
        ? $conn->prepare("UPDATE purchase_order_items SET unit = ? WHERE product_id = ? AND (unit IS NULL OR unit = '')")
        : null;

    foreach ($productIds as $productId) {
        if (!canAccessProduct($productId)) {
            $skipped[] = "ID $productId is outside your access.";
            continue;
        }

        $typeStmt = $conn->prepare('SELECT type, unit FROM products WHERE id = ?');
        $typeStmt->bind_param('i', $productId);
        $typeStmt->execute();
        $product = $typeStmt->get_result()->fetch_assoc();
        $typeStmt->close();
        if (($product['type'] ?? '') !== 'material') {
            $skipped[] = "ID $productId is not a raw material.";
            continue;
        }
        $existingUnit = normalizeProductUnit($product['unit'] ?? '');
        if ($existingUnit !== null && $existingUnit !== $unit) {
            $skipped[] = "ID $productId already uses " . getProductUnitLabel($existingUnit) . '; edit it individually to change stock units safely.';
            continue;
        }

        $updateProduct->bind_param('si', $unit, $productId);
        $updateProduct->execute();
        $updated++;

        if ($backfillPoUnit) {
            $backfillPoUnit->bind_param('si', $unit, $productId);
            $backfillPoUnit->execute();
        }
    }

    $updateProduct->close();
    if ($backfillPoUnit) {
        $backfillPoUnit->close();
    }
    $conn->commit();

    if ($updated > 0) {
        logActivity('Bulk updated raw material units', [
            'product_ids' => $productIds,
            'unit' => $unit,
        ]);
    }
    $message = "Updated the unit for $updated raw material(s). Blank historical PO units were backfilled too.";
    if (!empty($skipped)) {
        $message .= ' Some materials were skipped: ' . implode(' ', $skipped);
    }
    setAlert(!empty($skipped) ? 'warning' : 'success', $message);
} catch (Throwable $e) {
    $conn->rollback();
    setAlert('danger', 'Unable to update units: ' . $e->getMessage());
}

redirect($returnUrl);

<?php
require_once '../../includes/auth.php';
require_once '../../config/database.php';

// ?id= opens an existing order for editing; otherwise a new order is created.
$editOrderId = (int)($_GET['id'] ?? 0);
$isEdit = $editOrderId > 0;

// Permission check
if (!hasPermission($isEdit ? 'sales.orders.edit' : 'sales.orders.create')) {
    $_SESSION['error'] = "You don't have permission to access this page";
    header("Location: ../../dashboard.php");
    exit();
}

// Once an order is delivered or returned, its items and totals are final.
const SALES_ORDER_LOCKED_STATUSES = ['delivered', 'returned', 'returned-refunded', 'partially-returned', 'partially-returned-refunded'];

/**
 * Items of an order, keyed by item id. An item is locked (quantity fixed, cannot be
 * removed) once stock was deducted for it, it was returned, or it is being manufactured.
 */
function loadEditableOrderItems($orderId, $forUpdate = false) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT oi.*, p.name AS product_name FROM order_items oi JOIN products p ON p.id = oi.product_id WHERE oi.order_id = ? ORDER BY oi.id" . ($forUpdate ? ' FOR UPDATE' : ''));
    $stmt->execute([$orderId]);
    $items = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
        $item['lock_reason'] = null;
        $items[(int)$item['id']] = $item;
    }
    if (!$items) return $items;

    $mfgStmt = $pdo->prepare("SELECT DISTINCT product_id FROM manufacturing_orders WHERE sales_order_id = ?");
    $mfgStmt->execute([$orderId]);
    $manufactured = array_flip($mfgStmt->fetchAll(PDO::FETCH_COLUMN));
    $returnStmt = $pdo->prepare("SELECT DISTINCT order_item_id FROM order_returns WHERE order_id = ?");
    $returnStmt->execute([$orderId]);
    $returned = array_flip($returnStmt->fetchAll(PDO::FETCH_COLUMN));
    $stocked = [];
    if (tableExists('inventory_stock_logs')) {
        $ids = array_keys($items);
        $stockStmt = $pdo->prepare("SELECT DISTINCT source_id FROM inventory_stock_logs WHERE source_type IN ('sales_order', 'portal_order_conversion', 'quotation_conversion') AND source_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")");
        $stockStmt->execute($ids);
        $stocked = array_flip($stockStmt->fetchAll(PDO::FETCH_COLUMN));
    }
    foreach ($items as $id => &$item) {
        if (isset($stocked[$id])) $item['lock_reason'] = 'Stock already deducted';
        elseif (isset($returned[$id])) $item['lock_reason'] = 'Has returns';
        elseif (isset($manufactured[(int)$item['product_id']])) $item['lock_reason'] = 'Manufacturing order exists';
    }
    unset($item);
    return $items;
}

$editOrder = null;
$editItems = [];
if ($isEdit) {
    if (!canAccessOrder($editOrderId)) {
        $_SESSION['error'] = 'You do not have permission to edit this order.';
        header('Location: order_list.php');
        exit();
    }
    $orderStmt = $pdo->prepare("SELECT o.*, c.name AS customer_name, f.name AS factory_name FROM orders o JOIN customers c ON c.id = o.customer_id LEFT JOIN factories f ON f.id = o.factory_id WHERE o.id = ?");
    $orderStmt->execute([$editOrderId]);
    $editOrder = $orderStmt->fetch(PDO::FETCH_ASSOC);
    if (!$editOrder) {
        $_SESSION['error'] = 'Order not found';
        header('Location: order_list.php');
        exit();
    }
    if (in_array($editOrder['status'], SALES_ORDER_LOCKED_STATUSES, true)) {
        $_SESSION['error'] = 'Delivered or returned orders cannot be edited.';
        header('Location: order_details.php?id=' . $editOrderId);
        exit();
    }
    $editItems = loadEditableOrderItems($editOrderId);
}

// Generate internal ID
$internal_id = $isEdit ? $editOrder['internal_id'] : 'ORD-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $isEdit) {
    try {
        $pdo->beginTransaction();

        $lockStmt = $pdo->prepare("SELECT * FROM orders WHERE id = ? FOR UPDATE");
        $lockStmt->execute([$editOrderId]);
        $current = $lockStmt->fetch(PDO::FETCH_ASSOC);
        if (!$current || in_array($current['status'], SALES_ORDER_LOCKED_STATUSES, true)) {
            throw new Exception('This order can no longer be edited.');
        }
        $customerId = (int)$current['customer_id'];
        $existingItems = loadEditableOrderItems($editOrderId, true);

        $contactId = (int)($_POST['contact_id'] ?? 0);
        $contactStmt = $pdo->prepare("SELECT id FROM customer_contacts WHERE id = ? AND customer_id = ?");
        $contactStmt->execute([$contactId, $customerId]);
        if (!$contactStmt->fetchColumn()) {
            throw new Exception('Please select a valid contact for this customer.');
        }
        $orderDate = normalizeTransactionDate($_POST['order_date'] ?? '');
        if ($orderDate === null) {
            throw new Exception('Enter a valid order date.');
        }

        $productAccessStmt = $pdo->prepare("SELECT id FROM products p WHERE id = ? AND customer_id = ? AND type = 'final' AND " . getActiveProductSql('p'));
        $kept = [];
        $newItems = [];
        $itemsSubtotal = 0;
        $freeSampleCount = 0;
        foreach ($_POST['items'] ?? [] as $item) {
            $itemId = (int)($item['order_item_id'] ?? 0);
            $quantity = (int)($item['quantity'] ?? 0);
            $unitPrice = max(0, (float)($item['price'] ?? 0));
            $isFreeSample = !empty($item['is_free_sample']) ? 1 : 0;

            if ($itemId > 0) {
                if (!isset($existingItems[$itemId])) {
                    throw new Exception('An order item no longer exists. Refresh and try again.');
                }
                $existing = $existingItems[$itemId];
                if ($existing['lock_reason'] && $quantity !== (int)$existing['quantity']) {
                    throw new Exception("The quantity of {$existing['product_name']} cannot change ({$existing['lock_reason']}).");
                }
                $productId = (int)$existing['product_id'];
            } else {
                $productId = (int)($item['product_id'] ?? 0);
                $productAccessStmt->execute([$productId, $customerId]);
                if ($productId <= 0 || !$productAccessStmt->fetchColumn() || !canAccessProduct($productId)) {
                    throw new Exception('One or more selected products do not belong to this customer or are not available to you.');
                }
            }
            if ($quantity <= 0) {
                throw new Exception('Each item needs a quantity of at least 1. Remove items you no longer need.');
            }

            $lineTotal = $isFreeSample ? 0 : $quantity * $unitPrice;
            if ($isFreeSample) {
                $freeSampleCount += $quantity;
            } else {
                $itemsSubtotal += $lineTotal;
            }
            $row = [
                'id' => $itemId,
                'product_id' => $productId,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'total_price' => $lineTotal,
                'is_free_sample' => $isFreeSample,
            ];
            if ($itemId > 0) $kept[$itemId] = $row; else $newItems[] = $row;
        }
        if (!$kept && !$newItems) {
            throw new Exception('An order needs at least one item.');
        }
        foreach ($existingItems as $itemId => $existing) {
            if (!isset($kept[$itemId]) && $existing['lock_reason']) {
                throw new Exception("{$existing['product_name']} cannot be removed ({$existing['lock_reason']}).");
            }
        }

        $discount_percentage = max(0, min(100, (float)($_POST['discount_percentage'] ?? 0)));
        $discount_basis = $_POST['discount_basis'] ?? 'none';
        if (!in_array($discount_basis, ['none', 'product_quantity', 'cash', 'free_sample', 'mixed'], true)) {
            $discount_basis = 'none';
        }
        $discount_product_count = max(0, (int)($_POST['discount_product_count'] ?? 0));
        $manualDiscount = max(0, (float)($_POST['discount_cash_amount'] ?? 0));
        $discount_amount = round(min($itemsSubtotal, $itemsSubtotal * ($discount_percentage / 100) + $manualDiscount), 2);

        // Shipping is part of this form only for users allowed to edit it.
        if (hasPermission('sales.orders.shipping.edit')) {
            $shipping_cost_type = ($_POST['shipping_cost_type'] ?? 'none') === 'manual' ? 'manual' : 'none';
            $shippingCost = $shipping_cost_type === 'manual' ? max(0, (float)($_POST['shipping_cost'] ?? 0)) : 0;
        } else {
            $shipping_cost_type = $current['shipping_cost_type'] === 'manual' ? 'manual' : 'none';
            $shippingCost = $shipping_cost_type === 'manual' ? (float)$current['shipping_cost'] : 0;
        }

        $total_amount = round(max(0, $itemsSubtotal - $discount_amount) + $shippingCost, 2);
        if ($total_amount + 0.005 < (float)$current['paid_amount']) {
            throw new Exception('The new total (' . number_format($total_amount, 2) . ') is less than the amount already paid (' . number_format((float)$current['paid_amount'], 2) . '). Delete or reduce payments first.');
        }

        $priceLogs = [];
        $updateItemStmt = $pdo->prepare("UPDATE order_items SET quantity = ?, unit_price = ?, total_price = ?, is_free_sample = ? WHERE id = ? AND order_id = ?");
        foreach ($kept as $itemId => $item) {
            $updateItemStmt->execute([$item['quantity'], $item['unit_price'], $item['total_price'], $item['is_free_sample'], $itemId, $editOrderId]);
            if (!$item['is_free_sample'] && $item['unit_price'] > 0 && abs($item['unit_price'] - (float)$existingItems[$itemId]['unit_price']) >= 0.005) {
                $priceLogs[] = [$item['product_id'], $item['unit_price'], $item['quantity'], $itemId];
            }
        }
        $deleteItemStmt = $pdo->prepare("DELETE FROM order_items WHERE id = ? AND order_id = ?");
        foreach (array_diff_key($existingItems, $kept) as $itemId => $removed) {
            $deleteItemStmt->execute([$itemId, $editOrderId]);
        }
        $insertItemStmt = $pdo->prepare("INSERT INTO order_items (order_id, product_id, quantity, unit_price, total_price, is_free_sample) VALUES (?, ?, ?, ?, ?, ?)");
        foreach ($newItems as $item) {
            $insertItemStmt->execute([$editOrderId, $item['product_id'], $item['quantity'], $item['unit_price'], $item['total_price'], $item['is_free_sample']]);
            if (!$item['is_free_sample'] && $item['unit_price'] > 0) {
                $priceLogs[] = [$item['product_id'], $item['unit_price'], $item['quantity'], (int)$pdo->lastInsertId()];
            }
        }

        $notes = $_POST['notes'] ?? null;
        $pdo->prepare("
            UPDATE orders SET contact_id = ?, order_date = ?, notes = ?, total_amount = ?,
                   discount_percentage = ?, discount_basis = ?, discount_amount = ?, discount_product_count = ?,
                   free_sample_count = ?, shipping_cost_type = ?, shipping_cost = ?
            WHERE id = ?
        ")->execute([
            $contactId, $orderDate, $notes, $total_amount,
            $discount_percentage, $discount_basis, $discount_amount, $discount_product_count,
            $freeSampleCount, $shipping_cost_type, $shippingCost, $editOrderId
        ]);

        $pdo->commit();
        foreach ($priceLogs as [$productId, $price, $quantity, $sourceId]) {
            logProductPrice($productId, 'sell', $price, $quantity, 'sales_order', $sourceId, 'Sales order ' . $current['internal_id'] . ' (edited)');
        }
        logActivity("Edited sales order {$current['internal_id']}", [
            'old_total' => (float)$current['total_amount'],
            'new_total' => $total_amount,
            'items_added' => count($newItems),
            'items_removed' => count($existingItems) - count($kept),
        ], 'update', 'sales_order', $editOrderId);
        $_SESSION['success'] = 'Order updated successfully!';
        header('Location: order_details.php?id=' . $editOrderId);
        exit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['error'] = 'Error updating order: ' . $e->getMessage();
        header('Location: create_order.php?id=' . $editOrderId);
        exit();
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && !$isEdit) {
    try {
        $pdo->beginTransaction();

        $rawItems = $_POST['items'] ?? [];
        $customerId = !empty($_POST['customer_id']) ? (int)$_POST['customer_id'] : 0;
        $contactId = !empty($_POST['contact_id']) ? (int)$_POST['contact_id'] : 0;
        if ($customerId <= 0 || !canAccessCustomer($customerId)) {
            throw new Exception('Please select a customer assigned to you.');
        }

        $customerStmt = $pdo->prepare("SELECT factory_id FROM customers WHERE id = ?");
        $customerStmt->execute([$customerId]);
        $factoryId = $customerStmt->fetchColumn();
        $factoryId = $factoryId !== false && $factoryId !== null ? (int)$factoryId : null;
        if (($_SESSION['login_region'] ?? 'factory') !== 'factory') {
            $factoryId = null;
        }

        $contactStmt = $pdo->prepare("SELECT id FROM customer_contacts WHERE id = ? AND customer_id = ?");
        $contactStmt->execute([$contactId, $customerId]);
        if (!$contactStmt->fetchColumn()) {
            throw new Exception('Please select a valid contact for this customer.');
        }

        $productAccessStmt = $pdo->prepare("SELECT id FROM products p WHERE id = ? AND customer_id = ? AND type = 'final' AND " . getActiveProductSql('p'));
        $orderItems = [];
        $itemsSubtotal = 0;
        $freeSampleCount = 0;

        foreach ($rawItems as $item) {
            $productId = isset($item['product_id']) ? (int)$item['product_id'] : 0;
            $quantity = isset($item['quantity']) ? (int)$item['quantity'] : 0;
            $unitPrice = isset($item['price']) ? (float)$item['price'] : 0;
            $isFreeSample = !empty($item['is_free_sample']) ? 1 : 0;

            if ($productId <= 0 || $quantity <= 0) {
                continue;
            }

            $productAccessStmt->execute([$productId, $customerId]);
            if (!$productAccessStmt->fetchColumn() || !canAccessProduct($productId)) {
                throw new Exception('One or more selected products do not belong to this customer or are not available to you.');
            }

            $lineTotal = $quantity * $unitPrice;

            if ($isFreeSample) {
                $freeSampleCount += $quantity;
                $lineTotal = 0;
            } else {
                $itemsSubtotal += $lineTotal;
            }

            $orderItems[] = [
                'product_id' => $productId,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'total_price' => $lineTotal,
                'is_free_sample' => $isFreeSample
            ];
        }

        if (empty($orderItems)) {
            throw new Exception('Please select at least one valid product to create an order.');
        }

        $discount_percentage = isset($_POST['discount_percentage']) ? max(0, min(100, (float)$_POST['discount_percentage'])) : 0;
        $discount_basis_options = ['none', 'product_quantity', 'cash', 'free_sample', 'mixed'];
        $discount_basis = $_POST['discount_basis'] ?? 'none';
        if (!in_array($discount_basis, $discount_basis_options, true)) {
            $discount_basis = 'none';
        }
        $discount_product_count = isset($_POST['discount_product_count']) ? max(0, (int)$_POST['discount_product_count']) : 0;
        $manualDiscount = isset($_POST['discount_cash_amount']) ? max(0, (float)$_POST['discount_cash_amount']) : 0;

        $percentageDiscountValue = $discount_percentage > 0 ? ($itemsSubtotal * ($discount_percentage / 100)) : 0;
        $discount_amount = round(min($itemsSubtotal, $percentageDiscountValue + $manualDiscount), 2);

        $shipping_cost_type = $_POST['shipping_cost_type'] ?? 'none';
        $shippingCost = 0;
        if ($shipping_cost_type === 'manual') {
            $shippingCost = isset($_POST['shipping_cost']) ? max(0, (float)$_POST['shipping_cost']) : 0;
        } else {
            $shipping_cost_type = 'none';
        }

        $total_amount = max(0, $itemsSubtotal - $discount_amount) + $shippingCost;
        $notes = $_POST['notes'] ?? null;
        $currency = is_string($_POST['currency'] ?? null) ? $_POST['currency'] : 'EGP';
        $exchange_rate = currencyRateToEgp(getCurrencyRates(), $currency);

        // Insert order
        $stmt = $pdo->prepare("
            INSERT INTO orders (
                internal_id, customer_id, factory_id, contact_id, order_date, status,
                total_amount, currency, exchange_rate, paid_amount,
                discount_percentage, discount_basis, discount_amount,
                discount_product_count, free_sample_count, shipping_cost_type, shipping_cost,
                notes, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $_POST['internal_id'],
            $customerId,
            $factoryId,
            $contactId,
            $_POST['order_date'],
            'new',
            $total_amount,
            $currency,
            $exchange_rate,
            0.00,
            $discount_percentage,
            $discount_basis,
            $discount_amount,
            $discount_product_count,
            $freeSampleCount,
            $shipping_cost_type,
            $shippingCost,
            $notes,
            $_SESSION['user_id']
        ]);

        $order_id = $pdo->lastInsertId();
        $priceLogs = [];

        // Insert order items
        $stmt = $pdo->prepare("
            INSERT INTO order_items (order_id, product_id, quantity, unit_price, total_price, is_free_sample)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        foreach ($orderItems as $item) {
            $stmt->execute([
                $order_id,
                $item['product_id'],
                $item['quantity'],
                $item['unit_price'],
                $item['total_price'],
                $item['is_free_sample']
            ]);
            $orderItemId = (int)$pdo->lastInsertId();
            if (!$item['is_free_sample'] && (float)$item['unit_price'] > 0) {
                $priceLogs[] = [
                    'product_id' => (int)$item['product_id'],
                    'price' => (float)$item['unit_price'],
                    'quantity' => (float)$item['quantity'],
                    'source_id' => $orderItemId,
                    'notes' => 'Sales order ' . $_POST['internal_id'],
                ];
            }

        }

        $pdo->commit();
        foreach ($priceLogs as $priceLog) {
            logProductPrice($priceLog['product_id'], 'sell', $priceLog['price'], $priceLog['quantity'], 'sales_order', $priceLog['source_id'], $priceLog['notes']);
        }

        // Notify Production Manager and Supervisor
        $prod_roles = $pdo->query("SELECT id, slug FROM roles WHERE slug IN ('production_manager', 'production_supervisor')")->fetchAll(PDO::FETCH_ASSOC);
        $notif_title = "New Order: " . $_POST['internal_id'];
        $notif_msg = "A new sales order has been created. Order ID: " . $_POST['internal_id'];
        
        foreach ($prod_roles as $role) {
            createNotification(
                'sales_order_created',
                $notif_title,
                $notif_msg,
                'sales',
                'order',
                $order_id,
                'info',
                $role['id'],
                null,
                $_SESSION['user_id']
            );
        }

        logActivity("Created sales order {$_POST['internal_id']}", ['customer_id' => $customerId], 'create', 'sales_order', $order_id);
        $_SESSION['success'] = "Order created successfully!";
        header("Location: order_details.php?id=" . $order_id);
        exit();
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "Error creating order: " . $e->getMessage();
    }
}

// Get currencies for dropdown
$currencies = getCurrencyRates();

// Get customers for the selected direct-sales channel and current salesperson.
$loginRegion = $_SESSION['login_region'] ?? 'factory';
$customerSql = "SELECT c.id, c.name
                FROM customers c
                LEFT JOIN factories f ON f.id = c.factory_id
                WHERE " . ($loginRegion === 'factory' ? "c.direct_sale IS NULL" : "c.direct_sale = ?");
$customerParams = $loginRegion === 'factory' ? [] : [$loginRegion];
if (isSalesPersonUser()) {
    $customerSql .= " AND COALESCE(c.sales_person_id, f.sales_person_id) = ?";
    $customerParams[] = (int)$_SESSION['user_id'];
}
$customerSql .= " ORDER BY c.name";
$customerListStmt = $pdo->prepare($customerSql);
$customerListStmt->execute($customerParams);
$customers = $customerListStmt->fetchAll(PDO::FETCH_ASSOC);
if ($isEdit) {
    // The customer of an existing order cannot change.
    $customers = [['id' => $editOrder['customer_id'], 'name' => $editOrder['customer_name']]];
}
$editCashDiscount = 0;
if ($isEdit) {
    $editSubtotal = 0;
    foreach ($editItems as $item) {
        if (empty($item['is_free_sample'])) $editSubtotal += (float)$item['total_price'];
    }
    $editCashDiscount = round(max(0, (float)$editOrder['discount_amount'] - $editSubtotal * ((float)$editOrder['discount_percentage'] / 100)), 2);
}
$canEditShipping = !$isEdit || hasPermission('sales.orders.shipping.edit');
$canViewDiscount = !$isEdit || hasPermission('sales.orders.discount.view');
$formValue = static function ($key, $default) use ($editOrder) {
    return $editOrder !== null && isset($editOrder[$key]) ? $editOrder[$key] : $default;
};

// Get products for dropdown
$productSql = "
    SELECT p.id, p.name, p.sku, p.unit_price, p.category_id, p.subcategory_id, p.type, p.customer_id, c.name as category 
    FROM products p
    JOIN categories c ON p.category_id = c.id
    LEFT JOIN customers customer ON customer.id = p.customer_id
    LEFT JOIN factories customer_factory ON customer_factory.id = customer.factory_id
    WHERE p.type = 'final' AND " . getActiveProductSql('p') . "
";
$productParams = [];
$productSql .= $loginRegion === 'factory'
    ? " AND customer.direct_sale IS NULL"
    : " AND customer.direct_sale = ?";
if ($loginRegion !== 'factory') {
    $productParams[] = $loginRegion;
}
if (isSalesPersonUser()) {
    $productSql .= " AND COALESCE(customer.sales_person_id, customer_factory.sales_person_id) = ?";
    $productParams[] = (int)$_SESSION['user_id'];
}
$productSql .= " ORDER BY p.name";
$productListStmt = $pdo->prepare($productSql);
$productListStmt->execute($productParams);
$products = $productListStmt->fetchAll(PDO::FETCH_ASSOC);

// Get main categories for product filter
$categories = $pdo->query("SELECT id, name FROM categories WHERE parent_id IS NULL ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Get subcategories for product filter
$subcategories = $pdo->query("SELECT id, name, parent_id FROM categories WHERE parent_id IS NOT NULL ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Get customers for product filter (final products are linked to customers/factories)
$all_customers = $customers;

require_once '../../includes/header.php';
?>

<div class="container mt-4">
    <h2><?= $isEdit ? 'Edit Order ' . htmlspecialchars($internal_id) : 'Create New Order' ?></h2>
    <?php if ($isEdit): ?>
        <div class="alert alert-info">
            Customer and currency cannot change. Items marked <span class="badge bg-secondary">locked</span> keep their quantity because stock was already deducted, they were returned, or a manufacturing order exists for them.
            <?php if ((float)$editOrder['paid_amount'] > 0): ?>
                Already paid: <strong><?= number_format((float)$editOrder['paid_amount'], 2) ?> <?= htmlspecialchars($editOrder['currency'] ?? '') ?></strong> — the new total cannot be lower.
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php include '../../includes/messages.php'; ?>

    <form id="orderForm" method="post">
        <div class="card mb-4">
            <div class="card-header">Order Information</div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <div class="mb-3">
                            <label for="internal_id" class="form-label">Order ID</label>
                            <input type="text" class="form-control" id="internal_id" name="internal_id"
                                value="<?= $internal_id ?>" readonly>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="mb-3">
                            <label for="order_date" class="form-label">Order Date</label>
                            <input type="date" class="form-control" id="order_date" name="order_date"
                                value="<?= htmlspecialchars($formValue('order_date', date('Y-m-d'))) ?>" required>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="mb-3">
                            <label for="customer_id" class="form-label">Customer</label>
                            <select class="form-select" id="customer_id" name="customer_id" required <?= $isEdit ? 'disabled' : '' ?>>
                                <option value="">Select Customer</option>
                                <?php foreach ($customers as $customer) : ?>
                                    <option value="<?= $customer['id'] ?>" <?= $isEdit ? 'selected' : '' ?>><?= htmlspecialchars($customer['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-4 mb-3">
                        <div class="mb-3">
                            <label for="contact_id" class="form-label">Contact Person</label>
                            <select class="form-select" id="contact_id" name="contact_id" required disabled>
                                <option value="">Select Customer First</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="mb-3">
                            <label class="form-label">Factory</label>
                            <input type="text" class="form-control" id="factory_display" value="Select customer" readonly>
                            <input type="hidden" name="factory_id" id="factory_id">
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="mb-3">
                            <label for="notes" class="form-label">Notes</label>
                            <textarea class="form-control" id="notes" name="notes" rows="2"><?= htmlspecialchars((string)$formValue('notes', '')) ?></textarea>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-3 mb-3">
                        <label for="currency" class="form-label">Currency</label>
                        <select class="form-select" id="currency" name="currency" <?= $isEdit ? 'disabled' : '' ?>>
                            <?php if ($isEdit): ?>
                                <option value="<?= e($editOrder['currency'] ?: 'EGP') ?>" data-rate="<?= e(number_format((float)($editOrder['exchange_rate'] ?: 1), 4, '.', '')) ?>" selected><?= e($editOrder['currency'] ?: 'EGP') ?> (saved order rate)</option>
                            <?php else: ?>
                            <?php foreach ($currencies as $cur): ?>
                                <option value="<?= e($cur['code']) ?>" data-rate="<?= e($cur['code'] === 'EGP' ? '1.0000' : $cur['rate_to_egp']) ?>" <?= $cur['is_default'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cur['code']) ?> — <?= htmlspecialchars($cur['name']) ?>
                                </option>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="col-md-3 mb-3" id="exchange_rate_field" style="display:none;">
                        <label for="exchange_rate" class="form-label">Exchange Rate <small class="text-muted">(to EGP)</small></label>
                        <input type="text" class="form-control" id="exchange_rate" readonly value="1.0000">
                        <div class="form-text">Uses the rate saved in Settings. Prices are entered in the selected currency.</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Order Items</span>
                <div class="d-flex gap-2 flex-wrap">
                    <input type="text" class="form-control form-control-sm" id="productSearch" placeholder="Search by product name or SKU" aria-label="Search by product name or SKU" disabled>
                    <button type="button" class="btn btn-sm btn-primary" id="addItemBtn" disabled>Add Item</button>
                </div>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table" id="itemsTable">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Quantity</th>
                                <th>Selling Price</th>
                                <th>Total</th>
                                <th>Free Sample?</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <!-- Items will be added dynamically -->
                            <?php foreach ($editItems as $item): $locked = $item['lock_reason'] !== null; ?>
                                <tr id="item_<?= (int)$item['product_id'] ?>">
                                    <td>
                                        <?= htmlspecialchars($item['product_name']) ?>
                                        <?php if ($locked): ?><span class="badge bg-secondary ms-1" title="<?= e($item['lock_reason']) ?>">locked</span><?php endif; ?>
                                        <input type="hidden" name="items[e<?= (int)$item['id'] ?>][order_item_id]" value="<?= (int)$item['id'] ?>">
                                    </td>
                                    <td>
                                        <input type="number" class="form-control form-control-sm item-qty"
                                               name="items[e<?= (int)$item['id'] ?>][quantity]" value="<?= (int)$item['quantity'] ?>" min="1" <?= $locked ? 'readonly' : '' ?>>
                                    </td>
                                    <td>
                                        <input type="number" class="form-control form-control-sm item-price"
                                               name="items[e<?= (int)$item['id'] ?>][price]" value="<?= e($item['unit_price']) ?>" step="0.01" min="0">
                                    </td>
                                    <td class="item-total" data-value="0">0.00</td>
                                    <td>
                                        <input type="hidden" class="free-sample-flag" name="items[e<?= (int)$item['id'] ?>][is_free_sample]" value="<?= !empty($item['is_free_sample']) ? '1' : '0' ?>">
                                        <div class="form-check">
                                            <input type="checkbox" class="form-check-input item-free-sample" <?= !empty($item['is_free_sample']) ? 'checked' : '' ?>>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if (!$locked): ?>
                                        <button type="button" class="btn btn-sm btn-danger remove-item">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-6 mb-4" <?= $canViewDiscount ? '' : 'hidden' ?>>
                <div class="card h-100">
                    <div class="card-header">Discount Information</div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-sm-6">
                                <label for="discount_percentage" class="form-label">Discount %</label>
                                <input type="number" class="form-control" id="discount_percentage" name="discount_percentage"
                                    value="<?= e($formValue('discount_percentage', 0)) ?>" step="0.01" min="0" max="100">
                            </div>
                            <div class="col-sm-6">
                                <label for="discount_basis" class="form-label">Discount Type</label>
                                <select class="form-select" id="discount_basis" name="discount_basis">
                                    <?php foreach (['none' => 'No Discount', 'product_quantity' => 'Product Count', 'cash' => 'Cash Discount', 'free_sample' => 'Free Samples', 'mixed' => 'Mixed'] as $basis => $label): ?>
                                        <option value="<?= $basis ?>" <?= $formValue('discount_basis', 'none') === $basis ? 'selected' : '' ?>><?= $label ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-sm-6">
                                <label for="discount_cash_amount" class="form-label">Discount (Cash)</label>
                                <input type="number" class="form-control" id="discount_cash_amount" name="discount_cash_amount"
                                    value="<?= e($editCashDiscount ?? 0) ?>" step="0.01" min="0">
                            </div>
                            <div class="col-sm-6">
                                <label for="discount_product_count" class="form-label">Discounted Products</label>
                                <input type="number" class="form-control" id="discount_product_count" name="discount_product_count"
                                    value="<?= e($formValue('discount_product_count', 0)) ?>" min="0" step="1">
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6 mb-4" <?= $canEditShipping ? '' : 'hidden' ?>>
                <div class="card h-100">
                    <div class="card-header">Shipping Information</div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-sm-12">
                                <label for="shipping_cost_type" class="form-label">Shipping Cost</label>
                                <select class="form-select" id="shipping_cost_type" name="shipping_cost_type">
                                    <option value="none">No Shipping (لا يوجد)</option>
                                    <option value="manual" <?= $formValue('shipping_cost_type', 'none') === 'manual' ? 'selected' : '' ?>>Manual (يدوي)</option>
                                </select>
                            </div>
                            <div class="col-sm-12">
                                <label for="shipping_cost" class="form-label">Shipping Amount</label>
                                <input type="number" class="form-control" id="shipping_cost" name="shipping_cost"
                                    value="<?= e($formValue('shipping_cost', 0)) ?>" step="0.01" min="0" <?= $formValue('shipping_cost_type', 'none') === 'manual' ? '' : 'disabled' ?>>
                                <small class="text-muted d-block mt-1">Enabled only when shipping is manual.</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header">Financial Summary</div>
            <div class="card-body">
                <div class="row text-center text-md-start">
                    <div class="col-md-3 mb-3">
                        <small class="text-muted d-block">Items Subtotal</small>
                        <div class="fs-5 fw-semibold" id="itemsSubtotal">0.00</div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <small class="text-muted d-block">Discount Amount</small>
                        <div class="fs-5 text-danger" id="calculatedDiscount">0.00</div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <small class="text-muted d-block">Shipping Cost</small>
                        <div class="fs-5" id="calculatedShipping">0.00</div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <small class="text-muted d-block">Free Samples</small>
                        <div class="fs-5" id="freeSampleCounter">0</div>
                    </div>
                </div>
                <hr>
                <div class="d-flex justify-content-between align-items-center">
                    <small class="text-muted">Order Total</small>
                    <div class="fs-3 fw-bold text-primary" id="orderTotal">0.00</div>
                </div>
            </div>
        </div>

        <div class="d-flex justify-content-between">
            <a href="<?= $isEdit ? 'order_details.php?id=' . $editOrderId : 'index.php' ?>" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Save Changes' : 'Create Order' ?></button>
        </div>
    </form>
</div>

<!-- Product selection modal -->
<div class="modal fade" id="productModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Select Product</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="bg-light p-3 rounded mb-3">
                    <div class="row g-2">
                        <div class="col-md-4">
                            <label for="filter_category_modal" class="form-label small">Category</label>
                            <select class="form-select form-select-sm" id="filter_category_modal">
                                <option value="">All categories</option>
                                <?php foreach ($categories as $cat) : ?>
                                    <option value="<?= (int)$cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label for="filter_subcategory_modal" class="form-label small">Sub-Category</label>
                            <select class="form-select form-select-sm" id="filter_subcategory_modal">
                                <option value="">All sub-categories</option>
                                <?php foreach ($subcategories as $subcat) : ?>
                                    <option value="<?= (int)$subcat['id'] ?>" data-parent-id="<?= (int)$subcat['parent_id'] ?>"><?= htmlspecialchars($subcat['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label for="productSearchModal" class="form-label small">Search</label>
                            <input type="text" id="productSearchModal" class="form-control form-control-sm" placeholder="Search by product name or SKU">
                        </div>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-striped table-hover" id="productsTable">
                        <thead>
                            <tr>
                                <th>SKU</th>
                                <th>Name</th>
                                <th>Category</th>
                                <th>Selling Price</th>
                                <th>Stock</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($products as $product) : ?>
                            <tr data-category-id="<?= (int)($product['category_id'] ?? 0) ?>"
                                data-subcategory-id="<?= (int)($product['subcategory_id'] ?? 0) ?>"
                                data-customer-id="<?= (int)($product['customer_id'] ?? 0) ?>">
                                <td><?= htmlspecialchars($product['sku']) ?></td>
                                <td><?= htmlspecialchars($product['name']) ?></td>
                                <td><?= htmlspecialchars($product['category']) ?></td>
                                <td><?= number_format($product['unit_price'], 2) ?></td>
                                <td>
                                    <?php
                                    $stock = $pdo->query(
                                        "
                                        SELECT SUM(quantity) 
                                        FROM inventory_products 
                                        WHERE product_id = " . (int)$product['id']
                                    )->fetchColumn();
                                    echo $stock ? number_format($stock, 2) : '0.00';
                                    ?>
                                </td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-primary select-product"
                                        data-id="<?= $product['id'] ?>"
                                        data-name="<?= htmlspecialchars($product['name']) ?>"
                                        data-price="<?= $product['unit_price'] ?>">
                                        Select
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    const presetContactId = <?= $isEdit ? (int)$editOrder['contact_id'] : 'null' ?>;
    const isEditMode = <?= $isEdit ? 'true' : 'false' ?>;

    $(document).ready(function() {
        console.log("Document ready");
        $('#customer_id').select2({
            placeholder: 'Select Customer',
            allowClear: true,
            width: '100%'
        });

        // Load contacts when customer changes
        $('#customer_id').on('change', function() {
            const customerId = $(this).val();

            if (!customerId) {
                $('#contact_id').prop('disabled', true)
                    .html('<option value="">Select Customer First</option>');
                $('#factory_id').val('');
                $('#factory_display').val('Select customer');
                $('#addItemBtn, #productSearch').prop('disabled', true);
                return;
            }

            $('#addItemBtn, #productSearch').prop('disabled', false);

            $('#contact_id').prop('disabled', false)
                .html('<option>Loading...</option>');

            $.getJSON('../../ajax/get_customer_details.php', {
                    customer_id: customerId
                })
                .done(function(resp) {
                    if (!resp || resp.success !== true) {
                        console.error('Unexpected response:', resp);
                        $('#contact_id').html('<option value="">No contacts found</option>');
                        $('#factory_id').val('');
                        $('#factory_display').val('Not assigned');
                        return;
                    }

                    const factoryName = resp.customer && resp.customer.factory_name
                        ? resp.customer.factory_name
                        : 'Not assigned';
                    $('#factory_display').val(factoryName);
                    $('#factory_id').val(resp.customer ? resp.customer.factory_id : '');

                    let options = '<option value="">Select Contact</option>';
                    if (Array.isArray(resp.contacts) && resp.contacts.length) {
                        resp.contacts.forEach(c => {
                            const selected = (presetContactId ? Number(c.id) === presetContactId : c.is_primary) ? 'selected' : '';
                            const phone = c.phone ? ` (${c.phone})` : '';
                            options += `<option value="${c.id}" ${selected}>${c.name}${phone}</option>`;
                        });
                        $('#contact_id').html(options);
                    } else {
                        $('#contact_id').html('<option value="">No contacts found</option>');
                    }
                })
                .fail(function(xhr) {
                    console.error('Contacts load failed:', xhr.status, xhr.responseText);
                    $('#factory_id').val('');
                    $('#factory_display').val('Failed to load');
                    $('#contact_id').html('<option value="">Failed to load contacts</option>');
                });
        });


        // Add item button
        $('#addItemBtn').click(function() {
            console.log("Add item button clicked");
            if (productSearch) productSearch.value = '';
            $('#filter_category_modal').val('');
            $('#filter_subcategory_modal').val('').find('option').show();
            $('#filter_customer_modal').val('');
            $('#productSearchModal').val('');
            filterProducts();
            $('#productModal').modal('show');
        });

        const productModalEl = document.getElementById('productModal');
        const productSearch = document.getElementById('productSearch');
        const productSearchModal = document.getElementById('productSearchModal');
        const productCategoryFilter = document.getElementById('filter_category_modal');
        const productSubcategoryFilter = document.getElementById('filter_subcategory_modal');

        const getProductRows = () => Array.from(document.querySelectorAll('#productsTable tbody tr'));

        const filterProducts = () => {
            const term = ((productSearchModal && productSearchModal.value) || (productSearch && productSearch.value) || '').toLowerCase();
            const category = (productCategoryFilter && productCategoryFilter.value) || '';
            const subcategory = (productSubcategoryFilter && productSubcategoryFilter.value) || '';
            const customer = $('#customer_id').val() || '';
            const rows = getProductRows();
            rows.forEach(row => {
                const content = row.textContent.toLowerCase();
                const rowCategory = String(row.dataset.categoryId || '');
                const rowSubcategory = String(row.dataset.subcategoryId || '');
                const rowCustomer = String(row.dataset.customerId || '');

                const matchesTerm = term === '' || content.includes(term);
                const matchesCategory = category === '' || rowCategory === category;
                const matchesSubcategory = subcategory === '' || rowSubcategory === subcategory;
                const matchesCustomer = customer === '' || rowCustomer === customer;

                row.style.display = (matchesTerm && matchesCategory && matchesSubcategory && matchesCustomer) ? '' : 'none';
            });
        };

        if (productSearch) {
            productSearch.addEventListener('input', () => {
                if (productSearch.value.trim() !== '') {
                    $('#productModal').modal('show');
                }
                filterProducts();
            });
        }
        if (productSearchModal) {
            productSearchModal.addEventListener('input', filterProducts);
        }
        if (productCategoryFilter) {
            productCategoryFilter.addEventListener('change', function() {
                const parentId = this.value;
                $('#filter_subcategory_modal option').each(function() {
                    const optionParent = $(this).data('parentId');
                    if (parentId === '' || !optionParent || String(optionParent) === parentId) {
                        $(this).show();
                    } else {
                        $(this).hide();
                    }
                });
                
                // If current subcategory is not valid for this category, reset it
                const currentSub = $('#filter_subcategory_modal').val();
                if (currentSub) {
                    const currentOption = $('#filter_subcategory_modal option:selected');
                    const currentParent = String(currentOption.data('parentId') || '');
                    if (parentId !== '' && currentParent !== parentId) {
                        $('#filter_subcategory_modal').val('');
                    }
                }
                filterProducts();
            });
        }
        if (productSubcategoryFilter) {
            productSubcategoryFilter.addEventListener('change', filterProducts);
        }


        // Product selection
        $(document).on('click', '.select-product', function() {
            const productId = $(this).data('id');
            const productName = $(this).data('name');
            const productPrice = parseFloat($(this).data('price'));

            const rowId = 'item_' + productId;

            if ($('#' + rowId).length) {
                // If product already exists in table, just increase quantity
                const qtyInput = $('#' + rowId).find('.item-qty');
                if (qtyInput.prop('readonly')) {
                    alert('This product is already on the order and its quantity is locked.');
                    $('#productModal').modal('hide');
                    return;
                }
                qtyInput.val(parseInt(qtyInput.val()) + 1);
                updateRowTotal($('#' + rowId));
            } else {
                // Add new row
                const newRow = `
                <tr id="${rowId}">
                    <td>
                        ${productName}
                        <input type="hidden" name="items[${productId}][product_id]" value="${productId}">
                    </td>
                    <td>
                        <input type="number" class="form-control form-control-sm item-qty" 
                               name="items[${productId}][quantity]" value="1" min="1">
                    </td>
                    <td>
                        <input type="number" class="form-control form-control-sm item-price" 
                               name="items[${productId}][price]" value="${productPrice}" step="0.01" min="0">
                    </td>
                    <td class="item-total" data-value="${productPrice.toFixed(2)}">${productPrice.toFixed(2)}</td>
                    <td>
                        <input type="hidden" class="free-sample-flag" name="items[${productId}][is_free_sample]" value="0">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input item-free-sample">
                        </div>
                    </td>
                    <td>
                        <button type="button" class="btn btn-sm btn-danger remove-item">
                            <i class="fas fa-trash"></i>
                        </button>
                    </td>
                </tr>
            `;
                $('#itemsTable tbody').append(newRow);
            }

            const $newRow = $('#' + rowId);
            updateRowTotal($newRow);
            updateSummary();
            $('#productModal').modal('hide');
        });

        // Remove item
        $(document).on('click', '.remove-item', function() {
            $(this).closest('tr').remove();
            updateFreeSampleCounter();
            updateSummary();
        });

        // Update row total when quantity or price changes
        $(document).on('change', '.item-qty, .item-price', function() {
            updateRowTotal($(this).closest('tr'));
            updateSummary();
        });

        function updateRowTotal(row) {
            const qty = parseFloat(row.find('.item-qty').val()) || 0;
            const price = parseFloat(row.find('.item-price').val()) || 0;
            const isFree = row.find('.free-sample-flag').val() === '1';
            const total = isFree ? 0 : qty * price;
            const totalCell = row.find('.item-total');
            totalCell.data('value', total);
            totalCell.text(isFree ? `${total.toFixed(2)} (Free)` : total.toFixed(2));
            updateFreeSampleCounter();
        }

        $(document).on('change', '.item-free-sample', function() {
            const row = $(this).closest('tr');
            row.find('.free-sample-flag').val(this.checked ? '1' : '0');
            updateRowTotal(row);
            updateSummary();
        });

        function updateFreeSampleCounter() {
            let counter = 0;
            $('#itemsTable tbody tr').each(function() {
                if ($(this).find('.free-sample-flag').val() === '1') {
                    counter += parseFloat($(this).find('.item-qty').val()) || 0;
                }
            });
            $('#freeSampleCounter').text(counter);
        }

        function getItemsSubtotal() {
            let total = 0;
            $('.item-total').each(function() {
                total += parseFloat($(this).data('value')) || 0;
            });
            return total;
        }

        function calculateDiscount(subtotal) {
            const percentage = parseFloat($('#discount_percentage').val()) || 0;
            const cash = parseFloat($('#discount_cash_amount').val()) || 0;
            let discount = subtotal * (Math.min(percentage, 100) / 100);
            discount += Math.max(0, cash);
            if (discount > subtotal) {
                discount = subtotal;
            }
            return discount;
        }

        function getShippingCost() {
            if ($('#shipping_cost_type').val() === 'manual') {
                return Math.max(0, parseFloat($('#shipping_cost').val()) || 0);
            }
            return 0;
        }

        function updateSummary() {
            const subtotal = getItemsSubtotal();
            const discount = calculateDiscount(subtotal);
            const shipping = getShippingCost();
            const total = Math.max(0, subtotal - discount) + shipping;

            $('#itemsSubtotal').text(subtotal.toFixed(2));
            $('#calculatedDiscount').text(discount.toFixed(2));
            $('#calculatedShipping').text(shipping.toFixed(2));
            $('#orderTotal').text(total.toFixed(2));
        }

        $('#discount_percentage, #discount_cash_amount').on('input', updateSummary);
        $('#shipping_cost').on('input', updateSummary);
        $('#shipping_cost_type').on('change', function() {
            const manual = $(this).val() === 'manual';
            $('#shipping_cost').prop('disabled', !manual);
            if (!manual) {
                $('#shipping_cost').val(0);
            }
            updateSummary();
        });

        $('#discount_product_count').on('input', function() {
            if (parseInt($(this).val(), 10) < 0) {
                $(this).val(0);
            }
        });

        // Initialize summary on first load
        $('#itemsTable tbody tr').each(function() {
            updateRowTotal($(this));
        });
        updateFreeSampleCounter();
        updateSummary();

        if (isEditMode) {
            $('#customer_id').trigger('change');
        }
    });

    // Display only: the server independently loads the current configured rate.
    $('#currency').on('change', function() {
        const rate = $(this).find(':selected').attr('data-rate');
        $('#exchange_rate').val(rate || 'Not configured — contact an administrator');
        if ($(this).val() !== 'EGP') {
            $('#exchange_rate_field').show();
        } else {
            $('#exchange_rate_field').hide();
            $('#exchange_rate').val('1.0000');
        }
    }).trigger('change');
</script>

<?php require_once '../../includes/footer.php'; ?>

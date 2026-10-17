<?php
require_once __DIR__ . '/../../includes/functions.php';

// Formulas are written for a standard run of 1000 finished units
// (e.g. 100 L for 100 ml bottles); each pricing line can override it.
const PRICING_DEFAULT_BASIS_UNITS = 1000;

function pricingStorageReady() {
    return tableExists('order_pricings') && tableExists('order_pricing_lines');
}

function pricingFormToken() {
    if (empty($_SESSION['pricing_form_token'])) {
        $_SESSION['pricing_form_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['pricing_form_token'];
}

function pricingValidFormToken() {
    $submitted = $_POST['csrf_token'] ?? '';
    return is_string($submitted) && hash_equals(pricingFormToken(), $submitted);
}

function pricingPasswordStorageReady() {
    return tableExists('order_pricing_password');
}

function getPricingPasswordHash() {
    global $pdo;
    static $loaded = false;
    static $hash = null;
    if (!$loaded) {
        if (pricingPasswordStorageReady()) {
            $hash = $pdo->query('SELECT password_hash FROM order_pricing_password WHERE id = 1')->fetchColumn() ?: null;
        }
        $loaded = true;
    }
    return $hash;
}

// Changes whenever the password changes, which locks every open pricing session.
function pricingPasswordVersion() {
    return hash('sha256', (string)getPricingPasswordHash());
}

function setPricingPassword($password) {
    global $pdo;
    $stmt = $pdo->prepare('INSERT INTO order_pricing_password (id, password_hash, updated_by)
        VALUES (1, ?, ?) ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), updated_by = VALUES(updated_by), updated_at = CURRENT_TIMESTAMP');
    $stmt->execute([password_hash($password, PASSWORD_DEFAULT), (int)$_SESSION['user_id']]);
}

function unlockPricing($password) {
    $hash = getPricingPasswordHash();
    if ($hash === null || !is_string($password) || !password_verify($password, $hash)) {
        return false;
    }
    $_SESSION['pricing_unlocked'] = true;
    $_SESSION['pricing_password_version'] = pricingPasswordVersion();
    return true;
}

function isPricingUnlocked() {
    $version = $_SESSION['pricing_password_version'] ?? '';
    if (getPricingPasswordHash() === null || empty($_SESSION['pricing_unlocked']) || !is_string($version)
        || !hash_equals(pricingPasswordVersion(), $version)) {
        unset($_SESSION['pricing_unlocked'], $_SESSION['pricing_password_version']);
        return false;
    }
    return true;
}

/**
 * Stop the page unless the user may see pricing and has entered the pricing password.
 * Renders the unlock form when the sheet is still locked.
 */
function pricingRequireAccess($permission, $selfUrl) {
    if (!hasPermission($permission)) {
        setAlert('danger', 'Access denied. You do not have permission to use order pricing.');
        redirect('../../dashboard.php');
    }
    $passwordSet = getPricingPasswordHash() !== null;
    if (!$passwordSet && isAdminUser()) {
        setAlert('info', 'Set the order pricing password before first use.');
        redirect('pricing_password.php');
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['unlock_pricing_password'])) {
        if (unlockPricing($_POST['unlock_pricing_password'])) {
            setAlert('success', 'Pricing unlocked.');
        } else {
            setAlert('danger', 'Incorrect password.');
        }
        redirect($selfUrl);
    }
    if (isPricingUnlocked()) {
        return;
    }

    global $conn, $pdo;
    $page_title = 'Unlock Pricing';
    require_once __DIR__ . '/../../includes/header.php';
    ?>
    <div class="row justify-content-center mt-5">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header border-bottom"><h5 class="mb-0">Unlock Pricing</h5></div>
                <div class="card-body">
                    <?php if (!$passwordSet): ?>
                        <div class="alert alert-warning mb-0">The order pricing password has not been set yet. Ask an admin to set it.</div>
                    <?php else: ?>
                        <p class="text-muted">Enter the order pricing password to continue.</p>
                        <form method="post">
                            <div class="mb-3">
                                <label class="form-label">Password</label>
                                <input type="password" name="unlock_pricing_password" class="form-control" required autofocus>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">Unlock</button>
                            <?php if (isAdminUser()): ?>
                                <a href="pricing_password.php" class="btn btn-outline-secondary w-100 mt-2">Change Pricing Password</a>
                            <?php endif; ?>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

function pricingLoadOrder($orderId) {
    global $conn;

    $orderId = (int)$orderId;
    if ($orderId <= 0 || !canAccessOrder($orderId)) {
        return null;
    }
    $currencySelect = tableHasColumn('orders', 'currency') ? 'o.currency' : "'EGP' AS currency";
    $stmt = $conn->prepare("
        SELECT o.id, o.internal_id, o.order_date, o.customer_id, $currencySelect, c.name AS customer_name
        FROM orders o
        JOIN customers c ON c.id = o.customer_id
        WHERE o.id = ?
    ");
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$order) {
        return null;
    }
    $order['currency'] = $order['currency'] ?: 'EGP';

    $stmt = $conn->prepare("
        SELECT oi.product_id, p.name, p.sku, p.type, SUM(oi.quantity) AS quantity
        FROM order_items oi
        JOIN products p ON p.id = oi.product_id
        WHERE oi.order_id = ?
        GROUP BY oi.product_id, p.name, p.sku, p.type
        ORDER BY MIN(oi.id)
    ");
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $order['items'] = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $item) {
        $order['items'][(int)$item['product_id']] = $item;
    }
    $stmt->close();

    return $order;
}

function pricingOrderLabel(array $order) {
    return ($order['internal_id'] ?: '#' . $order['id']) . ' — ' . $order['customer_name'];
}

/**
 * Latest purchase price for each product, expressed per catalog unit.
 * Falls back to the catalog cost price when the product was never purchased.
 */
function pricingLatestMaterialPrices(array $productIds) {
    global $conn;

    $ids = array_values(array_unique(array_filter(array_map('intval', $productIds))));
    if (empty($ids)) {
        return [];
    }
    $idList = implode(',', $ids);

    $products = [];
    $result = $conn->query("SELECT id, unit, cost_price FROM products WHERE id IN ($idList)");
    while ($row = $result->fetch_assoc()) {
        $products[(int)$row['id']] = $row;
    }

    $prices = [];
    if (tableExists('purchase_order_items')) {
        $unitSelect = tableHasColumn('purchase_order_items', 'unit') ? 'poi.unit' : 'NULL AS unit';
        $result = $conn->query("
            SELECT poi.product_id, poi.unit_price, $unitSelect, po.order_date
            FROM purchase_order_items poi
            JOIN purchase_orders po ON po.id = poi.purchase_order_id
            WHERE poi.product_id IN ($idList)
              AND po.status <> 'cancelled'
              AND poi.unit_price > 0
            ORDER BY po.order_date DESC, poi.id DESC
        ");
        while ($row = $result->fetch_assoc()) {
            $productId = (int)$row['product_id'];
            if (isset($prices[$productId])) {
                continue;
            }
            $catalogUnit = $products[$productId]['unit'] ?? '';
            $purchaseUnit = $row['unit'] ?: $catalogUnit;
            // Price per purchase unit -> price per catalog unit.
            $purchaseUnitsPerCatalogUnit = convertProductUnitQuantity(1, $catalogUnit, $purchaseUnit);
            if ($purchaseUnitsPerCatalogUnit === null) {
                if (normalizeProductUnit($catalogUnit) !== null && normalizeProductUnit($purchaseUnit) !== null) {
                    continue;
                }
                $purchaseUnitsPerCatalogUnit = 1.0;
            }
            $prices[$productId] = [
                'price' => (float)$row['unit_price'] * $purchaseUnitsPerCatalogUnit,
                'source' => 'Last purchase ' . $row['order_date'],
            ];
        }
    }

    foreach ($products as $productId => $product) {
        if (!isset($prices[$productId]) && (float)$product['cost_price'] > 0) {
            $prices[$productId] = ['price' => (float)$product['cost_price'], 'source' => 'Catalog cost'];
        }
    }
    return $prices;
}

function pricingLoadProductRows(array $productIds) {
    global $conn;

    $ids = array_values(array_unique(array_filter(array_map('intval', $productIds))));
    if (empty($ids)) {
        return [];
    }
    $rows = [];
    $result = $conn->query('SELECT id, name, sku, unit FROM products WHERE id IN (' . implode(',', $ids) . ')');
    while ($row = $result->fetch_assoc()) {
        $rows[(int)$row['id']] = $row;
    }
    return $rows;
}

/**
 * Convert a formula/packaging quantity to the material's catalog unit, which is
 * the unit purchase prices are expressed in.
 */
function pricingToCatalogQuantity($quantity, $fromUnit, $catalogUnit) {
    if ($fromUnit === null || trim((string)$fromUnit) === '') {
        return (float)$quantity;
    }
    $converted = convertProductUnitQuantity($quantity, $fromUnit, $catalogUnit);
    return $converted ?? (float)$quantity;
}

/**
 * Build a fresh pricing line for a final product: formula raw materials at the
 * formula basis, and the product's packaging kit per finished unit.
 */
function pricingBuildLine($customerId, $productId, $productName, $quantity) {
    global $conn;

    $line = [
        'product_id' => (int)$productId,
        'product_name' => $productName,
        'quantity' => (float)$quantity,
        'basis_units' => PRICING_DEFAULT_BASIS_UNITS,
        'formula_id' => null,
        'formula_label' => '',
        'materials' => [],
        'packaging_enabled' => false,
        'packaging_option_id' => null,
        'packaging_options' => [],
        'packaging' => [],
        'warnings' => [],
    ];

    $stmt = $conn->prepare("
        SELECT id, name, batch_size, batch_unit, components_json
        FROM manufacturing_formulas
        WHERE product_id = ? AND is_active = 1
        ORDER BY updated_at DESC, id DESC
        LIMIT 1
    ");
    $stmt->bind_param('i', $productId);
    $stmt->execute();
    $formula = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $materialIds = [];
    $components = [];
    if ($formula) {
        $line['formula_id'] = (int)$formula['id'];
        $batch = rtrim(rtrim(number_format((float)$formula['batch_size'], 3, '.', ''), '0'), '.');
        $line['formula_label'] = $formula['name'] . ($batch !== '' && (float)$batch > 0 ? " ({$batch} " . $formula['batch_unit'] . ')' : '');
        $components = json_decode($formula['components_json'] ?? '[]', true) ?: [];
        foreach ($components as $component) {
            if (!empty($component['product_id'])) {
                $materialIds[] = (int)$component['product_id'];
            }
        }
    } else {
        $line['warnings'][] = 'No active formula is linked to this product.';
    }

    $packagingOptions = pricingPackagingOptionsForProduct($customerId, $productId);
    $line['packaging_options'] = $packagingOptions;
    foreach ($packagingOptions as $option) {
        foreach ($option['items'] as $item) {
            $materialIds[] = (int)$item['product_id'];
        }
    }

    $materialRows = pricingLoadProductRows($materialIds);
    $prices = pricingLatestMaterialPrices($materialIds);

    foreach ($components as $component) {
        $materialId = (int)($component['product_id'] ?? 0);
        $material = $materialRows[$materialId] ?? null;
        $catalogUnit = $material['unit'] ?? ($component['unit'] ?? '');
        $line['materials'][] = [
            'product_id' => $materialId ?: null,
            'name' => $material['name'] ?? (string)($component['name'] ?? ''),
            'quantity' => pricingToCatalogQuantity(is_numeric($component['quantity'] ?? null) ? $component['quantity'] : 0, $component['unit'] ?? '', $catalogUnit),
            'unit' => getProductFormulaUnit($catalogUnit) ?: (string)($component['unit'] ?? ''),
            'unit_cost' => $prices[$materialId]['price'] ?? 0,
            'price_source' => $prices[$materialId]['source'] ?? 'No price found',
        ];
    }

    foreach ($line['packaging_options'] as &$option) {
        foreach ($option['items'] as &$item) {
            $material = $materialRows[(int)$item['product_id']] ?? null;
            $catalogUnit = $material['unit'] ?? ($item['unit'] ?? '');
            $item['quantity'] = pricingToCatalogQuantity($item['quantity'], $item['unit'], $catalogUnit);
            $item['unit'] = getProductFormulaUnit($catalogUnit) ?: (string)$item['unit'];
            $item['unit_cost'] = $prices[(int)$item['product_id']]['price'] ?? 0;
            $item['price_source'] = $prices[(int)$item['product_id']]['source'] ?? 'No price found';
        }
        unset($item);
    }
    unset($option);

    if (!empty($line['packaging_options'])) {
        $first = $line['packaging_options'][0];
        $line['packaging_option_id'] = $first['id'];
        $line['packaging'] = $first['items'];
    }

    return $line;
}

/**
 * Active packaging kits for a product: kits linked to the product first, then
 * the customer's general kits that are not tied to a specific product.
 */
function pricingPackagingOptionsForProduct($customerId, $productId) {
    global $conn;

    if (!tableExists('packaging_options') || !tableExists('packaging_option_items')) {
        return [];
    }
    $hasProductColumn = tableHasColumn('packaging_options', 'product_id');
    $sql = $hasProductColumn
        ? 'SELECT id, name, product_id FROM packaging_options WHERE is_active = 1 AND (product_id = ? OR (product_id IS NULL AND customer_id = ?)) ORDER BY product_id IS NULL, name'
        : 'SELECT id, name, NULL AS product_id FROM packaging_options WHERE is_active = 1 AND customer_id = ? ORDER BY name';
    $stmt = $conn->prepare($sql);
    if ($hasProductColumn) {
        $stmt->bind_param('ii', $productId, $customerId);
    } else {
        $stmt->bind_param('i', $customerId);
    }
    $stmt->execute();
    $options = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $itemStmt = $conn->prepare('
        SELECT poi.product_id, p.name, poi.quantity, poi.unit
        FROM packaging_option_items poi
        JOIN products p ON p.id = poi.product_id
        WHERE poi.packaging_option_id = ?
        ORDER BY poi.id
    ');
    foreach ($options as &$option) {
        $option['id'] = (int)$option['id'];
        $itemStmt->bind_param('i', $option['id']);
        $itemStmt->execute();
        $option['items'] = $itemStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
    unset($option);
    $itemStmt->close();

    return $options;
}

function pricingNumber($value, $min = 0.0) {
    $number = is_numeric($value) ? (float)$value : 0.0;
    return is_finite($number) ? max($min, $number) : $min;
}

/**
 * Calculate one pricing line exactly like the pricing sheet:
 *  - material quantity for the order = formula quantity × quantity ÷ formula basis units
 *  - raw material cost = Σ material quantity × item cost
 *  - packaging cost = Σ (quantity per unit × price) × quantity, only when enabled
 *  - selling price = (raw + packaging) × (1 + margin%)
 *  - unit selling price = selling price ÷ quantity
 */
function pricingCalculateLine(array $line, $marginPercent) {
    $quantity = pricingNumber($line['quantity'] ?? 0);
    $basis = pricingNumber($line['basis_units'] ?? PRICING_DEFAULT_BASIS_UNITS);
    $scale = $basis > 0 ? $quantity / $basis : 0.0;

    $rawCost = 0.0;
    foreach ($line['materials'] as &$material) {
        $material['quantity'] = pricingNumber($material['quantity'] ?? 0);
        $material['unit_cost'] = pricingNumber($material['unit_cost'] ?? 0);
        $material['order_quantity'] = $material['quantity'] * $scale;
        $material['total_cost'] = $material['order_quantity'] * $material['unit_cost'];
        $rawCost += $material['total_cost'];
    }
    unset($material);

    $packagingUnitCost = 0.0;
    foreach ($line['packaging'] as &$item) {
        $item['quantity'] = pricingNumber($item['quantity'] ?? 0);
        $item['unit_cost'] = pricingNumber($item['unit_cost'] ?? 0);
        $item['unit_total'] = $item['quantity'] * $item['unit_cost'];
        $packagingUnitCost += $item['unit_total'];
    }
    unset($item);

    $packagingCost = !empty($line['packaging_enabled']) ? $packagingUnitCost * $quantity : 0.0;
    $totalCost = $rawCost + $packagingCost;
    $sellingPrice = $totalCost * (1 + pricingNumber($marginPercent) / 100);

    $line['quantity'] = $quantity;
    $line['basis_units'] = $basis;
    $line['raw_material_cost'] = $rawCost;
    $line['packaging_unit_cost'] = $packagingUnitCost;
    $line['packaging_cost'] = $packagingCost;
    $line['total_cost'] = $totalCost;
    $line['selling_price'] = $sellingPrice;
    $line['unit_selling_price'] = $quantity > 0 ? $sellingPrice / $quantity : 0.0;
    $line['unit_cost'] = $quantity > 0 ? $totalCost / $quantity : 0.0;
    $line['profit'] = $sellingPrice - $totalCost;
    $line['unit_profit'] = $quantity > 0 ? $line['profit'] / $quantity : 0.0;
    return $line;
}

function pricingCalculate(array $lines, $marginPercent) {
    $totals = ['total_cost' => 0.0, 'selling_price' => 0.0];
    foreach ($lines as &$line) {
        $line = pricingCalculateLine($line, $marginPercent);
        $totals['total_cost'] += $line['total_cost'];
        $totals['selling_price'] += $line['selling_price'];
    }
    unset($line);
    $totals['profit'] = $totals['selling_price'] - $totals['total_cost'];
    return [$lines, $totals];
}

/**
 * Rebuild lines from the submitted sheet, limited to products on the order.
 * Material rows are the ones the page rendered from the formula and packaging
 * kit; every total is recalculated server-side, never taken from the request.
 */
function pricingLinesFromRequest(array $order, $postedLines) {
    $lines = [];
    if (!is_array($postedLines)) {
        return $lines;
    }
    foreach ($postedLines as $posted) {
        $productId = (int)($posted['product_id'] ?? 0);
        if (!isset($order['items'][$productId])) {
            continue;
        }
        $line = [
            'product_id' => $productId,
            'product_name' => $order['items'][$productId]['name'],
            'quantity' => pricingNumber($posted['quantity'] ?? 0),
            'basis_units' => pricingNumber($posted['basis_units'] ?? PRICING_DEFAULT_BASIS_UNITS),
            'formula_id' => !empty($posted['formula_id']) ? (int)$posted['formula_id'] : null,
            'formula_label' => sanitize($posted['formula_label'] ?? ''),
            'packaging_enabled' => !empty($posted['packaging_enabled']),
            'packaging_option_id' => !empty($posted['packaging_option_id']) ? (int)$posted['packaging_option_id'] : null,
            'materials' => [],
            'packaging' => [],
        ];
        foreach (['materials', 'packaging'] as $group) {
            foreach ((array)($posted[$group] ?? []) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $line[$group][] = [
                    'product_id' => !empty($row['product_id']) ? (int)$row['product_id'] : null,
                    'name' => sanitize($row['name'] ?? ''),
                    'quantity' => pricingNumber($row['quantity'] ?? 0),
                    'unit' => sanitize($row['unit'] ?? ''),
                    'unit_cost' => pricingNumber($row['unit_cost'] ?? 0),
                    'price_source' => sanitize($row['price_source'] ?? ''),
                ];
            }
        }
        $lines[] = $line;
    }
    return $lines;
}

function pricingLoadSaved($pricingId) {
    global $conn;

    $stmt = $conn->prepare('SELECT * FROM order_pricings WHERE id = ?');
    $stmt->bind_param('i', $pricingId);
    $stmt->execute();
    $pricing = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$pricing || !canAccessOrder((int)$pricing['order_id'])) {
        return null;
    }

    $stmt = $conn->prepare('SELECT * FROM order_pricing_lines WHERE order_pricing_id = ? ORDER BY id');
    $stmt->bind_param('i', $pricingId);
    $stmt->execute();
    $pricing['lines'] = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $details = json_decode($row['details_json'], true) ?: [];
        $pricing['lines'][] = [
            'product_id' => (int)$row['product_id'],
            'product_name' => $row['product_name'],
            'quantity' => (float)$row['quantity'],
            'basis_units' => (float)$row['basis_units'],
            'formula_id' => $row['formula_id'] !== null ? (int)$row['formula_id'] : null,
            'formula_label' => $details['formula_label'] ?? '',
            'packaging_enabled' => (bool)$row['packaging_enabled'],
            'packaging_option_id' => $row['packaging_option_id'] !== null ? (int)$row['packaging_option_id'] : null,
            'materials' => $details['materials'] ?? [],
            'packaging' => $details['packaging'] ?? [],
            'packaging_options' => [],
            'warnings' => [],
        ];
    }
    $stmt->close();
    return $pricing;
}

/**
 * Save a calculated sheet. Returns the pricing id.
 */
function pricingSave($pricingId, array $order, array $lines, array $totals, $marginPercent, $notes) {
    global $pdo;

    $userId = (int)$_SESSION['user_id'];
    $pdo->beginTransaction();
    try {
        if ($pricingId > 0) {
            $stmt = $pdo->prepare('UPDATE order_pricings SET margin_percent = ?, total_cost = ?, total_selling_price = ?, notes = ?, updated_by = ? WHERE id = ? AND order_id = ?');
            $stmt->execute([$marginPercent, $totals['total_cost'], $totals['selling_price'], $notes, $userId, $pricingId, $order['id']]);
            $pdo->prepare('DELETE FROM order_pricing_lines WHERE order_pricing_id = ?')->execute([$pricingId]);
        } else {
            $stmt = $pdo->prepare('INSERT INTO order_pricings (order_id, customer_id, margin_percent, total_cost, total_selling_price, currency, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$order['id'], $order['customer_id'], $marginPercent, $totals['total_cost'], $totals['selling_price'], $order['currency'], $notes, $userId]);
            $pricingId = (int)$pdo->lastInsertId();
        }

        $lineStmt = $pdo->prepare('
            INSERT INTO order_pricing_lines
                (order_pricing_id, product_id, product_name, quantity, basis_units, formula_id, packaging_enabled, packaging_option_id,
                 raw_material_cost, packaging_cost, total_cost, selling_price, unit_selling_price, details_json)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        foreach ($lines as $line) {
            $lineStmt->execute([
                $pricingId, $line['product_id'], $line['product_name'], $line['quantity'], $line['basis_units'],
                $line['formula_id'], $line['packaging_enabled'] ? 1 : 0, $line['packaging_option_id'],
                $line['raw_material_cost'], $line['packaging_cost'], $line['total_cost'], $line['selling_price'], $line['unit_selling_price'],
                json_encode([
                    'formula_label' => $line['formula_label'],
                    'materials' => $line['materials'],
                    'packaging' => $line['packaging'],
                ], JSON_UNESCAPED_UNICODE),
            ]);
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $pricingId;
}

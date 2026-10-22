<?php
/** Normalize compatible quantities before combining requirements across orders. */
function manufacturing_planning_quantity(float $quantity, string $unit): array {
    $unit = strtolower(trim($unit));
    switch ($unit) {
        case 'g': case 'gram': case 'grams':
            return [$quantity / 1000, 'kg'];
        case 'kg': case 'kilo': case 'kilogram': case 'kilograms':
            return [$quantity, 'kg'];
        case 'ml': case 'milliliter': case 'milliliters':
            return [$quantity / 1000, 'L'];
        case 'l': case 'liter': case 'litre': case 'liters': case 'litres':
            return [$quantity, 'L'];
        case 'each': case 'pc': case 'pcs': case 'piece': case 'pieces':
            return [$quantity, 'pcs'];
        default:
            return [$quantity, $unit];
    }
}

/** Read-only plan for scoped orders whose sourcing has not been completed. */
function manufacturing_sourcing_plan(array $filters = [], bool $canViewNames = true): array {
    global $pdo;
    $where = [getCustomerChannelScopeSql('c', 'customer_factory'),
        "mo.status NOT IN ('completed', 'cancelled')",
        "NOT EXISTS (SELECT 1 FROM manufacturing_order_steps ms WHERE ms.manufacturing_order_id = mo.id AND ms.step_key = 'sourcing' AND ms.status = 'completed')"];
    $params = [];
    foreach (['customer_id', 'location_id'] as $filter) {
        if (!empty($filters[$filter])) {
            $where[] = "mo.$filter = ?";
            $params[] = (int)$filters[$filter];
        }
    }
    $stmt = $pdo->prepare("SELECT mo.*, c.name AS customer_name, p.name AS final_product_name,
            l.name AS location_name, f.components_json, f.batch_size AS formula_batch_size, f.batch_unit,
            bs.size AS bottle_size_value, bs.unit AS bottle_size_unit
        FROM manufacturing_orders mo
        JOIN customers c ON c.id = mo.customer_id
        LEFT JOIN factories customer_factory ON customer_factory.id = c.factory_id
        JOIN manufacturing_formulas f ON f.id = mo.formula_id
        LEFT JOIN products p ON p.id = mo.product_id
        LEFT JOIN locations l ON l.id = mo.location_id
        LEFT JOIN bottle_sizes bs ON bs.id = mo.bottle_size_id
        WHERE " . implode(' AND ', $where) . ' ORDER BY mo.due_date IS NULL, mo.due_date, mo.id');
    $stmt->execute($params);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$orders) return ['materials' => [], 'order_count' => 0, 'warnings' => []];

    $ids = array_column($orders, 'id');
    $stmt = $pdo->prepare('SELECT * FROM manufacturing_sourcing_components WHERE manufacturing_order_id IN ('
        . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY id');
    $stmt->execute($ids);
    $stored = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $stored[(int)$row['manufacturing_order_id']][] = $row;
    $rows = [];
    $warnings = [];
    $productIds = [];
    foreach ($orders as $order) {
        $orderId = (int)$order['id'];
        $saved = $stored[$orderId] ?? [];
        $frozen = !empty($order['packaging_option_id']) && (int)$order['number_of_bottles'] <= 0;
        foreach ($saved as $row) {
            if (!empty($row['deducted_at']) || $row['receipt_status'] === 'approved') $frozen = true;
        }
        if ($frozen) {
            $requirements = $saved;
            if ((int)$order['number_of_bottles'] <= 0) {
                $warnings[] = ['order' => $order, 'message' => 'No bottle count: showing saved requirements only.'];
            }
        } else {
            // Use the same calculation as individual orders, without requiring users to open them first.
            $preview = manufacturing_preview_sourcing($order);
            $requirements = $preview['requirements'];
            foreach ($preview['warnings'] as $message) $warnings[] = ['order' => $order, 'message' => $message];
        }
        foreach ($requirements as $index => $row) {
            if (!empty($row['deducted_at']) || ($row['receipt_status'] ?? '') === 'approved'
                || (float)$row['required_quantity'] <= 0) continue;
            $row['order'] = $order;
            $row['identity'] = $orderId . ':' . $index;
            $rows[] = $row;
            if (!empty($row['product_id'])) $productIds[(int)$row['product_id']] = (int)$row['product_id'];
        }
    }
    $products = [];
    if ($productIds) {
        $stmt = $pdo->prepare('SELECT id, name, sku, barcode FROM products WHERE id IN ('
            . implode(',', array_fill(0, count($productIds), '?')) . ')');
        $stmt->execute(array_values($productIds));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $product) $products[(int)$product['id']] = $product;
    }
    $materials = [];
    $includedOrders = [];
    $search = trim($filters['search'] ?? '');
    foreach ($rows as $row) {
        $source = $row['source'] ?? 'formula';
        if (!empty($filters['source']) && $filters['source'] !== $source) continue;
        $product = $products[(int)($row['product_id'] ?? 0)] ?? null;
        $sku = $product['sku'] ?? '';
        $name = $canViewNames ? ($product['name'] ?? $row['component_name']) : ($sku ?: 'Hidden');
        $barcode = $product['barcode'] ?? '';
        if ($search !== '' && mb_stripos($name . ' ' . $sku . ' ' . $barcode, $search) === false) continue;
        [$quantity, $unit] = manufacturing_planning_quantity((float)$row['required_quantity'], $row['unit'] ?? '');
        // Text-only components are kept separate; equal names do not establish a shared material identity.
        $identity = $product ? 'product:' . $product['id'] : 'manual:' . $row['identity'];
        $key = $identity . ':' . $unit;
        if (!isset($materials[$key])) {
            $materials[$key] = ['name' => $name, 'sku' => $sku, 'barcode' => $barcode,
                'product_id' => $product['id'] ?? null, 'quantity' => 0, 'unit' => $unit, 'sources' => [], 'orders' => []];
        }
        $material = &$materials[$key];
        $material['quantity'] += $quantity;
        $material['sources'][$source] = true;
        $orderId = (int)$row['order']['id'];
        $includedOrders[$orderId] = true;
        if (!isset($material['orders'][$orderId])) {
            $material['orders'][$orderId] = ['id' => $orderId, 'order_number' => $row['order']['order_number'],
                'customer_name' => $row['order']['customer_name'], 'product_name' => $row['order']['final_product_name'],
                'location_name' => $row['order']['location_name'], 'quantity' => 0];
        }
        $material['orders'][$orderId]['quantity'] += $quantity;
        unset($material);
    }
    uasort($materials, static fn($a, $b) => (isset($b['sources']['formula']) <=> isset($a['sources']['formula']))
        ?: strnatcasecmp($a['name'], $b['name']) ?: strcmp($a['unit'], $b['unit']));
    return ['materials' => array_values($materials), 'order_count' => count($includedOrders), 'warnings' => $warnings];
}

<?php

function manufacturing_packaging_count_labels(): array {
    return [
        'final_product_count' => 'عدد المنتج النهائي',
        'carton_count' => 'عدد الكراتين',
        'units_per_carton' => 'وحدات/كرتونة',
        'last_carton_units' => 'كسور في اخر كرتونة',
    ];
}

function manufacturing_validate_packaging_counts(array $values): void {
    foreach (manufacturing_packaging_count_labels() + ['breakage_count' => 'الكسر: العدد', 'primary_product_used' => 'منتج اولي مستخدم'] as $key => $label) {
        $value = $values[$key] ?? '';
        if ($value === '') continue;
        if (!is_scalar($value) || !preg_match('/^\d+$/D', (string)$value) || (float)$value > 2147483647) {
            throw new InvalidArgumentException($label . ': enter a non-negative whole number.');
        }
    }
    if (!empty($values['last_carton_units']) && !empty($values['units_per_carton'])
        && (int)$values['last_carton_units'] >= (int)$values['units_per_carton']) {
        throw new InvalidArgumentException('كسور في اخر كرتونة must be less than وحدات/كرتونة.');
    }
}

/** Calculate in the material's sourcing unit; whole pieces round up (e.g. cartons). */
function manufacturing_packaging_balance(array $component, int $plannedCount, ?int $finalCount): array {
    $planned = (float)$component['required_quantity'];
    $waste = (float)($component['waste_quantity'] ?? 0);
    if ($finalCount === null || $plannedCount <= 0) {
        return ['planned_quantity' => $planned, 'issued_quantity' => $planned,
            'used_quantity' => null, 'returned_quantity' => null, 'waste_quantity' => $waste];
    }
    $used = $planned * $finalCount / $plannedCount;
    $native = convertProductUnitQuantity($used, $component['unit'], $component['stock_unit']);
    if ($native !== null) {
        $native = normalizeProductUnit($component['stock_unit']) === 'each'
            ? max(0, ceil($native - 0.00000001)) : round($native, 2);
        $used = convertProductUnitQuantity($native, $component['stock_unit'], $component['unit']);
    }
    $used = round($used, 4);
    $issued = max($planned, round($used + $waste, 4));
    return ['planned_quantity' => $planned, 'issued_quantity' => $issued,
        'used_quantity' => $used, 'returned_quantity' => round($issued - $used - $waste, 4), 'waste_quantity' => $waste];
}

/** The same real material rows feed packaging, dispatch and both report formats. */
function manufacturing_packaging_material_rows($conn, int $orderId): array {
    $stmt = $conn->prepare("SELECT msc.*, p.sku, p.unit AS stock_unit, mo.number_of_bottles,
        (SELECT item_value FROM manufacturing_packaging_checklist WHERE manufacturing_order_id = mo.id
            AND item_key = 'final_product_count' LIMIT 1) AS final_count,
        COALESCE(mw.quantity, 0) AS waste_quantity,
        t.planned_quantity, t.issued_quantity, t.used_quantity, t.returned_quantity,
        t.waste_quantity AS posted_waste, t.sourcing_component_id AS tracked_component, t.posted_at, t.issued_notes, t.used_notes, t.returned_notes, t.waste_notes
        FROM manufacturing_sourcing_components msc
        JOIN manufacturing_orders mo ON mo.id = msc.manufacturing_order_id
        LEFT JOIN products p ON p.id = msc.product_id
        LEFT JOIN manufacturing_material_waste mw ON mw.sourcing_component_id = msc.id
        LEFT JOIN manufacturing_packaging_material_tracking t ON t.sourcing_component_id = msc.id
        WHERE msc.manufacturing_order_id = ? AND msc.source = 'packaging' ORDER BY msc.id");
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    foreach ($rows as &$row) {
        if ($row['tracked_component']) {
            $row['waste_quantity'] = $row['posted_waste'];
        } else {
            $final = $row['final_count'] === null || $row['final_count'] === '' ? null : (int)$row['final_count'];
            $row = array_replace($row, manufacturing_packaging_balance($row, (int)$row['number_of_bottles'], $final));
        }
    }
    unset($row);
    return $rows;
}

/** Actual line quantities are independent of total production. Returns are the unused balance. */
function manufacturing_save_packaging_material_entries(PDO $pdo, int $orderId, int $finalCount, array $entries): void {
    $order = manufacturing_lock_stock_order($pdo, $orderId);
    $stmt = $pdo->prepare("SELECT msc.*, p.unit AS stock_unit, COALESCE(mw.quantity, 0) AS waste_quantity,
        t.sourcing_component_id AS tracked_component, t.posted_at, t.issued_quantity, t.used_quantity,
        t.returned_quantity, t.waste_quantity AS tracked_waste
        FROM manufacturing_sourcing_components msc LEFT JOIN products p ON p.id = msc.product_id
        LEFT JOIN manufacturing_material_waste mw ON mw.sourcing_component_id = msc.id
        LEFT JOIN manufacturing_packaging_material_tracking t ON t.sourcing_component_id = msc.id
        WHERE msc.manufacturing_order_id = ? AND msc.source = 'packaging' ORDER BY msc.product_id, msc.id FOR UPDATE");
    $stmt->execute([$orderId]);
    $components = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($components && (int)$order['number_of_bottles'] <= 0) throw new DomainException('A planned bottle count is required to reconcile packaging materials.');
    foreach ($entries as $id => $fields) {
        if (!ctype_digit((string)$id) || !in_array((int)$id, array_map('intval', array_column($components, 'id')), true)
            || !is_array($fields) || array_diff(array_keys($fields), ['issued', 'used', 'waste'])) {
            throw new DomainException('Invalid packaging material entries for this order.');
        }
    }
    foreach ($components as $component) {
        if ($component['posted_at']) continue;
        if ($component['tracked_component'] && !isset($entries[$component['id']])) continue;
        $values = manufacturing_packaging_balance($component, (int)$order['number_of_bottles'], $finalCount);
        foreach (['issued', 'used', 'waste'] as $field) {
            $input = $entries[$component['id']][$field] ?? $values[$field . '_quantity'];
            if (!is_scalar($input) || !preg_match('/^\d+(?:\.\d{1,4})?$/D', (string)$input)) throw new DomainException('Enter non-negative packaging quantities with up to four decimal places.');
            $values[$field . '_quantity'] = (float)$input;
            manufacturing_component_stock_quantity(array_replace($component, ['required_quantity' => (float)$input]));
        }
        if ($values['issued_quantity'] < (float)$component['required_quantity']) throw new DomainException('Issued packaging cannot be less than the quantity already sourced.');
        $returned = round($values['issued_quantity'] - $values['used_quantity'] - $values['waste_quantity'], 4);
        if ($returned < 0) throw new DomainException('Issued packaging must cover used material and waste. Increase مصروف when receiving extra material.');
        manufacturing_component_stock_quantity(array_replace($component, ['required_quantity' => $returned]));
        $pdo->prepare('INSERT INTO manufacturing_packaging_material_tracking
            (sourcing_component_id, planned_quantity, issued_quantity, used_quantity, returned_quantity, waste_quantity, posted_at)
            VALUES (?, ?, ?, ?, ?, ?, NULL) ON DUPLICATE KEY UPDATE issued_quantity = VALUES(issued_quantity),
            used_quantity = VALUES(used_quantity), returned_quantity = VALUES(returned_quantity), waste_quantity = VALUES(waste_quantity)')
            ->execute([$component['id'], $component['required_quantity'], $values['issued_quantity'], $values['used_quantity'], $returned, $values['waste_quantity']]);
    }
}

function manufacturing_packaging_material_html(array $rows, bool $canViewNames): string {
    if (!$rows) return '<p>No packaging materials are linked to this order.</p>';
    $html = '<table><thead><tr><th>المادة</th><th>الوحدة</th><th>مصروف</th><th>مستخدم</th><th>راجع</th><th>الهالك</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        $name = $canViewNames ? $row['component_name'] : ($row['sku'] ?: 'Hidden');
        $html .= '<tr><td>' . htmlspecialchars($name) . '</td><td>' . htmlspecialchars($row['unit'] ?? '') . '</td>';
        foreach (['issued', 'used', 'returned', 'waste'] as $field) {
            $value = $row[$field . '_quantity'];
            $html .= '<td>' . htmlspecialchars($value === null ? '—' : (string)$value);
            if (!empty($row[$field . '_notes'])) $html .= '<br><small>' . htmlspecialchars($row[$field . '_notes']) . '</small>';
            $html .= '</td>';
        }
        $html .= '</tr>';
    }
    return $html . '</tbody></table>';
}

function manufacturing_packaging_output_html($conn, int $orderId): string {
    $stmt = $conn->prepare('SELECT produced_quantity, finished_quantity, primary_quantity, primary_used_quantity FROM manufacturing_packaging_output WHERE manufacturing_order_id = ?');
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $output = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$output) return '';
    return '<p>Total produced: ' . (int)$output['produced_quantity'] . ' | Packaged finished products: ' . (int)$output['finished_quantity']
        . ' | منتج أولي إلى مخزن منتج اولي: ' . (int)$output['primary_quantity'] . ' | منتج اولي مستخدم: ' . (int)$output['primary_used_quantity'] . '</p>';
}

/** Post actual packaging, waste and primary output atomically with step completion. */
function manufacturing_reconcile_finished_count(PDO $pdo, int $orderId, int $finalCount): ?string {
    $order = manufacturing_lock_stock_order($pdo, $orderId);
    manufacturing_validate_packaging_counts(['final_product_count' => $finalCount]);
    $posted = $pdo->prepare('SELECT manufacturing_order_id FROM manufacturing_packaging_output WHERE manufacturing_order_id = ? FOR UPDATE');
    $posted->execute([$orderId]);
    if ($posted->fetchColumn()) return null;
    $primaryUsedStmt = $pdo->prepare("SELECT item_value FROM manufacturing_packaging_checklist WHERE manufacturing_order_id = ? AND item_key = 'primary_product_used'");
    $primaryUsedStmt->execute([$orderId]);
    $primaryUsedValue = $primaryUsedStmt->fetchColumn();
    if ($primaryUsedValue === false || $primaryUsedValue === null || $primaryUsedValue === '') $primaryUsedValue = (string)$order['primary_stock_quantity'];
    manufacturing_validate_packaging_counts(['primary_product_used' => $primaryUsedValue]);
    $primaryUsed = (int)$primaryUsedValue;
    if ($primaryUsed > $finalCount) throw new DomainException('منتج اولي مستخدم cannot exceed total produced units.');
    manufacturing_save_packaging_material_entries($pdo, $orderId, $finalCount, []);
    $stmt = $pdo->prepare("SELECT msc.*, p.unit AS stock_unit, t.planned_quantity, t.issued_quantity, t.used_quantity,
        t.returned_quantity, t.waste_quantity, t.posted_at FROM manufacturing_sourcing_components msc
        LEFT JOIN products p ON p.id = msc.product_id
        JOIN manufacturing_packaging_material_tracking t ON t.sourcing_component_id = msc.id
        WHERE msc.manufacturing_order_id = ? AND msc.source = 'packaging' ORDER BY msc.product_id, msc.id FOR UPDATE");
    $stmt->execute([$orderId]);
    $components = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$components) {
        if ($primaryUsed > 0 || (int)$order['primary_stock_quantity'] > 0) throw new DomainException('Source the actual packaging materials before completing an order that uses primary stock.');
        if (!empty($order['packaging_option_id'])) throw new DomainException('The order has no sourced packaging materials. Complete packaging sourcing first.');
        return null;
    }
    if ((int)$order['number_of_bottles'] <= 0) throw new DomainException('A planned bottle count is required to reconcile packaging materials.');
    $maximumPacked = 0;
    $hasCountedPackaging = false;
    foreach ($components as $component) {
        if ($component['posted_at']) throw new DomainException('Historical packaging has already been reconciled.');
        if (!$component['product_id'] || !$component['deducted_at']) throw new DomainException('Complete sourcing for every packaging material first.');
        $consumed = round((float)$component['used_quantity'] + (float)$component['waste_quantity'], 4);
        if (abs($consumed - round($consumed, 2)) > 0.00000001) throw new DomainException('Packaging usage exceeds the precision of its sourcing unit. Review the material unit.');
        $before = manufacturing_component_stock_quantity($component);
        $extra = manufacturing_component_stock_quantity(array_replace($component, ['required_quantity' => round((float)$component['issued_quantity'] - (float)$component['planned_quantity'], 4)]));
        $returnQuantity = manufacturing_component_stock_quantity(array_replace($component, ['required_quantity' => $component['returned_quantity']]));
        if ($extra > 0 || $returnQuantity > 0) {
            $movements = $pdo->prepare("SELECT inventory_id, SUM(change_quantity) AS net_quantity FROM inventory_stock_logs
                WHERE product_id = ? AND source_id = ? AND source_type IN ('manufacturing_sourcing_component', 'manufacturing_return_component')
                GROUP BY inventory_id ORDER BY inventory_id");
            $movements->execute([$component['product_id'], $component['id']]);
            $withdrawals = $movements->fetchAll(PDO::FETCH_ASSOC);
            if (abs(-array_sum(array_column($withdrawals, 'net_quantity')) - $before) > 0.00000001
                || array_filter($withdrawals, static fn($row) => (float)$row['net_quantity'] > 0)) {
                throw new DomainException('Packaging stock history needs reconciliation before adjusting this legacy deduction.');
            }
            if ($extra > 0) {
                manufacturing_withdraw_component_stock($pdo, $order, $component, $extra);
                $movements->execute([$component['product_id'], $component['id']]);
                $withdrawals = $movements->fetchAll(PDO::FETCH_ASSOC);
            }
            $remaining = $returnQuantity;
            foreach ($withdrawals as $withdrawal) {
                $quantity = min($remaining, -(float)$withdrawal['net_quantity']);
                if ($quantity <= 0) continue;
                $inventory = $pdo->prepare('SELECT id FROM inventories WHERE id = ? FOR UPDATE');
                $inventory->execute([$withdrawal['inventory_id']]);
                if (!$inventory->fetchColumn()) throw new DomainException('The original packaging warehouse no longer exists.');
                $stock = $pdo->prepare('SELECT quantity FROM inventory_products WHERE inventory_id = ? AND product_id = ? FOR UPDATE');
                $stock->execute([$withdrawal['inventory_id'], $component['product_id']]);
                $previous = (float)($stock->fetchColumn() ?: 0);
                $pdo->prepare('INSERT INTO inventory_products (inventory_id, product_id, quantity) VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)')->execute([$withdrawal['inventory_id'], $component['product_id'], $quantity]);
                manufacturing_component_stock_log($pdo, (int)$withdrawal['inventory_id'], $component, $quantity, $previous, 'manufacturing_return_component');
                $remaining = round($remaining - $quantity, 2);
            }
            if ($remaining > 0) throw new DomainException('Packaging return exceeds its recorded source allocations.');
        }
        $pdo->prepare('UPDATE manufacturing_sourcing_components SET required_quantity = ? WHERE id = ?')->execute([$consumed, $component['id']]);
        $waste = (float)$component['waste_quantity'];
        if ($waste > 0) {
            $nativeWaste = manufacturing_component_stock_quantity(array_replace($component, ['required_quantity' => $waste]));
            $pdo->prepare('INSERT INTO manufacturing_material_waste (manufacturing_order_id, sourcing_component_id, product_id, quantity, unit, stock_quantity, recorded_by)
                VALUES (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE quantity = VALUES(quantity), stock_quantity = VALUES(stock_quantity), recorded_by = VALUES(recorded_by)')
                ->execute([$orderId, $component['id'], $component['product_id'], $waste, $component['unit'], $nativeWaste, $_SESSION['user_id'] ?? null]);
        } else {
            $pdo->prepare('DELETE FROM manufacturing_material_waste WHERE sourcing_component_id = ? AND posted_at IS NULL')->execute([$component['id']]);
        }
        $pdo->prepare('UPDATE manufacturing_packaging_material_tracking SET posted_at = NOW() WHERE sourcing_component_id = ?')->execute([$component['id']]);
        // Compare counts in product units: two labels per product represent one packaged product.
        if (normalizeProductUnit($component['stock_unit']) === 'each' && (float)$component['planned_quantity'] > 0) {
            $hasCountedPackaging = true;
            $equivalent = (float)$component['used_quantity'] * (int)$order['number_of_bottles'] / (float)$component['planned_quantity'];
            $maximumPacked = max($maximumPacked, $equivalent);
        }
    }
    $finished = $hasCountedPackaging ? min($finalCount, (int)floor($maximumPacked + 0.00000001)) : $finalCount;
    $primaryQuantity = $finalCount - $finished;
    $alreadyConsumed = !empty($order['primary_stock_deducted_at']) ? (int)$order['primary_stock_quantity'] : 0;
    $primaryDifference = $primaryUsed - $alreadyConsumed;
    if ($primaryDifference !== 0) {
        $available = manufacturing_primary_availability($pdo, (int)$order['product_id'], (int)$order['location_id']);
        if (!$available['primary_product_id']) throw new DomainException('There is no matching primary product to reconcile منتج اولي مستخدم.');
        manufacturing_move_primary_stock($pdo, (int)$available['primary_product_id'], -$primaryDifference, $orderId, 'manufacturing_primary_adjustment');
    }
    $primaryId = null;
    if ($primaryQuantity > 0) {
        $primaryId = manufacturing_ensure_primary_product($pdo, (int)$order['product_id']);
        manufacturing_move_primary_stock($pdo, $primaryId, $primaryQuantity, $orderId, 'manufacturing_primary_output');
    }
    $pdo->prepare('INSERT INTO manufacturing_packaging_output (manufacturing_order_id, produced_quantity, finished_quantity, primary_quantity, primary_product_id, primary_used_quantity)
        VALUES (?, ?, ?, ?, ?, ?)')->execute([$orderId, $finalCount, $finished, $primaryQuantity, $primaryId, $primaryUsed]);
    manufacturing_post_material_waste($pdo, $order, 'packaging');
    return sprintf('Packaging reconciled: %d finished products; %d primary products in مخزن منتج اولي. Returns restored and waste transferred to مخزن الهالك.', $finished, $primaryQuantity);
}

function manufacturing_save_packaging_material_notes(PDO $pdo, int $orderId, array $notes): void {
    if (!$pdo->inTransaction()) throw new LogicException('Packaging notes require a transaction.');
    foreach ($notes as $componentId => $fields) {
        if (!is_array($fields)) throw new InvalidArgumentException('Invalid packaging material notes.');
        foreach ($fields as $field => $note) {
            if (!in_array($field, ['issued', 'used', 'returned', 'waste'], true) || !is_scalar($note)) throw new InvalidArgumentException('Invalid packaging material note.');
            $pdo->prepare("UPDATE manufacturing_packaging_material_tracking t JOIN manufacturing_sourcing_components msc ON msc.id = t.sourcing_component_id
                SET t.{$field}_notes = ? WHERE msc.id = ? AND msc.manufacturing_order_id = ? AND msc.source = 'packaging'")
                ->execute([(string)$note, (int)$componentId, $orderId]);
        }
    }
}

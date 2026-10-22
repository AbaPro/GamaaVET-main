<?php
/** Shared formula + packaging requirements for manufacturing. */
function manufacturing_packaging_options(int $customerId, int $productId): array {
    global $pdo;
    $stmt = $pdo->prepare("SELECT id, name, product_id FROM packaging_options
        WHERE customer_id = ? AND is_active = 1 AND (product_id = ? OR product_id IS NULL)
        ORDER BY product_id IS NULL, name, id");
    $stmt->execute([$customerId, $productId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function manufacturing_packaging_items(int $optionId): array {
    global $pdo;
    $stmt = $pdo->prepare("SELECT poi.product_id, p.name, poi.quantity, poi.unit, poi.notes
        FROM packaging_option_items poi JOIN products p ON p.id = poi.product_id
        WHERE poi.packaging_option_id = ? ORDER BY poi.id");
    $stmt->execute([$optionId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function manufacturing_sourcing_key(array $row): string {
    return json_encode([
        $row['source'] ?? 'formula',
        !empty($row['product_id']) ? (int)$row['product_id'] : (string)$row['component_name'],
        strtolower(trim($row['unit'] ?? '')),
    ], JSON_UNESCAPED_UNICODE);
}

/** Packaging owns any material also present in a legacy formula. */
function manufacturing_sourcing_requirements(array $components, array $packaging, int $bottles): array {
    $packagingIds = array_column($packaging, 'product_id');
    $rows = [];
    foreach (['formula' => $components, 'packaging' => $packaging] as $source => $items) {
        foreach ($items as $index => $item) {
            if ($source === 'formula' && !empty($item['product_id'])
                && in_array($item['product_id'], $packagingIds)) {
                continue;
            }
            $row = [
                'source' => $source,
                'formula_component_index' => $index,
                'product_id' => !empty($item['product_id']) ? (int)$item['product_id'] : null,
                'component_name' => (string)($item['name'] ?? ''),
                'required_quantity' => round((float)($item['quantity'] ?? $item['ratio'] ?? 0) * ($source === 'packaging' ? $bottles : 1), 4),
                'unit' => $item['unit'] ?? '',
                'notes' => $item['notes'] ?? '',
            ];
            $key = manufacturing_sourcing_key($row);
            if (isset($rows[$key])) {
                $rows[$key]['required_quantity'] += $row['required_quantity'];
            } else {
                $rows[$key] = $row;
            }
        }
    }
    return $rows;
}

/** Calculate current requirements without saving an order or changing inventory. */
function manufacturing_preview_sourcing(array $order): array {
    $bottles = (int)($order['number_of_bottles'] ?? 0);
    $optionId = (int)($order['packaging_option_id'] ?? 0);
    $warnings = [];
    if (!$optionId && !empty($order['product_id']) && $bottles > 0) {
        $options = manufacturing_packaging_options((int)$order['customer_id'], (int)$order['product_id']);
        $optionId = (int)($options[0]['id'] ?? 0);
    }
    $packaging = $optionId && $bottles > 0 ? manufacturing_packaging_items($optionId) : [];
    if ($bottles <= 0) {
        $warnings[] = 'No bottle count: packaging quantities cannot be calculated.';
    } elseif (!$optionId) {
        $warnings[] = 'No active packaging option: only formula requirements are included.';
    } elseif (!$packaging) {
        $warnings[] = 'The selected packaging option contains no materials.';
    }
    $components = json_decode($order['components_json'] ?? '[]', true);
    $components = manufacturing_recalculate_components($order, is_array($components) ? $components : []);
    if (!$components) $warnings[] = 'The formula contains no raw materials.';
    return [
        'option_id' => $optionId,
        'requirements' => manufacturing_sourcing_requirements($components, $packaging, $bottles),
        'warnings' => $warnings,
    ];
}

/** Refresh only unconsumed, open sourcing lists. Preserve row IDs and user notes. */
function manufacturing_sync_sourcing(int $orderId): bool {
    global $pdo;
    $ownTransaction = !$pdo->inTransaction();
    if ($ownTransaction) $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT mo.*, f.components_json, f.batch_size AS formula_batch_size, f.batch_unit,
                bs.size AS bottle_size_value, bs.unit AS bottle_size_unit
            FROM manufacturing_orders mo
            JOIN manufacturing_formulas f ON f.id = mo.formula_id
            LEFT JOIN bottle_sizes bs ON bs.id = mo.bottle_size_id
            WHERE mo.id = ? FOR UPDATE");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt = $pdo->prepare("SELECT status FROM manufacturing_order_steps
            WHERE manufacturing_order_id = ? AND step_key = 'sourcing' FOR UPDATE");
        $stmt->execute([$orderId]);
        $sourcingStatus = $stmt->fetchColumn();
        $stmt = $pdo->prepare('SELECT * FROM manufacturing_sourcing_components WHERE manufacturing_order_id = ? ORDER BY id FOR UPDATE');
        $stmt->execute([$orderId]);
        $existing = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $protected = !$order || in_array($order['status'], ['completed', 'cancelled'], true) || $sourcingStatus === 'completed'
            || (!empty($order['packaging_option_id']) && (int)$order['number_of_bottles'] <= 0);
        foreach ($existing as $row) {
            if (!empty($row['deducted_at']) || $row['receipt_status'] === 'approved') $protected = true;
        }
        if ($protected) {
            if ($ownTransaction) $pdo->commit();
            return false;
        }

        $preview = manufacturing_preview_sourcing($order);
        if ($preview['option_id'] && empty($order['packaging_option_id'])) {
            $pdo->prepare('UPDATE manufacturing_orders SET packaging_option_id = ? WHERE id = ?')
                ->execute([$preview['option_id'], $orderId]);
        }
        $desired = $preview['requirements'];
        $byKey = [];
        foreach ($existing as $row) $byKey[manufacturing_sourcing_key($row)][] = $row;
        $keep = [];
        foreach ($desired as $key => $row) {
            $match = $byKey[$key][0] ?? null;
            // Carry a legacy formula packaging note into its packaging row.
            $legacy = $row;
            $legacy['source'] = 'formula';
            $noteRows = $byKey[$key] ?? [];
            if ($row['source'] === 'packaging') {
                $noteRows = array_merge($noteRows, $byKey[manufacturing_sourcing_key($legacy)] ?? []);
            }
            $notes = $noteRows
                ? implode('; ', array_unique(array_filter(array_map(static fn($item) => trim($item['notes'] ?? ''), $noteRows), static fn($note) => $note !== '')))
                : $row['notes'];
            if ($match) {
                $pdo->prepare('UPDATE manufacturing_sourcing_components SET formula_component_index = ?, component_name = ?, required_quantity = ?, notes = ? WHERE id = ?')
                    ->execute([$row['formula_component_index'], $row['component_name'], $row['required_quantity'], $notes, $match['id']]);
                $keep[] = (int)$match['id'];
            } else {
                $pdo->prepare('INSERT INTO manufacturing_sourcing_components
                    (manufacturing_order_id, source, formula_component_index, product_id, component_name, required_quantity, unit, notes)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)')->execute([$orderId, $row['source'], $row['formula_component_index'], $row['product_id'],
                        $row['component_name'], $row['required_quantity'], $row['unit'], $notes]);
                $keep[] = (int)$pdo->lastInsertId();
            }
        }
        foreach ($existing as $row) {
            if (!in_array((int)$row['id'], $keep, true)) {
                $pdo->prepare('DELETE FROM manufacturing_sourcing_components WHERE id = ? AND manufacturing_order_id = ?')->execute([$row['id'], $orderId]);
            }
        }
        if ($ownTransaction) $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($ownTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** Use saved sourcing requirements in handoffs, including locked historical orders. */
function manufacturing_sourcing_document_html(int $orderId, bool $canViewComponentName): string {
    global $pdo;
    $stmt = $pdo->prepare("SELECT msc.*, p.sku FROM manufacturing_sourcing_components msc
        LEFT JOIN products p ON p.id = msc.product_id WHERE manufacturing_order_id = ?
        ORDER BY source, formula_component_index, msc.id");
    $stmt->execute([$orderId]);
    $html = '<h4>Sourcing Requirements: Formula + Packaging</h4><table><thead><tr><th>Material</th><th>Source</th><th>Required Quantity</th><th>Unit</th><th>Notes</th></tr></thead><tbody>';
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $name = $canViewComponentName ? $row['component_name'] : ($row['sku'] ?: 'Hidden');
        $html .= '<tr><td>' . htmlspecialchars($name) . '</td><td>' . ($row['source'] === 'packaging' ? 'Packaging' : 'Formula')
            . '</td><td>' . htmlspecialchars($row['required_quantity']) . '</td><td>' . htmlspecialchars($row['unit'] ?? '')
            . '</td><td>' . htmlspecialchars($row['notes'] ?? '') . '</td></tr>';
    }
    return $html . '</tbody></table>';
}

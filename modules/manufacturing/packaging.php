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
    foreach (manufacturing_packaging_count_labels() + ['breakage_count' => 'الكسر: العدد'] as $key => $label) {
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

// Caller owns the transaction: quantities, prices, notes and step state commit together.
function manufacturing_reconcile_finished_count(PDO $pdo, int $orderId, int $finalCount): ?string {
    if (!$pdo->inTransaction()) throw new LogicException('Packaging reconciliation requires a transaction.');
    $stmt = $pdo->prepare('SELECT mo.*, bs.size AS bottle_size_value FROM manufacturing_orders mo LEFT JOIN bottle_sizes bs ON bs.id = mo.bottle_size_id WHERE mo.id = ? FOR UPDATE');
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) throw new RuntimeException('Manufacturing order not found.');
    $planned = $order['number_of_bottles'] !== null ? (int)$order['number_of_bottles'] : (int)$order['batch_size'];
    if ($finalCount <= $planned) return null;
    $extra = $finalCount - $planned;
    $note = sprintf('Packaging: final count increased from %d to %d (+%d) for %s.', $planned, $finalCount, $extra, $order['order_number']);
    if (!empty($order['sales_order_id'])) {
        $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ? AND customer_id = ? FOR UPDATE');
        $stmt->execute([$order['sales_order_id'], $order['customer_id']]);
        $sales = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$sales) throw new RuntimeException('Linked sales order not found for this customer.');
        $stmt = $pdo->prepare('SELECT * FROM order_items WHERE order_id = ? FOR UPDATE');
        $stmt->execute([$sales['id']]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $matches = array_values(array_filter($items, fn($item) => (int)$item['product_id'] === (int)$order['product_id'] && empty($item['is_free_sample'])));
        if (count($matches) !== 1) throw new RuntimeException('Cannot identify a unique paid sales order line for this product. Resolve the linked order lines before saving the excess count.');
        $item = $matches[0];
        $oldSubtotal = array_sum(array_column($items, 'total_price'));
        $quantity = (int)$item['quantity'] + $extra;
        $lineTotal = round($quantity * (float)$item['unit_price'], 2);
        $subtotal = $oldSubtotal - (float)$item['total_price'] + $lineTotal;
        $cashDiscount = max(0, (float)$sales['discount_amount'] - $oldSubtotal * (float)$sales['discount_percentage'] / 100);
        $discount = round(min($subtotal, $subtotal * (float)$sales['discount_percentage'] / 100 + $cashDiscount), 2);
        $total = round(max(0, $subtotal - $discount) + (float)$sales['shipping_cost'], 2);
        $pdo->prepare('UPDATE order_items SET quantity = ?, total_price = ? WHERE id = ?')->execute([$quantity, $lineTotal, $item['id']]);
        $salesNote = $note . sprintf(' Sales quantity: %d → %d; order total: %.2f → %.2f (existing unit price retained).', $item['quantity'], $quantity, $sales['total_amount'], $total);
        $pdo->prepare("UPDATE orders SET total_amount = ?, discount_amount = ?, notes = CONCAT_WS('\n', NULLIF(notes, ''), ?) WHERE id = ?")
            ->execute([$total, $discount, $salesNote, $sales['id']]);
    }
    $batchSize = !empty($order['bottle_size_value']) ? $finalCount * (float)$order['bottle_size_value'] : $finalCount;
    $pdo->prepare("UPDATE manufacturing_orders SET number_of_bottles = ?, batch_size = ?, notes = CONCAT_WS('\n', NULLIF(notes, ''), ?) WHERE id = ?")
        ->execute([$finalCount, $batchSize, $note, $orderId]);
    return $note;
}

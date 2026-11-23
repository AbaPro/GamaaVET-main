<?php

// Use the same saved packaging counts as Dispatch Prep; blank means unrecorded, not zero.
function manufacturing_delivery_counts($conn, int $orderId): array {
    $stmt = $conn->prepare("SELECT item_key, item_value FROM manufacturing_packaging_checklist WHERE manufacturing_order_id = ? AND section_name = 'after' ORDER BY id");
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $counts = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'item_value', 'item_key');
    $stmt->close();
    $output = $conn->prepare('SELECT finished_quantity, primary_used_quantity FROM manufacturing_packaging_output WHERE manufacturing_order_id = ?');
    $output->bind_param('i', $orderId);
    $output->execute();
    $row = $output->get_result()->fetch_assoc();
    $output->close();
    if ($row) {
        $counts['final_product_count'] = (string)$row['finished_quantity'];
        $counts['primary_product_used'] = (string)$row['primary_used_quantity'];
    }
    return array_intersect_key($counts, manufacturing_packaging_count_labels() + ['primary_product_used' => 'منتج اولي مستخدم']);
}

function manufacturing_delivery_counts_html(array $counts): string {
    $html = '<table class="table table-bordered table-sm"><tbody>';
    $labels = [
        'final_product_count' => 'إجمالي الكمية / Total quantity',
        'carton_count' => 'عدد الكراتين / Cartons',
        'units_per_carton' => 'وحدات/كرتونة / Units per carton',
        'last_carton_units' => 'كسور في اخر كرتونة / Units in last partial carton',
        'primary_product_used' => 'منتج اولي مستخدم',
    ];
    foreach ($labels as $key => $label) {
        $value = $counts[$key] ?? '';
        $html .= '<tr><th>' . htmlspecialchars($label) . '</th><td>'
            . htmlspecialchars($value === '' ? 'Not recorded / غير مسجل' : (string)$value) . '</td></tr>';
    }
    return $html . '</tbody></table>';
}

function manufacturing_delivery_groups($conn, int $salesOrderId = 0): array {
    $scope = getCustomerChannelScopeSql('c', 'customer_factory');
    $sql = "SELECT mo.id, mo.order_number, mo.sales_order_id, mo.customer_id, c.name AS customer_name,
        COALESCE(p.name, pp.name, 'N/A') AS product_name,
        COALESCE(ds.status, 'pending') AS delivery_status, COALESCE(ps.status, 'pending') AS dispatch_status,
        so.id AS linked_sales_order_id
        FROM manufacturing_orders mo
        JOIN customers c ON c.id = mo.customer_id
        LEFT JOIN factories customer_factory ON customer_factory.id = c.factory_id
        LEFT JOIN products p ON p.id = mo.product_id
        LEFT JOIN packaging_options po ON po.id = mo.packaging_option_id
        LEFT JOIN products pp ON pp.id = po.product_id
        LEFT JOIN orders so ON so.id = mo.sales_order_id
        LEFT JOIN manufacturing_order_steps ds ON ds.manufacturing_order_id = mo.id AND ds.step_key = 'delivering'
        LEFT JOIN manufacturing_order_steps ps ON ps.manufacturing_order_id = mo.id AND ps.step_key = 'dispatch'
        WHERE $scope";
    if ($salesOrderId > 0) $sql .= ' AND mo.sales_order_id = ' . $salesOrderId;
    $sql .= ' ORDER BY mo.created_at DESC, mo.id DESC';
    $groups = [];
    $customerAccess = [];
    $countsByOrder = [];
    $rows = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
    $ids = array_map('intval', array_column($rows, 'id'));
    if ($ids) {
        $result = $conn->query("SELECT manufacturing_order_id, item_key, item_value FROM manufacturing_packaging_checklist
            WHERE manufacturing_order_id IN (" . implode(',', $ids) . ") AND section_name = 'after' ORDER BY id");
        while ($count = $result->fetch_assoc()) {
            $countsByOrder[$count['manufacturing_order_id']][$count['item_key']] = $count['item_value'];
        }
        $output = $conn->query('SELECT manufacturing_order_id, finished_quantity, primary_used_quantity FROM manufacturing_packaging_output WHERE manufacturing_order_id IN (' . implode(',', $ids) . ')');
        while ($row = $output->fetch_assoc()) {
            $countsByOrder[$row['manufacturing_order_id']]['final_product_count'] = (string)$row['finished_quantity'];
            $countsByOrder[$row['manufacturing_order_id']]['primary_product_used'] = (string)$row['primary_used_quantity'];
        }
    }
    foreach ($rows as $row) {
        $customerId = (int)$row['customer_id'];
        if (!array_key_exists($customerId, $customerAccess)) $customerAccess[$customerId] = canAccessCustomer($customerId);
        if (!$customerAccess[$customerId]) continue;
        $key = $row['linked_sales_order_id'] ? 'sales-' . $row['linked_sales_order_id'] : 'manufacturing-' . $row['id'];
        $row['counts'] = $countsByOrder[$row['id']] ?? [];
        $groups[$key][] = $row;
    }
    return $groups;
}

function manufacturing_delivery_group_html(array $rows): string {
    $html = '<div class="table-responsive"><table class="table table-bordered align-middle"><thead><tr>'
        . '<th>Product</th><th>Manufacturing order</th><th>Total quantity</th><th>Cartons</th><th>Units per carton</th><th>Units in last partial carton</th><th>منتج اولي مستخدم</th><th>Dispatch</th><th>Delivery</th><th>Action</th></tr></thead><tbody>';
    $primaryUsedTotal = 0;
    $totals = ['final_product_count' => 0, 'carton_count' => 0];
    $missing = array_fill_keys(array_keys($totals), false);
    foreach ($rows as $row) {
        $html .= '<tr><td>' . htmlspecialchars($row['product_name']) . '</td><td>' . htmlspecialchars($row['order_number']) . '</td>';
        foreach (manufacturing_packaging_count_labels() as $key => $label) {
            $value = $row['counts'][$key] ?? '';
            $html .= '<td>' . htmlspecialchars($value === '' ? 'Not recorded' : (string)$value) . '</td>';
            if (array_key_exists($key, $totals)) {
                if ($value === '') $missing[$key] = true;
                else $totals[$key] += (int)$value;
            }
        }
        $primaryUsed = (int)($row['counts']['primary_product_used'] ?? 0);
        $primaryUsedTotal += $primaryUsed;
        $html .= '<td>' . $primaryUsed . '</td>';
        $html .= '<td>' . htmlspecialchars(ucfirst($row['dispatch_status'])) . '</td><td>' . htmlspecialchars(ucfirst($row['delivery_status'])) . '</td>'
            . '<td><a class="btn btn-sm btn-outline-primary" href="order.php?id=' . (int)$row['id'] . '#step-delivering">Delivery details</a></td></tr>';
    }
    $html .= '<tr class="table-light"><th colspan="2">Order totals</th>';
    foreach ($totals as $key => $value) $html .= '<th>' . ($missing[$key] ? 'Incomplete (recorded: ' . $value . ')' : (string)$value) . '</th>';
    return $html . '<td colspan="2"></td><th>' . $primaryUsedTotal . '</th><td colspan="3"></td></tr></tbody></table></div>';
}

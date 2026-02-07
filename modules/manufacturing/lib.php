<?php

/**
 * Provides reusable helpers for the manufacturing module.
 */

function manufacturing_get_step_definitions() {
    return [
        'sourcing' => [
            'label' => 'Sourcing & Procurement',
            'description' => 'Confirm supplier availability, request samples, and secure contracts for the raw materials required by the customer formula.',
            'handover' => 'Provide POs, lead-time confirmations, and supplier QA approvals to the receiving team.'
        ],
        'receipt' => [
            'label' => 'Receipt & Quality Check',
            'description' => 'Receive materials, compare them against POs, run QA checks, and flag any deviations before releasing to production.',
            'handover' => 'Include inspection reports, quantity write-offs, and start-of-process timestamp for production.'
        ],
        'preparation' => [
            'label' => 'Preparation & Mixing',
            'description' => 'Weigh, blend, and stage components according to the provider formula, logging ratios, temperatures, and dwell times.',
            'handover' => 'Capture blending durations, mixing quality observations, and any ingredient substitutions for the final QA team.'
        ],
        'quality' => [
            'label' => 'Quality Validation',
            'description' => 'Perform in-process quality tests, adjust parameters if needed, and sign-off on batch compliance before packaging.',
            'handover' => 'Summarize QA results, tolerance checks, and any actions taken before progressing to packaging.'
        ],
        'packaging' => [
            'label' => 'Packaging & Labeling',
            'description' => 'Package the batch, print labels, and stage according to delivery instructions. Document protective packaging or cooling needs.',
            'handover' => 'Mention packaging material IDs, serial numbers, and storage instructions for dispatch.'
        ],
        'dispatch' => [
            'label' => 'Dispatch Prep',
            'description' => 'Arrange logistics, review delivery windows, and prep supporting documents like customs paperwork or certificates.',
            'handover' => 'Note transporter, ETA, and any special handling (e.g., refrigerated, hazardous) for the driver/tracking team.'
        ],
        'delivering' => [
            'label' => 'Delivery & Client Handover',
            'description' => 'Confirm shipment actually leaves facility, hand over documentation, and ensure the client receives the Excel/PDF handoff.',
            'handover' => 'Attach final delivery note, acknowledgement of receipt, and outstanding follow-up actions for after-sales.'
        ],
    ];
}

function manufacturing_get_step_label($stepKey) {
    $steps = manufacturing_get_step_definitions();
    return $steps[$stepKey]['label'] ?? ucfirst(str_replace('_', ' ', $stepKey));
}

function manufacturing_get_step_instruction($stepKey) {
    $steps = manufacturing_get_step_definitions();
    return $steps[$stepKey]['description'] ?? '';
}

function manufacturing_get_step_handover_note($stepKey) {
    $steps = manufacturing_get_step_definitions();
    return $steps[$stepKey]['handover'] ?? '';
}

function manufacturing_status_badge_class($status) {
    switch ($status) {
        case 'in_progress':
            return 'bg-info';
        case 'completed':
            return 'bg-success';
        case 'pending':
        default:
            return 'bg-secondary';
    }
}

function manufacturing_slugify($value) {
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9\-]+/', '_', $value);
    $value = preg_replace('/_+/', '_', $value);
    return trim($value, '_');
}

function manufacturing_get_storage_base_path() {
    static $basePath;
    if ($basePath) {
        return $basePath;
    }
    $root = realpath(__DIR__ . '/../../');
    $basePath = $root . '/assets/uploads/manufacturing';
    if (!is_dir($basePath)) {
        mkdir($basePath, 0777, true);
    }
    return $basePath;
}

function manufacturing_get_storage_path_for_step($orderNumber, $stepKey) {
    $base = manufacturing_get_storage_base_path();
    $orderSegment = manufacturing_slugify($orderNumber ?: 'order');
    $stepSegment = manufacturing_slugify($stepKey);
    $target = $base . '/' . $orderSegment . '/' . $stepSegment;
    if (!is_dir($target)) {
        mkdir($target, 0777, true);
    }
    return $target;
}

function manufacturing_convert_to_relative_path($fullPath) {
    $fullPath = str_replace('\\', '/', $fullPath);
    $root = str_replace('\\', '/', realpath(__DIR__ . '/../../'));
    if (strpos($fullPath, $root) === 0) {
        $relative = ltrim(substr($fullPath, strlen($root)), '/');
        return $relative;
    }
    return $fullPath;
}

function manufacturing_build_step_document_html($order, $orderStep, $formula, $components, $notes, $statusLabel, $conn = null) {
    $orderNumber = htmlspecialchars($order['order_number'] ?? 'UNKNOWN');
    $customerName = htmlspecialchars($order['customer_name'] ?? 'Unknown provider');
    $formulaName = htmlspecialchars($formula['name'] ?? 'Custom formula');
    $priority = htmlspecialchars(ucfirst($order['priority'] ?? 'normal'));
    $dueDate = $order['due_date'] ? htmlspecialchars($order['due_date']) : 'TBD';
    $notes = nl2br(htmlspecialchars($notes ?: $orderStep['notes'] ?? 'No notes yet.'));
    $stepLabel = htmlspecialchars($statusLabel);
    $stepKey = $orderStep['step_key'];

    // Updated CSS with better Arabic font support
    $html = '<style>body{font-family:"aealarabiya","Arial Unicode MS",Arial,sans-serif;font-size:12px;direction:ltr;}table{width:100%;border-collapse:collapse;margin-bottom:12px;}td,th{border:1px solid #ddd;padding:8px;text-align:left;}th{background:#f4f4f4;font-weight:600;}.status-approved{background:#d4edda;color:#155724;}.status-rejected{background:#f8d7da;color:#721c24;}.status-pending{background:#fff3cd;color:#856404;}</style>';
    $html .= "<h3>Manufacturing Handoff / {$stepLabel}</h3>";
    $html .= '<table><tbody>';
    $html .= "<tr><th>Order</th><td>{$orderNumber}</td><th>Provider</th><td>{$customerName}</td></tr>";
    $html .= "<tr><th>Formula</th><td>{$formulaName}</td><th>Priority</th><td>{$priority}</td></tr>";
    $html .= "<tr><th>Batch Size</th><td>" . htmlspecialchars($order['batch_size'] ?? '0') . "</td><th>Due Date</th><td>{$dueDate}</td></tr>";
    $html .= "<tr><th>Current Status</th><td colspan=\"3\">" . htmlspecialchars(ucfirst($orderStep['status'])) . "</td></tr>";
    $html .= '</tbody></table>';

    $html .= '<p><strong>Step Notes / Preparation Log</strong><br>' . $notes . '</p>';
    $html .= '<p><strong>Step Handover Reminder</strong><br>' . htmlspecialchars(manufacturing_get_step_handover_note($orderStep['step_key'])) . '</p>';

    // Add Receipt Quality Check data for receipt step
    if ($stepKey === 'receipt' && $conn) {
        $receiptStmt = $conn->prepare("
            SELECT msc.component_name, msc.required_quantity, msc.unit, msc.receipt_status, msc.receipt_notes, p.barcode
            FROM manufacturing_sourcing_components msc
            LEFT JOIN products p ON p.id = msc.product_id
            WHERE msc.manufacturing_order_id = ?
            ORDER BY msc.formula_component_index
        ");
        $receiptStmt->bind_param("i", $order['id']);
        $receiptStmt->execute();
        $receiptResult = $receiptStmt->get_result();
        
        if ($receiptResult->num_rows > 0) {
            $html .= '<h4>Quality Check Results</h4>';
            $html .= '<table><thead><tr><th>Material</th><th>Barcode</th><th>Expected Qty</th><th>Unit</th><th>QA Status</th><th>QA Notes</th></tr></thead><tbody>';
            while ($rc = $receiptResult->fetch_assoc()) {
                $status = $rc['receipt_status'] ?? 'pending';
                $statusClass = '';
                $statusText = ucfirst($status);
                if ($status === 'approved') {
                    $statusClass = 'class="status-approved"';
                    $statusText = '✓ Approved';
                } elseif ($status === 'rejected') {
                    $statusClass = 'class="status-rejected"';
                    $statusText = '✗ Rejected';
                } else {
                    $statusClass = 'class="status-pending"';
                    $statusText = '⏳ Pending';
                }
                
                $html .= '<tr>';
                $html .= '<td>' . htmlspecialchars($rc['component_name']) . '</td>';
                $html .= '<td>' . htmlspecialchars($rc['barcode'] ?? '-') . '</td>';
                $html .= '<td>' . htmlspecialchars($rc['required_quantity']) . '</td>';
                $html .= '<td>' . htmlspecialchars($rc['unit']) . '</td>';
                $html .= '<td ' . $statusClass . '>' . $statusText . '</td>';
                $html .= '<td>' . htmlspecialchars($rc['receipt_notes'] ?? '-') . '</td>';
                $html .= '</tr>';
            }
            $html .= '</tbody></table>';
        }
        $receiptStmt->close();
    }

    // Add Preparation Measurements for preparation step
    if ($stepKey === 'preparation' && $conn) {
        $prepStmt = $conn->prepare("
            SELECT field_name, field_value
            FROM manufacturing_preparation_measurements
            WHERE manufacturing_order_id = ?
            ORDER BY field_order
        ");
        $prepStmt->bind_param("i", $order['id']);
        $prepStmt->execute();
        $prepResult = $prepStmt->get_result();
        
        if ($prepResult->num_rows > 0) {
            $html .= '<h4>Mixing Process Measurements</h4>';
            $html .= '<table><thead><tr><th>Measurement</th><th>Value</th></tr></thead><tbody>';
            while ($pm = $prepResult->fetch_assoc()) {
                $html .= '<tr>';
                $html .= '<td><strong>' . htmlspecialchars($pm['field_name']) . '</strong></td>';
                $html .= '<td>' . htmlspecialchars($pm['field_value']) . '</td>';
                $html .= '</tr>';
            }
            $html .= '</tbody></table>';
        }
        $prepStmt->close();
    }

    // Add Quality Validation Checklist for quality step
    if ($stepKey === 'quality' && $conn) {
        $qualityStmt = $conn->prepare("
            SELECT section_name, item_text, status, notes
            FROM manufacturing_quality_checklist
            WHERE manufacturing_order_id = ?
            ORDER BY item_order
        ");
        $qualityStmt->bind_param("i", $order['id']);
        $qualityStmt->execute();
        $qualityResult = $qualityStmt->get_result();
        
        if ($qualityResult->num_rows > 0) {
            $html .= '<div style="direction:rtl;text-align:right;">';
            $html .= '<h4 style="direction:rtl;text-align:right;">Quality Validation Checklist</h4>';
            
            // Group by section
            $currentSection = '';
            $html .= '<table style="direction:rtl;"><thead><tr><th style="text-align:right;">البند</th><th style="text-align:center;">Status</th><th style="text-align:right;">Notes</th></tr></thead><tbody>';
            
            while ($qi = $qualityResult->fetch_assoc()) {
                // Add section header if changed
                if ($currentSection !== $qi['section_name']) {
                    $currentSection = $qi['section_name'];
                    $html .= '<tr style="background:#e9ecef;"><td colspan="3" style="text-align:right;"><strong>' . htmlspecialchars($currentSection) . '</strong></td></tr>';
                }
                
                $status = $qi['status'] ?? 'pending';
                $statusClass = '';
                $statusText = ucfirst($status);
                if ($status === 'approved') {
                    $statusClass = 'class="status-approved"';
                    $statusText = 'YES - Approved';
                } elseif ($status === 'rejected') {
                    $statusClass = 'class="status-rejected"';
                    $statusText = 'NO - Rejected';
                } else {
                    $statusClass = 'class="status-pending"';
                    $statusText = 'Pending';
                }
                
                $html .= '<tr>';
                $html .= '<td style="text-align:right;">' . htmlspecialchars($qi['item_text']) . '</td>';
                $html .= '<td style="text-align:center;" ' . $statusClass . '>' . $statusText . '</td>';
                $html .= '<td style="text-align:right;">' . htmlspecialchars($qi['notes'] ?? '-') . '</td>';
                $html .= '</tr>';
            }
            $html .= '</tbody></table>';
            $html .= '</div>';
        }
        $qualityStmt->close();
    }

    // Add Packaging & Labeling Checklist for packaging step
    if ($stepKey === 'packaging' && $conn) {
        $packagingStmt = $conn->prepare("
            SELECT section_name, item_key, item_text, item_type, item_value
            FROM manufacturing_packaging_checklist
            WHERE manufacturing_order_id = ?
            ORDER BY item_order
        ");
        $packagingStmt->bind_param("i", $order['id']);
        $packagingStmt->execute();
        $packagingResult = $packagingStmt->get_result();
        
        if ($packagingResult->num_rows > 0) {
            $html .= '<div style="direction:rtl;text-align:right;">';
            $html .= '<h4 style="direction:rtl;text-align:right;">Packaging & Labeling Tracking</h4>';
            
            // Group by section
            $packagingData = [
                'before' => [],
                'during' => [],
                'after' => []
            ];
            while ($pi = $packagingResult->fetch_assoc()) {
                $packagingData[$pi['section_name']][] = $pi;
            }
            
            // Before Operation (Line Clearance)
            if (!empty($packagingData['before'])) {
                $html .= '<h5 style="direction:rtl;text-align:right;margin-top:15px;">قبل التشغيل (Line Clearance)</h5>';
                $html .= '<table style="direction:rtl;"><tbody>';
                foreach ($packagingData['before'] as $item) {
                    $checked = ($item['item_value'] === 'checked') ? '<strong>YES</strong>' : 'NO';
                    $html .= '<tr>';
                    $html .= '<td style="text-align:right;width:70%;">' . htmlspecialchars($item['item_text']) . '</td>';
                    $html .= '<td style="text-align:center;width:30%;">' . $checked . '</td>';
                    $html .= '</tr>';
                }
                $html .= '</tbody></table>';
            }
            
            // During Packaging
            if (!empty($packagingData['during'])) {
                $html .= '<h5 style="direction:rtl;text-align:right;margin-top:15px;">أثناء التعبئة</h5>';
                $html .= '<table style="direction:rtl;"><tbody>';
                foreach ($packagingData['during'] as $item) {
                    $checked = ($item['item_value'] === 'checked') ? '<strong>YES</strong>' : 'NO';
                    $html .= '<tr>';
                    $html .= '<td style="text-align:right;width:70%;">' . htmlspecialchars($item['item_text']) . '</td>';
                    $html .= '<td style="text-align:center;width:30%;">' . $checked . '</td>';
                    $html .= '</tr>';
                }
                $html .= '</tbody></table>';
            }
            
            // After Packaging
            if (!empty($packagingData['after'])) {
                $html .= '<h5 style="direction:rtl;text-align:right;margin-top:15px;">بعد التعبئة</h5>';
                $html .= '<table style="direction:rtl;"><tbody>';
                
                // Final counts
                $finalProductCount = '';
                $cartonCount = '';
                $unitsPerCarton = '';
                $printsIssued = '';
                $printsUsed = '';
                $printsReturned = '';
                $printsReturnedToStock = '';
                $breakageRecorded = '';
                $breakageCount = '';
                $breakageNotes = '';
                
                foreach ($packagingData['after'] as $item) {
                    if ($item['item_key'] === 'final_product_count') $finalProductCount = $item['item_value'];
                    if ($item['item_key'] === 'carton_count') $cartonCount = $item['item_value'];
                    if ($item['item_key'] === 'units_per_carton') $unitsPerCarton = $item['item_value'];
                    if ($item['item_key'] === 'prints_issued') $printsIssued = $item['item_value'];
                    if ($item['item_key'] === 'prints_used') $printsUsed = $item['item_value'];
                    if ($item['item_key'] === 'prints_returned') $printsReturned = $item['item_value'];
                    if ($item['item_key'] === 'prints_returned_to_stock') $printsReturnedToStock = ($item['item_value'] === 'checked') ? '<strong>YES</strong>' : 'NO';
                    if ($item['item_key'] === 'breakage_recorded') $breakageRecorded = ($item['item_value'] === 'checked') ? '<strong>YES</strong>' : 'NO';
                    if ($item['item_key'] === 'breakage_count') $breakageCount = $item['item_value'];
                    if ($item['item_key'] === 'breakage_notes') $breakageNotes = $item['item_value'];
                }
                
                $html .= '<tr><td style="text-align:right;"><strong>عدد المنتج النهائي:</strong></td><td>' . htmlspecialchars($finalProductCount ?: '_____') . '</td></tr>';
                $html .= '<tr><td style="text-align:right;"><strong>عدد الكراتين:</strong></td><td>' . htmlspecialchars($cartonCount ?: '_____') . '</td></tr>';
                $html .= '<tr><td style="text-align:right;"><strong>وحدات/كرتونة:</strong></td><td>' . htmlspecialchars($unitsPerCarton ?: '_____') . '</td></tr>';
                $html .= '<tr><td style="text-align:right;"><strong>مطابقة المطبوعات:</strong></td><td>مصروف ' . htmlspecialchars($printsIssued ?: '___') . ' / مستخدم ' . htmlspecialchars($printsUsed ?: '___') . ' / راجع ' . htmlspecialchars($printsReturned ?: '___') . '</td></tr>';
                $html .= '<tr><td style="text-align:right;"><strong>إعادة المطبوعات للمخزن:</strong></td><td>' . $printsReturnedToStock . '</td></tr>';
                $html .= '<tr><td style="text-align:right;"><strong>أي كسر تم تسجيله:</strong></td><td>' . $breakageRecorded . ' العدد: ' . htmlspecialchars($breakageCount ?: '___') . '</td></tr>';
                $html .= '<tr><td style="text-align:right;"><strong>ملاحظات الكسر:</strong></td><td>' . htmlspecialchars($breakageNotes ?: '-') . '</td></tr>';
                
                $html .= '</tbody></table>';
                
                // Material Tracking Table
                $html .= '<h5 style="direction:rtl;text-align:right;margin-top:15px;">تتبع المواد</h5>';
                $html .= '<table style="direction:rtl;"><thead><tr style="background:#e9ecef;">';
                $html .= '<th style="text-align:right;">المادة</th>';
                $html .= '<th style="text-align:center;">مصروف</th>';
                $html .= '<th style="text-align:center;">مستخدم</th>';
                $html .= '<th style="text-align:center;">راجع</th>';
                $html .= '<th style="text-align:center;">الهالك</th>';
                $html .= '</tr></thead><tbody>';
                
                $materials = [
                    'boxes' => 'العلب',
                    'stickers' => 'الاستيكر',
                    'leaflets' => 'نشره'
                ];
                
                foreach ($materials as $matKey => $matLabel) {
                    $issued = '';
                    $used = '';
                    $returned = '';
                    $waste = '';
                    
                    foreach ($packagingData['after'] as $item) {
                        if ($item['item_key'] === $matKey . '_issued') $issued = $item['item_value'];
                        if ($item['item_key'] === $matKey . '_used') $used = $item['item_value'];
                        if ($item['item_key'] === $matKey . '_returned') $returned = $item['item_value'];
                        if ($item['item_key'] === $matKey . '_waste') $waste = $item['item_value'];
                    }
                    
                    $html .= '<tr>';
                    $html .= '<td style="text-align:right;"><strong>' . $matLabel . '</strong></td>';
                    $html .= '<td style="text-align:center;">' . htmlspecialchars($issued ?: '0') . '</td>';
                    $html .= '<td style="text-align:center;">' . htmlspecialchars($used ?: '0') . '</td>';
                    $html .= '<td style="text-align:center;">' . htmlspecialchars($returned ?: '0') . '</td>';
                    $html .= '<td style="text-align:center;">' . htmlspecialchars($waste ?: '0') . '</td>';
                    $html .= '</tr>';
                }
                
                $html .= '</tbody></table>';
            }
            
            $html .= '</div>';
        }
        $packagingStmt->close();
    }

    // Dispatch Prep data
    if ($stepKey === 'dispatch') {
        $orderId = $order['id'];
        $dispatchStmt = $conn->prepare("
            SELECT section_name, item_key, item_text, item_type, item_value
            FROM manufacturing_dispatch_checklist
            WHERE manufacturing_order_id = ?
            ORDER BY item_order
        ");
        $dispatchStmt->bind_param("i", $orderId);
        $dispatchStmt->execute();
        $dispatchResult = $dispatchStmt->get_result();
        $dispatchData = ['details' => [], 'before_loading' => []];
        while ($row = $dispatchResult->fetch_assoc()) {
            $dispatchData[$row['section_name']][] = $row;
        }
        
        if (!empty($dispatchData['details']) || !empty($dispatchData['before_loading'])) {
            $html .= '<div style="direction:rtl; text-align:right; margin-top:20px;">';
            $html .= '<h4 style="color:#0d6efd;">تفاصيل الشحن والتحميل (Dispatch Prep)</h4>';
            
            // Dispatch Details
            if (!empty($dispatchData['details'])) {
                $html .= '<h5 style="margin-top:15px;">تفاصيل الشحن (Dispatch Details)</h5>';
                $html .= '<table border="1" cellpadding="5" style="width:100%; border-collapse:collapse; margin-bottom:15px;">';
                $html .= '<tbody>';
                foreach ($dispatchData['details'] as $item) {
                    $val = htmlspecialchars($item['item_value'] ?: '______');
                    $html .= '<tr>';
                    $html .= '<td style="width:50%; text-align:right;"><strong>' . htmlspecialchars($item['item_text']) . '</strong></td>';
                    $html .= '<td style="width:50%; text-align:center;">' . $val . '</td>';
                    $html .= '</tr>';
                }
                $html .= '</tbody></table>';
            }
            
            // Before Loading
            if (!empty($dispatchData['before_loading'])) {
                $html .= '<h5 style="margin-top:15px;">قبل التحميل (Before Loading)</h5>';
                $html .= '<table border="1" cellpadding="5" style="width:100%; border-collapse:collapse; margin-bottom:15px;">';
                $html .= '<tbody>';
                foreach ($dispatchData['before_loading'] as $item) {
                    $checked = ($item['item_value'] === 'checked') ? '<strong>YES</strong>' : 'NO';
                    $html .= '<tr>';
                    $html .= '<td style="width:80%; text-align:right;">' . htmlspecialchars($item['item_text']) . '</td>';
                    $html .= '<td style="width:20%; text-align:center;">' . $checked . '</td>';
                    $html .= '</tr>';
                }
                $html .= '</tbody></table>';
            }
            
            $html .= '</div>';
        }
        $dispatchStmt->close();
    }

    // Delivery data
    if ($stepKey === 'delivering') {
        $orderId = $order['id'];
        $deliveryStmt = $conn->prepare("
            SELECT recipient_name, recipient_phone, delivery_datetime, customer_notes, photo_path
            FROM manufacturing_delivery_info
            WHERE manufacturing_order_id = ?
        ");
        $deliveryStmt->bind_param("i", $orderId);
        $deliveryStmt->execute();
        $deliveryResult = $deliveryStmt->get_result();
        $deliveryInfo = $deliveryResult->fetch_assoc();
        
        if ($deliveryInfo) {
            $html .= '<div style="direction:rtl; text-align:right; margin-top:20px;">';
            $html .= '<h4 style="color:#0d6efd;">التسليم (Delivery Information)</h4>';
            
            $html .= '<table border="1" cellpadding="5" style="width:100%; border-collapse:collapse; margin-bottom:15px;">';
            $html .= '<tbody>';
            
            $recipientName = htmlspecialchars($deliveryInfo['recipient_name'] ?: '__________');
            $html .= '<tr>';
            $html .= '<td style="width:40%; text-align:right;"><strong>اسم المستلم (Recipient Name)</strong></td>';
            $html .= '<td style="width:60%; text-align:center;">' . $recipientName . '</td>';
            $html .= '</tr>';
            
            $recipientPhone = htmlspecialchars($deliveryInfo['recipient_phone'] ?: '__________');
            $html .= '<tr>';
            $html .= '<td style="text-align:right;"><strong>رقم تليفون (Phone Number)</strong></td>';
            $html .= '<td style="text-align:center;">' . $recipientPhone . '</td>';
            $html .= '</tr>';
            
            $deliveryDatetime = $deliveryInfo['delivery_datetime'] ? htmlspecialchars($deliveryInfo['delivery_datetime']) : '__________';
            $html .= '<tr>';
            $html .= '<td style="text-align:right;"><strong>تاريخ ووقت التسليم (Delivery Date & Time)</strong></td>';
            $html .= '<td style="text-align:center;">' . $deliveryDatetime . '</td>';
            $html .= '</tr>';
            
            $customerNotes = htmlspecialchars($deliveryInfo['customer_notes'] ?: 'لا توجد ملاحظات');
            $html .= '<tr>';
            $html .= '<td style="text-align:right;"><strong>ملاحظات العميل (Customer Notes)</strong></td>';
            $html .= '<td style="text-align:right; padding:10px;">' . nl2br($customerNotes) . '</td>';
            $html .= '</tr>';
            
            if (!empty($deliveryInfo['photo_path'])) {
                $html .= '<tr>';
                $html .= '<td style="text-align:right;"><strong>صورة التسليم (Delivery Photo)</strong></td>';
                $html .= '<td style="text-align:center;">Photo attached: ' . htmlspecialchars(basename($deliveryInfo['photo_path'])) . '</td>';
                $html .= '</tr>';
            }
            
            $html .= '</tbody></table>';
            $html .= '</div>';
        }
        $deliveryStmt->close();
    }

    $html .= '<table><thead><tr><th>Component</th><th>Quantity / Ratio</th><th>Unit</th><th>Notes</th></tr></thead><tbody>';
    if (is_array($components) && count($components) > 0) {
        foreach ($components as $component) {
            $componentName = htmlspecialchars($component['name'] ?? 'TBD');
            $quantity = htmlspecialchars($component['quantity'] ?? ($component['ratio'] ?? ''));
            $unit = htmlspecialchars($component['unit'] ?? 'N/A');
            $componentNotes = htmlspecialchars($component['notes'] ?? '-');
            $html .= "<tr><td>{$componentName}</td><td>{$quantity}</td><td>{$unit}</td><td>{$componentNotes}</td></tr>";
        }
    } else {
        $html .= '<tr><td colspan="4" class="text-center">No components defined.</td></tr>';
    }
    $html .= '</tbody></table>';

    return $html;
}

function manufacturing_generate_step_documents($conn, $order, $orderStep, $formula, $components, $notes, $generatedBy = null) {
    $stepKey = $orderStep['step_key'];
    $statusLabel = manufacturing_get_step_label($stepKey);
    $documentHtml = manufacturing_build_step_document_html($order, $orderStep, $formula, $components, $notes, $statusLabel, $conn);

    // Excel-like handoff
    $excelPayload = manufacturing_create_excel_document($order, $stepKey, $documentHtml);
    manufacturing_register_document($conn, $orderStep['id'], 'excel', $excelPayload['relative_path'], $excelPayload['file_name'], $generatedBy);

    // PDF handoff
    $pdfPayload = manufacturing_create_pdf_document($order, $stepKey, $documentHtml);
    manufacturing_register_document($conn, $orderStep['id'], 'pdf', $pdfPayload['relative_path'], $pdfPayload['file_name'], $generatedBy);

    return [
        'excel' => $excelPayload,
        'pdf' => $pdfPayload,
    ];
}

function manufacturing_create_excel_document($order, $stepKey, $htmlContent) {
    $orderNumber = $order['order_number'] ?? 'order';
    $storageDir = manufacturing_get_storage_path_for_step($orderNumber, $stepKey);
    $timestamp = date('YmdHis');
    $fileName = "{$orderNumber}_{$stepKey}_{$timestamp}.xls";
    $fileName = str_replace(' ', '_', $fileName);
    $filePath = $storageDir . '/' . $fileName;
    file_put_contents($filePath, $htmlContent);

    return [
        'file_name' => $fileName,
        'relative_path' => manufacturing_convert_to_relative_path($filePath),
    ];
}

function manufacturing_create_pdf_document($order, $stepKey, $htmlContent) {
    $orderNumber = $order['order_number'] ?? 'order';
    $storageDir = manufacturing_get_storage_path_for_step($orderNumber, $stepKey);
    $timestamp = date('YmdHis');
    $fileName = "{$orderNumber}_{$stepKey}_{$timestamp}.pdf";
    $filePath = $storageDir . '/' . $fileName;

    require_once __DIR__ . '/../../tcpdf/tcpdf.php';
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('GammaVET Manufacturing Module');
    $pdf->SetTitle("{$orderNumber} - " . manufacturing_get_step_label($stepKey));
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(15, 15, 15);
    
    // Set font to support Arabic characters
    $pdf->SetFont('aealarabiya', '', 11);
    
    // Enable RTL (Right-to-Left) support for Arabic
    $pdf->setRTL(false); // Set to true only if entire document is RTL
    
    $pdf->AddPage();
    $pdf->writeHTML($htmlContent, true, false, true, false, '');
    $pdf->Output($filePath, 'F');

    return [
        'file_name' => $fileName,
        'relative_path' => manufacturing_convert_to_relative_path($filePath),
    ];
}

function manufacturing_register_document($conn, $stepId, $type, $relativePath, $fileName, $generatedBy = null) {
    $stmt = $conn->prepare("
        INSERT INTO manufacturing_step_documents 
            (manufacturing_order_step_id, type, file_path, file_name, generated_by) 
        VALUES (?, ?, ?, ?, ?)
    ");
    $generatedByValue = $generatedBy !== null ? $generatedBy : null;
    $stmt->bind_param('isssi', $stepId, $type, $relativePath, $fileName, $generatedByValue);
    $stmt->execute();
    $stmt->close();
}

function manufacturing_get_documents_by_step($conn, $stepId) {
    $stmt = $conn->prepare("
        SELECT md.*, u.name AS generated_by_name 
        FROM manufacturing_step_documents md 
        LEFT JOIN users u ON u.id = md.generated_by 
        WHERE md.manufacturing_order_step_id = ? 
        ORDER BY md.generated_at DESC
    ");
    $stmt->bind_param('i', $stepId);
    $stmt->execute();
    $result = $stmt->get_result();
    $documents = [];
    while ($row = $result->fetch_assoc()) {
        $documents[] = $row;
    }
    $stmt->close();
    return $documents;
}

function manufacturing_determine_order_status_from_steps(array $steps) {
    $allCompleted = true;
    $anyInProgress = false;
    
    foreach ($steps as $step) {
        if ($step['status'] === 'in_progress') {
            $anyInProgress = true;
        }
        if ($step['status'] !== 'completed') {
            $allCompleted = false;
        }
    }
    
    if ($allCompleted) {
        return 'completed';
    } elseif ($anyInProgress) {
        return 'in_progress';
    } else {
        return 'pending';
    }
}

function manufacturing_get_next_step_label(array $steps) {
    $stepKeys = array_keys(manufacturing_get_step_definitions());
    foreach ($stepKeys as $stepKey) {
        foreach ($steps as $step) {
            if ($step['step_key'] !== $stepKey) {
                continue;
            }
            if ($step['status'] !== 'completed') {
                return manufacturing_get_step_label($stepKey);
            }
            break;
        }
    }
    return 'All steps completed';
}

function manufacturing_order_status_badge($status) {
    switch ($status) {
        case 'getting':
            return ['label' => 'Getting', 'class' => 'bg-info'];
        case 'preparing':
            return ['label' => 'Preparing', 'class' => 'bg-warning text-dark'];
        case 'delivering':
            return ['label' => 'Delivering', 'class' => 'bg-primary'];
        case 'completed':
            return ['label' => 'Completed', 'class' => 'bg-success'];
        case 'cancelled':
            return ['label' => 'Cancelled', 'class' => 'bg-danger'];
        default:
            return ['label' => ucfirst($status), 'class' => 'bg-secondary'];
    }
}

function manufacturing_get_document_url($relativePath) {
    $relativePath = str_replace('\\', '/', $relativePath);
    $relativePath = ltrim($relativePath, '/');
    return rtrim(BASE_URL, '/') . '/' . $relativePath;
}

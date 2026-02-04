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

function manufacturing_build_step_document_html($order, $orderStep, $formula, $components, $notes, $statusLabel) {
    $orderNumber = htmlspecialchars($order['order_number'] ?? 'UNKNOWN');
    $customerName = htmlspecialchars($order['customer_name'] ?? 'Unknown provider');
    $formulaName = htmlspecialchars($formula['name'] ?? 'Custom formula');
    $priority = htmlspecialchars(ucfirst($order['priority'] ?? 'normal'));
    $dueDate = $order['due_date'] ? htmlspecialchars($order['due_date']) : 'TBD';
    $notes = nl2br(htmlspecialchars($notes ?: $orderStep['notes'] ?? 'No notes yet.'));
    $stepLabel = htmlspecialchars($statusLabel);

    $html = '<style>body{font-family:"DejaVu Sans",Arial,sans-serif;font-size:12px;}table{width:100%;border-collapse:collapse;margin-bottom:12px;}td,th{border:1px solid #ddd;padding:8px;}th{background:#f4f4f4;font-weight:600;}</style>';
    $html .= "<h3>Manufacturing Handoff / {$stepLabel}</h3>";
    $html .= '<table><tbody>';
    $html .= "<tr><th>Order</th><td>{$orderNumber}</td><th>Provider</th><td>{$customerName}</td></tr>";
    $html .= "<tr><th>Formula</th><td>{$formulaName}</td><th>Priority</th><td>{$priority}</td></tr>";
    $html .= "<tr><th>Batch Size</th><td>" . htmlspecialchars($order['batch_size'] ?? '0') . "</td><th>Due Date</th><td>{$dueDate}</td></tr>";
    $html .= "<tr><th>Current Status</th><td colspan=\"3\">" . htmlspecialchars(ucfirst($orderStep['status'])) . "</td></tr>";
    $html .= '</tbody></table>';

    $html .= '<p><strong>Step Notes / Preparation Log</strong><br>' . $notes . '</p>';
    $html .= '<p><strong>Step Handover Reminder</strong><br>' . htmlspecialchars(manufacturing_get_step_handover_note($orderStep['step_key'])) . '</p>';

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
    $documentHtml = manufacturing_build_step_document_html($order, $orderStep, $formula, $components, $notes, $statusLabel);

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
    $stepKeys = array_keys(manufacturing_get_step_definitions());
    foreach ($stepKeys as $stepKey) {
        foreach ($steps as $step) {
            if ($step['step_key'] !== $stepKey) {
                continue;
            }
            if ($step['status'] !== 'completed') {
                return $stepKey;
            }
            break;
        }
    }
    return 'completed';
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

<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once 'lib.php';

$orderId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($orderId <= 0) {
    setAlert('danger', 'Invalid manufacturing order specified.');
    redirect('index.php');
}

$orderStmt = $conn->prepare("
    SELECT mo.*, c.name AS customer_name, f.name AS formula_name, f.description AS formula_description, f.components_json,
           l.name AS location_name, l.address AS location_address, p.name AS product_name, p.sku AS product_sku
    FROM manufacturing_orders mo
    JOIN customers c ON c.id = mo.customer_id
    JOIN manufacturing_formulas f ON f.id = mo.formula_id
    LEFT JOIN locations l ON l.id = mo.location_id
    LEFT JOIN products p ON p.id = mo.product_id
    WHERE mo.id = ?
    LIMIT 1
");
$orderStmt->bind_param('i', $orderId);
$orderStmt->execute();
$orderResult = $orderStmt->get_result();
$order = $orderResult->fetch_assoc();
$orderStmt->close();

if (!$order) {
    setAlert('danger', 'Manufacturing order not found.');
    redirect('index.php');
}

$formulaComponents = json_decode($order['components_json'] ?? '[]', true);
if (!is_array($formulaComponents)) {
    $formulaComponents = [];
}

// Initialize sourcing components if they don't exist yet
$checkSourcingStmt = $conn->prepare("SELECT COUNT(*) as count FROM manufacturing_sourcing_components WHERE manufacturing_order_id = ?");
$checkSourcingStmt->bind_param("i", $orderId);
$checkSourcingStmt->execute();
$sourcingCheckResult = $checkSourcingStmt->get_result();
$sourcingCheckRow = $sourcingCheckResult->fetch_assoc();
$checkSourcingStmt->close();

if ($sourcingCheckRow['count'] == 0 && !empty($formulaComponents)) {
    // Insert sourcing components for this order
    $insertSourcingStmt = $conn->prepare("
        INSERT INTO manufacturing_sourcing_components 
        (manufacturing_order_id, formula_component_index, product_id, component_name, required_quantity, unit, available_quantity)
        VALUES (?, ?, ?, ?, ?, ?, 0)
    ");
    
    foreach ($formulaComponents as $index => $component) {
        $productId = $component['product_id'] ?? null;
        $componentName = $component['name'] ?? '';
        $requiredQty = $component['quantity'] ?? 0;
        $unit = $component['unit'] ?? '';
        
        $insertSourcingStmt->bind_param("iiisds", $orderId, $index, $productId, $componentName, $requiredQty, $unit);
        $insertSourcingStmt->execute();
    }
    $insertSourcingStmt->close();
}

// Initialize quality validation checklist if it doesn't exist yet for quality step
$checkQualityStmt = $conn->prepare("SELECT COUNT(*) as count FROM manufacturing_quality_checklist WHERE manufacturing_order_id = ?");
$checkQualityStmt->bind_param("i", $orderId);
$checkQualityStmt->execute();
$qualityCheckResult = $checkQualityStmt->get_result();
$qualityCheckRow = $qualityCheckResult->fetch_assoc();
$checkQualityStmt->close();

if ($qualityCheckRow['count'] == 0) {
    // Define the complete checklist structure
    $qualityChecklist = [
        'المواصفات والتركيبة' => [
            'مواصفة المنتج معتمدة ومتاحة',
            'المنتج مطابق لطلب العميل (في حاله التعديل يجب كتابه التفاصيل)',
            'تم التأكد من وجود او عدم وجود بواقي من نفس المنتج يمكن استخدامها  (في حاله الوجود يجب كتابه التفاصيل)'
        ],
        'الليبل والتعبئة (أخطر نقطة)' => [
            'المطبوعات معتمده (آخر إصدار)',
            'لا يوجد أي مطبوعات قديمة أو ملغية',
            'اسم المنتج على المطبوعات صحيح',
            'Batch No. صحيح',
            'Expiry Date محسوب وصحيح',
            'تأكيد على جاهزية ماكينات التعبئة والتغليف وتعقيمها قبل البدء',
            'نوع الكرتونة مطابق لمواصفة العميل',
            'عدد الوحدات داخل الكرتونة صحيح'
        ],
        'الخامات ومواد التعبئة' => [
            'الخامات متوفرة ومعتمدة',
            'مواد التعبئة متوفرة ومعتمدة',
            'كمية المطبوعات كافية للطلب',
            'لا يوجد نقص قد يسبب توقف'
        ],
        'الجاهزية للتشغيل' => [
            'إجراء Line Clearance مخطط',
            'لا توجد تشغيلة أخرى على نفس الخط',
            'لا توجد NCR مفتوحة تخص هذا المنتج',
            'لا توجد شكاوى عميل تمنع التشغيل او المراجعه'
        ]
    ];
    
    $insertQualityStmt = $conn->prepare("
        INSERT INTO manufacturing_quality_checklist 
        (manufacturing_order_id, section_name, item_text, item_order)
        VALUES (?, ?, ?, ?)
    ");
    
    $itemOrder = 0;
    foreach ($qualityChecklist as $sectionName => $items) {
        foreach ($items as $itemText) {
            $insertQualityStmt->bind_param("issi", $orderId, $sectionName, $itemText, $itemOrder);
            $insertQualityStmt->execute();
            $itemOrder++;
        }
    }
    $insertQualityStmt->close();
}

// Initialize packaging checklist if it doesn't exist yet for packaging step
$checkPackagingStmt = $conn->prepare("SELECT COUNT(*) as count FROM manufacturing_packaging_checklist WHERE manufacturing_order_id = ?");
$checkPackagingStmt->bind_param("i", $orderId);
$checkPackagingStmt->execute();
$packagingCheckResult = $checkPackagingStmt->get_result();
$packagingCheckRow = $packagingCheckResult->fetch_assoc();
$checkPackagingStmt->close();

if ($packagingCheckRow['count'] == 0) {
    // Define the packaging checklist structure
    $packagingChecklist = [
        'before' => [
            ['key' => 'remove_old_prints', 'text' => 'إزالة أي مطبوعات قديمة من المنطقة', 'type' => 'checkbox'],
            ['key' => 'remove_old_cartons', 'text' => 'إزالة أي كراتين قديمة', 'type' => 'checkbox'],
            ['key' => 'clean_surfaces', 'text' => 'تنظيف الأسطح والمعدات', 'type' => 'checkbox'],
            ['key' => 'single_product_line', 'text' => 'المنتج الواحد فقط على الخط', 'type' => 'checkbox'],
            ['key' => 'qc_approval', 'text' => 'اعتماد QC قبل البدء', 'type' => 'checkbox']
        ],
        'during' => [
            ['key' => 'check_prints_30min', 'text' => 'التحقق من المطبوعات كل فترة (كل 30 دقيقة)', 'type' => 'checkbox'],
            ['key' => 'verify_batch_expiry', 'text' => 'التأكد من Batch/Expiry واضح', 'type' => 'checkbox'],
            ['key' => 'count_during_production', 'text' => 'عدّ الكمية أثناء الإنتاج', 'type' => 'checkbox'],
            ['key' => 'verify_carton_match', 'text' => 'الكراتين المستخدمة مطابقة للمطلوب', 'type' => 'checkbox']
        ],
        'after' => [
            ['key' => 'final_product_count', 'text' => 'عدد المنتج النهائي', 'type' => 'number'],
            ['key' => 'carton_count', 'text' => 'عدد الكراتين', 'type' => 'number'],
            ['key' => 'units_per_carton', 'text' => 'وحدات/كرتونة', 'type' => 'number'],
            ['key' => 'prints_issued', 'text' => 'مطبوعات: مصروف', 'type' => 'number'],
            ['key' => 'prints_used', 'text' => 'مطبوعات: مستخدم', 'type' => 'number'],
            ['key' => 'prints_returned', 'text' => 'مطبوعات: راجع', 'type' => 'number'],
            ['key' => 'prints_returned_to_stock', 'text' => 'إعادة المطبوعات الراجعة للمخزن فورًا', 'type' => 'checkbox'],
            ['key' => 'breakage_recorded', 'text' => 'أي كسر تم تسجيله', 'type' => 'checkbox'],
            ['key' => 'breakage_count', 'text' => 'الكسر: العدد', 'type' => 'number'],
            ['key' => 'breakage_notes', 'text' => 'الكسر: ملاحظات', 'type' => 'text'],
            ['key' => 'boxes_issued', 'text' => 'مصروف العلب', 'type' => 'number'],
            ['key' => 'boxes_used', 'text' => 'العلب: مستخدم', 'type' => 'number'],
            ['key' => 'boxes_returned', 'text' => 'العلب: راجع', 'type' => 'number'],
            ['key' => 'boxes_waste', 'text' => 'العلب: الهالك', 'type' => 'number'],
            ['key' => 'stickers_issued', 'text' => 'مصروف الاستيكر', 'type' => 'number'],
            ['key' => 'stickers_used', 'text' => 'الاستيكر: مستخدم', 'type' => 'number'],
            ['key' => 'stickers_returned', 'text' => 'الاستيكر: راجع', 'type' => 'number'],
            ['key' => 'stickers_waste', 'text' => 'الاستيكر: الهالك', 'type' => 'number'],
            ['key' => 'leaflets_issued', 'text' => 'مصروف نشره', 'type' => 'number'],
            ['key' => 'leaflets_used', 'text' => 'نشره: مستخدم', 'type' => 'number'],
            ['key' => 'leaflets_returned', 'text' => 'نشره: راجع', 'type' => 'number'],
            ['key' => 'leaflets_waste', 'text' => 'نشره: الهالك', 'type' => 'number']
        ]
    ];
    
    $insertPackagingStmt = $conn->prepare("
        INSERT INTO manufacturing_packaging_checklist 
        (manufacturing_order_id, section_name, item_key, item_text, item_type, item_order)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    
    $itemOrder = 0;
    foreach ($packagingChecklist as $sectionName => $items) {
        foreach ($items as $item) {
            $insertPackagingStmt->bind_param("issssi", $orderId, $sectionName, $item['key'], $item['text'], $item['type'], $itemOrder);
            $insertPackagingStmt->execute();
            $itemOrder++;
        }
    }
    $insertPackagingStmt->close();
}

// Initialize Dispatch Prep checklist if not exists
$checkDispatchStmt = $conn->prepare("SELECT COUNT(*) as cnt FROM manufacturing_dispatch_checklist WHERE manufacturing_order_id = ?");
$checkDispatchStmt->bind_param("i", $orderId);
$checkDispatchStmt->execute();
$dispatchCount = $checkDispatchStmt->get_result()->fetch_assoc()['cnt'];
$checkDispatchStmt->close();

if ($dispatchCount == 0) {
    $dispatchChecklist = [
        'details' => [
            ['key' => 'final_product_count', 'text' => 'عدد المنتج النهائي', 'type' => 'number'],
            ['key' => 'carton_count', 'text' => 'عدد الكراتين', 'type' => 'number'],
            ['key' => 'units_per_carton', 'text' => 'وحدات/كرتونة', 'type' => 'number']
        ],
        'before_loading' => [
            ['key' => 'release_order_received', 'text' => 'تم استلام إذن صرف/أمر تسليم', 'type' => 'checkbox'],
            ['key' => 'quantity_counted', 'text' => 'تم عدّ الكمية قبل التحميل', 'type' => 'checkbox'],
            ['key' => 'load_photographed', 'text' => 'تم تصوير الحمولة (صور مرفقة)', 'type' => 'checkbox']
        ]
    ];
    
    $insertDispatchStmt = $conn->prepare("INSERT INTO manufacturing_dispatch_checklist (manufacturing_order_id, section_name, item_key, item_text, item_type, item_order) VALUES (?, ?, ?, ?, ?, ?)");
    $itemOrder = 0;
    foreach ($dispatchChecklist as $sectionName => $items) {
        foreach ($items as $item) {
            $insertDispatchStmt->bind_param("issssi", $orderId, $sectionName, $item['key'], $item['text'], $item['type'], $itemOrder);
            $insertDispatchStmt->execute();
            $itemOrder++;
        }
    }
    $insertDispatchStmt->close();
}

// Initialize Delivery info record if not exists
$checkDeliveryStmt = $conn->prepare("SELECT COUNT(*) as cnt FROM manufacturing_delivery_info WHERE manufacturing_order_id = ?");
$checkDeliveryStmt->bind_param("i", $orderId);
$checkDeliveryStmt->execute();
$deliveryCount = $checkDeliveryStmt->get_result()->fetch_assoc()['cnt'];
$checkDeliveryStmt->close();

if ($deliveryCount == 0) {
    $insertDeliveryStmt = $conn->prepare("INSERT INTO manufacturing_delivery_info (manufacturing_order_id) VALUES (?)");
    $insertDeliveryStmt->bind_param("i", $orderId);
    $insertDeliveryStmt->execute();
    $insertDeliveryStmt->close();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stepKey = $_POST['step_key'] ?? '';
    $action = $_POST['action'] ?? 'update';
    $statusInput = $_POST['status'] ?? '';
    $notesInput = trim($_POST['notes'] ?? '');

    // Store component notes for sourcing step (always save notes regardless of status)
    if ($stepKey === 'sourcing') {
        $componentNotes = $_POST['component_notes'] ?? [];
        if (is_array($componentNotes)) {
            foreach ($componentNotes as $compId => $compNote) {
                $updateNoteStmt = $conn->prepare("UPDATE manufacturing_sourcing_components SET notes = ? WHERE id = ? AND manufacturing_order_id = ?");
                $updateNoteStmt->bind_param("sii", $compNote, $compId, $orderId);
                $updateNoteStmt->execute();
                $updateNoteStmt->close();
            }
        }
    }

    // Store receipt approval status and notes for receipt step
    if ($stepKey === 'receipt') {
        $receiptStatuses = $_POST['receipt_status'] ?? [];
        $receiptNotes = $_POST['receipt_notes'] ?? [];
        
        if (is_array($receiptStatuses)) {
            foreach ($receiptStatuses as $compId => $status) {
                $note = $receiptNotes[$compId] ?? '';
                $updateReceiptStmt = $conn->prepare("UPDATE manufacturing_sourcing_components SET receipt_status = ?, receipt_notes = ? WHERE id = ? AND manufacturing_order_id = ?");
                $updateReceiptStmt->bind_param("ssii", $status, $note, $compId, $orderId);
                $updateReceiptStmt->execute();
                $updateReceiptStmt->close();
            }
        }
    }

    // Store preparation measurements for preparation step
    if ($stepKey === 'preparation') {
        $prepMeasurements = $_POST['prep_measurements'] ?? [];
        
        // Delete existing measurements for this order
        $deleteStmt = $conn->prepare("DELETE FROM manufacturing_preparation_measurements WHERE manufacturing_order_id = ?");
        $deleteStmt->bind_param("i", $orderId);
        $deleteStmt->execute();
        $deleteStmt->close();
        
        // Insert new measurements
        if (is_array($prepMeasurements)) {
            $insertPrepStmt = $conn->prepare("INSERT INTO manufacturing_preparation_measurements (manufacturing_order_id, field_name, field_value, field_order) VALUES (?, ?, ?, ?)");
            $order_index = 0;
            foreach ($prepMeasurements as $fieldName => $fieldValue) {
                if (!empty(trim($fieldName)) && !empty(trim($fieldValue))) {
                    $insertPrepStmt->bind_param("issi", $orderId, $fieldName, $fieldValue, $order_index);
                    $insertPrepStmt->execute();
                    $order_index++;
                }
            }
            $insertPrepStmt->close();
        }
    }

    // Store quality checklist status and notes for quality step
    if ($stepKey === 'quality') {
        $qualityStatuses = $_POST['quality_status'] ?? [];
        $qualityNotes = $_POST['quality_notes'] ?? [];
        
        if (is_array($qualityStatuses)) {
            foreach ($qualityStatuses as $itemId => $status) {
                $note = $qualityNotes[$itemId] ?? '';
                $updateQualityStmt = $conn->prepare("UPDATE manufacturing_quality_checklist SET status = ?, notes = ? WHERE id = ? AND manufacturing_order_id = ?");
                $updateQualityStmt->bind_param("ssii", $status, $note, $itemId, $orderId);
                $updateQualityStmt->execute();
                $updateQualityStmt->close();
            }
        }
    }

    // Store packaging checklist values for packaging step
    if ($stepKey === 'packaging') {
        $packagingValues = $_POST['packaging_values'] ?? [];
        
        if (is_array($packagingValues)) {
            foreach ($packagingValues as $itemKey => $value) {
                $updatePackagingStmt = $conn->prepare("UPDATE manufacturing_packaging_checklist SET item_value = ? WHERE item_key = ? AND manufacturing_order_id = ?");
                $updatePackagingStmt->bind_param("ssi", $value, $itemKey, $orderId);
                $updatePackagingStmt->execute();
                $updatePackagingStmt->close();
            }
        }
    }

    // Store dispatch checklist values for dispatch step
    if ($stepKey === 'dispatch') {
        $dispatchValues = $_POST['dispatch_values'] ?? [];
        
        if (is_array($dispatchValues)) {
            foreach ($dispatchValues as $itemKey => $value) {
                $updateDispatchStmt = $conn->prepare("UPDATE manufacturing_dispatch_checklist SET item_value = ? WHERE item_key = ? AND manufacturing_order_id = ?");
                $updateDispatchStmt->bind_param("ssi", $value, $itemKey, $orderId);
                $updateDispatchStmt->execute();
                $updateDispatchStmt->close();
            }
        }
    }

    // Store delivery information for delivering step
    if ($stepKey === 'delivering') {
        $recipientName = trim($_POST['recipient_name'] ?? '');
        $recipientPhone = trim($_POST['recipient_phone'] ?? '');
        $deliveryDate = trim($_POST['delivery_date'] ?? '');
        $deliveryTime = trim($_POST['delivery_time'] ?? '');
        $customerNotes = trim($_POST['customer_notes'] ?? '');
        
        // Combine date and time into datetime
        $deliveryDatetime = null;
        if (!empty($deliveryDate) && !empty($deliveryTime)) {
            $deliveryDatetime = $deliveryDate . ' ' . $deliveryTime;
        }
        
        // Handle photo upload
        $photoPath = null;
        if (isset($_FILES['delivery_photo']) && $_FILES['delivery_photo']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = __DIR__ . '/../../assets/uploads/delivery/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            $fileExt = pathinfo($_FILES['delivery_photo']['name'], PATHINFO_EXTENSION);
            $fileName = 'delivery_' . $orderId . '_' . time() . '.' . $fileExt;
            $uploadPath = $uploadDir . $fileName;
            
            if (move_uploaded_file($_FILES['delivery_photo']['tmp_name'], $uploadPath)) {
                $photoPath = 'assets/uploads/delivery/' . $fileName;
            }
        }
        
        // Update delivery info
        $updateDeliveryStmt = $conn->prepare("
            UPDATE manufacturing_delivery_info 
            SET recipient_name = ?, recipient_phone = ?, delivery_datetime = ?, customer_notes = ?, photo_path = COALESCE(?, photo_path)
            WHERE manufacturing_order_id = ?
        ");
        $updateDeliveryStmt->bind_param("sssssi", $recipientName, $recipientPhone, $deliveryDatetime, $customerNotes, $photoPath, $orderId);
        $updateDeliveryStmt->execute();
        $updateDeliveryStmt->close();
    }

    // Special validation for sourcing step when completing
    if ($stepKey === 'sourcing' && $statusInput === 'completed') {
        // Check if all required quantities are available
        // Only check products (components with product_id), skip manual components
        $sourcingStmt = $conn->prepare("
            SELECT msc.id, msc.component_name, msc.product_id, msc.required_quantity, msc.unit,
                   p.barcode, COALESCE(SUM(ip.quantity), 0) AS available_qty
            FROM manufacturing_sourcing_components msc
            LEFT JOIN products p ON p.id = msc.product_id
            LEFT JOIN inventories inv ON inv.location_id = ?
            LEFT JOIN inventory_products ip ON ip.product_id = msc.product_id AND ip.inventory_id = inv.id
            WHERE msc.manufacturing_order_id = ? AND msc.product_id IS NOT NULL
            GROUP BY msc.id, msc.component_name, msc.product_id, msc.required_quantity, msc.unit, p.barcode
        ");
        $sourcingStmt->bind_param("ii", $order['location_id'], $orderId);
        $sourcingStmt->execute();
        $sourcingResult = $sourcingStmt->get_result();
        
        $insufficientQty = false;
        $insufficientItems = [];
        while ($comp = $sourcingResult->fetch_assoc()) {
            $availableQty = $comp['available_qty'] ?? 0;
            if ($availableQty < $comp['required_quantity']) {
                $insufficientQty = true;
                $insufficientItems[] = $comp['component_name'] . ' (need: ' . $comp['required_quantity'] . ' ' . $comp['unit'] . ', have: ' . $availableQty . ')';
            }
        }
        $sourcingStmt->close();
        
        if ($insufficientQty) {
            setAlert('danger', 'Cannot complete sourcing: Insufficient quantities in location. Missing: ' . implode(', ', $insufficientItems));
            redirect('order.php?id=' . $orderId);
        }
    }

    // Special validation for receipt step when completing
    if ($stepKey === 'receipt' && $statusInput === 'completed') {
        // Check if all materials are approved
        $receiptCheckStmt = $conn->prepare("
            SELECT component_name, receipt_status
            FROM manufacturing_sourcing_components
            WHERE manufacturing_order_id = ?
        ");
        $receiptCheckStmt->bind_param("i", $orderId);
        $receiptCheckStmt->execute();
        $receiptCheckResult = $receiptCheckStmt->get_result();
        
        $hasUnapproved = false;
        $unapprovedItems = [];
        while ($comp = $receiptCheckResult->fetch_assoc()) {
            if ($comp['receipt_status'] !== 'approved') {
                $hasUnapproved = true;
                $unapprovedItems[] = $comp['component_name'] . ' (' . ($comp['receipt_status'] ?? 'pending') . ')';
            }
        }
        $receiptCheckStmt->close();
        
        if ($hasUnapproved) {
            setAlert('danger', 'Cannot complete receipt step: All materials must be approved. Pending/Rejected: ' . implode(', ', $unapprovedItems));
            redirect('order.php?id=' . $orderId);
        }
    }

    // Special validation for quality step when completing
    if ($stepKey === 'quality' && $statusInput === 'completed') {
        // Check if all checklist items are approved
        $qualityCheckStmt = $conn->prepare("
            SELECT item_text, status
            FROM manufacturing_quality_checklist
            WHERE manufacturing_order_id = ?
        ");
        $qualityCheckStmt->bind_param("i", $orderId);
        $qualityCheckStmt->execute();
        $qualityCheckResult = $qualityCheckStmt->get_result();
        
        $hasUnapproved = false;
        $unapprovedItems = [];
        while ($item = $qualityCheckResult->fetch_assoc()) {
            if ($item['status'] !== 'approved') {
                $hasUnapproved = true;
                $unapprovedItems[] = substr($item['item_text'], 0, 50) . '... (' . ($item['status'] ?? 'pending') . ')';
            }
        }
        $qualityCheckStmt->close();
        
        if ($hasUnapproved) {
            setAlert('danger', 'Cannot complete quality step: All checklist items must be approved. Pending/Rejected items found.');
            redirect('order.php?id=' . $orderId);
        }
    }

    $stepStmt = $pdo->prepare("
        SELECT * FROM manufacturing_order_steps 
        WHERE manufacturing_order_id = ? AND step_key = ? 
        LIMIT 1
    ");
    $stepStmt->execute([$orderId, $stepKey]);
    $stepRow = $stepStmt->fetch(PDO::FETCH_ASSOC);
    $stepStmt = null;

    if (!$stepRow) {
        setAlert('danger', 'Selected step cannot be found.');
        redirect('order.php?id=' . $orderId);
    }

    // Check if previous step is completed (sequential workflow enforcement)
    if ($action !== 'regenerate' && ($statusInput === 'in_progress' || $statusInput === 'completed')) {
        $stepDefinitions = manufacturing_get_step_definitions();
        $stepKeys = array_keys($stepDefinitions);
        $currentStepIndex = array_search($stepKey, $stepKeys);
        
        if ($currentStepIndex > 0) {
            // Get previous step
            $previousStepKey = $stepKeys[$currentStepIndex - 1];
            $prevStepStmt = $conn->prepare("SELECT status FROM manufacturing_order_steps WHERE manufacturing_order_id = ? AND step_key = ?");
            $prevStepStmt->bind_param("is", $orderId, $previousStepKey);
            $prevStepStmt->execute();
            $prevStepResult = $prevStepStmt->get_result();
            $prevStep = $prevStepResult->fetch_assoc();
            $prevStepStmt->close();
            
            if ($prevStep && $prevStep['status'] !== 'completed') {
                setAlert('danger', 'Cannot start this step. Previous step "' . manufacturing_get_step_label($previousStepKey) . '" must be completed first.');
                redirect('order.php?id=' . $orderId);
            }
        }
    }

    $allowedStatuses = ['pending', 'in_progress', 'completed'];
    $statusInput = trim($statusInput);
    $statusToSave = in_array($statusInput, $allowedStatuses, true) ? $statusInput : $stepRow['status'];
    if ($action === 'regenerate') {
        $statusToSave = $stepRow['status'];
    }

    $startedAt = $stepRow['started_at'];
    $completedAt = $stepRow['completed_at'];
    if ($action !== 'regenerate') {
        if ($statusToSave === 'in_progress' && !$startedAt) {
            $startedAt = date('Y-m-d H:i:s');
        }
        if ($statusToSave === 'completed') {
            if (!$startedAt) {
                $startedAt = date('Y-m-d H:i:s');
            }
            $completedAt = date('Y-m-d H:i:s');
        } elseif ($statusToSave === 'pending') {
            $startedAt = null;
            $completedAt = null;
        } else {
            $completedAt = null;
        }
    }

    try {
        $pdo->beginTransaction();

        $updateStep = $pdo->prepare("
            UPDATE manufacturing_order_steps 
            SET status = ?, notes = ?, started_at = ?, completed_at = ?, updated_at = NOW() 
            WHERE id = ?
        ");
        $updateStep->execute([
            $statusToSave,
            $notesInput,
            $startedAt,
            $completedAt,
            $stepRow['id']
        ]);

        $orderStepsStmt = $conn->prepare("SELECT step_key, status FROM manufacturing_order_steps WHERE manufacturing_order_id = ?");
        $orderStepsStmt->bind_param('i', $orderId);
        $orderStepsStmt->execute();
        $stepsForStatus = [];
        $result = $orderStepsStmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $stepsForStatus[] = $row;
        }
        $orderStepsStmt->close();

        $overallStatus = manufacturing_determine_order_status_from_steps($stepsForStatus);
        $updateOrder = $pdo->prepare("UPDATE manufacturing_orders SET status = ?, updated_at = NOW() WHERE id = ?");
        $updateOrder->execute([$overallStatus, $orderId]);

        $pdo->commit();
    } catch (Exception $ex) {
        $pdo->rollBack();
        setAlert('danger', 'Unable to save the step: ' . $ex->getMessage());
        redirect('order.php?id=' . $orderId);
    }

    $docMessage = '';
    try {
        $freshStepStmt = $conn->prepare("SELECT * FROM manufacturing_order_steps WHERE id = ?");
        $freshStepStmt->bind_param('i', $stepRow['id']);
        $freshStepStmt->execute();
        $updatedStep = $freshStepStmt->get_result()->fetch_assoc();
        $freshStepStmt->close();

        manufacturing_generate_step_documents(
            $conn,
            $order,
            $updatedStep,
            ['name' => $order['formula_name'], 'components_json' => $order['components_json'] ?? '[]'],
            $formulaComponents,
            $notesInput,
            $_SESSION['user_id'] ?? null
        );
    } catch (Exception $docEx) {
        $docMessage = ' Document generation failed: ' . $docEx->getMessage();
    }

    $actionText = $action === 'regenerate' ? 'Regenerated' : 'Saved';
    setAlert('success', "{$actionText} the step and Excel/PDF handoff created.{$docMessage}");
    logActivity("Updated manufacturing step {$stepKey} for order {$order['order_number']}", [
        'order_id' => $orderId,
        'step_key' => $stepKey,
        'status' => $statusToSave,
    ]);

    header('Location: order.php?id=' . $orderId);
    exit;
}

$page_title = 'Manufacturing Order ' . $order['order_number'];
require_once '../../includes/header.php';

$steps = [];
$stepStmt = $conn->prepare("
    SELECT * FROM manufacturing_order_steps 
    WHERE manufacturing_order_id = ? 
    ORDER BY FIELD(step_key, 'sourcing', 'receipt', 'preparation', 'quality', 'packaging', 'dispatch', 'delivering')
");
$stepStmt->bind_param('i', $orderId);
$stepStmt->execute();
$stepResult = $stepStmt->get_result();
while ($stepRow = $stepResult->fetch_assoc()) {
    $steps[] = $stepRow;
}
$stepStmt->close();

$stepsByKey = [];
foreach ($steps as $stepItem) {
    $stepsByKey[$stepItem['step_key']] = $stepItem;
}

$orderDocuments = [];
$totalDocuments = 0;
foreach ($steps as $stepRow) {
    $docs = manufacturing_get_documents_by_step($conn, $stepRow['id']);
    $orderDocuments[$stepRow['id']] = $docs;
    $totalDocuments += count($docs);
}

$stepDefinitions = manufacturing_get_step_definitions();
$totalSteps = max(1, count($stepDefinitions));
$completedSteps = 0;
foreach ($steps as $stepRow) {
    if ($stepRow['status'] === 'completed') {
        $completedSteps++;
    }
}
$progressPercent = (int)(($completedSteps / $totalSteps) * 100);
$nextStepLabel = manufacturing_get_next_step_label($steps);
$orderBadge = manufacturing_order_status_badge($order['status']);
?>

<div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-3">
    <div>
        <h2>Manufacturing Order <?= htmlspecialchars($order['order_number']); ?></h2>
        <p class="text-muted mb-0">Provider: <?= htmlspecialchars($order['customer_name']); ?> | Product: <?= htmlspecialchars($order['product_name'] ?? 'N/A'); ?> | Formula: <?= htmlspecialchars($order['formula_name']); ?></p>
    </div>
    <div class="d-flex gap-2">
        <span class="badge <?= $orderBadge['class']; ?> text-uppercase">
            <?= $orderBadge['label']; ?>
        </span>
        <a href="edit.php?id=<?= $orderId; ?>" class="btn btn-outline-primary">
            <i class="fas fa-edit me-1"></i> Edit
        </a>
        <?php if (hasPermission('manufacturing.delete')): ?>
            <button type="button" class="btn btn-danger" 
                    onclick="confirmDelete(<?= $orderId; ?>, '<?= htmlspecialchars($order['order_number']); ?>')">
                <i class="fas fa-trash me-1"></i> Delete
            </button>
        <?php endif; ?>
        <a href="index.php" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left me-1"></i> Back to dashboard
        </a>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header">Order snapshot</div>
            <div class="card-body">
                <p class="mb-1"><strong>Final Product:</strong> <?= htmlspecialchars($order['product_name'] ?? 'Not set'); ?> <?= $order['product_sku'] ? '(' . htmlspecialchars($order['product_sku']) . ')' : ''; ?></p>
                <p class="mb-1"><strong>Priority:</strong> <?= ucfirst(htmlspecialchars($order['priority'])); ?></p>
                <p class="mb-1"><strong>Location:</strong> <?= $order['location_name'] ? htmlspecialchars($order['location_name']) . ' - ' . htmlspecialchars($order['location_address'])  : '<span class="text-muted">Not set</span>'; ?></p>
                <p class="mb-1"><strong>Due Date:</strong> <?= $order['due_date'] ? htmlspecialchars($order['due_date']) : '<span class="text-muted">Not set</span>'; ?></p>
                <p class="mb-1"><strong>Batch size:</strong> <?= htmlspecialchars($order['batch_size']); ?></p>
                <p class="mb-1"><strong>Notes:</strong><br><?= nl2br(htmlspecialchars($order['notes'] ?? 'No notes provided.')); ?></p>
                <div class="mt-3">
                    <div class="progress" style="height:10px;">
                        <div class="progress-bar" role="progressbar" style="width: <?= $progressPercent; ?>%;" aria-valuenow="<?= $progressPercent; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                    </div>
                    <small class="text-muted"><?= $completedSteps; ?>/<?= $totalSteps; ?> steps complete • Next: <?= htmlspecialchars($nextStepLabel); ?></small>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header">Formula components</div>
            <div class="card-body">
                <?php if (!empty($formulaComponents)): ?>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead>
                                <tr>
                                    <th class="ps-0">Component</th>
                                    <th>Qty / Ratio</th>
                                    <th>Unit</th>
                                    <th>Notes</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($formulaComponents as $fComponent): ?>
                                    <tr>
                                        <td class="ps-0"><?= htmlspecialchars($fComponent['name'] ?? '-'); ?></td>
                                        <td><?= htmlspecialchars($fComponent['quantity'] ?? $fComponent['ratio'] ?? '-'); ?></td>
                                        <td><?= htmlspecialchars($fComponent['unit'] ?? '-'); ?></td>
                                        <td><?= htmlspecialchars($fComponent['notes'] ?? '-'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-muted small mb-0">No components were defined for this formula.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header">Handoff documentation</div>
            <div class="card-body">
                <p class="mb-1"><strong>Total files:</strong> <?= $totalDocuments; ?> (Excel + PDF per step)</p>
                <p class="mb-1"><strong>Formula description:</strong> <?= htmlspecialchars($order['formula_description'] ?? 'No description'); ?></p>
                <p class="text-muted small mb-0">Every save regenerates the Excel and PDF that travel with the order to the next internal team.</p>
            </div>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header">Workflow timeline</div>
    <div class="card-body">
        <div class="list-group list-group-flush">
            <?php foreach ($stepDefinitions as $stepKey => $definition): ?>
                <?php
                // Check permission for this specific step
                $stepPermissionKey = 'manufacturing.view_step_' . $stepKey;
                if (!hasPermission($stepPermissionKey)) {
                    continue; // Skip this step if user doesn't have permission
                }
                $stepData = $stepsByKey[$stepKey] ?? null;
                $stepStatus = $stepData['status'] ?? 'pending';
                $docCountForStep = $stepData ? count($orderDocuments[$stepData['id']] ?? []) : 0;
                ?>
                <div class="list-group-item border-bottom">
                    <div class="d-flex justify-content-between align-items-start gap-2">
                        <div>
                            <div class="fw-semibold"><?= htmlspecialchars($definition['label']); ?></div>
                            <div class="text-muted small"><?= htmlspecialchars($definition['description']); ?></div>
                        </div>
                        <div class="text-end">
                            <span class="badge <?= manufacturing_status_badge_class($stepStatus); ?> text-uppercase"><?= htmlspecialchars($stepStatus); ?></span>
                            <div class="small text-muted mt-1"><?= $docCountForStep; ?> files</div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="card-footer text-muted small">
        Each status change triggers an Excel + PDF handoff stored with the order so downstream teams always have the latest data.
    </div>
</div>

<div class="card">
    <div class="card-header">Step-by-step manufacturing workflow</div>
    <div class="card-body">
        <div class="accordion" id="manufacturingSteps">
            <?php foreach ($steps as $index => $stepRow): ?>
                <?php 
                    // Check permission for this specific step
                    $stepPermissionKey = 'manufacturing.view_step_' . $stepRow['step_key'];
                    if (!hasPermission($stepPermissionKey)) {
                        continue; // Skip this step if user doesn't have permission
                    }
                    $documents = $orderDocuments[$stepRow['id']] ?? []; 
                ?>
                <div class="accordion-item">
                    <h2 class="accordion-header" id="stepHeading<?= $stepRow['id']; ?>">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#stepCollapse<?= $stepRow['id']; ?>" aria-expanded="false">
                            <?= manufacturing_get_step_label($stepRow['step_key']); ?>
                            <span class="badge <?= manufacturing_status_badge_class($stepRow['status']); ?> ms-3 text-uppercase"><?= $stepRow['status']; ?></span>
                        </button>
                    </h2>
                    <div id="stepCollapse<?= $stepRow['id']; ?>" class="accordion-collapse collapse" aria-labelledby="stepHeading<?= $stepRow['id']; ?>" data-bs-parent="#manufacturingSteps">
                        <div class="accordion-body">
                            <p class="text-muted small mb-3"><?= manufacturing_get_step_instruction($stepRow['step_key']); ?></p>
                            
                            <form method="post" enctype="multipart/form-data">
                                <?php if ($stepRow['step_key'] === 'sourcing'): ?>
                                    <!-- Sourcing Components Table -->
                                    <?php
                                    $sourcingStmt = $conn->prepare("
                                        SELECT msc.id, msc.component_name, msc.product_id, msc.required_quantity, msc.unit, msc.notes,
                                               p.barcode, COALESCE(SUM(ip.quantity), 0) AS available_qty
                                        FROM manufacturing_sourcing_components msc
                                        LEFT JOIN products p ON p.id = msc.product_id
                                        LEFT JOIN inventories inv ON inv.location_id = ?
                                        LEFT JOIN inventory_products ip ON ip.product_id = msc.product_id AND ip.inventory_id = inv.id
                                        WHERE msc.manufacturing_order_id = ?
                                        GROUP BY msc.id, msc.component_name, msc.product_id, msc.required_quantity, msc.unit, msc.notes, p.barcode
                                        ORDER BY msc.formula_component_index
                                    ");
                                    $sourcingStmt->bind_param("ii", $order['location_id'], $orderId);
                                    $sourcingStmt->execute();
                                    $sourcingResult = $sourcingStmt->get_result();
                                    $sourcingComponents = [];
                                    while ($sc = $sourcingResult->fetch_assoc()) {
                                        $sourcingComponents[] = $sc;
                                    }
                                    $sourcingStmt->close();
                                    ?>
                                    <div class="table-responsive mb-3">
                                        <table class="table table-bordered table-sm">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Item</th>
                                                    <th>Barcode</th>
                                                    <th>Required Qty</th>
                                                    <th>Unit</th>
                                                    <th>Available in Location</th>
                                                    <th style="background-color: #fff3cd;">Status</th>
                                                    <th>Notes</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($sourcingComponents as $sc): ?>
                                                    <?php 
                                                    $availableQty = $sc['available_qty'] ?? 0;
                                                    $requiredQty = $sc['required_quantity'];
                                                    $isValid = $availableQty >= $requiredQty;
                                                    $statusClass = $isValid ? 'table-success' : 'table-danger';
                                                    $statusText = $isValid ? '✓ OK' : '✗ Insufficient';
                                                    ?>
                                                    <tr>
                                                        <td><?= htmlspecialchars($sc['component_name']); ?></td>
                                                        <td><?= $sc['barcode'] ? htmlspecialchars($sc['barcode']) : '<span class="text-muted">-</span>'; ?></td>
                                                        <td><?= htmlspecialchars($sc['required_quantity']); ?></td>
                                                        <td><?= htmlspecialchars($sc['unit']); ?></td>
                                                        <td><?= htmlspecialchars($availableQty); ?></td>
                                                        <td class="<?= $statusClass; ?> text-center fw-bold"><?= $statusText; ?></td>
                                                        <td>
                                                            <input type="text" class="form-control form-control-sm" 
                                                                   name="component_notes[<?= $sc['id']; ?>]" 
                                                                   value="<?= htmlspecialchars($sc['notes'] ?? ''); ?>"
                                                                   placeholder="Add notes...">
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <?php if (!empty($sourcingComponents)): ?>
                                        <div class="alert alert-info small mb-3">
                                            <i class="fas fa-info-circle me-2"></i>
                                            <strong>Sourcing Requirements:</strong> All items must show "✓ OK" status in the location before you can mark this step as completed.
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>

                                <?php if ($stepRow['step_key'] === 'receipt'): ?>
                                    <!-- Receipt & Quality Check Table -->
                                    <?php
                                    $receiptStmt = $conn->prepare("
                                        SELECT msc.id, msc.component_name, msc.product_id, msc.required_quantity, msc.unit,
                                               msc.receipt_status, msc.receipt_notes, p.barcode
                                        FROM manufacturing_sourcing_components msc
                                        LEFT JOIN products p ON p.id = msc.product_id
                                        WHERE msc.manufacturing_order_id = ?
                                        ORDER BY msc.formula_component_index
                                    ");
                                    $receiptStmt->bind_param("i", $orderId);
                                    $receiptStmt->execute();
                                    $receiptResult = $receiptStmt->get_result();
                                    $receiptComponents = [];
                                    while ($rc = $receiptResult->fetch_assoc()) {
                                        $receiptComponents[] = $rc;
                                    }
                                    $receiptStmt->close();
                                    ?>
                                    <div class="table-responsive mb-3">
                                        <table class="table table-bordered table-sm">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Material</th>
                                                    <th>Barcode</th>
                                                    <th>Expected Qty</th>
                                                    <th>Unit</th>
                                                    <th style="background-color: #d1ecf1;">Quality Status</th>
                                                    <th>QA Notes</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($receiptComponents as $rc): ?>
                                                    <?php
                                                    $receiptStatus = $rc['receipt_status'] ?? 'pending';
                                                    $rowClass = '';
                                                    if ($receiptStatus === 'approved') {
                                                        $rowClass = 'table-success';
                                                    } elseif ($receiptStatus === 'rejected') {
                                                        $rowClass = 'table-danger';
                                                    }
                                                    ?>
                                                    <tr class="<?= $rowClass; ?>">
                                                        <td><?= htmlspecialchars($rc['component_name']); ?></td>
                                                        <td><?= $rc['barcode'] ? htmlspecialchars($rc['barcode']) : '<span class="text-muted">-</span>'; ?></td>
                                                        <td><?= htmlspecialchars($rc['required_quantity']); ?></td>
                                                        <td><?= htmlspecialchars($rc['unit']); ?></td>
                                                        <td>
                                                            <select class="form-select form-select-sm" name="receipt_status[<?= $rc['id']; ?>]">
                                                                <option value="pending" <?= $receiptStatus === 'pending' ? 'selected' : ''; ?>>⏳ Pending</option>
                                                                <option value="approved" <?= $receiptStatus === 'approved' ? 'selected' : ''; ?>>✓ Approved</option>
                                                                <option value="rejected" <?= $receiptStatus === 'rejected' ? 'selected' : ''; ?>>✗ Rejected</option>
                                                            </select>
                                                        </td>
                                                        <td>
                                                            <input type="text" class="form-control form-control-sm" 
                                                                   name="receipt_notes[<?= $rc['id']; ?>]" 
                                                                   value="<?= htmlspecialchars($rc['receipt_notes'] ?? ''); ?>"
                                                                   placeholder="QA notes...">
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <?php if (!empty($receiptComponents)): ?>
                                        <div class="alert alert-info small mb-3">
                                            <i class="fas fa-info-circle me-2"></i>
                                            <strong>Quality Check Requirements:</strong> All materials must be approved (✓ Approved) before you can mark this step as completed. Compare received materials against POs, run QA checks, and document any deviations.
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>

                                <?php if ($stepRow['step_key'] === 'preparation'): ?>
                                    <!-- Preparation & Mixing Measurements -->
                                    <?php
                                    $prepStmt = $conn->prepare("
                                        SELECT field_name, field_value, field_order
                                        FROM manufacturing_preparation_measurements
                                        WHERE manufacturing_order_id = ?
                                        ORDER BY field_order
                                    ");
                                    $prepStmt->bind_param("i", $orderId);
                                    $prepStmt->execute();
                                    $prepResult = $prepStmt->get_result();
                                    $prepMeasurements = [];
                                    while ($pm = $prepResult->fetch_assoc()) {
                                        $prepMeasurements[$pm['field_name']] = $pm['field_value'];
                                    }
                                    $prepStmt->close();
                                    
                                    // Define standard fields
                                    $standardFields = ['pH', 'TDS', 'Temperature', 'Humidity'];
                                    ?>
                                    <div class="mb-3">
                                        <h6 class="mb-3">Mixing Process Measurements</h6>
                                        <div id="preparationFields">
                                            <?php foreach ($standardFields as $field): ?>
                                                <div class="row mb-2 align-items-center">
                                                    <div class="col-md-3">
                                                        <label class="form-label mb-0"><?= htmlspecialchars($field); ?></label>
                                                    </div>
                                                    <div class="col-md-9">
                                                        <input type="text" class="form-control form-control-sm" 
                                                               name="prep_measurements[<?= htmlspecialchars($field); ?>]" 
                                                               value="<?= htmlspecialchars($prepMeasurements[$field] ?? ''); ?>"
                                                               placeholder="Enter <?= htmlspecialchars($field); ?> value">
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                            
                                            <?php
                                            // Show custom fields that were previously saved
                                            foreach ($prepMeasurements as $fieldName => $fieldValue) {
                                                if (!in_array($fieldName, $standardFields)) {
                                                    echo '<div class="row mb-2 align-items-center custom-field">';
                                                    echo '<div class="col-md-3">';
                                                    echo '<input type="text" class="form-control form-control-sm field-name-input" value="' . htmlspecialchars($fieldName) . '" placeholder="Field name" readonly>';
                                                    echo '</div>';
                                                    echo '<div class="col-md-8">';
                                                    echo '<input type="text" class="form-control form-control-sm" name="prep_measurements[' . htmlspecialchars($fieldName) . ']" value="' . htmlspecialchars($fieldValue) . '" placeholder="Enter value">';
                                                    echo '</div>';
                                                    echo '<div class="col-md-1">';
                                                    echo '<button type="button" class="btn btn-sm btn-outline-danger remove-field"><i class="fas fa-times"></i></button>';
                                                    echo '</div>';
                                                    echo '</div>';
                                                }
                                            }
                                            ?>
                                        </div>
                                        <button type="button" class="btn btn-sm btn-outline-success mt-2" id="addPrepField">
                                            <i class="fas fa-plus me-1"></i> Add Custom Field
                                        </button>
                                    </div>
                                    <div class="alert alert-info small mb-3">
                                        <i class="fas fa-info-circle me-2"></i>
                                        <strong>Preparation Guidelines:</strong> Record mixing parameters, temperatures, and dwell times. Use custom fields to add any additional measurements specific to this formula.
                                    </div>
                                    
                                    <script>
                                    document.addEventListener('DOMContentLoaded', function() {
                                        let fieldCounter = 0;
                                        
                                        document.getElementById('addPrepField')?.addEventListener('click', function() {
                                            fieldCounter++;
                                            const container = document.getElementById('preparationFields');
                                            const newField = document.createElement('div');
                                            newField.className = 'row mb-2 align-items-center custom-field';
                                            newField.innerHTML = `
                                                <div class="col-md-3">
                                                    <input type="text" class="form-control form-control-sm field-name-input" 
                                                           placeholder="Field name" data-field-id="custom_${fieldCounter}">
                                                </div>
                                                <div class="col-md-8">
                                                    <input type="text" class="form-control form-control-sm" 
                                                           name="prep_measurements[custom_${fieldCounter}]" 
                                                           placeholder="Enter value" disabled>
                                                </div>
                                                <div class="col-md-1">
                                                    <button type="button" class="btn btn-sm btn-outline-danger remove-field">
                                                        <i class="fas fa-times"></i>
                                                    </button>
                                                </div>
                                            `;
                                            container.appendChild(newField);
                                            
                                            // Enable value input when field name is entered
                                            const nameInput = newField.querySelector('.field-name-input');
                                            const valueInput = newField.querySelector('input[type="text"]:not(.field-name-input)');
                                            nameInput.addEventListener('input', function() {
                                                const fieldName = this.value.trim();
                                                if (fieldName) {
                                                    valueInput.disabled = false;
                                                    valueInput.name = `prep_measurements[${fieldName}]`;
                                                } else {
                                                    valueInput.disabled = true;
                                                    valueInput.name = `prep_measurements[custom_${fieldCounter}]`;
                                                }
                                            });
                                        });
                                        
                                        // Remove field handler (using event delegation)
                                        document.getElementById('preparationFields')?.addEventListener('click', function(e) {
                                            if (e.target.closest('.remove-field')) {
                                                e.target.closest('.custom-field').remove();
                                            }
                                        });
                                    });
                                    </script>
                                <?php endif; ?>

                                <?php if ($stepRow['step_key'] === 'quality'): ?>
                                    <!-- Quality Validation Checklist -->
                                    <?php
                                    $qualityStmt = $conn->prepare("
                                        SELECT id, section_name, item_text, status, notes
                                        FROM manufacturing_quality_checklist
                                        WHERE manufacturing_order_id = ?
                                        ORDER BY item_order
                                    ");
                                    $qualityStmt->bind_param("i", $orderId);
                                    $qualityStmt->execute();
                                    $qualityResult = $qualityStmt->get_result();
                                    $qualityItems = [];
                                    while ($qi = $qualityResult->fetch_assoc()) {
                                        $qualityItems[] = $qi;
                                    }
                                    $qualityStmt->close();
                                    
                                    // Group items by section
                                    $qualitySections = [];
                                    foreach ($qualityItems as $item) {
                                        $qualitySections[$item['section_name']][] = $item;
                                    }
                                    ?>
                                    <div class="mb-3">
                                        <h6 class="mb-3">Quality Validation Checklist</h6>
                                        <?php foreach ($qualitySections as $sectionName => $items): ?>
                                            <h6 class="mt-4 mb-3 text-primary"><?= htmlspecialchars($sectionName); ?></h6>
                                            <div class="table-responsive mb-3">
                                                <table class="table table-bordered table-sm">
                                                    <thead class="table-light">
                                                        <tr>
                                                            <th style="width: 50%;" dir="rtl">-</th>
                                                            <th style="width: 20%; background-color: #d1ecf1;">✔ / ✖</th>
                                                            <th style="width: 30%;">Notes</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($items as $item): ?>
                                                            <?php
                                                            $itemStatus = $item['status'] ?? 'pending';
                                                            $rowClass = '';
                                                            if ($itemStatus === 'approved') {
                                                                $rowClass = 'table-success';
                                                            } elseif ($itemStatus === 'rejected') {
                                                                $rowClass = 'table-danger';
                                                            }
                                                            ?>
                                                            <tr class="<?= $rowClass; ?>">
                                                                <td dir="rtl"><?= htmlspecialchars($item['item_text']); ?></td>
                                                                <td>
                                                                    <select class="form-select form-select-sm" name="quality_status[<?= $item['id']; ?>]">
                                                                        <option value="pending" <?= $itemStatus === 'pending' ? 'selected' : ''; ?>>⬜ Pending</option>
                                                                        <option value="approved" <?= $itemStatus === 'approved' ? 'selected' : ''; ?>>✓ Approved</option>
                                                                        <option value="rejected" <?= $itemStatus === 'rejected' ? 'selected' : ''; ?>>✖ Rejected</option>
                                                                    </select>
                                                                </td>
                                                                <td>
                                                                    <input type="text" class="form-control form-control-sm" 
                                                                           name="quality_notes[<?= $item['id']; ?>]" 
                                                                           value="<?= htmlspecialchars($item['notes'] ?? ''); ?>"
                                                                           placeholder="Add notes...">
                                                                </td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="alert alert-info small mb-3">
                                        <i class="fas fa-info-circle me-2"></i>
                                        <strong>Quality Validation Requirements:</strong> All checklist items must be approved (✓) before you can mark this step as completed.
                                    </div>
                                <?php endif; ?>

                                <?php if ($stepRow['step_key'] === 'packaging'): ?>
                                    <!-- Packaging & Labeling Checklist -->
                                    <?php
                                    $packagingStmt = $conn->prepare("
                                        SELECT id, section_name, item_key, item_text, item_type, item_value
                                        FROM manufacturing_packaging_checklist
                                        WHERE manufacturing_order_id = ?
                                        ORDER BY item_order
                                    ");
                                    $packagingStmt->bind_param("i", $orderId);
                                    $packagingStmt->execute();
                                    $packagingResult = $packagingStmt->get_result();
                                    $packagingItems = [];
                                    while ($pi = $packagingResult->fetch_assoc()) {
                                        $packagingItems[] = $pi;
                                    }
                                    $packagingStmt->close();
                                    
                                    // Group items by section
                                    $packagingSections = [
                                        'before' => [],
                                        'during' => [],
                                        'after' => []
                                    ];
                                    foreach ($packagingItems as $item) {
                                        $packagingSections[$item['section_name']][] = $item;
                                    }
                                    ?>
                                    <style>
                                        .packaging-checkbox {
                                            width: 24px;
                                            height: 24px;
                                            cursor: pointer;
                                        }
                                    </style>
                                    <div class="mb-3" style="direction: rtl; text-align: right;">
                                        <h6 class="mb-3" style="text-align: center;">Packaging & Labeling Process Tracking</h6>
                                        
                                        <!-- Before Operation (Line Clearance) -->
                                        <h6 class="mt-4 mb-3 text-primary">قبل التشغيل (Line Clearance)</h6>
                                        <div class="table-responsive mb-3">
                                            <table class="table table-bordered table-sm">
                                                <tbody>
                                                    <?php foreach ($packagingSections['before'] as $item): ?>
                                                        <tr>
                                                            <td style="width: 70%; text-align: right;"><?= htmlspecialchars($item['item_text']); ?></td>
                                                            <td style="width: 30%; text-align: center;">
                                                                <div class="form-check d-flex justify-content-center">
                                                                    <input class="form-check-input packaging-checkbox" type="checkbox" 
                                                                           name="packaging_values[<?= htmlspecialchars($item['item_key']); ?>]" 
                                                                           value="checked"
                                                                           <?= $item['item_value'] === 'checked' ? 'checked' : ''; ?>>
                                                                </div>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                        
                                        <!-- During Packaging -->
                                        <h6 class="mt-4 mb-3 text-primary">أثناء التعبئة</h6>
                                        <div class="table-responsive mb-3">
                                            <table class="table table-bordered table-sm">
                                                <tbody>
                                                    <?php foreach ($packagingSections['during'] as $item): ?>
                                                        <tr>
                                                            <td style="width: 70%; text-align: right;"><?= htmlspecialchars($item['item_text']); ?></td>
                                                            <td style="width: 30%; text-align: center;">
                                                                <div class="form-check d-flex justify-content-center">
                                                                    <input class="form-check-input packaging-checkbox" type="checkbox" 
                                                                           name="packaging_values[<?= htmlspecialchars($item['item_key']); ?>]" 
                                                                           value="checked"
                                                                           <?= $item['item_value'] === 'checked' ? 'checked' : ''; ?>>
                                                                </div>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                        
                                        <!-- After Packaging -->
                                        <h6 class="mt-4 mb-3 text-primary">بعد التعبئة</h6>
                                        <div class="row g-3 mb-3">
                                            <?php foreach ($packagingSections['after'] as $item): ?>
                                                <?php if ($item['item_type'] === 'number'): ?>
                                                    <div class="col-md-6">
                                                        <label class="form-label"><?= htmlspecialchars($item['item_text']); ?></label>
                                                        <input type="number" class="form-control form-control-sm" 
                                                               name="packaging_values[<?= htmlspecialchars($item['item_key']); ?>]" 
                                                               value="<?= htmlspecialchars($item['item_value'] ?? ''); ?>"
                                                               placeholder="0">
                                                    </div>
                                                <?php elseif ($item['item_type'] === 'text'): ?>
                                                    <div class="col-md-12">
                                                        <label class="form-label"><?= htmlspecialchars($item['item_text']); ?></label>
                                                        <textarea class="form-control form-control-sm" rows="2"
                                                                  name="packaging_values[<?= htmlspecialchars($item['item_key']); ?>]" 
                                                                  placeholder="..."><?= htmlspecialchars($item['item_value'] ?? ''); ?></textarea>
                                                    </div>
                                                <?php elseif ($item['item_type'] === 'checkbox'): ?>
                                                    <div class="col-md-6">
                                                        <div class="form-check">
                                                            <input class="form-check-input packaging-checkbox" type="checkbox" 
                                                                   name="packaging_values[<?= htmlspecialchars($item['item_key']); ?>]" 
                                                                   value="checked"
                                                                   <?= $item['item_value'] === 'checked' ? 'checked' : ''; ?>>
                                                            <label class="form-check-label"><?= htmlspecialchars($item['item_text']); ?></label>
                                                        </div>
                                                    </div>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </div>
                                        
                                        <!-- Material Tracking Summary Table -->
                                        <h6 class="mt-4 mb-3 text-primary">تتبع المواد (Material Tracking)</h6>
                                        <div class="table-responsive mb-3">
                                            <table class="table table-bordered table-sm">
                                                <thead class="table-light">
                                                    <tr>
                                                        <th style="text-align: right;">المادة</th>
                                                        <th style="text-align: right;">مصروف</th>
                                                        <th style="text-align: right;">مستخدم</th>
                                                        <th style="text-align: right;">راجع</th>
                                                        <th style="text-align: right;">الهالك</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php
                                                    $materials = [
                                                        'boxes' => 'العلب',
                                                        'stickers' => 'الاستيكر',
                                                        'leaflets' => 'نشره'
                                                    ];
                                                    foreach ($materials as $matKey => $matLabel):
                                                        $issuedKey = $matKey . '_issued';
                                                        $usedKey = $matKey . '_used';
                                                        $returnedKey = $matKey . '_returned';
                                                        $wasteKey = $matKey . '_waste';
                                                        
                                                        // Find values from packagingSections
                                                        $issuedVal = '';
                                                        $usedVal = '';
                                                        $returnedVal = '';
                                                        $wasteVal = '';
                                                        foreach ($packagingSections['after'] as $item) {
                                                            if ($item['item_key'] === $issuedKey) $issuedVal = $item['item_value'] ?? '';
                                                            if ($item['item_key'] === $usedKey) $usedVal = $item['item_value'] ?? '';
                                                            if ($item['item_key'] === $returnedKey) $returnedVal = $item['item_value'] ?? '';
                                                            if ($item['item_key'] === $wasteKey) $wasteVal = $item['item_value'] ?? '';
                                                        }
                                                    ?>
                                                        <tr>
                                                            <td style="text-align: right;"><strong><?= $matLabel; ?></strong></td>
                                                            <td><input type="number" class="form-control form-control-sm" name="packaging_values[<?= $issuedKey; ?>]" value="<?= htmlspecialchars($issuedVal); ?>" placeholder="0"></td>
                                                            <td><input type="number" class="form-control form-control-sm" name="packaging_values[<?= $usedKey; ?>]" value="<?= htmlspecialchars($usedVal); ?>" placeholder="0"></td>
                                                            <td><input type="number" class="form-control form-control-sm" name="packaging_values[<?= $returnedKey; ?>]" value="<?= htmlspecialchars($returnedVal); ?>" placeholder="0"></td>
                                                            <td><input type="number" class="form-control form-control-sm" name="packaging_values[<?= $wasteKey; ?>]" value="<?= htmlspecialchars($wasteVal); ?>" placeholder="0"></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                    <div class="alert alert-info small mb-3">
                                        <i class="fas fa-info-circle me-2"></i>
                                        <strong>Packaging Guidelines:</strong> Complete line clearance before starting. Track all materials accurately and record final counts. Ensure all packaging materials are reconciled (issued = used + returned + waste).
                                    </div>
                                <?php endif; ?>

                                <?php if ($stepRow['step_key'] === 'dispatch'): ?>
                                    <?php
                                    $dispatchStmt = $conn->prepare("
                                        SELECT id, section_name, item_key, item_text, item_type, item_value
                                        FROM manufacturing_dispatch_checklist
                                        WHERE manufacturing_order_id = ?
                                        ORDER BY item_order
                                    ");
                                    $dispatchStmt->bind_param("i", $orderId);
                                    $dispatchStmt->execute();
                                    $dispatchResult = $dispatchStmt->get_result();
                                    $dispatchItems = [];
                                    while ($di = $dispatchResult->fetch_assoc()) {
                                        $dispatchItems[] = $di;
                                    }
                                    $dispatchStmt->close();
                                    
                                    // Group items by section
                                    $dispatchSections = [
                                        'details' => [],
                                        'before_loading' => []
                                    ];
                                    foreach ($dispatchItems as $item) {
                                        $dispatchSections[$item['section_name']][] = $item;
                                    }
                                    ?>
                                    <style>
                                        .dispatch-checkbox {
                                            width: 24px;
                                            height: 24px;
                                            cursor: pointer;
                                        }
                                    </style>
                                    <div class="mb-3" style="direction: rtl; text-align: right;">
                                        <h6 class="mb-3" style="text-align: center;">Dispatch Prep - Inventory Coordinator</h6>
                                        
                                        <!-- Dispatch Details -->
                                        <h6 class="mt-4 mb-3 text-primary">تفاصيل الشحن (Dispatch Details)</h6>
                                        <div class="row g-3 mb-3">
                                            <?php foreach ($dispatchSections['details'] as $item): ?>
                                                <div class="col-md-4">
                                                    <label class="form-label"><?= htmlspecialchars($item['item_text']); ?></label>
                                                    <input type="number" class="form-control form-control-sm" 
                                                           name="dispatch_values[<?= htmlspecialchars($item['item_key']); ?>]" 
                                                           value="<?= htmlspecialchars($item['item_value'] ?? ''); ?>"
                                                           placeholder="0">
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        
                                        <!-- Before Loading -->
                                        <h6 class="mt-4 mb-3 text-primary">قبل التحميل (Before Loading)</h6>
                                        <div class="table-responsive mb-3">
                                            <table class="table table-bordered table-sm">
                                                <tbody>
                                                    <?php foreach ($dispatchSections['before_loading'] as $item): ?>
                                                        <tr>
                                                            <td style="width: 70%; text-align: right;"><?= htmlspecialchars($item['item_text']); ?></td>
                                                            <td style="width: 30%; text-align: center;">
                                                                <div class="form-check d-flex justify-content-center">
                                                                    <input class="form-check-input dispatch-checkbox" type="checkbox" 
                                                                           name="dispatch_values[<?= htmlspecialchars($item['item_key']); ?>]" 
                                                                           value="checked"
                                                                           <?= $item['item_value'] === 'checked' ? 'checked' : ''; ?>>
                                                                </div>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                    <div class="alert alert-info small mb-3">
                                        <i class="fas fa-info-circle me-2"></i>
                                        <strong>Dispatch Guidelines:</strong> Verify all dispatch details. Ensure release order is received before loading. Count and photograph the load for documentation.
                                    </div>
                                <?php endif; ?>

                                <?php if ($stepRow['step_key'] === 'delivering'): ?>
                                    <!-- Delivery Information -->
                                    <?php
                                    $deliveryStmt = $conn->prepare("
                                        SELECT recipient_name, recipient_phone, delivery_datetime, customer_notes, photo_path
                                        FROM manufacturing_delivery_info
                                        WHERE manufacturing_order_id = ?
                                    ");
                                    $deliveryStmt->bind_param("i", $orderId);
                                    $deliveryStmt->execute();
                                    $deliveryResult = $deliveryStmt->get_result();
                                    $deliveryInfo = $deliveryResult->fetch_assoc();
                                    $deliveryStmt->close();
                                    
                                    // Parse datetime if exists
                                    $deliveryDate = '';
                                    $deliveryTime = '';
                                    if (!empty($deliveryInfo['delivery_datetime'])) {
                                        $dt = new DateTime($deliveryInfo['delivery_datetime']);
                                        $deliveryDate = $dt->format('Y-m-d');
                                        $deliveryTime = $dt->format('H:i');
                                    }
                                    ?>
                                    <div class="mb-3" style="direction: rtl; text-align: right;">
                                        <h6 class="mb-3" style="text-align: center;">التسليم (Delivery - Driver Responsibility)</h6>
                                        
                                        <div class="row g-3 mb-3">
                                            <div class="col-md-6">
                                                <label class="form-label">اسم المستلم (Recipient Name)</label>
                                                <input type="text" class="form-control form-control-sm" 
                                                       name="recipient_name" 
                                                       value="<?= htmlspecialchars($deliveryInfo['recipient_name'] ?? ''); ?>"
                                                       placeholder="__________">
                                            </div>
                                            
                                            <div class="col-md-6">
                                                <label class="form-label">رقم تليفون (Phone Number)</label>
                                                <input type="tel" class="form-control form-control-sm" 
                                                       name="recipient_phone" 
                                                       value="<?= htmlspecialchars($deliveryInfo['recipient_phone'] ?? ''); ?>"
                                                       placeholder="__________">
                                            </div>
                                            
                                            <div class="col-md-6">
                                                <label class="form-label">تاريخ التسليم (Delivery Date)</label>
                                                <input type="date" class="form-control form-control-sm" 
                                                       name="delivery_date" 
                                                       value="<?= htmlspecialchars($deliveryDate); ?>">
                                            </div>
                                            
                                            <div class="col-md-6">
                                                <label class="form-label">وقت التسليم (Delivery Time)</label>
                                                <input type="time" class="form-control form-control-sm" 
                                                       name="delivery_time" 
                                                       value="<?= htmlspecialchars($deliveryTime); ?>">
                                            </div>
                                            
                                            <div class="col-md-12">
                                                <label class="form-label">ملاحظات العميل (Customer Notes)</label>
                                                <textarea class="form-control form-control-sm" rows="3"
                                                          name="customer_notes" 
                                                          placeholder="إن وجدت..."><?= htmlspecialchars($deliveryInfo['customer_notes'] ?? ''); ?></textarea>
                                            </div>
                                            
                                            <div class="col-md-12">
                                                <label class="form-label">إضافة صورة (Add Photo)</label>
                                                <input type="file" class="form-control form-control-sm" 
                                                       name="delivery_photo" 
                                                       accept="image/*">
                                                <?php if (!empty($deliveryInfo['photo_path'])): ?>
                                                    <div class="mt-2">
                                                        <small class="text-muted">Existing photo:</small>
                                                        <a href="<?= htmlspecialchars($deliveryInfo['photo_path']); ?>" target="_blank" class="btn btn-sm btn-outline-primary ms-2">
                                                            <i class="fas fa-image"></i> View Photo
                                                        </a>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="alert alert-info small mb-3">
                                        <i class="fas fa-info-circle me-2"></i>
                                        <strong>Delivery Guidelines:</strong> Record recipient details accurately. Note exact delivery date and time. Capture customer feedback and attach delivery photo for documentation.
                                    </div>
                                <?php endif; ?>
                            
                                <input type="hidden" name="order_id" value="<?= $order['id']; ?>">
                                <input type="hidden" name="step_key" value="<?= htmlspecialchars($stepRow['step_key']); ?>">
                                <div class="row g-3 mb-3">
                                    <div class="col-md-4">
                                        <label class="form-label">Step status</label>
                                        <?php
                                        // Check if previous step is completed
                                        $stepDefinitions = manufacturing_get_step_definitions();
                                        $stepKeys = array_keys($stepDefinitions);
                                        $currentStepIndex = array_search($stepRow['step_key'], $stepKeys);
                                        $canProgress = true;
                                        $blockMessage = '';
                                        
                                        if ($currentStepIndex > 0) {
                                            $previousStepKey = $stepKeys[$currentStepIndex - 1];
                                            $prevCheckStmt = $conn->prepare("SELECT status FROM manufacturing_order_steps WHERE manufacturing_order_id = ? AND step_key = ?");
                                            $prevCheckStmt->bind_param("is", $orderId, $previousStepKey);
                                            $prevCheckStmt->execute();
                                            $prevCheckResult = $prevCheckStmt->get_result();
                                            $prevCheckStep = $prevCheckResult->fetch_assoc();
                                            $prevCheckStmt->close();
                                            
                                            if ($prevCheckStep && $prevCheckStep['status'] !== 'completed') {
                                                $canProgress = false;
                                                $blockMessage = 'Previous step "' . manufacturing_get_step_label($previousStepKey) . '" must be completed first.';
                                            }
                                        }
                                        ?>
                                        <select class="form-select" name="status" <?= !$canProgress && $stepRow['status'] === 'pending' ? 'disabled' : ''; ?>>
                                            <?php foreach (['pending', 'in_progress', 'completed'] as $statusOption): ?>
                                                <?php
                                                // Disable in_progress and completed if can't progress
                                                $isDisabled = !$canProgress && $stepRow['status'] === 'pending' && ($statusOption === 'in_progress' || $statusOption === 'completed');
                                                ?>
                                                <option value="<?= $statusOption; ?>" 
                                                    <?= $stepRow['status'] === $statusOption ? 'selected' : ''; ?>
                                                    <?= $isDisabled ? 'disabled' : ''; ?>>
                                                    <?= ucfirst(str_replace('_', ' ', $statusOption)); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <?php if (!$canProgress && $stepRow['status'] === 'pending'): ?>
                                            <div class="form-text text-warning">
                                                <i class="fas fa-lock me-1"></i><?= $blockMessage; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-md-8">
                                        <label class="form-label">Notes for Excel/PDF handoff</label>
                                        <textarea name="notes" class="form-control" rows="3" placeholder="Detail what should travel to the next team."><?= e($stepRow['notes']); ?></textarea>
                                    </div>
                                </div>
                                <div class="d-flex gap-2">
                                    <button type="submit" name="action" value="update" class="btn btn-primary btn-sm">
                                        <i class="fas fa-file-export me-1"></i> Save step &amp; generate docs
                                    </button>
                                    <button type="submit" name="action" value="regenerate" class="btn btn-outline-secondary btn-sm">
                                        <i class="fas fa-sync-alt me-1"></i> Regenerate Excel/PDF
                                    </button>
                                </div>
                            </form>
                            <div class="mt-4 border rounded p-3 bg-light">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span class="fw-semibold">Latest handoff files</span>
                                    <span class="text-muted small"><?= count($documents); ?> files</span>
                                </div>
                                <?php if (!empty($documents)): ?>
                                    <ul class="list-group list-group-flush">
                                        <?php foreach (array_slice($documents, 0, 4) as $document): ?>
                                            <li class="list-group-item px-0 py-2 d-flex justify-content-between align-items-center border-0">
                                                <div>
                                                    <span class="badge <?= $document['type'] === 'pdf' ? 'bg-danger' : 'bg-success'; ?> me-2">
                                                        <?= strtoupper($document['type']); ?>
                                                    </span>
                                                    <?= htmlspecialchars($document['file_name']); ?>
                                                    <div class="text-muted small">
                                                        <?= formatDateTime($document['generated_at']); ?>
                                                        <?php if (!empty($document['generated_by_name'])): ?>
                                                            by <?= htmlspecialchars($document['generated_by_name']); ?>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <a href="<?= manufacturing_get_document_url($document['file_path']); ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                                    <i class="fas fa-download"></i>
                                                </a>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php else: ?>
                                    <p class="text-muted small mb-0">No Excel/PDF exports yet.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>

<script>
    function confirmDelete(id, orderNumber) {
        if (confirm('Are you sure you want to delete manufacturing order #' + orderNumber + '? This will also delete all steps, and documents associated with this order. This action cannot be undone.')) {
            window.location.href = 'delete.php?id=' + id;
        }
    }
</script>

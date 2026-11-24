<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once __DIR__ . '/deletion_approval.php';
require_once '../../includes/formula_access.php';
require_once 'lib.php';

if (($_SESSION['login_region'] ?? 'factory') !== 'factory' || !hasPermission('manufacturing.view')) {
    http_response_code(403);
    exit('Manufacturing access required.');
}

/**
 * Format a quantity+unit, appending the other unit in parentheses:
 *   ml → also show L,  L → also show ml,  kg → also show g,  g → also show kg.
 * $decimals controls rounding of the primary value.
 */
function fmt_qty(float $value, string $unit, int $decimals = 2): string {
    $unit = trim($unit);
    $primary = number_format($value, $decimals) . ($unit ? ' ' . $unit : '');
    switch (strtolower($unit)) {
        case 'ml':
            $alt = rtrim(rtrim(number_format($value / 1000, 3), '0'), '.') . ' L';
            return $primary . ' (' . $alt . ')';
        case 'l':
            $alt = number_format($value * 1000, 0) . ' ml';
            return $primary . ' (' . $alt . ')';
        case 'kg':
            $alt = number_format($value * 1000, 0) . ' g';
            return $primary . ' (' . $alt . ')';
        case 'g':
            $alt = rtrim(rtrim(number_format($value / 1000, 3), '0'), '.') . ' kg';
            return $primary . ' (' . $alt . ')';
        default:
            return $primary;
    }
}

$orderId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($orderId <= 0) {
    setAlert('danger', 'Invalid manufacturing order specified.');
    redirect('index.php');
}

$orderStmt = $conn->prepare("
    SELECT mo.*, c.name AS customer_name, f.name AS formula_name, f.description AS formula_description, f.components_json, f.sample_images_json, f.batch_size AS formula_batch_size, f.batch_unit,
           l.name AS location_name, l.address AS location_address, p.name AS product_name, p.sku AS product_sku,
           bs.name AS bottle_size_name, bs.size AS bottle_size_value, bs.unit AS bottle_size_unit, bs.type AS bottle_size_type
    FROM manufacturing_orders mo
    JOIN customers c ON c.id = mo.customer_id
    LEFT JOIN factories customer_factory ON customer_factory.id = c.factory_id
    JOIN manufacturing_formulas f ON f.id = mo.formula_id
    LEFT JOIN locations l ON l.id = mo.location_id
    LEFT JOIN products p ON p.id = mo.product_id
    LEFT JOIN bottle_sizes bs ON bs.id = mo.bottle_size_id
    WHERE mo.id = ?
      AND " . getCustomerChannelScopeSql('c', 'customer_factory') . "
    LIMIT 1
");
$orderStmt->bind_param('i', $orderId);
$orderStmt->execute();
$orderResult = $orderStmt->get_result();
$order = $orderResult->fetch_assoc();
$orderStmt->close();

if (!$order || !canAccessCustomer((int)$order['customer_id'])) {
    setAlert('danger', 'Manufacturing order not found.');
    redirect('index.php');
}

$outputCountsStmt = $pdo->prepare("SELECT item_key, item_value FROM manufacturing_packaging_checklist WHERE manufacturing_order_id = ? AND item_key IN ('final_product_count', 'breakage_count')");
$outputCountsStmt->execute([$orderId]);
$outputCounts = $outputCountsStmt->fetchAll(PDO::FETCH_KEY_PAIR);
$usableOutputCount = isset($outputCounts['final_product_count']) && $outputCounts['final_product_count'] !== ''
    ? (float)$outputCounts['final_product_count'] : (float)($order['number_of_bottles'] ?? 0);
$damagedOutputCount = (float)($outputCounts['breakage_count'] ?? 0);
$packagingOutputStmt = $pdo->prepare('SELECT * FROM manufacturing_packaging_output WHERE manufacturing_order_id = ?');
$packagingOutputStmt->execute([$orderId]);
$packagingOutput = $packagingOutputStmt->fetch(PDO::FETCH_ASSOC);
if ($packagingOutput) $usableOutputCount = (float)$packagingOutput['finished_quantity'];

$formulaComponentsRaw = json_decode($order['components_json'] ?? '[]', true);
if (!is_array($formulaComponentsRaw)) {
    $formulaComponentsRaw = [];
}
$formulaSampleImages = json_decode($order['sample_images_json'] ?? '[]', true);
if (!is_array($formulaSampleImages)) {
    $formulaSampleImages = [];
}
// Scaled quantities used for sourcing; raw quantities shown in the formula card
$formulaComponents = manufacturing_recalculate_components($order, $formulaComponentsRaw);

$canViewFormula = hasPermission('manufacturing.formula.view_all');
$canViewComponentName = canViewManufacturingMaterialNames();

$compProductIds = [];
foreach ($formulaComponents as $fc) {
    if (!empty($fc['product_id'])) {
        $compProductIds[] = (int)$fc['product_id'];
    }
}
$formulaSkus = [];
if (!empty($compProductIds)) {
    $skuResult = $conn->query("SELECT id, sku FROM products WHERE id IN (" . implode(',', $compProductIds) . ")");
    while ($r = $skuResult->fetch_assoc()) {
        $formulaSkus[$r['id']] = $r['sku'];
    }
}

// Refresh both sources together; completed/consumed orders are protected by the helper.
manufacturing_sync_sourcing($orderId);
$packagingInfoStmt = $pdo->prepare("SELECT po.name FROM manufacturing_orders mo
    JOIN packaging_options po ON po.id = mo.packaging_option_id WHERE mo.id = ?");
$packagingInfoStmt->execute([$orderId]);
$packagingOptionName = $packagingInfoStmt->fetchColumn() ?: '';

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
            ['key' => 'breakage_recorded', 'text' => 'أي كسر تم تسجيله', 'type' => 'checkbox'],
            ['key' => 'breakage_count', 'text' => 'الكسر: العدد', 'type' => 'number'],
            ['key' => 'breakage_notes', 'text' => 'الكسر: ملاحظات', 'type' => 'text'],
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

$ensurePartialCarton = $conn->prepare("INSERT IGNORE INTO manufacturing_packaging_checklist
    (manufacturing_order_id, section_name, item_key, item_text, item_type, item_order)
    VALUES (?, 'after', 'last_carton_units', 'كسور في اخر كرتونة', 'number', 12)");
$ensurePartialCarton->bind_param('i', $orderId);
$ensurePartialCarton->execute();
$ensurePartialCarton->close();

$ensurePrimaryUsed = $pdo->prepare("INSERT IGNORE INTO manufacturing_packaging_checklist
    (manufacturing_order_id, section_name, item_key, item_text, item_type, item_value, item_order)
    VALUES (?, 'after', 'primary_product_used', 'منتج اولي مستخدم', 'number', ?, 13)");
$ensurePrimaryUsed->execute([$orderId, (int)$order['primary_stock_quantity']]);

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
            ['key' => 'units_per_carton', 'text' => 'وحدات/كرتونة', 'type' => 'number'],
        ],
        'before_loading' => [
            ['key' => 'release_order_received', 'text' => 'تم استلام إذن صرف/أمر تسليم', 'type' => 'checkbox'],
            ['key' => 'quantity_counted', 'text' => 'تم عدّ الكمية قبل التحميل', 'type' => 'checkbox'],
            ['key' => 'load_photographed', 'text' => 'تم تصوير الحمولة (صور مرفقة)', 'type' => 'image']
        ]
    ];
    
    $insertDispatchStmt = $conn->prepare("INSERT INTO manufacturing_dispatch_checklist (manufacturing_order_id, section_name, item_key, item_text, item_type, item_value, item_order) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $itemOrder = 0;
    foreach ($dispatchChecklist as $sectionName => $items) {
        foreach ($items as $item) {
            $itemValue = $item['value'] ?? null;
            $insertDispatchStmt->bind_param("isssssi", $orderId, $sectionName, $item['key'], $item['text'], $item['type'], $itemValue, $itemOrder);
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
    manufacturingValidateCsrf();
    if (isset($_POST['unlock_formula_password'])) {
        if (unlockFormulas($_POST['unlock_formula_password'])) {
            setAlert('success', 'Formula view unlocked.');
        } else {
            setAlert('danger', 'Incorrect password.');
        }
        header("Location: order.php?id=$orderId");
        exit;
    }

    if (($_POST['action'] ?? '') === 'save_material_waste') {
        try {
            $quantities = $_POST['material_waste'] ?? [];
            if (!is_array($quantities)) throw new DomainException('Invalid material waste entries.');
            $pdo->beginTransaction();
            manufacturing_save_material_waste($pdo, $orderId, $quantities);
            $pdo->commit();
            logActivity('Recorded material waste', ['order_id' => $orderId], 'update', 'manufacturing_order', $orderId);
            setAlert('success', 'Material waste saved. It will be added to the waste warehouse when an authorized user completes this order.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            setAlert('danger', $e->getMessage());
        }
        redirect('order.php?id=' . $orderId);
    }

    // Orders whose steps are all completed but whose status never became completed.
    if (($_POST['action'] ?? '') === 'complete_order') {
        if (!hasPermission('manufacturing.orders.complete')) {
            setAlert('danger', 'You do not have permission to complete this order.');
            redirect('order.php?id=' . $orderId);
        }
        $inventoryId = (int)($_POST['finished_goods_inventory_id'] ?? 0);
        $options = manufacturing_get_finished_goods_inventories($conn, $order);
        $finishedGoodsLog = null;
        try {
            if (!isset($options['inventories'][$inventoryId])) {
                throw new DomainException('Choose the warehouse that receives the finished product.');
            }
            $pdo->beginTransaction();
            $lock = $pdo->prepare('SELECT status FROM manufacturing_orders WHERE id = ? FOR UPDATE');
            $lock->execute([$orderId]);
            if (in_array($lock->fetchColumn(), ['completed', 'cancelled'], true)) {
                throw new DomainException('This order is already completed or cancelled.');
            }
            // Delivering may still be open: the order can be completed once Dispatch Prep is done.
            $open = $pdo->prepare("SELECT COUNT(*) FROM manufacturing_order_steps WHERE manufacturing_order_id = ? AND step_key <> 'delivering' AND status <> 'completed'");
            $open->execute([$orderId]);
            if ((int)$open->fetchColumn() > 0) {
                throw new DomainException('All steps up to Dispatch Prep must be completed first.');
            }
            $pdo->prepare("UPDATE manufacturing_orders SET status = 'completed', updated_at = NOW() WHERE id = ?")->execute([$orderId]);
            $finishedGoodsLog = manufacturing_add_finished_goods($pdo, $order, $inventoryId);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            setAlert('danger', 'Cannot complete the order: ' . $e->getMessage());
            redirect('order.php?id=' . $orderId);
        }
        manufacturing_log_finished_goods($finishedGoodsLog, $order);
        logActivity("Completed manufacturing order {$order['order_number']}", ['order_id' => $orderId, 'inventory_id' => $inventoryId], 'update', 'manufacturing_order', $orderId);
        setAlert('success', 'Order completed and the finished product was added to stock.');
        redirect('order.php?id=' . $orderId);
    }

    $stepKey = $_POST['step_key'] ?? '';
    $action = $_POST['action'] ?? 'update';
    $statusInput = $_POST['status'] ?? '';
    $notesInput = trim($_POST['notes'] ?? '');

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

    if (!canAccessManufacturingStep($stepKey)) {
        setAlert('danger', 'You do not have permission to update this step.');
        redirect('order.php?id=' . $orderId);
    }

    if ($stepRow['status'] === 'completed') {
        setAlert('danger', 'This manufacturing step is completed and locked. Completed steps cannot be changed.');
        redirect('order.php?id=' . $orderId);
    }

    if ($order['status'] === 'cancelled') {
        setAlert('danger', 'This manufacturing order is cancelled. Its steps cannot be changed.');
        redirect('order.php?id=' . $orderId);
    }

    // Will this save finish the last open step? Then the finished product goes into stock,
    // so check the receiving warehouse before anything is written.
    $finishedGoodsInventoryId = 0;
    $finishedGoodsQuantity = $usableOutputCount;
    $completesOrder = false;
    if ($action !== 'regenerate' && trim($_POST['status'] ?? '') === 'completed' && $order['status'] !== 'completed') {
        $openStepsStmt = $pdo->prepare("SELECT COUNT(*) FROM manufacturing_order_steps WHERE manufacturing_order_id = ? AND id <> ? AND status <> 'completed'");
        $openStepsStmt->execute([$orderId, $stepRow['id']]);
        $completesOrder = (int)$openStepsStmt->fetchColumn() === 0 && hasPermission('manufacturing.orders.complete');
    }
    if ($completesOrder) {
        if (empty($order['product_id']) || $finishedGoodsQuantity + $damagedOutputCount <= 0) {
            setAlert('danger', 'Cannot complete the order: it needs a final product and a number of bottles to add to stock.');
            redirect('order.php?id=' . $orderId);
        }
        $finishedGoodsOptions = manufacturing_get_finished_goods_inventories($conn, $order);
        $finishedGoodsInventoryId = (int)($_POST['finished_goods_inventory_id'] ?? 0);
        if (!isset($finishedGoodsOptions['inventories'][$finishedGoodsInventoryId])) {
            setAlert('danger', 'Cannot complete the order: choose the warehouse that receives the finished product.');
            redirect('order.php?id=' . $orderId);
        }
    }

    // Store component notes for sourcing step (always save notes regardless of status)
    if ($stepKey === 'sourcing') {
        $componentNotes = $_POST['component_notes'] ?? [];
        if (is_array($componentNotes)) {
            foreach ($componentNotes as $compId => $compNote) {
                // Look up the component_name and product_id for this row so we can update all
                // duplicate rows (same product/name) in one go.
                $lookupStmt = $conn->prepare("SELECT component_name, product_id, unit FROM manufacturing_sourcing_components WHERE id = ? AND manufacturing_order_id = ?");
                $lookupStmt->bind_param("ii", $compId, $orderId);
                $lookupStmt->execute();
                $lookupRow = $lookupStmt->get_result()->fetch_assoc();
                $lookupStmt->close();

                if ($lookupRow) {
                    if ($lookupRow['product_id']) {
                        $updateNoteStmt = $conn->prepare("UPDATE manufacturing_sourcing_components SET notes = ? WHERE manufacturing_order_id = ? AND product_id = ? AND unit = ?");
                        $updateNoteStmt->bind_param("siis", $compNote, $orderId, $lookupRow['product_id'], $lookupRow['unit']);
                    } else {
                        $updateNoteStmt = $conn->prepare("UPDATE manufacturing_sourcing_components SET notes = ? WHERE manufacturing_order_id = ? AND component_name = ? AND unit = ?");
                        $updateNoteStmt->bind_param("siss", $compNote, $orderId, $lookupRow['component_name'], $lookupRow['unit']);
                    }
                    $updateNoteStmt->execute();
                    $updateNoteStmt->close();
                }
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

        if (!empty($_FILES['preparation_images']) && is_array($_FILES['preparation_images']['name'])) {
            $targetDir = manufacturing_get_storage_path_for_step($order['order_number'], 'preparation_images');
            $relativeDir = 'assets/uploads/manufacturing/' . manufacturing_slugify($order['order_number']) . '/preparation_images';
            $insertImageStmt = $conn->prepare("INSERT INTO manufacturing_preparation_images (manufacturing_order_id, manufacturing_order_step_id, file_path, original_name, uploaded_by) VALUES (?, ?, ?, ?, ?)");
            $stepId = (int)$stepRow['id'];
            foreach ($_FILES['preparation_images']['name'] as $idx => $originalName) {
                if ($originalName === '') {
                    continue;
                }
                $uploaded = manufacturing_store_uploaded_image(
                    manufacturing_normalize_uploaded_file($_FILES['preparation_images'], $idx),
                    $targetDir,
                    $relativeDir,
                    'preparation_' . $orderId
                );
                if ($uploaded) {
                    $uploadedBy = $_SESSION['user_id'] ?? null;
                    $filePath = $uploaded['path'];
                    $originalFileName = $uploaded['original_name'];
                    $insertImageStmt->bind_param('iissi', $orderId, $stepId, $filePath, $originalFileName, $uploadedBy);
                    $insertImageStmt->execute();
                }
            }
            $insertImageStmt->close();
        }
    }

    // Store quality checklist status and notes for quality step
    if ($stepKey === 'quality') {
        $qualityStatuses = $_POST['quality_status'] ?? [];
        $qualityNotes = $_POST['quality_notes'] ?? [];
        
        if (is_array($qualityStatuses)) {
            $updateQualityStmt = $conn->prepare("UPDATE manufacturing_quality_checklist SET status = ?, notes = ? WHERE id = ? AND manufacturing_order_id = ?");
            foreach ($qualityStatuses as $itemId => $status) {
                $note = $qualityNotes[$itemId] ?? '';
                $itemIdInt = (int)$itemId;
                $updateQualityStmt->bind_param("ssii", $status, $note, $itemIdInt, $orderId);
                $updateQualityStmt->execute();
            }
            $updateQualityStmt->close();
        }
    }

    // Store packaging checklist values for packaging step
    $packagingImagesUploaded = 0;
    if ($stepKey === 'packaging') {
        $packagingValues = $_POST['packaging_values'] ?? [];
        
        try {
            if (!is_array($packagingValues)) throw new InvalidArgumentException('Invalid packaging values.');
            manufacturing_validate_packaging_counts($packagingValues);
        } catch (InvalidArgumentException $ex) {
            setAlert('danger', $ex->getMessage());
            redirect('order.php?id=' . $orderId);
        }

        if (!empty($_FILES['packaging_images']) && is_array($_FILES['packaging_images']['name'])
            && manufacturing_table_exists($conn, 'manufacturing_packaging_images')) {
            $targetDir = manufacturing_get_storage_path_for_step($order['order_number'], 'packaging_images');
            $relativeDir = 'assets/uploads/manufacturing/' . manufacturing_slugify($order['order_number']) . '/packaging_images';
            $insertImageStmt = $conn->prepare("INSERT INTO manufacturing_packaging_images (manufacturing_order_id, manufacturing_order_step_id, file_path, original_name, uploaded_by) VALUES (?, ?, ?, ?, ?)");
            $stepId = (int)$stepRow['id'];
            foreach ($_FILES['packaging_images']['name'] as $idx => $originalName) {
                if ($originalName === '') {
                    continue;
                }
                $uploaded = manufacturing_store_uploaded_image(
                    manufacturing_normalize_uploaded_file($_FILES['packaging_images'], $idx),
                    $targetDir,
                    $relativeDir,
                    'packaging_' . $orderId
                );
                if ($uploaded) {
                    $uploadedBy = $_SESSION['user_id'] ?? null;
                    $filePath = $uploaded['path'];
                    $originalFileName = $uploaded['original_name'];
                    $insertImageStmt->bind_param('iissi', $orderId, $stepId, $filePath, $originalFileName, $uploadedBy);
                    $insertImageStmt->execute();
                    $packagingImagesUploaded++;
                }
            }
            $insertImageStmt->close();
        }
    }

    // Store dispatch checklist values for dispatch step
    if ($stepKey === 'dispatch') {
        $dispatchValues = $_POST['dispatch_values'] ?? [];
        
        if (is_array($dispatchValues)) {
            foreach ($dispatchValues as $itemKey => $value) {
                if (array_key_exists($itemKey, manufacturing_packaging_count_labels())) continue;
                // Skip if it's an image item, as it's handled separately via $_FILES
                $checkTypeStmt = $conn->prepare("SELECT item_type FROM manufacturing_dispatch_checklist WHERE item_key = ? AND manufacturing_order_id = ? LIMIT 1");
                $checkTypeStmt->bind_param("si", $itemKey, $orderId);
                $checkTypeStmt->execute();
                $itemType = $checkTypeStmt->get_result()->fetch_assoc()['item_type'] ?? '';
                $checkTypeStmt->close();
                
                if ($itemType === 'image') continue;

                if ($itemKey === 'custom_material_name' && trim((string)$value) === '') {
                    $value = 'مطبوعات';
                }

                $updateDispatchStmt = $conn->prepare("UPDATE manufacturing_dispatch_checklist SET item_value = ? WHERE item_key = ? AND manufacturing_order_id = ?");
                $updateDispatchStmt->bind_param("ssi", $value, $itemKey, $orderId);
                $updateDispatchStmt->execute();
                $updateDispatchStmt->close();
            }
        }

        // Remove individual dispatch photos requested for deletion.
        $removedPhotos = $_POST['dispatch_photos_remove'] ?? [];
        if (is_array($removedPhotos)) {
            foreach ($removedPhotos as $itemKey => $pathsToRemove) {
                if (!is_array($pathsToRemove) || empty($pathsToRemove)) {
                    continue;
                }
                $existing = manufacturing_decode_dispatch_photos(
                    manufacturing_get_dispatch_item_value($conn, $orderId, $itemKey)
                );
                $kept = [];
                foreach ($existing as $photo) {
                    if (in_array($photo['path'], $pathsToRemove, true)) {
                        $absolute = __DIR__ . '/../../' . $photo['path'];
                        if (is_file($absolute)) {
                            @unlink($absolute);
                        }
                        continue;
                    }
                    $kept[] = $photo;
                }
                $encoded = $kept ? json_encode($kept, JSON_UNESCAPED_UNICODE) : null;
                $removeStmt = $conn->prepare("UPDATE manufacturing_dispatch_checklist SET item_value = ? WHERE item_key = ? AND manufacturing_order_id = ?");
                $removeStmt->bind_param("ssi", $encoded, $itemKey, $orderId);
                $removeStmt->execute();
                $removeStmt->close();
            }
        }

        // Handle image uploads for dispatch checklist items (multiple per item).
        if (isset($_FILES['dispatch_photos']) && is_array($_FILES['dispatch_photos']['name'])) {
            $targetDir = manufacturing_get_storage_path_for_type('dispatch_photos');
            $relativeDir = 'assets/uploads/manufacturing/dispatch_photos';
            foreach ($_FILES['dispatch_photos']['name'] as $itemKey => $fileNames) {
                // Each item is a multi-file input, so the per-item entry is itself an array.
                $fileNames = (array)$fileNames;
                $uploaded = manufacturing_decode_dispatch_photos(
                    manufacturing_get_dispatch_item_value($conn, $orderId, $itemKey)
                );

                foreach (array_keys($fileNames) as $fileIdx) {
                    $file = [
                        'name'     => $_FILES['dispatch_photos']['name'][$itemKey][$fileIdx] ?? '',
                        'type'     => $_FILES['dispatch_photos']['type'][$itemKey][$fileIdx] ?? '',
                        'tmp_name' => $_FILES['dispatch_photos']['tmp_name'][$itemKey][$fileIdx] ?? '',
                        'error'    => $_FILES['dispatch_photos']['error'][$itemKey][$fileIdx] ?? UPLOAD_ERR_NO_FILE,
                        'size'     => $_FILES['dispatch_photos']['size'][$itemKey][$fileIdx] ?? 0,
                    ];
                    if ($file['name'] === '') {
                        continue;
                    }
                    try {
                        $stored = manufacturing_store_uploaded_image(
                            $file,
                            $targetDir,
                            $relativeDir,
                            'dispatch_' . $orderId . '_' . $itemKey
                        );
                        if ($stored) {
                            $uploaded[] = $stored;
                        }
                    } catch (Exception $e) {
                        setAlert('warning', 'Photo "' . htmlspecialchars($file['name']) . '" was not saved: ' . $e->getMessage());
                    }
                }

                $encoded = $uploaded ? json_encode($uploaded, JSON_UNESCAPED_UNICODE) : null;
                $updateDispatchPhotoStmt = $conn->prepare("UPDATE manufacturing_dispatch_checklist SET item_value = ? WHERE item_key = ? AND manufacturing_order_id = ?");
                $updateDispatchPhotoStmt->bind_param("ssi", $encoded, $itemKey, $orderId);
                $updateDispatchPhotoStmt->execute();
                $updateDispatchPhotoStmt->close();
            }
        }
        
        // Handle material tracking notes from dispatch step
        $packagingNotes = $_POST['packaging_notes'] ?? [];
        if (is_array($packagingNotes)) {
            foreach ($packagingNotes as $itemKey => $note) {
                // We only update the notes from dispatch step, as the values should have been set in packaging
                $updatePackagingStmt = $conn->prepare("UPDATE manufacturing_packaging_checklist SET item_notes = ? WHERE item_key = ? AND manufacturing_order_id = ?");
                $updatePackagingStmt->bind_param("ssi", $note, $itemKey, $orderId);
                $updatePackagingStmt->execute();
                $updatePackagingStmt->close();
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

    // Special validation for quality step when completing
    if ($stepKey === 'quality' && $statusInput === 'completed') {
        // Check if all checklist items are approved (case-insensitive and trimmed)
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
            $statusVal = strtolower(trim($item['status'] ?? ''));
            if ($statusVal !== 'approved') {
                $hasUnapproved = true;
                $unapprovedItems[] = '[' . ($statusVal ?: 'pending') . '] ' . mb_substr($item['item_text'], 0, 40) . '...';
            }
        }
        $qualityCheckStmt->close();
        
        if ($hasUnapproved) {
            setAlert('danger', 'Cannot complete quality step: ' . count($unapprovedItems) . ' items are not approved. Missing: ' . implode(', ', $unapprovedItems));
            redirect('order.php?id=' . $orderId);
        }
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
    $currentUserId = $_SESSION['user_id'] ?? null;
    $hasStepUserColumns = manufacturing_table_has_column($conn, 'manufacturing_order_steps', 'started_by');
    $startedBy = $stepRow['started_by'] ?? null;
    $completedBy = $stepRow['completed_by'] ?? null;
    if ($action !== 'regenerate') {
        if ($statusToSave === 'in_progress' && !$startedAt) {
            $startedAt = date('Y-m-d H:i:s');
            $startedBy = $currentUserId;
        }
        if ($statusToSave === 'completed') {
            if (!$startedAt) {
                $startedAt = date('Y-m-d H:i:s');
                $startedBy = $currentUserId;
            }
            $completedAt = date('Y-m-d H:i:s');
            $completedBy = $currentUserId;
        } elseif ($statusToSave === 'pending') {
            $startedAt = null;
            $completedAt = null;
            $startedBy = null;
            $completedBy = null;
        } else {
            $completedAt = null;
            $completedBy = null;
        }
    }

    try {
        $pdo->beginTransaction();
        $orderLock = $pdo->prepare('SELECT status FROM manufacturing_orders WHERE id = ? FOR UPDATE');
        $orderLock->execute([$orderId]);
        $lockedOrderStatus = $orderLock->fetchColumn();
        // A completed order stays editable only for its Delivering step.
        if ($lockedOrderStatus === 'cancelled' || ($lockedOrderStatus === 'completed' && $stepKey !== 'delivering')) {
            throw new RuntimeException('This order is locked.');
        }
        $currentStepLock = $pdo->prepare('SELECT status FROM manufacturing_order_steps WHERE id = ? FOR UPDATE');
        $currentStepLock->execute([$stepRow['id']]);
        if ($currentStepLock->fetchColumn() === 'completed') throw new RuntimeException('This step is already completed.');

        if ($action !== 'regenerate' && $stepKey === 'sourcing' && $statusToSave === 'completed') {
            manufacturing_deduct_sourcing_stock($pdo, $orderId);
        }
        if ($action !== 'regenerate' && $stepKey === 'receipt') {
            $receiptStatuses = $_POST['receipt_status'] ?? [];
            $receiptNotes = $_POST['receipt_notes'] ?? [];
            if (!is_array($receiptStatuses) || !is_array($receiptNotes)) throw new DomainException('Invalid receipt entries.');
            manufacturing_save_receipt_stock($pdo, $orderId, $receiptStatuses, $receiptNotes);
            if ($statusToSave === 'completed') {
                $unapproved = $pdo->prepare("SELECT COUNT(*) FROM manufacturing_sourcing_components WHERE manufacturing_order_id = ? AND (receipt_status IS NULL OR receipt_status <> 'approved')");
                $unapproved->execute([$orderId]);
                if ((int)$unapproved->fetchColumn() > 0) throw new DomainException('Cannot complete receipt: all materials must be approved.');
            }
        }

        if ($stepKey === 'packaging') {
            $pdo->prepare("UPDATE manufacturing_packaging_checklist SET item_value = '' WHERE manufacturing_order_id = ? AND item_type = 'checkbox'")->execute([$orderId]);
            $saveValue = $pdo->prepare('UPDATE manufacturing_packaging_checklist SET item_value = ? WHERE item_key = ? AND manufacturing_order_id = ?');
            foreach ($packagingValues as $itemKey => $value) {
                if (!is_scalar($value)) throw new InvalidArgumentException('Invalid packaging value.');
                if ($itemKey === 'custom_material_name' && trim((string)$value) === '') $value = 'مطبوعات';
                $saveValue->execute([$value, $itemKey, $orderId]);
            }
            $materialEntries = $_POST['packaging_materials'] ?? [];
            if (!is_array($materialEntries)) throw new InvalidArgumentException('Invalid packaging material quantities.');
            $count = $pdo->prepare("SELECT item_value FROM manufacturing_packaging_checklist WHERE manufacturing_order_id = ? AND item_key = 'final_product_count'");
            $count->execute([$orderId]);
            $finalCount = $count->fetchColumn();
            if ($finalCount !== false && $finalCount !== '') {
                manufacturing_validate_packaging_counts(['final_product_count' => $finalCount]);
                manufacturing_save_packaging_material_entries($pdo, $orderId, (int)$finalCount, $materialEntries);
            }
            if ($action !== 'regenerate' && $statusToSave === 'completed') {
                if ($finalCount === false || $finalCount === '') throw new DomainException('Enter the final product count before completing packaging.');
                manufacturing_validate_packaging_counts(['final_product_count' => $finalCount]);
                $adjustmentNote = manufacturing_reconcile_finished_count($pdo, $orderId, (int)$finalCount);
                if ($adjustmentNote) $notesInput = trim($notesInput . "\n" . $adjustmentNote);
            }
        }

        if ($stepKey === 'dispatch') {
            $materialNotes = $_POST['packaging_material_notes'] ?? [];
            if (!is_array($materialNotes)) throw new InvalidArgumentException('Invalid packaging material notes.');
            manufacturing_save_packaging_material_notes($pdo, $orderId, $materialNotes);
        }


        if ($hasStepUserColumns) {
            $updateStep = $pdo->prepare("
                UPDATE manufacturing_order_steps 
                SET status = ?, notes = ?, started_at = ?, completed_at = ?, started_by = ?, completed_by = ?, last_updated_by = ?, updated_at = NOW() 
                WHERE id = ?
            ");
            $updateStep->execute([
                $statusToSave,
                $notesInput,
                $startedAt,
                $completedAt,
                $startedBy,
                $completedBy,
                $currentUserId,
                $stepRow['id']
            ]);
        } else {
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
        }

        // Read through $pdo: the step update above is not committed yet, so the
        // separate mysqli connection would still see the old step status.
        $orderStepsStmt = $pdo->prepare("SELECT step_key, status FROM manufacturing_order_steps WHERE manufacturing_order_id = ?");
        $orderStepsStmt->execute([$orderId]);
        $stepsForStatus = $orderStepsStmt->fetchAll(PDO::FETCH_ASSOC);

        $overallStatus = manufacturing_determine_order_status_from_steps($stepsForStatus);
        $alreadyCompleted = $lockedOrderStatus === 'completed';
        if ($alreadyCompleted) {
            // Completed after Dispatch Prep: delivering updates never reopen the order.
            $overallStatus = 'completed';
        } elseif ($overallStatus === 'completed' && !$completesOrder) {
            // Never mark completed without stocking the finished product.
            $overallStatus = 'in_progress';
        }
        $updateOrder = $pdo->prepare("UPDATE manufacturing_orders SET status = ?, updated_at = NOW() WHERE id = ?");
        $updateOrder->execute([$overallStatus, $orderId]);

        // Order just became completed: add the finished product to the chosen warehouse, once.
        $finishedGoodsLog = null;
        if ($overallStatus === 'completed' && !$alreadyCompleted) {
            $finishedGoodsLog = manufacturing_add_finished_goods($pdo, $order, $finishedGoodsInventoryId);
        }

        // Auto-sync linked sales order status.
        // Uses the MINIMUM (least advanced) status across ALL manufacturing orders
        // tied to the same sales order, so the order only advances when every MO has.
        $soRow = $pdo->prepare("SELECT sales_order_id FROM manufacturing_orders WHERE id = ?");
        $soRow->execute([$orderId]);
        $soData = $soRow->fetch(PDO::FETCH_ASSOC);

        if (!empty($soData['sales_order_id']) && $action !== 'regenerate') {
            $linkedSalesOrderId = $soData['sales_order_id'];

            $stepToSalesStatus = [
                'sourcing'    => ['in_progress' => 'in-production', 'completed' => 'in-production'],
                'receipt'     => ['in_progress' => 'in-production', 'completed' => 'in-production'],
                'preparation' => ['in_progress' => 'in-production', 'completed' => 'in-production'],
                'quality'     => ['in_progress' => 'in-production', 'completed' => 'in-production'],
                'packaging'   => ['in_progress' => 'in-packing',    'completed' => 'in-packing'],
                'dispatch'    => ['in_progress' => 'in-packing',    'completed' => 'in-packing'],
                'delivering'  => ['in_progress' => 'delivering',    'completed' => 'delivered'],
            ];
            $statusPriority = ['in-production' => 1, 'in-packing' => 2, 'delivering' => 3, 'delivered' => 4];

            // All MOs linked to this sales order
            $allMfgStmt = $pdo->prepare("SELECT id FROM manufacturing_orders WHERE sales_order_id = ?");
            $allMfgStmt->execute([$linkedSalesOrderId]);
            $allMfgIds = $allMfgStmt->fetchAll(PDO::FETCH_COLUMN);

            $minSalesStatus = null;
            foreach ($allMfgIds as $mfgId) {
                // Most advanced non-pending step for this MO
                $stepStmt = $pdo->prepare("
                    SELECT step_key, status FROM manufacturing_order_steps
                    WHERE manufacturing_order_id = ? AND status != 'pending'
                    ORDER BY FIELD(step_key,'sourcing','receipt','preparation','quality','packaging','dispatch','delivering') DESC
                    LIMIT 1
                ");
                $stepStmt->execute([$mfgId]);
                $lastStep = $stepStmt->fetch(PDO::FETCH_ASSOC);

                $mfgSalesStatus = $lastStep
                    ? ($stepToSalesStatus[$lastStep['step_key']][$lastStep['status']] ?? 'in-production')
                    : 'in-production';

                if ($minSalesStatus === null || $statusPriority[$mfgSalesStatus] < $statusPriority[$minSalesStatus]) {
                    $minSalesStatus = $mfgSalesStatus;
                }
            }

            if ($minSalesStatus !== null) {
                $pdo->prepare("UPDATE orders SET status = ? WHERE id = ?")
                    ->execute([$minSalesStatus, $linkedSalesOrderId]);
            }
        }

        $pdo->commit();
    } catch (Exception $ex) {
        $pdo->rollBack();
        setAlert('danger', 'Unable to save the step: ' . $ex->getMessage());
        redirect('order.php?id=' . $orderId);
    }

    manufacturing_log_finished_goods($finishedGoodsLog, $order);

    if ($stepKey === 'packaging') {
        $refresh = $pdo->prepare('SELECT number_of_bottles, batch_size, notes FROM manufacturing_orders WHERE id = ?');
        $refresh->execute([$orderId]);
        $order = array_merge($order, $refresh->fetch(PDO::FETCH_ASSOC));
        $formulaComponents = manufacturing_recalculate_components($order, $formulaComponentsRaw);
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
        'packaging_images_uploaded' => $packagingImagesUploaded,
    ], 'update', 'manufacturing_order', $orderId);

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
$stepPeople = manufacturing_get_step_people($conn, array_column($steps, 'id'));
$auditNotes = manufacturing_detect_order_audit_notes($conn, $order, $steps);

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
$isCompletedOrder = $order['status'] === 'completed';
?>

<style>
    .cursor-pointer { cursor: pointer; }
    .transition-icon { transition: transform 0.2s ease; }
    details[open] .transition-icon { transform: rotate(90deg); }
    details summary::-webkit-details-marker { display: none; }
</style>

<div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-3">
    <div>
        <h2>Manufacturing Order <?= htmlspecialchars($order['order_number']); ?></h2>
        <p class="text-muted mb-0">Provider: <?= htmlspecialchars($order['customer_name']); ?> | Product: <?= htmlspecialchars($order['product_name'] ?? 'N/A'); ?> | Formula: <?= canViewManufacturingFormula() ? htmlspecialchars($order['formula_name']) : 'Protected formula'; ?></p>
    </div>
    <div class="d-flex gap-2">
        <span class="badge <?= $orderBadge['class']; ?> text-uppercase">
            <?= $orderBadge['label']; ?>
        </span>
        <?= activityLogLink('manufacturing_order', $orderId); ?>
        <?php if (!$isCompletedOrder && hasPermission('manufacturing.edit') && canViewManufacturingTechnicalDetails()): ?>
            <a href="edit.php?id=<?= $orderId; ?>" class="btn btn-outline-primary">
                <i class="fas fa-edit me-1"></i> Edit
            </a>
        <?php endif; ?>
        <?php if (canRequestManufacturingDeletion()): ?>
            <button type="button" class="btn btn-danger" 
                    onclick="confirmDelete(<?= $orderId; ?>, '<?= htmlspecialchars($order['order_number']); ?>')">
                <i class="fas fa-trash me-1"></i> Request Deletion
            </button>
        <?php endif; ?>
        <?php if ($progressPercent === 100 && hasPermission('manufacturing.view_report') && canViewManufacturingTechnicalDetails()): ?>
            <a href="view_report.php?id=<?= $orderId; ?>" class="btn btn-success" target="_blank">
                <i class="fas fa-file-pdf me-1"></i> Full Report
            </a>
        <?php endif; ?>
        <a href="index.php" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left me-1"></i> Back to dashboard
        </a>
    </div>
</div>

<?php
// Ready to complete once every step up to Dispatch Prep is done; Delivering can follow later.
$productionStepsDone = !empty($steps) && isset($stepsByKey['dispatch'])
    && count(array_filter($steps, fn($s) => $s['step_key'] !== 'delivering' && $s['status'] !== 'completed')) === 0;
?>
<?php if ($productionStepsDone && !in_array($order['status'], ['completed', 'cancelled'], true)
          && hasPermission('manufacturing.orders.complete')): ?>
    <?php $finishedGoodsOptions = manufacturing_get_finished_goods_inventories($conn, $order); ?>
    <div class="alert alert-warning">
        <form method="post" class="d-flex flex-wrap align-items-center gap-2 mb-0">
<input type="hidden" name="manufacturing_csrf" value="<?= htmlspecialchars(manufacturingCsrfToken()); ?>">
            <input type="hidden" name="action" value="complete_order">
            <span><strong>Dispatch Prep is completed.</strong>
                You can complete the order now (Delivering can be finished afterwards).
                Complete it to add <?= (int)$usableOutputCount; ?> × <?= e($order['product_name'] ?? '—'); ?> to stock:</span>
            <?php if (empty($finishedGoodsOptions['inventories'])): ?>
                <span class="text-danger">no warehouse at <?= e($order['location_name'] ?? 'this location'); ?> can receive this product.</span>
            <?php else: ?>
                <select class="form-select form-select-sm w-auto" name="finished_goods_inventory_id" required>
                    <option value="">Select warehouse</option>
                    <?php foreach ($finishedGoodsOptions['inventories'] as $invId => $invName): ?>
                        <option value="<?= (int)$invId; ?>" <?= $invId === $finishedGoodsOptions['default_id'] ? 'selected' : ''; ?>><?= e($invName); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-success btn-sm"><i class="fas fa-check me-1"></i> Complete order</button>
            <?php endif; ?>
        </form>
    </div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header">Order snapshot</div>
            <div class="card-body">
                <p class="mb-1"><strong>Final Product:</strong> <?= htmlspecialchars($order['product_name'] ?? 'Not set'); ?> <?= $order['product_sku'] ? '(' . htmlspecialchars($order['product_sku']) . ')' : ''; ?></p>
                <p class="mb-1"><strong>Priority:</strong> <?= ucfirst(htmlspecialchars($order['priority'])); ?></p>
                <p class="mb-1"><strong>Location:</strong> <?= $order['location_name'] ? htmlspecialchars($order['location_name']) . ' - ' . htmlspecialchars($order['location_address'])  : '<span class="text-muted">Not set</span>'; ?></p>
                <p class="mb-1"><strong>Due Date:</strong> <?= $order['due_date'] ? htmlspecialchars($order['due_date']) : '<span class="text-muted">Not set</span>'; ?></p>
                <?php if (canViewManufacturingTechnicalDetails()): ?>
                <?php
                $batchSizeVal  = (float)$order['batch_size'];
                $batchUnit     = $order['bottle_size_unit'] ?? '';
                $batchDisplay  = fmt_qty($batchSizeVal, $batchUnit);
                ?>
                <p class="mb-1"><strong>Batch size:</strong> <?= $batchDisplay; ?></p>
                <?php if (!empty($order['number_of_bottles'])): ?>
                <p class="mb-1"><strong>Number of Bottles:</strong> <?= (int)$order['number_of_bottles']; ?></p>
                <?php endif; ?>
                <?php if (!empty($order['finished_goods_added_at'])): ?>
                    <?php
                    $fgInvStmt = $conn->prepare('SELECT name FROM inventories WHERE id = ?');
                    $fgInvStmt->bind_param('i', $order['inventory_id']);
                    $fgInvStmt->execute();
                    $fgInvName = $fgInvStmt->get_result()->fetch_column() ?: '—';
                    $fgInvStmt->close();
                    ?>
                    <p class="mb-1 text-success"><strong>Added to stock:</strong>
                        <?= rtrim(rtrim(number_format((float)$order['finished_goods_quantity'], 2, '.', ''), '0'), '.'); ?>
                        into <?= e($fgInvName); ?> on <?= date('d/m/Y H:i', strtotime($order['finished_goods_added_at'])); ?></p>
                    <?php if (($order['finished_goods_waste_quantity'] ?? 0) > 0): ?>
                        <p class="mb-1 text-warning"><strong>Production breakage:</strong>
                            <?= (int)$order['finished_goods_waste_quantity']; ?> damaged units recorded (الكسر: العدد); material waste is recorded separately.</p>
                    <?php endif; ?>
                <?php endif; ?>
                <p class="mb-1"><strong>Bottle Size:</strong>
                    <?php if ($order['bottle_size_name']): ?>
                        <?php
                        $bsVal  = (float)$order['bottle_size_value'];
                        $bsUnit = $order['bottle_size_unit'];
                        $bsDisplay = fmt_qty($bsVal, $bsUnit, 3);
                        ?>
                        <?= htmlspecialchars($order['bottle_size_name']); ?> — <?= $bsDisplay; ?>
                        <span class="badge <?= $order['bottle_size_type'] === 'liquid' ? 'bg-info' : 'bg-warning text-dark'; ?> ms-1">
                            <?= ucfirst($order['bottle_size_type']); ?>
                        </span>
                    <?php else: ?>
                        <span class="text-muted">Not set</span>
                    <?php endif; ?>
                </p>
                <p class="mb-1"><strong>Notes:</strong><br><?= nl2br(htmlspecialchars($order['notes'] ?? 'No notes provided.')); ?></p>
                <?php endif; ?>
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
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Formula components</span>
                <?php if (isAdminUser()): ?>
                    <a href="formula_password.php" class="btn btn-sm btn-outline-secondary">Reset Formula Password</a>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if (!$canViewFormula): ?>
                    <p class="text-muted small mb-0">You do not have permission to view the entire formula details.</p>
                <?php elseif (!areFormulasUnlocked()): ?>
                    <p class="text-muted small mb-3">Formula is locked. Enter password to view.</p>
                    <form method="post">
<input type="hidden" name="manufacturing_csrf" value="<?= htmlspecialchars(manufacturingCsrfToken()); ?>">
                        <div class="input-group">
                            <input type="password" name="unlock_formula_password" class="form-control" placeholder="Password" required>
                            <button class="btn btn-primary" type="submit">Unlock</button>
                        </div>
                    </form>
                <?php elseif (!empty($formulaComponentsRaw)): ?>
                    <p class="small text-muted mb-2">
                        Standard batch: <strong><?= fmt_qty((float)$order['formula_batch_size'], $order['batch_unit'] ?? ''); ?></strong>
                        &rarr; This order: <strong><?= fmt_qty((float)$order['batch_size'], $order['bottle_size_unit'] ?: ($order['batch_unit'] ?? '')); ?></strong>
                    </p>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead>
                                <tr>
                                    <th class="ps-0">Component</th>
                                    <th>Per Batch</th>
                                    <th>Required (this order)</th>
                                    <th>Unit</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($formulaComponentsRaw as $i => $fComponent): ?>
                                    <?php
                                    $fSku = !empty($fComponent['product_id']) && isset($formulaSkus[$fComponent['product_id']]) ? $formulaSkus[$fComponent['product_id']] : '-';
                                    $scaledQty = $formulaComponents[$i]['quantity'] ?? '-';
                                    ?>
                                    <?php
                                    $cUnit    = $fComponent['unit'] ?? '';
                                    $rawQty   = $fComponent['quantity'] ?? '-';
                                    $perBatch = is_numeric($rawQty)
                                        ? fmt_qty((float)$rawQty, $cUnit)
                                        : htmlspecialchars($rawQty);
                                    if (is_numeric($scaledQty)) {
                                        $scaledVal  = (float)$scaledQty;
                                        $scaledUnit = $cUnit;
                                        // Upgrade g→kg or ml→L when scaled quantity is large
                                        if (strtolower($cUnit) === 'g' && $scaledVal >= 1000) {
                                            $scaledVal  = $scaledVal / 1000;
                                            $scaledUnit = 'kg';
                                        } elseif (strtolower($cUnit) === 'ml' && $scaledVal >= 1000) {
                                            $scaledVal  = $scaledVal / 1000;
                                            $scaledUnit = 'L';
                                        }
                                        $thisOrder = fmt_qty($scaledVal, $scaledUnit);
                                    } else {
                                        $thisOrder = htmlspecialchars($scaledQty);
                                    }
                                    ?>
                                    <tr>
                                        <td class="ps-0"><?= htmlspecialchars($fComponent['name'] ?? '-'); ?></td>
                                        <td class="text-muted"><?= $perBatch; ?></td>
                                        <td><strong><?= $thisOrder; ?></strong></td>
                                        <td><?= htmlspecialchars($cUnit ?: '-'); ?></td>
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
                <?php if (canViewManufacturingFormula()): ?>
                <p class="mb-1"><strong>Formula description:</strong> <?= htmlspecialchars($order['formula_description'] ?? 'No description'); ?></p>
                <?php if (!empty($formulaSampleImages)): ?>
                    <div class="mt-3">
                        <strong class="d-block mb-2">Formula samples:</strong>
                        <div class="d-flex flex-wrap gap-2">
                            <?php foreach ($formulaSampleImages as $sampleImage): ?>
                                <?php if (empty($sampleImage['path'])) continue; ?>
                                <a href="<?= htmlspecialchars(manufacturingFormulaSampleUrl((int)$order['formula_id'], $sampleImage['path'])); ?>" target="_blank">
                                    <img src="<?= htmlspecialchars(manufacturingFormulaSampleUrl((int)$order['formula_id'], $sampleImage['path'])); ?>" class="rounded border" style="width:64px;height:64px;object-fit:cover;" alt="Formula sample">
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
                <?php endif; ?>
                <p class="text-muted small mb-0">Every save regenerates the Excel and PDF that travel with the order to the next internal team.</p>
            </div>
        </div>
    </div>
</div>

<?php if ($packagingOutput): ?>
<div class="alert alert-info">Produced: <?= (int)$packagingOutput['produced_quantity']; ?> | Packaged finished products: <?= (int)$packagingOutput['finished_quantity']; ?>
    | منتج أولي إلى مخزن منتج اولي: <?= (int)$packagingOutput['primary_quantity']; ?> | منتج اولي مستخدم: <?= (int)$packagingOutput['primary_used_quantity']; ?></div>
<?php endif; ?>
<?php if ((int)$order['primary_stock_quantity'] > 0): ?>
<div class="alert alert-light border">Existing primary stock selected: <?= (int)$order['primary_stock_quantity']; ?> units from مخزن منتج اولي.
    <?= $order['primary_stock_deducted_at'] ? 'Consumed for this order.' : 'Will be consumed when sourcing completes.'; ?></div>
<?php endif; ?>
<div class="card mb-4">
    <div class="card-header">Workflow timeline</div>
    <div class="card-body">
        <div class="list-group list-group-flush">
            <?php foreach ($stepDefinitions as $stepKey => $definition): ?>
                <?php
                // Check permission for this specific step
                $stepPermissionKey = 'manufacturing.view_step_' . $stepKey;
                if (!canAccessManufacturingStep($stepKey)) {
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

<?php if (hasPermission('manufacturing.waste.manage') && canViewManufacturingTechnicalDetails() && canViewManufacturingFormula()): ?>
<?php
$wasteStmt = $pdo->prepare("SELECT msc.id, msc.component_name, msc.source, msc.required_quantity, msc.unit,
    COALESCE(mw.quantity, 0) AS waste_quantity, mw.posted_at, t.posted_at AS tracking_posted_at
    FROM manufacturing_sourcing_components msc JOIN products p ON p.id = msc.product_id AND p.type = 'material'
    LEFT JOIN manufacturing_material_waste mw ON mw.manufacturing_order_id = msc.manufacturing_order_id AND mw.sourcing_component_id = msc.id
    LEFT JOIN manufacturing_packaging_material_tracking t ON t.sourcing_component_id = msc.id
    WHERE msc.manufacturing_order_id = ? AND msc.source = 'formula' AND msc.deducted_at IS NOT NULL ORDER BY msc.source, msc.id");
$wasteStmt->execute([$orderId]); $wasteRows = $wasteStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="card mb-4">
<div class="card-header">Material waste / هالك الخامات</div>
<div class="card-body">
<p class="text-muted">Record ingredient waste here. Packaging waste is entered in Material Tracking and goes directly to مخزن الهالك when packaging completes. Ingredient waste posts at order completion.</p>
<form method="post">
<input type="hidden" name="manufacturing_csrf" value="<?= htmlspecialchars(manufacturingCsrfToken()); ?>">
<input type="hidden" name="action" value="save_material_waste">
<div class="table-responsive"><table class="table"><thead><tr><th>Material</th><th>Source</th><th>Consumed</th><th>Unit</th><th>Actual waste</th></tr></thead><tbody>
<?php foreach ($wasteRows as $wr): ?>
<tr><td><?= htmlspecialchars($wr['component_name']); ?></td><td><?= htmlspecialchars($wr['source']); ?></td><td><?= htmlspecialchars($wr['required_quantity']); ?></td><td><?= htmlspecialchars($wr['unit']); ?></td>
<td><input type="number" class="form-control" min="0" max="<?= htmlspecialchars($wr['required_quantity']); ?>" step="0.0001" name="material_waste[<?= (int)$wr['id']; ?>]" value="<?= htmlspecialchars($wr['waste_quantity']); ?>" <?= $wr['tracking_posted_at'] || in_array($order['status'], ['completed', 'cancelled'], true) ? 'disabled' : ''; ?> required></td></tr>
<?php endforeach; ?>
<?php if (!$wasteRows): ?><tr><td colspan="5">No consumed materials yet.</td></tr><?php endif; ?>
</tbody></table></div>
<?php if ($wasteRows && !in_array($order['status'], ['completed', 'cancelled'], true)): ?><button type="submit" class="btn btn-primary">Save material waste</button><?php endif; ?>
</form></div></div>
<?php endif; ?>

<?php if (canViewManufacturingAudit()): ?>
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>Manufacturing audit / points to check</span>
        <span class="badge <?= empty($auditNotes) ? 'bg-success' : 'bg-warning text-dark'; ?>"><?= count($auditNotes); ?> points</span>
    </div>
    <div class="card-body">
        <?php if (!empty($auditNotes)): ?>
            <div class="list-group list-group-flush">
                <?php foreach (array_slice($auditNotes, 0, 10) as $note): ?>
                    <div class="list-group-item px-0 d-flex gap-3 align-items-start">
                        <span class="badge <?= $note['severity'] === 'danger' ? 'bg-danger' : 'bg-warning text-dark'; ?> text-uppercase"><?= htmlspecialchars($note['severity']); ?></span>
                        <div>
                            <div class="fw-semibold"><?= htmlspecialchars($note['title']); ?></div>
                            <div class="text-muted small"><?= htmlspecialchars($note['detail']); ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="text-muted mb-0">No automatic audit warnings were detected.</p>
        <?php endif; ?>
    </div>
</div>

<?php endif; ?>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center gap-2">
        <span>Step-by-step manufacturing workflow</span>
        <button type="button" class="btn btn-sm btn-outline-primary" id="toggleManufacturingSteps">
            <i class="fas fa-expand-alt me-1"></i> Expand all
        </button>
    </div>
    <div class="card-body">
        <div id="manufacturingSteps">
            <?php 
            // Identify the active step: the first one that is not 'completed'
            $activeStepKey = null;
            foreach ($steps as $step) {
                if ($step['status'] !== 'completed') {
                    $activeStepKey = $step['step_key'];
                    break;
                }
            }
            // If all are completed, show the last one? Or just keep all closed. 
            // Let's show the first incomplete one.
            ?>
            <?php foreach ($steps as $index => $stepRow): ?>
                <?php 
                    // Check permission for this specific step
                    $stepPermissionKey = 'manufacturing.view_step_' . $stepRow['step_key'];
                    if (!canAccessManufacturingStep($stepRow['step_key'])) {
                        continue; // Skip this step if user doesn't have permission
                    }
                    $documents = $orderDocuments[$stepRow['id']] ?? []; 
                    $isOpen = ($stepRow['step_key'] === $activeStepKey);
                    $isStepLocked = ($stepRow['status'] === 'completed');
                    $people = $stepPeople[(int)$stepRow['id']] ?? [];
                ?>
                <details id="step-<?= htmlspecialchars($stepRow['step_key']); ?>" class="border rounded mb-2" <?= $isOpen ? 'open' : ''; ?>>
                    <summary class="p-3 fw-bold cursor-pointer bg-light d-flex justify-content-between align-items-center" style="list-style: none;">
                        <div>
                            <i class="fas fa-chevron-right me-2 transition-icon"></i>
                            <?= manufacturing_get_step_label($stepRow['step_key']); ?>
                            <span class="badge <?= manufacturing_status_badge_class($stepRow['status']); ?> ms-3 text-uppercase"><?= $stepRow['status']; ?></span>
                        </div>
                        <div class="text-end small text-muted fw-normal">
                            <div>Start: <?= $stepRow['started_at'] ? formatDateTime($stepRow['started_at']) : '-'; ?></div>
                            <div>End: <?= $stepRow['completed_at'] ? formatDateTime($stepRow['completed_at']) : '-'; ?></div>
                        </div>
                    </summary>
                    <div class="p-3 border-top">
                        <div class="step-content">
                            <p class="text-muted small mb-3"><?= manufacturing_get_step_instruction($stepRow['step_key']); ?></p>
                            <div class="row g-2 mb-3 small">
                                <div class="col-md-3"><strong>Started by:</strong> <?= htmlspecialchars($people['started_by_name'] ?? '-'); ?></div>
                                <div class="col-md-3"><strong>Completed by:</strong> <?= htmlspecialchars($people['completed_by_name'] ?? '-'); ?></div>
                                <div class="col-md-3"><strong>Last updated by:</strong> <?= htmlspecialchars($people['updated_by_name'] ?? '-'); ?></div>
                                <div class="col-md-3"><strong>Duration:</strong> <?= htmlspecialchars(manufacturing_format_duration($stepRow['started_at'], $stepRow['completed_at'])); ?></div>
                            </div>
                            
                            <form method="post" enctype="multipart/form-data">
<input type="hidden" name="manufacturing_csrf" value="<?= htmlspecialchars(manufacturingCsrfToken()); ?>">
                                <input type="hidden" name="order_id" value="<?= $order['id']; ?>">
                                <input type="hidden" name="step_key" value="<?= htmlspecialchars($stepRow['step_key']); ?>">
                                <?php if ($isStepLocked): ?>
                                    <div class="alert alert-success small mb-3">
                                        <i class="fas fa-lock me-2"></i>
                                        This step is completed and locked. Its saved data, notes, files, and status can no longer be changed.
                                    </div>
                                <?php endif; ?>
                                <fieldset <?= $isStepLocked ? 'disabled' : ''; ?>>
                                <?php if ($stepRow['step_key'] === 'sourcing'): ?>
                                    <div class="alert alert-light border small mb-3">
                                        <strong>Requirements:</strong> Raw materials from the formula + packaging from
                                        <strong><?= e($packagingOptionName ?: 'no selected packaging option'); ?></strong>.
                                        Packaging quantities = quantity per bottle × number of bottles.
                                        Packaging items also present in the formula are counted once from the kit when the open list is refreshed.
                                    </div>
                                    <!-- Sourcing Components Table -->
                                    <?php
                                    $sourcingStmt = $conn->prepare("
                                        SELECT MIN(msc.id) AS id,
                                               msc.component_name, msc.product_id, SUM(msc.required_quantity) AS required_quantity,
                                               msc.unit, GROUP_CONCAT(DISTINCT msc.source) AS sources, GROUP_CONCAT(DISTINCT msc.notes SEPARATOR '; ') AS notes,
                                               p.barcode, p.sku, p.unit AS stock_unit,
                                               COALESCE((
                                                   SELECT SUM(ip.quantity)
                                                   FROM inventory_products ip
                                                   JOIN inventories inv ON inv.id = ip.inventory_id
                                                   WHERE ip.product_id = msc.product_id AND inv.location_id = ? AND inv.is_active = 1 AND inv.system_key IS NULL AND " . getInventoryChannelScopeSql('inv') . "
                                               ), 0) AS available_qty
                                        FROM manufacturing_sourcing_components msc
                                        LEFT JOIN products p ON p.id = msc.product_id
                                        WHERE msc.manufacturing_order_id = ?
                                        GROUP BY msc.component_name, msc.product_id, msc.unit, p.barcode, p.sku, p.unit
                                        ORDER BY MIN(CASE WHEN msc.source = 'formula' THEN 0 ELSE 1 END), MIN(msc.formula_component_index)
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
                                                    <th>SKU / Barcode</th>
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
                                                    $availableQty = convertProductUnitQuantity((float)($sc['available_qty'] ?? 0), $sc['stock_unit'] ?? '', $sc['unit']);
                                                    $requiredQty  = $sc['required_quantity'];
                                                    $displayQty   = (float)$requiredQty;
                                                    $displayUnit  = $sc['unit'];
                                                    if (strtolower($displayUnit) === 'g' && $displayQty >= 1000) {
                                                        $displayQty  /= 1000;
                                                        $displayUnit  = 'kg';
                                                    } elseif (strtolower($displayUnit) === 'ml' && $displayQty >= 1000) {
                                                        $displayQty  /= 1000;
                                                        $displayUnit  = 'L';
                                                    }
                                                    $isValid = $availableQty !== null && $availableQty >= $requiredQty;
                                                    $statusClass = $isValid ? 'table-success' : 'table-danger';
                                                    $statusText = $availableQty === null ? '✗ Check units' : ($isValid ? '✓ OK' : '✗ Insufficient');
                                                    ?>
                                                    <tr>
                                                        <td>
                                                            <?php $isPackagingMaterial = str_contains($sc['sources'] ?? '', 'packaging'); ?>
                                                            <?php if ($canViewComponentName || $isPackagingMaterial): ?>
                                                                <?= htmlspecialchars($sc['component_name']); ?>
                                                            <?php else: ?>
                                                                <span class="text-muted"><?= $sc['sku'] ? htmlspecialchars($sc['sku']) : 'Hidden'; ?></span>
                                                            <?php endif; ?>
                                                            <div><span class="badge bg-light text-dark border"><?= $isPackagingMaterial ? 'Packaging' : 'Formula'; ?></span></div>
                                                        </td>
                                                        <td>
                                                            <?= $sc['sku'] ? htmlspecialchars($sc['sku']) . '<br>' : ''; ?>
                                                            <?= $sc['barcode'] ? htmlspecialchars($sc['barcode']) : '<span class="text-muted">-</span>'; ?>
                                                        </td>
                                                        <td><?= htmlspecialchars(rtrim(rtrim(number_format($displayQty, 4), '0'), '.')); ?></td>
                                                        <td><?= htmlspecialchars($displayUnit); ?></td>
                                                        <td><?= $availableQty === null ? '—' : e(fmt_qty((float)$availableQty, $sc['unit'])); ?></td>
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
                                        SELECT msc.id, msc.source, msc.component_name, msc.product_id, msc.required_quantity, msc.unit,
                                               msc.receipt_status, msc.receipt_notes, p.barcode, p.sku
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
                                                    <th>SKU / Barcode</th>
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
                                                        <td>
                                                            <?php if ($canViewComponentName || ($rc['source'] ?? '') === 'packaging'): ?>
                                                                <?= htmlspecialchars($rc['component_name']); ?>
                                                            <?php else: ?>
                                                                <span class="text-muted"><?= $rc['sku'] ? htmlspecialchars($rc['sku']) : 'Hidden'; ?></span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <?= $rc['sku'] ? htmlspecialchars($rc['sku']) . '<br>' : ''; ?>
                                                            <?= $rc['barcode'] ? htmlspecialchars($rc['barcode']) : '<span class="text-muted">-</span>'; ?>
                                                        </td>
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

                                    $prepImageStmt = $conn->prepare("SELECT file_path, original_name, uploaded_at FROM manufacturing_preparation_images WHERE manufacturing_order_id = ? ORDER BY uploaded_at DESC");
                                    $prepImageStmt->bind_param('i', $orderId);
                                    $prepImageStmt->execute();
                                    $prepImagesResult = $prepImageStmt->get_result();
                                    $prepImages = [];
                                    while ($prepImage = $prepImagesResult->fetch_assoc()) {
                                        $prepImages[] = $prepImage;
                                    }
                                    $prepImageStmt->close();
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
                                                               placeholder="Enter <?= htmlspecialchars($field); ?> value" required>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                            
                                            <?php
                                            // Show custom fields that were previously saved
                                            foreach ($prepMeasurements as $fieldName => $fieldValue) {
                                                if (!in_array($fieldName, $standardFields)) {
                                                    echo '<div class="row mb-2 align-items-center custom-field">';
                                                    echo '<div class="col-md-3">';
                                                    echo '<input type="text" class="form-control form-control-sm field-name-input" value="' . htmlspecialchars($fieldName) . '" placeholder="Field name" readonly required>';
                                                    echo '</div>';
                                                    echo '<div class="col-md-8">';
                                                    echo '<input type="text" class="form-control form-control-sm" name="prep_measurements[' . htmlspecialchars($fieldName) . ']" value="' . htmlspecialchars($fieldValue) . '" placeholder="Enter value" required>';
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
                                    <div class="mb-3">
                                        <h6 class="mb-2">Preparation &amp; Mixing Images</h6>
                                        <input type="file" class="form-control form-control-sm" name="preparation_images[]" accept="image/jpeg,image/png,image/gif,image/webp" multiple>
                                        <div class="form-text">Allowed: JPG, PNG, GIF, WEBP. Max 5MB per image.</div>
                                        <?php if (!empty($prepImages)): ?>
                                            <div class="row g-2 mt-2">
                                                <?php foreach ($prepImages as $prepImage): ?>
                                                    <div class="col-6 col-md-3">
                                                        <a href="../../<?= htmlspecialchars($prepImage['file_path']); ?>" target="_blank" class="d-block border rounded p-1 bg-light">
                                                            <img src="../../<?= htmlspecialchars($prepImage['file_path']); ?>" class="img-fluid rounded" alt="Preparation image">
                                                        </a>
                                                        <small class="text-muted d-block text-truncate mt-1"><?= htmlspecialchars($prepImage['original_name'] ?: basename($prepImage['file_path'])); ?></small>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
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
                                                           placeholder="Field name" data-field-id="custom_${fieldCounter}" required>
                                                </div>
                                                <div class="col-md-8">
                                                    <input type="text" class="form-control form-control-sm" 
                                                           name="prep_measurements[custom_${fieldCounter}]" 
                                                           placeholder="Enter value" disabled required>
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
                                        SELECT id, section_name, item_key, item_text, item_type, item_value, item_notes
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
                                        <p class="small text-muted">عدد المنتج النهائي is total usable production before packaging. Enter actual issued, used and destroyed packaging below. Completing packaging returns unused materials, transfers waste to مخزن الهالك and places unpackaged output in مخزن منتج اولي. The formula, planned bottle count and sales totals stay unchanged.</p>
                                        <h6 class="mt-4 mb-3 text-primary">بعد التعبئة</h6>
                                        <div class="row g-3 mb-3">
                                            <?php foreach ($packagingSections['after'] as $item): ?>
                                                <?php 
                                                // Skip items that are handled in the Material Tracking table below
                                                $skipKeys = [
                                                    'custom_material_name', 'prints_returned_to_stock',
                                                    'prints_issued', 'prints_used', 'prints_returned', 'prints_waste',
                                                    'boxes_issued', 'boxes_used', 'boxes_returned', 'boxes_waste',
                                                    'stickers_issued', 'stickers_used', 'stickers_returned', 'stickers_waste',
                                                    'leaflets_issued', 'leaflets_used', 'leaflets_returned', 'leaflets_waste'
                                                ];
                                                if (in_array($item['item_key'], $skipKeys)) continue;
                                                ?>
                                                <?php if ($item['item_type'] === 'number'): ?>
                                                    <div class="col-md-6">
                                                        <label class="form-label"><?= htmlspecialchars($item['item_text']); ?></label>
                                                        <input type="number" class="form-control form-control-sm" 
                                                               name="packaging_values[<?= htmlspecialchars($item['item_key']); ?>]" 
                                                               value="<?= htmlspecialchars($item['item_value'] ?? ''); ?>"
                                                               placeholder="0" min="0" step="1" <?= $item['item_key'] === 'last_carton_units' ? '' : 'required'; ?>>
                                                        <?php if ($item['item_key'] === 'primary_product_used'): ?><div class="form-text">Actual primary products used from this location’s مخزن منتج اولي. Defaults to the selected quantity; any difference is reconciled when packaging completes.</div><?php endif; ?>
                                                    </div>
                                                <?php elseif ($item['item_type'] === 'text'): ?>
                                                    <div class="col-md-12">
                                                        <label class="form-label"><?= htmlspecialchars($item['item_text']); ?></label>
                                                        <textarea class="form-control form-control-sm" rows="2"
                                                                  name="packaging_values[<?= htmlspecialchars($item['item_key']); ?>]" 
                                                                  placeholder="..." required><?= htmlspecialchars($item['item_value'] ?? ''); ?></textarea>
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
                                        <h6 class="mt-4 mb-2 text-primary">تتبع المواد (Material Tracking)</h6>
                                        <div class="alert alert-light border small py-2 mb-2" dir="rtl">
                                            <i class="fas fa-info-circle ms-1"></i>
                                            أدخل المصروف الفعلي والمستخدم والهالك لكل مادة. الراجع هو المصروف ناقص المستخدم والهالك ويعود إلى مخزنه الأصلي.
                                            عند إكمال التعبئة تُسجل حركات المخزن مرة واحدة ويُنقل الهالك فورًا إلى مخزن الهالك.
                                            يجب أن يكون: <strong>مصروف = مستخدم + راجع + هالك</strong>.
                                        </div>
                                        <div class="table-responsive mb-3">
                                            <table class="table table-bordered table-sm" id="materialTrackingTable">
                                                <thead class="table-light">
                                                    <tr>
                                                        <th style="text-align: right;">المادة / الوحدة</th>
                                                        <th style="text-align: right;">مصروف <?= helpTip('الكمية التي تم صرفها من المخزن لخط التعبئة.'); ?></th>
                                                        <th style="text-align: right;">مستخدم <?= helpTip('الكمية التي دخلت فعليًا في المنتج النهائي.'); ?></th>
                                                        <th style="text-align: right;">راجع <?= helpTip('الكمية السليمة غير المستخدمة التي تعود للمخزن.'); ?></th>
                                                        <th style="text-align: right;">الهالك <?= helpTip('الكمية التالفة التي تنتقل إلى مخزن الهالك عند إكمال التعبئة.'); ?></th>
                                                        <th style="text-align: center; width: 90px;">المطابقة <?= helpTip('يتحقق أن المصروف = المستخدم + الراجع + الهالك.'); ?></th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php $packagingMaterialRows = manufacturing_packaging_material_rows($conn, $orderId); ?>
                                                    <?php foreach ($packagingMaterialRows as $material): ?>
                                                        <tr data-component-id="<?= (int)$material['id']; ?>" data-planned="<?= htmlspecialchars($material['planned_quantity']); ?>"
                                                            data-bottles="<?= (int)$order['number_of_bottles']; ?>"
                                                            data-stock-factor="<?= htmlspecialchars((string)(convertProductUnitQuantity(1, $material['unit'], $material['stock_unit']) ?? 0)); ?>"
                                                            data-whole="<?= normalizeProductUnit($material['stock_unit']) === 'each' ? '1' : '0'; ?>"
                                                            data-dirty="<?= $material['tracked_component'] ? '1' : '0'; ?>"
                                                            data-posted="<?= $material['posted_at'] ? '1' : '0'; ?>">
                                                            <td><strong><?= htmlspecialchars($material['component_name'] ?: ($material['sku'] ?: 'Hidden')); ?></strong>
                                                                <small class="d-block text-muted"><?= htmlspecialchars($material['unit'] ?? ''); ?></small></td>
                                                            <?php foreach (['issued', 'used', 'returned', 'waste'] as $field): ?>
                                                            <td><input type="number" min="<?= $field === 'issued' ? htmlspecialchars($material['planned_quantity']) : '0'; ?>" step="0.0001" class="form-control form-control-sm mt-<?= $field; ?>"
                                                                <?= $field !== 'returned' ? 'name="packaging_materials[' . (int)$material['id'] . '][' . $field . ']" required' : 'readonly'; ?>
                                                                value="<?= htmlspecialchars((string)$material[$field . '_quantity']); ?>" <?= $material['posted_at'] ? 'readonly' : ''; ?>></td>
                                                            <?php endforeach; ?>
                                                            <td class="text-center align-middle mt-status"></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                    <?php if (!$packagingMaterialRows): ?><tr><td colspan="6">No packaging materials are linked to this order. Select a packaging option and complete sourcing first.</td></tr><?php endif; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                        <div id="packagingOutputPreview" class="alert alert-info small" aria-live="polite"></div>
                                    </div>
                                    <?php if (manufacturing_table_exists($conn, 'manufacturing_packaging_images')):
                                        $packImageStmt = $conn->prepare("SELECT file_path, original_name FROM manufacturing_packaging_images WHERE manufacturing_order_id = ? ORDER BY uploaded_at DESC");
                                        $packImageStmt->bind_param('i', $orderId);
                                        $packImageStmt->execute();
                                        $packImages = $packImageStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                                        $packImageStmt->close();
                                    ?>
                                    <div class="mb-3">
                                        <h6 class="mb-2">Packaging &amp; Labeling Images <?= helpTip('Photos of the labeled and packed product. They are kept with this order and included in the packaging step document.'); ?></h6>
                                        <input type="file" class="form-control form-control-sm" name="packaging_images[]" accept="image/jpeg,image/png,image/gif,image/webp" multiple>
                                        <div class="form-text">Optional. You can pick several images; they upload when you save this step. Allowed: JPG, PNG, GIF, WEBP. Max 5MB per image.</div>
                                        <?php if (!empty($packImages)): ?>
                                            <div class="row g-2 mt-2">
                                                <?php foreach ($packImages as $packImage): ?>
                                                    <div class="col-6 col-md-3">
                                                        <a href="../../<?= htmlspecialchars($packImage['file_path']); ?>" target="_blank" class="d-block border rounded p-1 bg-light">
                                                            <img src="../../<?= htmlspecialchars($packImage['file_path']); ?>" class="img-fluid rounded" alt="Packaging image">
                                                        </a>
                                                        <small class="text-muted d-block text-truncate mt-1"><?= htmlspecialchars($packImage['original_name'] ?: basename($packImage['file_path'])); ?></small>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <?php endif; ?>
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
                                    
                                    // Also fetch packaging items for material tracking table
                                    $packagingStmt = $conn->prepare("
                                        SELECT section_name, item_key, item_text, item_value, item_notes
                                        FROM manufacturing_packaging_checklist
                                        WHERE manufacturing_order_id = ?
                                        ORDER BY item_order
                                    ");
                                    $packagingStmt->bind_param("i", $orderId);
                                    $packagingStmt->execute();
                                    $pkgResult = $packagingStmt->get_result();
                                    $pkgItems = [];
                                    while ($pi = $pkgResult->fetch_assoc()) {
                                        $pkgItems[] = $pi;
                                    }
                                    $packagingStmt->close();
                                    
                                    $pkgSections = ['after' => []];
                                    foreach ($pkgItems as $pi) {
                                        if ($pi['section_name'] === 'after') {
                                            $pkgSections['after'][] = $pi;
                                        }
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
                                            <?php
                                            $finishedCounts = manufacturing_delivery_counts($conn, $orderId);
                                            foreach (manufacturing_packaging_count_labels() as $key => $label): ?>
                                                <div class="col-md-3">
                                                    <label class="form-label"><?= htmlspecialchars($label); ?></label>
                                                    <input class="form-control form-control-sm" value="<?= htmlspecialchars($finishedCounts[$key] ?? ''); ?>" readonly>
                                                </div>
                                            <?php endforeach; ?>
                                            <div class="small text-muted">Counts from Packaging &amp; Labeling, including units in the last partial carton.</div>
                                            <div class="col-md-3"><label class="form-label">منتج اولي مستخدم</label>
                                                <input class="form-control form-control-sm" value="<?= (int)($finishedCounts['primary_product_used'] ?? 0); ?>" readonly></div>
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
                                                                <?php if ($item['item_type'] === 'checkbox'): ?>
                                                                    <div class="form-check d-flex justify-content-center">
                                                                        <input class="form-check-input dispatch-checkbox" type="checkbox" 
                                                                               name="dispatch_values[<?= htmlspecialchars($item['item_key']); ?>]" 
                                                                               value="checked"
                                                                               <?= $item['item_value'] === 'checked' ? 'checked' : ''; ?>>
                                                                    </div>
                                                                <?php elseif ($item['item_type'] === 'image'): ?>
                                                                    <?php $itemPhotos = manufacturing_decode_dispatch_photos($item['item_value'] ?? ''); ?>
                                                                    <div class="mb-2">
                                                                        <input type="file" class="form-control form-control-sm"
                                                                               name="dispatch_photos[<?= htmlspecialchars($item['item_key']); ?>][]"
                                                                               accept="image/*" multiple>
                                                                        <small class="text-muted d-block mt-1">
                                                                            يمكن اختيار أكثر من صورة (JPG, PNG, GIF, WEBP — حد أقصى 5 ميجا لكل صورة)
                                                                        </small>
                                                                        <?php if (!empty($itemPhotos)): ?>
                                                                            <div class="d-flex flex-wrap justify-content-center gap-2 mt-2">
                                                                                <?php foreach ($itemPhotos as $photo): ?>
                                                                                    <div class="text-center">
                                                                                        <a href="../../<?= htmlspecialchars($photo['path']); ?>" target="_blank">
                                                                                            <img src="../../<?= htmlspecialchars($photo['path']); ?>" class="img-thumbnail" style="max-height: 80px;">
                                                                                        </a>
                                                                                        <div class="form-check d-flex justify-content-center mt-1">
                                                                                            <input class="form-check-input" type="checkbox"
                                                                                                   id="rm_<?= htmlspecialchars($item['item_key'] . '_' . md5($photo['path'])); ?>"
                                                                                                   name="dispatch_photos_remove[<?= htmlspecialchars($item['item_key']); ?>][]"
                                                                                                   value="<?= htmlspecialchars($photo['path']); ?>">
                                                                                            <label class="form-check-label small text-danger ms-1"
                                                                                                   for="rm_<?= htmlspecialchars($item['item_key'] . '_' . md5($photo['path'])); ?>">حذف</label>
                                                                                        </div>
                                                                                    </div>
                                                                                <?php endforeach; ?>
                                                                            </div>
                                                                            <small class="text-muted d-block mt-1">
                                                                                <?= count($itemPhotos); ?> صورة مرفقة
                                                                            </small>
                                                                        <?php endif; ?>
                                                                    </div>
                                                                <?php endif; ?>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                        
                                        <!-- Material Tracking Table (from Packaging) -->
                                        <h6 class="mt-4 mb-3 text-primary">تتبع مواد التعبئة (Packaging Material Tracking)</h6>
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
                                                    <?php foreach (manufacturing_packaging_material_rows($conn, $orderId) as $material): ?>
                                                        <tr>
                                                            <td><strong><?= htmlspecialchars($material['component_name'] ?: ($material['sku'] ?: 'Hidden')); ?></strong>
                                                                <small class="d-block text-muted"><?= htmlspecialchars($material['unit'] ?? ''); ?></small></td>
                                                            <?php foreach (['issued', 'used', 'returned', 'waste'] as $field): ?>
                                                            <td>
                                                                <div class="small fw-bold mb-1"><?= htmlspecialchars((string)($material[$field . '_quantity'] ?? '—')); ?></div>
                                                                <?php if ($material['posted_at']): ?>
                                                                <input type="text" class="form-control form-control-sm" name="packaging_material_notes[<?= (int)$material['id']; ?>][<?= $field; ?>]" value="<?= htmlspecialchars($material[$field . '_notes'] ?? ''); ?>" placeholder="Note...">
                                                                <?php endif; ?>
                                                            </td>
                                                            <?php endforeach; ?>
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
                                    <div class="alert alert-light border mb-3">
                                        <h6>Shipment verification / مراجعة الشحنة</h6>
                                        <p class="small text-muted">Saved counts from Dispatch Prep / Packaging &amp; Labeling. Verify these against the load.</p>
                                        <?= manufacturing_delivery_counts_html(manufacturing_delivery_counts($conn, $orderId)); ?>
                                        <?php if (!empty($order['sales_order_id'])): ?>
                                            <p class="small">All products for sales order #<?= (int)$order['sales_order_id']; ?>:</p>
                                            <?php foreach (manufacturing_delivery_groups($conn, (int)$order['sales_order_id']) as $deliveryRows): ?>
                                                <?= manufacturing_delivery_group_html($deliveryRows); ?>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                        <a href="delivery.php" class="btn btn-sm btn-outline-primary">View deliveries by sales order</a>
                                    </div>
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
                                                       placeholder="__________" required>
                                            </div>
                                            
                                            <div class="col-md-6">
                                                <label class="form-label">رقم تليفون (Phone Number)</label>
                                                <input type="tel" class="form-control form-control-sm" 
                                                       name="recipient_phone" 
                                                       value="<?= htmlspecialchars($deliveryInfo['recipient_phone'] ?? ''); ?>"
                                                       placeholder="__________" required>
                                            </div>
                                            
                                            <div class="col-md-6">
                                                <label class="form-label">تاريخ التسليم (Delivery Date)</label>
                                                <input type="date" class="form-control form-control-sm" 
                                                       name="delivery_date" 
                                                       value="<?= htmlspecialchars($deliveryDate); ?>" required>
                                            </div>
                                            
                                            <div class="col-md-6">
                                                <label class="form-label">وقت التسليم (Delivery Time)</label>
                                                <input type="time" class="form-control form-control-sm" 
                                                       name="delivery_time" 
                                                       value="<?= htmlspecialchars($deliveryTime); ?>" required>
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
                                    <?php
                                    // Last open step: completing it completes the order and stocks the finished product.
                                    $isLastOpenStep = $stepRow['status'] !== 'completed'
                                        && count(array_filter($steps, fn($s) => $s['status'] !== 'completed' && (int)$s['id'] !== (int)$stepRow['id'])) === 0;
                                    ?>
                                    <?php if ($isLastOpenStep && !$isCompletedOrder && hasPermission('manufacturing.orders.complete')): ?>
                                        <?php $finishedGoodsOptions = manufacturing_get_finished_goods_inventories($conn, $order); ?>
                                        <div class="col-12">
                                            <div class="border rounded p-3 bg-light">
                                                <label class="form-label fw-semibold mb-1">Finished goods warehouse</label>
                                                <p class="text-muted small mb-2">
                                                    Completing this step completes the order and adds
                                                    <strong><?= (int)$usableOutputCount; ?></strong> ×
                                                    <strong><?= e($order['product_name'] ?? '—'); ?></strong> to the warehouse below.
                                                    Material waste is recorded separately by material and unit.
                                                </p>
                                                <?php if (empty($finishedGoodsOptions['inventories'])): ?>
                                                    <div class="text-danger small">
                                                        No warehouse at <?= e($order['location_name'] ?? 'this location'); ?> can receive this product, so the order cannot be completed yet.
                                                    </div>
                                                <?php else: ?>
                                                    <select class="form-select form-select-sm" name="finished_goods_inventory_id" style="max-width: 420px;">
                                                        <option value="">Select warehouse</option>
                                                        <?php foreach ($finishedGoodsOptions['inventories'] as $invId => $invName): ?>
                                                            <option value="<?= (int)$invId; ?>" <?= $invId === $finishedGoodsOptions['default_id'] ? 'selected' : ''; ?>><?= e($invName); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="d-flex gap-2">
                                    <button type="submit" name="action" value="update" class="btn btn-primary btn-sm">
                                        <i class="fas fa-file-export me-1"></i> Save step &amp; generate docs
                                    </button>
                                    <button type="submit" name="action" value="regenerate" class="btn btn-outline-secondary btn-sm">
                                        <i class="fas fa-sync-alt me-1"></i> Regenerate Excel/PDF
                                    </button>
                                </div>
                                </fieldset>
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
                                                <a href="<?= $document['type'] === 'pdf' ? 'view_report.php?id=' . $orderId . '&amp;step=' . urlencode($stepRow['step_key']) : 'view_report.php?id=' . $orderId . '&amp;step=' . urlencode($stepRow['step_key']) . '&amp;format=excel'; ?>" target="_blank" class="btn btn-sm btn-outline-primary">
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
                </details>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>

<script>
    function openLinkedManufacturingStep() {
        const linkedStep = document.getElementById(location.hash.slice(1));
        if (linkedStep && linkedStep.matches('#manufacturingSteps details')) linkedStep.open = true;
    }
    openLinkedManufacturingStep();
    window.addEventListener('hashchange', openLinkedManufacturingStep);
    const toggleManufacturingSteps = document.getElementById('toggleManufacturingSteps');
    if (toggleManufacturingSteps) {
        toggleManufacturingSteps.addEventListener('click', function () {
            const stepDetails = document.querySelectorAll('#manufacturingSteps details');
            const shouldOpen = Array.from(stepDetails).some((detail) => !detail.open);
            stepDetails.forEach((detail) => {
                detail.open = shouldOpen;
            });
            this.innerHTML = shouldOpen
                ? '<i class="fas fa-compress-alt me-1"></i> Collapse all'
                : '<i class="fas fa-expand-alt me-1"></i> Expand all';
        });
    }

    // Preview the same per-material reconciliation that the server posts on completion.
    function refreshMaterialTrackingRow(row) {
        if (!row.dataset.planned) return;
        if (row.dataset.posted !== '1') {
            const count = document.querySelector('[name="packaging_values[final_product_count]"]')?.value ?? '';
            const bottles = Number(row.dataset.bottles);
            const planned = Number(row.dataset.planned);
            const factor = Number(row.dataset.stockFactor);
            if (row.dataset.dirty !== '1' && count !== '' && bottles > 0) {
                let used = planned * Number(count) / bottles;
                if (factor > 0) {
                    const native = row.dataset.whole === '1' ? Math.ceil(used * factor - 1e-8) : Math.round(used * factor * 100) / 100;
                    used = native / factor;
                }
                used = Math.round(used * 10000) / 10000;
                const waste = Number(row.querySelector('.mt-waste').value);
                row.querySelector('.mt-used').value = used;
                row.querySelector('.mt-issued').value = Math.max(planned, Math.round((used + waste) * 10000) / 10000);
            }
            const issued = Number(row.querySelector('.mt-issued').value);
            const used = Number(row.querySelector('.mt-used').value);
            const waste = Number(row.querySelector('.mt-waste').value);
            row.querySelector('.mt-returned').value = Math.round((issued - used - waste) * 10000) / 10000;
        }
        const num = (selector) => parseFloat(row.querySelector(selector)?.value) || 0;
        const fields = ['.mt-issued', '.mt-used', '.mt-returned', '.mt-waste'];
        const isEmpty = fields.every((selector) => (row.querySelector(selector)?.value ?? '') === '');
        const status = row.querySelector('.mt-status');
        if (!status) return;
        if (isEmpty) {
            status.innerHTML = '<span class="text-muted">—</span>';
            return;
        }
        const diff = num('.mt-issued') - (num('.mt-used') + num('.mt-returned') + num('.mt-waste'));
        if (Math.abs(diff) < 0.0001) {
            status.innerHTML = '<span class="badge bg-success"><i class="fas fa-check"></i> مطابق</span>';
        } else {
            const amount = Number(Math.abs(diff).toFixed(3));
            const label = diff > 0 ? 'غير محسوب ' + amount : 'زائد ' + amount;
            status.innerHTML = '<span class="badge bg-danger" title="المصروف لا يساوي المستخدم + الراجع + الهالك">' + label + '</span>';
        }
    }
    document.querySelectorAll('#materialTrackingTable tbody tr').forEach((row) => {
        refreshMaterialTrackingRow(row);
        row.addEventListener('input', () => { row.dataset.dirty = '1'; refreshMaterialTrackingRow(row); refreshPackagingOutput(); });
    });
    document.querySelector('[name="packaging_values[final_product_count]"]')?.addEventListener('input', () => {
        document.querySelectorAll('#materialTrackingTable tbody tr').forEach(refreshMaterialTrackingRow);
        refreshPackagingOutput();
    });

    function refreshPackagingOutput() {
        const preview = document.getElementById('packagingOutputPreview');
        if (!preview) return;
        const count = document.querySelector('[name="packaging_values[final_product_count]"]')?.value ?? '';
        if (count === '') { preview.textContent = 'Enter total production to preview finished and primary quantities.'; return; }
        let maximum = 0, counted = false;
        document.querySelectorAll('#materialTrackingTable tbody tr[data-planned]').forEach(row => {
            if (row.dataset.whole !== '1' || Number(row.dataset.planned) <= 0) return;
            counted = true;
            maximum = Math.max(maximum, Number(row.querySelector('.mt-used').value) * Number(row.dataset.bottles) / Number(row.dataset.planned));
        });
        const finished = counted ? Math.min(Number(count), Math.floor(maximum + 1e-8)) : Number(count);
        preview.textContent = 'منتج نهائي معبأ: ' + finished + ' | منتج أولي إلى مخزن منتج اولي: ' + (Number(count) - finished)
            + ' | منتج اولي مستخدم: ' + (Number(document.querySelector('[name="packaging_values[primary_product_used]"]')?.value) || 0)
            + ' — Based on the maximum used packaging quantity in equivalent product units.';
    }
    refreshPackagingOutput();
    document.querySelector('[name="packaging_values[primary_product_used]"]')?.addEventListener('input', refreshPackagingOutput);

    function confirmDelete(id, orderNumber) {
        if (confirm('Request approval to delete manufacturing order #' + orderNumber + '? The order and its related data will remain until another authorized user approves deletion.')) {
            const form = document.createElement('form');
            form.method = 'post';
            form.action = 'delete.php';
            const values = {id: id, deletion_token: <?= json_encode(manufacturingDeletionToken()) ?>};
            for (const [name, value] of Object.entries(values)) {
                const input = document.createElement('input');
                input.type = 'hidden'; input.name = name; input.value = value;
                form.appendChild(input);
            }
            document.body.appendChild(form);
            form.submit();
        }
    }
</script>

<?php
session_start();
require_once '../config/database.php';

/**
 * Simple message page (modern + responsive)
 */
function renderMessage(string $text): void
{
    $safeText = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    echo <<<HTML
<!doctype html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="utf-8">
  <title>بوابة العميل - GammaVET</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = {
      darkMode: 'class',
      theme: { extend: { boxShadow: { soft: '0 10px 30px rgba(2,6,23,.08)' } } }
    }
  </script>
  <style>
    body{font-family:"Cairo",sans-serif;}
    html{-webkit-font-smoothing:antialiased;-moz-osx-font-smoothing:grayscale;}
  </style>
</head>
<body class="min-h-screen bg-slate-50 text-slate-900 flex items-center justify-center px-4 py-12">
  <div class="w-full max-w-md rounded-3xl border border-slate-200 bg-white p-7 shadow-soft">
    <div class="space-y-2 text-center">
      <div class="mx-auto h-12 w-12 rounded-2xl bg-gradient-to-br from-cyan-600 to-blue-700 flex items-center justify-center">
        <span class="text-white text-xl font-bold">G</span>
      </div>
      <h1 class="text-xl font-extrabold">بوابة العميل</h1>
      <p class="text-slate-600 leading-7">$safeText</p>
      <p class="text-xs text-slate-400 pt-3">GammaVET</p>
    </div>
  </div>
</body>
</html>
HTML;
    exit;
}

/**
 * Password prompt page (modern + robust)
 */
function renderPasswordPrompt(array $customer, string $token, ?string $hint = null, ?string $error = null): void
{
    $safeCustomer = htmlspecialchars($customer['name'] ?? '', ENT_QUOTES, 'UTF-8');
    $safeToken = htmlspecialchars($token, ENT_QUOTES, 'UTF-8');

    $hintBlock = $hint
        ? "<p class='text-xs text-slate-500 mt-2'>تلميح كلمة المرور: " . htmlspecialchars($hint, ENT_QUOTES, 'UTF-8') . "</p>"
        : '';

    $errorBlock = $error
        ? "<div class='text-sm text-red-700 bg-red-50 border border-red-200 rounded-2xl px-4 py-3 mb-4'>$error</div>"
        : '';

    echo <<<HTML
<!doctype html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="utf-8">
  <title>بوابة العميل - GammaVET</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css">
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = {
      darkMode: 'class',
      theme: { extend: { boxShadow: { soft: '0 10px 30px rgba(2,6,23,.08)' } } }
    }
  </script>
  <style>
    body{font-family:"Cairo",sans-serif;}
    html{-webkit-font-smoothing:antialiased;-moz-osx-font-smoothing:grayscale;}
  </style>
</head>
<body class="min-h-screen bg-slate-50 flex items-center justify-center px-4 py-12">
  <div class="w-full max-w-md rounded-3xl border border-slate-200 bg-white shadow-soft overflow-hidden">
    <div class="p-7">
      <div class="text-center space-y-2">
        <div class="mx-auto h-12 w-12 rounded-2xl bg-gradient-to-br from-cyan-600 to-blue-700 flex items-center justify-center">
          <i class="bx bx-lock-alt text-2xl text-white"></i>
        </div>
        <h1 class="text-2xl font-extrabold text-slate-900">بوابة العميل</h1>
        <p class="text-slate-600 text-sm leading-6">
          هذه الصفحة محمية بكلمة مرور خاصة بالعميل
          <span class="font-bold text-slate-800">$safeCustomer</span>.
        </p>
      </div>

      <div class="mt-6">
        $errorBlock
        <form method="post" class="space-y-4">
          <input type="hidden" name="token" value="$safeToken">

          <div class="space-y-2">
            <label class="block text-sm font-bold text-slate-700">كلمة المرور للبوابة</label>
            <div class="relative">
              <i class="bx bx-key absolute right-4 top-1/2 -translate-y-1/2 text-slate-400 text-xl"></i>
              <input
                type="password"
                name="portal_password"
                class="w-full rounded-2xl border border-slate-200 bg-white px-12 py-3 text-slate-900 placeholder-slate-400
                       focus:outline-none focus:ring-4 focus:ring-cyan-200 focus:border-cyan-500"
                placeholder="••••••••"
                required
              >
            </div>
            $hintBlock
          </div>

          <button type="submit"
            class="w-full rounded-2xl bg-gradient-to-r from-cyan-600 to-blue-700 text-white font-bold py-3 shadow-soft
                   hover:opacity-95 active:scale-[0.99] transition">
            تأكيد الدخول
          </button>

          <p class="text-xs text-slate-400 text-center pt-2">GammaVET</p>
        </form>
      </div>
    </div>
  </div>
</body>
</html>
HTML;
    exit;
}

function touchPortalAccess(PDO $pdo, int $customerId): void
{
    $stmt = $pdo->prepare("UPDATE customers SET portal_last_access_at = NOW() WHERE id = ?");
    $stmt->execute([$customerId]);
}

function normalizeEgyptWhatsappNumber(?string $phone): string
{
    $digits = preg_replace('/\D+/', '', (string)$phone);
    if ($digits === '') {
        return '';
    }

    if (strpos($digits, '00') === 0) {
        $digits = substr($digits, 2);
    }

    if (strpos($digits, '20') === 0) {
        return $digits;
    }

    if ($digits[0] === '0') {
        return '20' . substr($digits, 1);
    }

    return '20' . $digits;
}

function whatsappUrl(?string $phone): ?string
{
    $number = normalizeEgyptWhatsappNumber($phone);
    if ($number === '') {
        return null;
    }

    return 'https://wa.me/' . $number;
}

/* =========================
   Data & Access Checks
========================= */

$token = $_GET['token'] ?? ($_POST['token'] ?? '');
$token = trim((string)$token);

if (empty($token)) {
    renderMessage('رابط غير صالح. برجاء التواصل مع فريق المبيعات للحصول على رابط جديد.');
}

$stmt = $pdo->prepare("
    SELECT c.*, f.name AS factory_name, f.contact_person, f.contact_phone,
           f.whatsapp_number AS factory_whatsapp_number,
           f.sales_person_id AS factory_sales_person_id
    FROM customers c
    LEFT JOIN factories f ON c.factory_id = f.id
    WHERE c.portal_token = ?
    LIMIT 1
");
$stmt->execute([$token]);
$customer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$customer) {
    renderMessage('تعذر العثور على العميل المطلوب، تأكد من الرابط وحاول مجدداً.');
}

if (!empty($customer['portal_token_expires']) && strtotime($customer['portal_token_expires']) < time()) {
    renderMessage('انتهت صلاحية الرابط. اطلب إعادة إرسال الرابط من فريق المبيعات.');
}

$customerId = (int)$customer['id'];
$requiresPassword = !empty($customer['portal_password_hash']);

if ($requiresPassword) {
    if (!isset($_SESSION['portal_access'])) {
        $_SESSION['portal_access'] = [];
    }

    $portalSessionKey = 'customer_' . $customerId;
    $hasPortalAccess = !empty($_SESSION['portal_access'][$portalSessionKey]);
    $passwordError = null;

    if (!$hasPortalAccess && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['portal_password'])) {
        $passwordAttempt = (string)($_POST['portal_password'] ?? '');
        if (password_verify($passwordAttempt, $customer['portal_password_hash'])) {
            $_SESSION['portal_access'][$portalSessionKey] = true;
            $hasPortalAccess = true;
        } else {
            $passwordError = 'كلمة المرور غير صحيحة، حاول مرة أخرى.';
        }
    }

    if (!$hasPortalAccess) {
        renderPasswordPrompt($customer, $token, $customer['portal_password_hint'] ?? null, $passwordError);
    }

    touchPortalAccess($pdo, $customerId);
} else {
    touchPortalAccess($pdo, $customerId);
}

$noteTableStmt = $pdo->query("
    SELECT COUNT(*)
    FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'customer_order_notes'
");
$customerOrderNotesReady = (bool)$noteTableStmt->fetchColumn();

$portalSessionKey = 'customer_' . $customerId;
if (!isset($_SESSION['portal_note_csrf'])) {
    $_SESSION['portal_note_csrf'] = [];
}
if (empty($_SESSION['portal_note_csrf'][$portalSessionKey])) {
    $_SESSION['portal_note_csrf'][$portalSessionKey] = bin2hex(random_bytes(32));
}
$portalNoteCsrf = $_SESSION['portal_note_csrf'][$portalSessionKey];

$portalNoteFlash = $_SESSION['portal_note_flash'][$portalSessionKey] ?? null;
unset($_SESSION['portal_note_flash'][$portalSessionKey]);
$portalOrderFlash = $_SESSION['portal_order_flash'][$portalSessionKey] ?? null;
unset($_SESSION['portal_order_flash'][$portalSessionKey]);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['portal_action'] ?? '') === 'create_order') {
    $submittedCsrf = (string)($_POST['portal_note_csrf'] ?? '');
    $note = trim((string)($_POST['new_order_note'] ?? ''));
    $rawItems = is_array($_POST['items'] ?? null) ? $_POST['items'] : [];
    $flash = ['type' => 'error', 'message' => 'تعذر إنشاء الطلب. حاول مرة أخرى.'];

    try {
        if ($submittedCsrf === '' || !hash_equals($portalNoteCsrf, $submittedCsrf)) {
            throw new DomainException('انتهت صلاحية النموذج. حدّث الصفحة وحاول مرة أخرى.');
        }

        $noteLength = function_exists('mb_strlen') ? mb_strlen($note, 'UTF-8') : strlen($note);
        if ($noteLength > 2000) {
            throw new DomainException('يجب ألا تتجاوز الملاحظة 2000 حرف.');
        }

        $requestedQuantities = [];
        foreach (array_slice($rawItems, 0, 100) as $rawItem) {
            if (!is_array($rawItem)) {
                continue;
            }
            $productId = filter_var($rawItem['product_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
            $quantity = filter_var($rawItem['quantity'] ?? null, FILTER_VALIDATE_INT) ?: 0;
            if ($productId <= 0 || $quantity <= 0) {
                continue;
            }
            if ($quantity > 1000000) {
                throw new DomainException('كمية أحد المنتجات أكبر من الحد المسموح.');
            }
            $requestedQuantities[$productId] = ($requestedQuantities[$productId] ?? 0) + $quantity;
            if ($requestedQuantities[$productId] > 1000000) {
                throw new DomainException('إجمالي كمية أحد المنتجات أكبر من الحد المسموح.');
            }
        }

        if (!$requestedQuantities) {
            throw new DomainException('اختر منتجاً واحداً على الأقل وحدد الكمية المطلوبة.');
        }

        $productIds = array_keys($requestedQuantities);
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $productStmt = $pdo->prepare("
            SELECT id, name, unit_price
            FROM products
            WHERE customer_id = ? AND type = 'final' AND id IN ($placeholders)
        ");

        $pdo->beginTransaction();
        $productStmt->execute(array_merge([$customerId], $productIds));
        $selectedProducts = $productStmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($selectedProducts) !== count($productIds)) {
            throw new DomainException('أحد المنتجات المختارة غير متاح لهذا الحساب.');
        }

        $responsibleUserId = (int)($customer['sales_person_id'] ?? 0);
        if ($responsibleUserId <= 0) {
            $responsibleUserId = (int)($customer['factory_sales_person_id'] ?? 0);
        }
        if ($responsibleUserId > 0) {
            $userCheck = $pdo->prepare("SELECT id FROM users WHERE id = ? AND is_active = 1 LIMIT 1");
            $userCheck->execute([$responsibleUserId]);
            if (!$userCheck->fetchColumn()) {
                $responsibleUserId = 0;
            }
        }
        if ($responsibleUserId <= 0) {
            $responsibleUserId = (int)$pdo->query("
                SELECT u.id
                FROM users u
                LEFT JOIN roles r ON r.id = u.role_id
                WHERE u.is_active = 1
                  AND COALESCE(r.slug, u.role) IN (
                      'salesman', 'factory_sales', 'representative_sales', 'sales_manager', 'admin'
                  )
                ORDER BY FIELD(
                    COALESCE(r.slug, u.role),
                    'salesman', 'factory_sales', 'representative_sales', 'sales_manager', 'admin'
                ), u.id
                LIMIT 1
            ")->fetchColumn();
        }
        if ($responsibleUserId <= 0) {
            throw new DomainException('لا يوجد مسؤول مبيعات متاح لاستلام الطلب حالياً.');
        }

        $contactStmt = $pdo->prepare("
            SELECT id
            FROM customer_contacts
            WHERE customer_id = ?
            ORDER BY is_primary DESC, id ASC
            LIMIT 1
        ");
        $contactStmt->execute([$customerId]);
        $contactId = (int)$contactStmt->fetchColumn();
        if ($contactId <= 0) {
            $createContact = $pdo->prepare("
                INSERT INTO customer_contacts (customer_id, name, email, phone, is_primary, position)
                VALUES (?, ?, ?, ?, 1, 'Portal contact')
            ");
            $contactPhone = trim((string)($customer['phone'] ?: ($customer['whatsapp_phone'] ?: '-')));
            $createContact->execute([
                $customerId,
                $customer['name'],
                $customer['email'] ?: null,
                $contactPhone,
            ]);
            $contactId = (int)$pdo->lastInsertId();
        }

        // Portal submissions land in a review queue with no confirmed prices yet —
        // staff must price/approve them before any stock is touched or a real
        // order is created (see modules/sales/portal_orders/review.php).
        foreach ($selectedProducts as &$selectedProduct) {
            $selectedProduct['quantity'] = $requestedQuantities[(int)$selectedProduct['id']];
        }
        unset($selectedProduct);

        $insertPortalOrder = $pdo->prepare("
            INSERT INTO portal_orders (
                customer_id, contact_id, factory_id, status,
                customer_note, total_amount, responsible_user_id
            ) VALUES (?, ?, ?, 'pending_review', ?, 0, ?)
        ");
        $insertPortalOrder->execute([
            $customerId,
            $contactId,
            empty($customer['direct_sale']) ? ($customer['factory_id'] ?: null) : null,
            $note !== '' ? $note : null,
            $responsibleUserId,
        ]);
        $portalOrderId = (int)$pdo->lastInsertId();

        $insertItem = $pdo->prepare("
            INSERT INTO portal_order_items (portal_order_id, product_id, quantity, unit_price, total_price, is_priced)
            VALUES (?, ?, ?, 0, 0, 0)
        ");
        foreach ($selectedProducts as $selectedProduct) {
            $insertItem->execute([$portalOrderId, (int)$selectedProduct['id'], (int)$selectedProduct['quantity']]);
        }

        $notify = $pdo->prepare("
            INSERT INTO notifications
                (type, title, message, module, entity_type, entity_id, severity, created_for_user_id)
            VALUES
                ('customer_portal_order', ?, ?, 'sales', 'portal_order', ?, 'info', ?)
        ");
        $notify->execute([
            'New portal order request #' . $portalOrderId,
            $customer['name'] . ' submitted a new order request through the customer portal. It needs pricing and approval.',
            $portalOrderId,
            $responsibleUserId,
        ]);

        $pdo->commit();
        $flash = [
            'type' => 'success',
            'order_id' => $portalOrderId,
            'message' => 'تم إرسال طلبك بنجاح، وسيقوم فريق المبيعات بمراجعته وتحديد السعر قبل التأكيد.',
        ];
    } catch (DomainException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $flash['message'] = $e->getMessage();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Customer portal order failed: ' . $e->getMessage());
    }

    $_SESSION['portal_order_flash'][$portalSessionKey] = $flash;
    header('Location: customer_portal.php?token=' . rawurlencode($token) . '#newOrderPanel');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['portal_action'] ?? '') === 'add_order_note') {
    $orderId = filter_input(INPUT_POST, 'order_id', FILTER_VALIDATE_INT) ?: 0;
    $note = trim((string)($_POST['customer_order_note'] ?? ''));
    $submittedCsrf = (string)($_POST['portal_note_csrf'] ?? '');
    $flash = ['type' => 'error', 'order_id' => $orderId, 'message' => 'تعذر إضافة الملاحظة. حاول مرة أخرى.'];

    try {
        if (!$customerOrderNotesReady) {
            throw new DomainException('خدمة ملاحظات الطلبات غير متاحة حالياً.');
        }
        if ($submittedCsrf === '' || !hash_equals($portalNoteCsrf, $submittedCsrf)) {
            throw new DomainException('انتهت صلاحية النموذج. حدّث الصفحة وحاول مرة أخرى.');
        }
        if ($orderId <= 0) {
            throw new DomainException('الطلب المحدد غير صالح.');
        }
        if ($note === '') {
            throw new DomainException('اكتب الملاحظة قبل الإرسال.');
        }

        $noteLength = function_exists('mb_strlen') ? mb_strlen($note, 'UTF-8') : strlen($note);
        if ($noteLength > 2000) {
            throw new DomainException('يجب ألا تتجاوز الملاحظة 2000 حرف.');
        }

        $orderCheck = $pdo->prepare("
            SELECT id, internal_id, created_by
            FROM orders
            WHERE id = ? AND customer_id = ?
            LIMIT 1
        ");
        $orderCheck->execute([$orderId, $customerId]);
        $ownedOrder = $orderCheck->fetch(PDO::FETCH_ASSOC);
        if (!$ownedOrder) {
            throw new DomainException('لا يمكنك إضافة ملاحظة إلى هذا الطلب.');
        }

        $pdo->beginTransaction();
        $insertNote = $pdo->prepare("
            INSERT INTO customer_order_notes (order_id, customer_id, note)
            VALUES (?, ?, ?)
        ");
        $insertNote->execute([$orderId, $customerId, $note]);

        $notify = $pdo->prepare("
            INSERT INTO notifications
                (type, title, message, module, entity_type, entity_id, severity, created_for_user_id)
            VALUES
                ('customer_order_note', ?, ?, 'sales', 'order', ?, 'info', ?)
        ");
        $orderLabel = $ownedOrder['internal_id'] ?: 'Order #' . $orderId;
        $notify->execute([
            'Customer note on ' . $orderLabel,
            $customer['name'] . ' added a note to ' . $orderLabel . '.',
            $orderId,
            (int)$ownedOrder['created_by']
        ]);
        $pdo->commit();

        $flash = ['type' => 'success', 'order_id' => $orderId, 'message' => 'تمت إضافة ملاحظتك إلى الطلب بنجاح.'];
    } catch (DomainException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $flash['message'] = $e->getMessage();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Customer portal note failed: ' . $e->getMessage());
    }

    $_SESSION['portal_note_flash'][$portalSessionKey] = $flash;
    header('Location: customer_portal.php?token=' . rawurlencode($token) . '#order-' . max($orderId, 0));
    exit;
}

/* =========================
   Queries
========================= */

$ordersStmt = $pdo->prepare("
    SELECT id, internal_id, status, total_amount, paid_amount, order_date,
           shipping_cost_type, shipping_cost, discount_amount, discount_basis,
           discount_percentage, free_sample_count, notes
    FROM orders
    WHERE customer_id = ?
    ORDER BY order_date DESC
");
$ordersStmt->execute([$customerId]);
$orders = $ordersStmt->fetchAll(PDO::FETCH_ASSOC);

$pendingPortalOrdersStmt = $pdo->prepare("
    SELECT id, status, customer_note, review_note, created_at
    FROM portal_orders
    WHERE customer_id = ? AND status IN ('pending_review', 'priced', 'rejected')
    ORDER BY created_at DESC
");
$pendingPortalOrdersStmt->execute([$customerId]);
$pendingPortalOrders = $pendingPortalOrdersStmt->fetchAll(PDO::FETCH_ASSOC);

$pendingPortalOrderItemsByOrder = [];
if ($pendingPortalOrders) {
    $pendingIds = array_column($pendingPortalOrders, 'id');
    $pendingPlaceholders = implode(',', array_fill(0, count($pendingIds), '?'));
    $pendingItemsStmt = $pdo->prepare("
        SELECT poi.portal_order_id, p.name AS product_name, poi.quantity
        FROM portal_order_items poi
        JOIN products p ON poi.product_id = p.id
        WHERE poi.portal_order_id IN ($pendingPlaceholders)
        ORDER BY poi.id
    ");
    $pendingItemsStmt->execute($pendingIds);
    while ($row = $pendingItemsStmt->fetch(PDO::FETCH_ASSOC)) {
        $pendingPortalOrderItemsByOrder[$row['portal_order_id']][] = $row;
    }
}

$portalOrderStatusLabelMap = [
    'pending_review' => 'قيد المراجعة',
    'priced' => 'تم التسعير — بانتظار التأكيد النهائي',
    'rejected' => 'مرفوض',
];
$portalOrderStatusBadgeMap = [
    'pending_review' => 'bg-amber-500',
    'priced' => 'bg-sky-600',
    'rejected' => 'bg-rose-600',
];

$walletStmt = $pdo->prepare("
    SELECT id, amount, type, notes, transaction_date, created_at
    FROM customer_wallet_transactions
    WHERE customer_id = ?
    ORDER BY transaction_date DESC, created_at DESC
    LIMIT 50
");
$walletStmt->execute([$customerId]);
$walletMoves = $walletStmt->fetchAll(PDO::FETCH_ASSOC);

$productsStmt = $pdo->prepare("
    SELECT p.id, p.name, p.sku, p.type, p.unit_price, c.name as category_name,
           COALESCE(SUM(ip.quantity), 0) AS stock
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN inventory_products ip ON ip.product_id = p.id
    WHERE p.customer_id = ?
    GROUP BY p.id
    ORDER BY p.name
");
$productsStmt->execute([$customerId]);
$customerProducts = $productsStmt->fetchAll(PDO::FETCH_ASSOC);
$orderableProducts = array_values(array_filter(
    $customerProducts,
    static fn(array $product): bool => ($product['type'] ?? '') === 'final'
));

$balanceStmt = $pdo->prepare("
    SELECT SUM(total_amount - paid_amount) AS due
    FROM orders
    WHERE customer_id = ?
");
$balanceStmt->execute([$customerId]);
$dueAmount = (float)($balanceStmt->fetchColumn() ?? 0);

$presentOrderStatuses = [];
foreach ($orders as $order) {
    $presentOrderStatuses[$order['status']] = true;
}

$statusBadgeMap = [
    'new' => 'bg-indigo-600',
    'in-production' => 'bg-blue-600',
    'in-packing' => 'bg-sky-600',
    'delivering' => 'bg-amber-600',
    'delivered' => 'bg-emerald-600',
    'returned' => 'bg-rose-600',
    'returned-refunded' => 'bg-slate-600',
    'partially-returned' => 'bg-yellow-600',
    'partially-returned-refunded' => 'bg-gray-600'
];

$statusLabelMap = [
    'new' => 'طلب جديد',
    'in-production' => 'قيد الإنتاج',
    'in-packing' => 'قيد التغليف',
    'delivering' => 'قيد التوصيل',
    'delivered' => 'تم التسليم',
    'returned' => 'تم الإرجاع',
    'returned-refunded' => 'تم الإرجاع مع استرداد',
    'partially-returned' => 'إرجاع جزئي',
    'partially-returned-refunded' => 'إرجاع جزئي مع استرداد'
];

$walletTypeLabels = [
    'deposit' => 'إيداع',
    'payment' => 'دفعة',
    'refund' => 'استرداد',
    'adjustment' => 'تسوية',
    'withdrawal' => 'سحب'
];

$walletTypeBadges = [
    'deposit' => 'bg-emerald-100 text-emerald-700',
    'payment' => 'bg-blue-100 text-blue-700',
    'refund' => 'bg-rose-100 text-rose-700',
    'adjustment' => 'bg-amber-100 text-amber-700',
    'withdrawal' => 'bg-slate-100 text-slate-700'
];

$discountBasisMap = [
    'none' => 'بدون خصم',
    'product_quantity' => 'خصم على الكمية',
    'cash' => 'خصم نقدي',
    'free_sample' => 'عينات مجانية',
    'mixed' => 'خصم مركب'
];

$orderItemsByOrder = [];
$customerOrderNotesByOrder = [];

if (!empty($orders)) {
    $orderIds = array_column($orders, 'id');
    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));

    $itemsStmt = $pdo->prepare("
        SELECT oi.order_id, p.name AS product_name, oi.quantity, oi.unit_price, oi.total_price, oi.is_free_sample
        FROM order_items oi
        JOIN products p ON oi.product_id = p.id
        WHERE oi.order_id IN ($placeholders)
        ORDER BY oi.id
    ");
    $itemsStmt->execute($orderIds);

    while ($row = $itemsStmt->fetch(PDO::FETCH_ASSOC)) {
        $orderItemsByOrder[$row['order_id']][] = $row;
    }

    if ($customerOrderNotesReady) {
        $notesStmt = $pdo->prepare("
            SELECT id, order_id, note, created_at
            FROM customer_order_notes
            WHERE customer_id = ? AND order_id IN ($placeholders)
            ORDER BY created_at ASC, id ASC
        ");
        $notesStmt->execute(array_merge([$customerId], $orderIds));
        while ($row = $notesStmt->fetch(PDO::FETCH_ASSOC)) {
            $customerOrderNotesByOrder[$row['order_id']][] = $row;
        }
    }
}
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="utf-8">
  <title>بوابة العميل - <?= htmlspecialchars($customer['name']) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css">
  <script src="https://cdn.tailwindcss.com"></script>

  <script>
    tailwind.config = {
      darkMode: 'class',
      theme: {
        extend: {
          boxShadow: { soft: '0 10px 30px rgba(2,6,23,.08)' }
        }
      }
    }
  </script>

  <style>
    body { font-family: 'Cairo', sans-serif; }
    html { -webkit-font-smoothing: antialiased; -moz-osx-font-smoothing: grayscale; }
  </style>
</head>

<body class="bg-slate-50 text-slate-900 dark:bg-slate-950 dark:text-slate-100">

  <!-- Top Bar -->
  <header class="sticky top-0 z-30 border-b border-slate-200/70 bg-white/80 backdrop-blur dark:border-slate-800/70 dark:bg-slate-950/60">
    <div class="mx-auto max-w-6xl px-4 py-3 flex items-center justify-between">
      <div class="flex items-center gap-3">
        <div class="h-10 w-10 rounded-2xl bg-gradient-to-br from-white to-gray-400 flex items-center justify-center shadow-soft">
          <img src="<?= BASE_URL ?>logo.png" class="w-6 h-6">
        </div>
        <div class="leading-tight">
          <p class="text-sm text-slate-500 dark:text-slate-400">GammaVET</p>
          <p class="font-extrabold">بوابة العميل</p>
        </div>
      </div>

      <div class="flex items-center gap-2">
        <div class="hidden sm:flex items-center gap-2 rounded-2xl bg-slate-100 px-3 py-2 text-sm dark:bg-slate-900">
          <i class="bx bx-user text-lg text-slate-500"></i>
          <span class="font-bold"><?= htmlspecialchars($customer['name']); ?></span>
        </div>

        <!-- Optional: Enable Dark Mode Toggle
        <button id="themeToggle" type="button"
          class="rounded-2xl border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm hover:bg-slate-50 dark:border-slate-800 dark:bg-slate-900 dark:hover:bg-slate-800">
          <i class="bx bx-moon"></i>
        </button>
        -->
      </div>
    </div>
  </header>

  <main class="mx-auto max-w-6xl px-4 py-8 space-y-6">

    <!-- Hero -->
    <section class="relative overflow-hidden rounded-3xl border border-slate-200 bg-gradient-to-br from-cyan-600 via-blue-700 to-indigo-800 text-white shadow-soft dark:border-slate-800">
      <div class="absolute -top-24 -left-24 h-72 w-72 rounded-full bg-white/10 blur-2xl"></div>
      <div class="absolute -bottom-24 -right-24 h-72 w-72 rounded-full bg-white/10 blur-2xl"></div>

      <div class="relative p-7 sm:p-10">
        <div class="flex flex-col gap-6 sm:flex-row sm:items-end sm:justify-between">
          <div class="space-y-2">
            <h1 class="text-2xl sm:text-3xl font-extrabold">
              مرحباً <?= htmlspecialchars($customer['name']); ?>
            </h1>
            <p class="text-white/80 max-w-2xl">
              تابع أحدث الطلبات، المدفوعات، المخزون وحركة المحفظة في مكان واحد.
            </p>
          </div>
        </div>

        <div class="mt-7 grid gap-4 sm:grid-cols-3">
          <div class="rounded-2xl bg-white/10 p-5 backdrop-blur border border-white/10">
            <p class="text-sm text-white/80 mb-1">إجمالي المستحقات</p>
            <p class="text-3xl font-extrabold tracking-tight">
              <?= number_format($dueAmount, 2); ?>
              <span class="text-base font-semibold text-white/80">EGP</span>
            </p>
          </div>

          <div class="rounded-2xl bg-white/10 p-5 backdrop-blur border border-white/10">
            <p class="text-sm text-white/80 mb-1">رصيد المحفظة</p>
            <p class="text-3xl font-extrabold tracking-tight">
              <?= number_format((float)$customer['wallet_balance'], 2); ?>
              <span class="text-base font-semibold text-white/80">EGP</span>
            </p>
          </div>

          <div class="rounded-2xl bg-white/10 p-5 backdrop-blur border border-white/10">
            <p class="text-sm text-white/80 mb-1">آخر طلب</p>
            <p class="text-3xl font-extrabold tracking-tight">
              <?= !empty($orders) ? date('Y-m-d', strtotime($orders[0]['order_date'])) : 'لا توجد بيانات'; ?>
            </p>
          </div>
        </div>
      </div>
    </section>

    <!-- Factory -->
    <section class="rounded-3xl bg-white border border-slate-200 shadow-soft dark:bg-slate-900 dark:border-slate-800">
      <div class="p-6 sm:p-7">
        <div class="flex items-center gap-3 mb-5">
          <div class="h-11 w-11 rounded-2xl bg-indigo-50 text-indigo-700 flex items-center justify-center dark:bg-indigo-900/30 dark:text-indigo-200">
            <i class="bx bx-buildings text-2xl"></i>
          </div>
          <div>
            <h2 class="text-lg sm:text-xl font-extrabold">بيانات المصنع</h2>
            <p class="text-sm text-slate-500 dark:text-slate-400">تفاصيل المصنع المرتبط بحساب العميل.</p>
          </div>
        </div>

        <?php if (!empty($customer['factory_name'])): ?>
          <div class="grid gap-4 sm:grid-cols-3">
            <div class="rounded-2xl bg-slate-50 border border-slate-200 p-4 dark:bg-slate-950 dark:border-slate-800">
              <p class="text-xs text-slate-500 dark:text-slate-400 mb-1">اسم المصنع</p>
              <p class="font-extrabold"><?= htmlspecialchars($customer['factory_name']); ?></p>
            </div>

            <?php if (!empty($customer['contact_person'])): ?>
              <div class="rounded-2xl bg-slate-50 border border-slate-200 p-4 dark:bg-slate-950 dark:border-slate-800">
                <p class="text-xs text-slate-500 dark:text-slate-400 mb-1">الشخص المسؤول</p>
                <p class="font-extrabold"><?= htmlspecialchars($customer['contact_person']); ?></p>
              </div>
            <?php endif; ?>

            <?php if (!empty($customer['contact_phone'])): ?>
              <?php $factoryWhatsappUrl = whatsappUrl($customer['factory_whatsapp_number'] ?: $customer['contact_phone']); ?>
              <div class="rounded-2xl bg-slate-50 border border-slate-200 p-4 dark:bg-slate-950 dark:border-slate-800">
                <p class="text-xs text-slate-500 dark:text-slate-400 mb-1">رقم التواصل</p>
                <div class="flex items-center justify-between gap-3 dir-ltr">
                  <p class="font-extrabold text-left"><?= htmlspecialchars($customer['contact_phone']); ?></p>
                  <?php if ($factoryWhatsappUrl): ?>
                    <a
                      href="<?= htmlspecialchars($factoryWhatsappUrl, ENT_QUOTES, 'UTF-8'); ?>"
                      target="_blank"
                      rel="noopener"
                      class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-emerald-50 text-emerald-700 hover:bg-emerald-100 dark:bg-emerald-900/30 dark:text-emerald-200"
                      title="WhatsApp"
                      aria-label="WhatsApp"
                    >
                      <i class="bx bxl-whatsapp text-xl"></i>
                    </a>
                  <?php endif; ?>
                </div>
              </div>
            <?php endif; ?>
          </div>
        <?php else: ?>
          <div class="rounded-2xl bg-slate-50 border border-slate-200 p-5 text-slate-600 dark:bg-slate-950 dark:border-slate-800 dark:text-slate-300">
            لا توجد بيانات مصنع مرتبطة بهذا الحساب.
          </div>
        <?php endif; ?>
      </div>
    </section>

    <!-- Orders -->
    <section class="rounded-3xl bg-white border border-slate-200 shadow-soft dark:bg-slate-900 dark:border-slate-800">
      <div class="p-6 sm:p-7">
        <div class="mb-5 flex flex-wrap items-center justify-between gap-4">
          <div class="flex items-center gap-3">
            <div class="h-11 w-11 rounded-2xl bg-blue-50 text-blue-700 flex items-center justify-center dark:bg-blue-900/30 dark:text-blue-200">
              <i class="bx bx-receipt text-2xl"></i>
            </div>
            <div>
              <h2 class="text-lg sm:text-xl font-extrabold">طلباتك</h2>
              <p class="text-sm text-slate-500 dark:text-slate-400">افتح أي طلب لإضافة ملاحظة خاصة به.</p>
            </div>
          </div>
          <button
            type="button"
            id="toggleNewOrder"
            class="inline-flex items-center gap-2 rounded-xl bg-blue-700 px-4 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-blue-800 disabled:cursor-not-allowed disabled:opacity-50"
            aria-controls="newOrderPanel"
            aria-expanded="<?= $portalOrderFlash && ($portalOrderFlash['type'] ?? '') !== 'success' ? 'true' : 'false'; ?>"
            <?= !$orderableProducts ? 'disabled' : ''; ?>
          >
            <i class="bx bx-plus-circle text-lg"></i>
            إضافة طلب جديد
          </button>
        </div>

        <?php if ($portalOrderFlash): ?>
          <div class="mb-5 rounded-xl border px-4 py-3 text-sm font-bold <?= ($portalOrderFlash['type'] ?? '') === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-rose-200 bg-rose-50 text-rose-700'; ?>">
            <?= htmlspecialchars($portalOrderFlash['message'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
          </div>
        <?php endif; ?>

        <?php if ($orderableProducts): ?>
          <div
            id="newOrderPanel"
            class="<?= $portalOrderFlash && ($portalOrderFlash['type'] ?? '') !== 'success' ? '' : 'hidden'; ?> mb-6 rounded-2xl border border-blue-200 bg-blue-50/60 p-4 sm:p-5 dark:border-blue-900 dark:bg-blue-950/30"
          >
            <div class="mb-4">
              <h3 class="font-extrabold text-slate-900 dark:text-slate-100">طلب جديد</h3>
              <p class="text-xs leading-6 text-slate-500 dark:text-slate-400">اختر المنتجات والكميات المطلوبة. سيصل الطلب مباشرة إلى مسؤول المبيعات.</p>
            </div>
            <form method="post" action="customer_portal.php?token=<?= rawurlencode($token); ?>#newOrderPanel" class="space-y-4" id="newOrderForm">
              <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">
              <input type="hidden" name="portal_action" value="create_order">
              <input type="hidden" name="portal_note_csrf" value="<?= htmlspecialchars($portalNoteCsrf, ENT_QUOTES, 'UTF-8'); ?>">

              <div id="newOrderItems" class="space-y-3">
                <div class="grid gap-3 rounded-xl border border-blue-100 bg-white p-3 sm:grid-cols-[minmax(0,1fr)_130px_42px] dark:border-slate-800 dark:bg-slate-950" data-order-item-row>
                  <div>
                    <label class="mb-1 block text-xs font-bold text-slate-600 dark:text-slate-300">المنتج</label>
                    <select name="items[0][product_id]" required class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-900 focus:border-blue-500 focus:outline-none focus:ring-4 focus:ring-blue-100 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-100">
                      <option value="">اختر المنتج</option>
                      <?php foreach ($orderableProducts as $product): ?>
                        <option value="<?= (int)$product['id']; ?>">
                          <?= htmlspecialchars($product['name'] . (!empty($product['sku']) ? ' — ' . $product['sku'] : ''), ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div>
                    <label class="mb-1 block text-xs font-bold text-slate-600 dark:text-slate-300">الكمية</label>
                    <input type="number" name="items[0][quantity]" min="1" max="1000000" step="1" value="1" required class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-900 focus:border-blue-500 focus:outline-none focus:ring-4 focus:ring-blue-100 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-100">
                  </div>
                  <button type="button" class="remove-order-item mt-5 inline-flex h-10 w-10 items-center justify-center rounded-xl text-slate-400 transition hover:bg-rose-50 hover:text-rose-600 disabled:cursor-not-allowed disabled:opacity-30 dark:hover:bg-rose-950/30" aria-label="حذف المنتج" disabled>
                    <i class="bx bx-trash text-xl"></i>
                  </button>
                </div>
              </div>

              <button type="button" id="addOrderItem" class="inline-flex items-center gap-2 rounded-xl border border-blue-200 bg-white px-4 py-2 text-sm font-bold text-blue-700 transition hover:bg-blue-50 dark:border-blue-900 dark:bg-slate-950 dark:text-blue-200">
                <i class="bx bx-plus"></i>
                إضافة منتج آخر
              </button>

              <div>
                <label for="newOrderNote" class="mb-1 block text-sm font-bold text-slate-700 dark:text-slate-200">ملاحظات الطلب (اختياري)</label>
                <textarea id="newOrderNote" name="new_order_note" rows="3" maxlength="2000" class="w-full rounded-xl border border-blue-200 bg-white px-4 py-3 text-sm text-slate-900 placeholder-slate-400 focus:border-blue-500 focus:outline-none focus:ring-4 focus:ring-blue-100 dark:border-slate-800 dark:bg-slate-950 dark:text-slate-100 dark:focus:ring-blue-900/40" placeholder="أضف أي تفاصيل أو تعليمات خاصة بالطلب..."></textarea>
              </div>

              <div class="flex flex-wrap gap-3">
                <button type="submit" class="inline-flex items-center gap-2 rounded-xl bg-blue-700 px-5 py-2.5 text-sm font-bold text-white transition hover:bg-blue-800">
                  <i class="bx bx-send text-lg"></i>
                  إرسال الطلب
                </button>
                <button type="button" id="cancelNewOrder" class="rounded-xl px-4 py-2.5 text-sm font-bold text-slate-600 transition hover:bg-white dark:text-slate-300 dark:hover:bg-slate-950">إلغاء</button>
              </div>
            </form>
          </div>

          <template id="newOrderItemTemplate">
            <div class="grid gap-3 rounded-xl border border-blue-100 bg-white p-3 sm:grid-cols-[minmax(0,1fr)_130px_42px] dark:border-slate-800 dark:bg-slate-950" data-order-item-row>
              <div>
                <label class="mb-1 block text-xs font-bold text-slate-600 dark:text-slate-300">المنتج</label>
                <select name="items[__INDEX__][product_id]" required class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-900 focus:border-blue-500 focus:outline-none focus:ring-4 focus:ring-blue-100 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-100">
                  <option value="">اختر المنتج</option>
                  <?php foreach ($orderableProducts as $product): ?>
                    <option value="<?= (int)$product['id']; ?>">
                      <?= htmlspecialchars($product['name'] . (!empty($product['sku']) ? ' — ' . $product['sku'] : ''), ENT_QUOTES, 'UTF-8'); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div>
                <label class="mb-1 block text-xs font-bold text-slate-600 dark:text-slate-300">الكمية</label>
                <input type="number" name="items[__INDEX__][quantity]" min="1" max="1000000" step="1" value="1" required class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-900 focus:border-blue-500 focus:outline-none focus:ring-4 focus:ring-blue-100 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-100">
              </div>
              <button type="button" class="remove-order-item mt-5 inline-flex h-10 w-10 items-center justify-center rounded-xl text-slate-400 transition hover:bg-rose-50 hover:text-rose-600 dark:hover:bg-rose-950/30" aria-label="حذف المنتج">
                <i class="bx bx-trash text-xl"></i>
              </button>
            </div>
          </template>
        <?php else: ?>
          <div class="mb-5 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200">
            لا توجد منتجات نهائية متاحة لإنشاء طلب جديد حالياً.
          </div>
        <?php endif; ?>

        <?php if ($pendingPortalOrders): ?>
          <div class="mb-6 space-y-3">
            <h3 class="font-extrabold text-slate-900 dark:text-slate-100">طلبات قيد المراجعة</h3>
            <p class="text-xs text-slate-500 dark:text-slate-400 -mt-2">
              هذه الطلبات تحت مراجعة فريق المبيعات ولم يتم تأكيدها بعد، لذلك لا يظهر لها سعر حالياً.
            </p>
            <?php foreach ($pendingPortalOrders as $pendingOrder): ?>
              <?php
                $pendingItems = $pendingPortalOrderItemsByOrder[$pendingOrder['id']] ?? [];
                $pendingStatusClass = $portalOrderStatusBadgeMap[$pendingOrder['status']] ?? 'bg-slate-500';
                $pendingStatusLabel = $portalOrderStatusLabelMap[$pendingOrder['status']] ?? $pendingOrder['status'];
              ?>
              <article class="rounded-2xl border border-amber-200 bg-amber-50/60 p-5 dark:border-amber-900 dark:bg-amber-950/20">
                <div class="flex items-start justify-between gap-4 flex-wrap">
                  <div class="space-y-1">
                    <div class="flex items-center gap-2 flex-wrap">
                      <p class="text-sm font-extrabold">طلب رقم #<?= (int)$pendingOrder['id']; ?></p>
                      <span class="inline-flex items-center gap-2 px-3 py-1 rounded-full text-white text-xs <?= $pendingStatusClass; ?>">
                        <?= htmlspecialchars($pendingStatusLabel); ?>
                      </span>
                    </div>
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                      تاريخ الإرسال: <?= date('Y-m-d', strtotime($pendingOrder['created_at'])); ?>
                    </p>
                  </div>
                </div>

                <?php if ($pendingItems): ?>
                  <ul class="mt-3 space-y-1 text-sm text-slate-700 dark:text-slate-200">
                    <?php foreach ($pendingItems as $item): ?>
                      <li>• <?= htmlspecialchars($item['product_name']); ?> — الكمية: <?= (int)$item['quantity']; ?></li>
                    <?php endforeach; ?>
                  </ul>
                <?php endif; ?>

                <?php if (!empty($pendingOrder['customer_note'])): ?>
                  <p class="mt-3 text-xs text-slate-600 dark:text-slate-300">ملاحظتك: <?= nl2br(htmlspecialchars($pendingOrder['customer_note'])); ?></p>
                <?php endif; ?>

                <?php if ($pendingOrder['status'] === 'rejected' && !empty($pendingOrder['review_note'])): ?>
                  <p class="mt-3 text-xs font-bold text-rose-700 dark:text-rose-300">سبب الرفض: <?= nl2br(htmlspecialchars($pendingOrder['review_note'])); ?></p>
                <?php endif; ?>
              </article>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <?php if ($orders): ?>
          <div class="mb-5 grid gap-3 md:grid-cols-[1fr_180px_190px]">
            <div class="relative">
              <i class="bx bx-search absolute right-4 top-1/2 -translate-y-1/2 text-xl text-slate-400"></i>
              <input
                type="search"
                id="orderSearch"
                class="w-full rounded-2xl border border-slate-200 bg-white py-3 pr-12 pl-4 text-sm text-slate-900 placeholder-slate-400 focus:border-blue-500 focus:outline-none focus:ring-4 focus:ring-blue-100 dark:border-slate-800 dark:bg-slate-950 dark:text-slate-100 dark:focus:ring-blue-900/40"
                placeholder="ابحث برقم الطلب أو المنتج أو الملاحظات"
              >
            </div>
            <select
              id="orderStatusFilter"
              class="rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm font-bold text-slate-700 focus:border-blue-500 focus:outline-none focus:ring-4 focus:ring-blue-100 dark:border-slate-800 dark:bg-slate-950 dark:text-slate-100 dark:focus:ring-blue-900/40"
            >
              <option value="">كل الحالات</option>
              <?php foreach (array_keys($presentOrderStatuses) as $status): ?>
                <option value="<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>">
                  <?= htmlspecialchars($statusLabelMap[$status] ?? $status); ?>
                </option>
              <?php endforeach; ?>
            </select>
            <select
              id="orderSort"
              class="rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm font-bold text-slate-700 focus:border-blue-500 focus:outline-none focus:ring-4 focus:ring-blue-100 dark:border-slate-800 dark:bg-slate-950 dark:text-slate-100 dark:focus:ring-blue-900/40"
            >
              <option value="date-desc">الأحدث أولاً</option>
              <option value="date-asc">الأقدم أولاً</option>
              <option value="total-desc">القيمة من الأعلى</option>
              <option value="total-asc">القيمة من الأقل</option>
              <option value="due-desc">المتبقي من الأعلى</option>
              <option value="due-asc">المتبقي من الأقل</option>
            </select>
          </div>

          <div id="ordersList" class="space-y-3">
            <?php foreach ($orders as $order): ?>
              <?php
                $orderItems = $orderItemsByOrder[$order['id']] ?? [];
                $customerOrderNotes = $customerOrderNotesByOrder[$order['id']] ?? [];
                $shippingAmount = ($order['shipping_cost_type'] === 'manual') ? (float)$order['shipping_cost'] : 0;
                $orderDue = max(0, (float)$order['total_amount'] - (float)$order['paid_amount']);
                $statusClass = $statusBadgeMap[$order['status']] ?? 'bg-slate-600';
                $statusLabel = $statusLabelMap[$order['status']] ?? $order['status'];
                $discountLabel = $discountBasisMap[$order['discount_basis']] ?? 'خصم غير محدد';
                $orderProductNames = implode(' ', array_column($orderItems, 'product_name'));
                $customerNoteSearchText = implode(' ', array_column($customerOrderNotes, 'note'));
                $orderSearchText = trim(implode(' ', [
                    $order['internal_id'],
                    $order['status'],
                    $statusLabel,
                    $order['order_date'],
                    $order['notes'],
                    $customerNoteSearchText,
                    $orderProductNames
                ]));
              ?>

              <article
                class="portal-order rounded-2xl border border-slate-200 bg-white overflow-hidden dark:border-slate-800 dark:bg-slate-950"
                data-status="<?= htmlspecialchars($order['status'], ENT_QUOTES, 'UTF-8'); ?>"
                data-date="<?= htmlspecialchars($order['order_date'], ENT_QUOTES, 'UTF-8'); ?>"
                data-total="<?= htmlspecialchars((string)(float)$order['total_amount'], ENT_QUOTES, 'UTF-8'); ?>"
                data-due="<?= htmlspecialchars((string)$orderDue, ENT_QUOTES, 'UTF-8'); ?>"
                data-search="<?= htmlspecialchars($orderSearchText, ENT_QUOTES, 'UTF-8'); ?>"
              >
                <button
                  type="button"
                  class="w-full flex items-start justify-between gap-4 p-5 sm:p-6 text-right hover:bg-slate-50 dark:hover:bg-slate-900/50 transition toggle-order"
                  data-target="order-<?= $order['id']; ?>"
                  aria-expanded="false"
                >
                  <div class="space-y-1">
                    <div class="flex items-center gap-2 flex-wrap">
                      <p class="text-base font-extrabold"><?= htmlspecialchars($order['internal_id']); ?></p>
                      <span class="inline-flex items-center gap-2 px-3 py-1 rounded-full text-white text-xs <?= $statusClass; ?>">
                        <?= htmlspecialchars($statusLabel); ?>
                      </span>
                    </div>
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                      تاريخ الطلب: <?= date('Y-m-d', strtotime($order['order_date'])); ?>
                    </p>
                  </div>

                  <div class="text-left space-y-1 min-w-[160px]">
                    <p class="text-lg font-extrabold text-slate-900 dark:text-slate-100">
                      <?= number_format($order['total_amount'], 2); ?>
                      <span class="text-xs font-semibold text-slate-500 dark:text-slate-400">EGP</span>
                    </p>
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                      المدفوع: <?= number_format($order['paid_amount'], 2); ?> • المتبقي: <?= number_format($orderDue, 2); ?>
                    </p>
                  </div>

                  <i class="bx bx-chevron-down text-2xl text-slate-400 mt-1 shrink-0 transition-transform duration-200 chevron"></i>
                </button>

                <div id="order-<?= $order['id']; ?>" class="order-details max-h-0 overflow-hidden transition-[max-height] duration-300 ease-in-out">
                  <div class="px-5 sm:px-6 pb-6 space-y-4 text-sm text-slate-700 dark:text-slate-200">
                    <div class="flex flex-wrap items-center gap-2">
                      <span class="inline-flex items-center px-3 py-1 rounded-full bg-slate-100 text-slate-700 text-xs dark:bg-slate-900 dark:text-slate-200">
                        نوع الخصم: <?= htmlspecialchars($discountLabel); ?> — <?= number_format($order['discount_amount'], 2); ?> EGP
                      </span>
                      <span class="inline-flex items-center px-3 py-1 rounded-full bg-slate-100 text-slate-700 text-xs dark:bg-slate-900 dark:text-slate-200">
                        الشحن: <?= number_format($shippingAmount, 2); ?> EGP
                      </span>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                      <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4 dark:bg-slate-900 dark:border-slate-800">
                        <p class="text-xs text-slate-500 dark:text-slate-400 mb-1">إجمالي الطلب</p>
                        <p class="font-extrabold"><?= number_format($order['total_amount'], 2); ?> EGP</p>
                      </div>
                      <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4 dark:bg-slate-900 dark:border-slate-800">
                        <p class="text-xs text-slate-500 dark:text-slate-400 mb-1">المدفوع</p>
                        <p class="font-extrabold text-emerald-600"><?= number_format($order['paid_amount'], 2); ?> EGP</p>
                      </div>
                      <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4 dark:bg-slate-900 dark:border-slate-800">
                        <p class="text-xs text-slate-500 dark:text-slate-400 mb-1">المتبقي</p>
                        <p class="font-extrabold text-rose-600"><?= number_format($orderDue, 2); ?> EGP</p>
                      </div>
                    </div>

                    <div class="space-y-2">
                      <h4 class="font-extrabold text-slate-900 dark:text-slate-100">تفاصيل المنتجات</h4>

                      <?php if ($orderItems): ?>
                        <div class="rounded-2xl border border-slate-200 overflow-x-auto dark:border-slate-800">
                          <table class="min-w-full text-sm">
                            <thead class="bg-slate-50 text-slate-600 dark:bg-slate-900 dark:text-slate-300">
                              <tr>
                                <th class="px-4 py-3 text-right font-bold">المنتج</th>
                                <th class="px-4 py-3 text-center font-bold">الكمية</th>
                                <th class="px-4 py-3 text-center font-bold">سعر الوحدة</th>
                                <th class="px-4 py-3 text-center font-bold">الإجمالي</th>
                              </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                              <?php foreach ($orderItems as $line): ?>
                                <tr class="<?= $line['is_free_sample'] ? 'bg-amber-50 dark:bg-amber-900/20' : 'bg-white dark:bg-slate-950'; ?>">
                                  <td class="px-4 py-3 font-bold">
                                    <?= htmlspecialchars($line['product_name']); ?>
                                    <?php if ($line['is_free_sample']): ?>
                                      <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs bg-amber-100 text-amber-800 mr-2 dark:bg-amber-900/40 dark:text-amber-200">
                                        عينة مجانية
                                      </span>
                                    <?php endif; ?>
                                  </td>
                                  <td class="px-4 py-3 text-center"><?= (int)$line['quantity']; ?></td>
                                  <td class="px-4 py-3 text-center"><?= number_format($line['unit_price'], 2); ?></td>
                                  <td class="px-4 py-3 text-center font-extrabold"><?= number_format($line['total_price'], 2); ?></td>
                                </tr>
                              <?php endforeach; ?>
                            </tbody>
                          </table>
                        </div>
                      <?php else: ?>
                        <div class="rounded-2xl bg-slate-50 border border-slate-200 p-4 text-slate-600 dark:bg-slate-900 dark:border-slate-800 dark:text-slate-300">
                          لا توجد منتجات مسجلة لهذا الطلب.
                        </div>
                      <?php endif; ?>
                    </div>

                    <?php if (!empty($order['notes'])): ?>
                      <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4 dark:bg-slate-900 dark:border-slate-800">
                        <p class="font-extrabold text-slate-900 dark:text-slate-100 mb-1">ملاحظات الطلب</p>
                        <p class="text-slate-700 dark:text-slate-200"><?= nl2br(htmlspecialchars($order['notes'])); ?></p>
                      </div>
                    <?php endif; ?>

                    <?php if ($customerOrderNotesReady): ?>
                      <div class="rounded-2xl border border-blue-200 bg-blue-50/60 p-4 dark:border-blue-900 dark:bg-blue-950/30">
                        <div class="flex items-center justify-between gap-3 mb-3">
                          <div>
                            <p class="font-extrabold text-slate-900 dark:text-slate-100">ملاحظاتك على الطلب</p>
                            <p class="text-xs text-slate-500 dark:text-slate-400">يمكنك إضافة ملاحظة جديدة، وستظهر لفريق GammaVET.</p>
                          </div>
                          <span class="inline-flex items-center rounded-full bg-white px-3 py-1 text-xs font-bold text-blue-700 dark:bg-slate-900 dark:text-blue-200">
                            <?= count($customerOrderNotes); ?> ملاحظة
                          </span>
                        </div>

                        <?php if ($portalNoteFlash && (int)($portalNoteFlash['order_id'] ?? 0) === (int)$order['id']): ?>
                          <div class="mb-3 rounded-xl border px-4 py-3 text-sm font-bold <?= ($portalNoteFlash['type'] ?? '') === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-rose-200 bg-rose-50 text-rose-700'; ?>">
                            <?= htmlspecialchars($portalNoteFlash['message'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                          </div>
                        <?php endif; ?>

                        <?php if ($customerOrderNotes): ?>
                          <div class="mb-4 space-y-2">
                            <?php foreach ($customerOrderNotes as $customerNote): ?>
                              <div class="rounded-xl border border-blue-100 bg-white p-3 dark:border-slate-800 dark:bg-slate-950">
                                <p class="text-slate-700 dark:text-slate-200"><?= nl2br(htmlspecialchars($customerNote['note'], ENT_QUOTES, 'UTF-8')); ?></p>
                                <p class="mt-2 text-[11px] text-slate-400"><?= date('Y-m-d H:i', strtotime($customerNote['created_at'])); ?></p>
                              </div>
                            <?php endforeach; ?>
                          </div>
                        <?php endif; ?>

                        <form method="post" action="customer_portal.php?token=<?= rawurlencode($token); ?>#order-<?= (int)$order['id']; ?>" class="space-y-3">
                          <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">
                          <input type="hidden" name="portal_action" value="add_order_note">
                          <input type="hidden" name="order_id" value="<?= (int)$order['id']; ?>">
                          <input type="hidden" name="portal_note_csrf" value="<?= htmlspecialchars($portalNoteCsrf, ENT_QUOTES, 'UTF-8'); ?>">
                          <label for="customer-order-note-<?= (int)$order['id']; ?>" class="block text-sm font-bold text-slate-700 dark:text-slate-200">إضافة ملاحظة</label>
                          <textarea
                            id="customer-order-note-<?= (int)$order['id']; ?>"
                            name="customer_order_note"
                            rows="3"
                            maxlength="2000"
                            required
                            class="w-full rounded-xl border border-blue-200 bg-white px-4 py-3 text-sm text-slate-900 placeholder-slate-400 focus:border-blue-500 focus:outline-none focus:ring-4 focus:ring-blue-100 dark:border-slate-800 dark:bg-slate-950 dark:text-slate-100 dark:focus:ring-blue-900/40"
                            placeholder="اكتب ملاحظتك الخاصة بهذا الطلب..."
                          ></textarea>
                          <button type="submit" class="inline-flex items-center gap-2 rounded-xl bg-blue-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-blue-800 transition">
                            <i class="bx bx-message-square-add text-lg"></i>
                            إرسال الملاحظة
                          </button>
                        </form>
                      </div>
                    <?php endif; ?>
                  </div>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
          <div id="ordersEmpty" class="hidden rounded-2xl bg-slate-50 border border-slate-200 p-5 text-slate-600 dark:bg-slate-950 dark:border-slate-800 dark:text-slate-300">
            لا توجد طلبات مطابقة للبحث الحالي.
          </div>
        <?php else: ?>
          <div class="rounded-2xl bg-slate-50 border border-slate-200 p-5 text-slate-600 dark:bg-slate-950 dark:border-slate-800 dark:text-slate-300">
            لا توجد طلبات متاحة حالياً.
          </div>
        <?php endif; ?>
      </div>
    </section>

    <!-- Wallet + Inventory -->
    <section class="grid gap-6 md:grid-cols-2">
      <!-- Wallet -->
      <div class="rounded-3xl bg-white border border-slate-200 shadow-soft dark:bg-slate-900 dark:border-slate-800">
        <div class="p-6 sm:p-7">
          <div class="flex items-center gap-3 mb-5">
            <div class="h-11 w-11 rounded-2xl bg-emerald-50 text-emerald-700 flex items-center justify-center dark:bg-emerald-900/30 dark:text-emerald-200">
              <i class="bx bx-wallet text-2xl"></i>
            </div>
            <div>
              <h2 class="text-lg sm:text-xl font-extrabold">حركة المحفظة</h2>
              <p class="text-sm text-slate-500 dark:text-slate-400">آخر 50 عملية على المحفظة.</p>
            </div>
          </div>

          <?php if ($walletMoves): ?>
            <div class="rounded-2xl border border-slate-200 overflow-x-auto dark:border-slate-800">
              <table class="min-w-full divide-y divide-slate-100 text-sm dark:divide-slate-800">
                <thead class="bg-slate-50 text-slate-600 dark:bg-slate-950 dark:text-slate-300">
                  <tr>
                    <th class="px-4 py-3 text-right font-bold">التاريخ</th>
                    <th class="px-4 py-3 text-right font-bold">النوع</th>
                    <th class="px-4 py-3 text-right font-bold">المبلغ</th>
                    <th class="px-4 py-3 text-right font-bold">الملاحظات</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                  <?php foreach ($walletMoves as $move): ?>
                    <tr class="hover:bg-slate-50 dark:hover:bg-slate-950/60 transition">
                      <td class="px-4 py-3"><?= date('Y-m-d', strtotime($move['transaction_date'])); ?></td>
                      <?php
                        $typeLabel = $walletTypeLabels[$move['type']] ?? $move['type'];
                        $typeBadge = $walletTypeBadges[$move['type']] ?? 'bg-slate-100 text-slate-700';
                      ?>
                      <td class="px-4 py-3">
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-bold <?= $typeBadge; ?>">
                          <?= htmlspecialchars($typeLabel); ?>
                        </span>
                      </td>
                      <td class="px-4 py-3 font-extrabold"><?= number_format($move['amount'], 2); ?> EGP</td>
                      <td class="px-4 py-3 text-slate-600 dark:text-slate-300"><?= htmlspecialchars($move['notes']); ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php else: ?>
            <div class="rounded-2xl bg-slate-50 border border-slate-200 p-5 text-slate-600 dark:bg-slate-950 dark:border-slate-800 dark:text-slate-300">
              لا توجد حركات مسجلة حتى الآن.
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Products -->
      <div class="rounded-3xl bg-white border border-slate-200 shadow-soft dark:bg-slate-900 dark:border-slate-800">
        <div class="p-6 sm:p-7">
          <div class="flex items-center gap-3 mb-5">
            <div class="h-11 w-11 rounded-2xl bg-amber-50 text-amber-700 flex items-center justify-center dark:bg-amber-900/30 dark:text-amber-200">
              <i class="bx bx-package text-2xl"></i>
            </div>
            <div>
              <h2 class="text-lg sm:text-xl font-extrabold">منتجاتك</h2>
              <p class="text-sm text-slate-500 dark:text-slate-400">قائمة بجميع المنتجات المسجلة والمخزون المتاح.</p>
            </div>
          </div>

          <?php if ($customerProducts): ?>
            <div class="mb-5 grid gap-3 md:grid-cols-[1fr_150px_170px]">
              <div class="relative">
                <i class="bx bx-search absolute right-4 top-1/2 -translate-y-1/2 text-xl text-slate-400"></i>
                <input
                  type="search"
                  id="productSearch"
                  class="w-full rounded-2xl border border-slate-200 bg-white py-3 pr-12 pl-4 text-sm text-slate-900 placeholder-slate-400 focus:border-amber-500 focus:outline-none focus:ring-4 focus:ring-amber-100 dark:border-slate-800 dark:bg-slate-950 dark:text-slate-100 dark:focus:ring-amber-900/40"
                  placeholder="ابحث باسم المنتج أو SKU"
                >
              </div>
              <select
                id="productStockFilter"
                class="rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm font-bold text-slate-700 focus:border-amber-500 focus:outline-none focus:ring-4 focus:ring-amber-100 dark:border-slate-800 dark:bg-slate-950 dark:text-slate-100 dark:focus:ring-amber-900/40"
              >
                <option value="">كل المخزون</option>
                <option value="in-stock">متوفر</option>
                <option value="out-of-stock">غير متوفر</option>
              </select>
              <select
                id="productSort"
                class="rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm font-bold text-slate-700 focus:border-amber-500 focus:outline-none focus:ring-4 focus:ring-amber-100 dark:border-slate-800 dark:bg-slate-950 dark:text-slate-100 dark:focus:ring-amber-900/40"
              >
                <option value="name-asc">الاسم أ-ي</option>
                <option value="name-desc">الاسم ي-أ</option>
                <option value="stock-desc">المخزون من الأعلى</option>
                <option value="stock-asc">المخزون من الأقل</option>
              </select>
            </div>
            <div class="rounded-2xl border border-slate-200 overflow-x-auto dark:border-slate-800">
              <table class="min-w-full divide-y divide-slate-100 text-sm dark:divide-slate-800">
                <thead class="bg-slate-50 text-slate-600 dark:bg-slate-950 dark:text-slate-300">
                  <tr>
                    <th class="px-4 py-3 text-right font-bold">المنتج</th>
                    <th class="px-4 py-3 text-center font-bold">التصنيف</th>
                    <th class="px-4 py-3 text-center font-bold">المخزون</th>
                  </tr>
                </thead>
                <tbody id="productsList" class="divide-y divide-slate-100 dark:divide-slate-800">
                  <?php foreach ($customerProducts as $product): ?>
                    <?php
                      $productSearchText = trim(implode(' ', [
                          $product['name'],
                          $product['sku'],
                          $product['category_name']
                      ]));
                    ?>
                    <tr
                      class="portal-product hover:bg-slate-50 dark:hover:bg-slate-950/60 transition"
                      data-name="<?= htmlspecialchars((string)$product['name'], ENT_QUOTES, 'UTF-8'); ?>"
                      data-stock="<?= htmlspecialchars((string)(float)$product['stock'], ENT_QUOTES, 'UTF-8'); ?>"
                      data-search="<?= htmlspecialchars($productSearchText, ENT_QUOTES, 'UTF-8'); ?>"
                    >
                      <td class="px-4 py-3">
                        <div class="font-bold"><?= htmlspecialchars($product['name']); ?></div>
                        <div class="text-[10px] text-slate-400 font-mono"><?= htmlspecialchars($product['sku']); ?></div>
                      </td>
                      <td class="px-4 py-3 text-center text-slate-600 dark:text-slate-400">
                        <?= htmlspecialchars($product['category_name'] ?? '-'); ?>
                      </td>
                      <td class="px-4 py-3 text-center">
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-extrabold <?= $product['stock'] > 0 ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-400'; ?>">
                          <?= number_format((float)$product['stock']); ?>
                        </span>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <div id="productsEmpty" class="hidden mt-3 rounded-2xl bg-slate-50 border border-slate-200 p-5 text-slate-600 dark:bg-slate-950 dark:border-slate-800 dark:text-slate-300">
              لا توجد منتجات مطابقة للبحث الحالي.
            </div>
          <?php else: ?>
            <div class="rounded-2xl bg-slate-50 border border-slate-200 p-5 text-slate-600 dark:bg-slate-950 dark:border-slate-800 dark:text-slate-300">
              لا تتوفر بيانات منتجات حالياً.
            </div>
          <?php endif; ?>
        </div>
      </div>
    </section>

    <footer class="py-4 text-center text-xs text-slate-400">
      GammaVET © <?= date('Y'); ?>
    </footer>

  </main>

  <script>
    const newOrderToggle = document.getElementById('toggleNewOrder');
    const newOrderPanel = document.getElementById('newOrderPanel');
    const cancelNewOrder = document.getElementById('cancelNewOrder');
    const addOrderItem = document.getElementById('addOrderItem');
    const newOrderItems = document.getElementById('newOrderItems');
    const newOrderItemTemplate = document.getElementById('newOrderItemTemplate');
    let nextOrderItemIndex = 1;

    function setNewOrderPanel(open) {
      if (!newOrderPanel || !newOrderToggle) return;
      newOrderPanel.classList.toggle('hidden', !open);
      newOrderToggle.setAttribute('aria-expanded', String(open));
      if (open) {
        newOrderPanel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      }
    }

    function updateOrderItemRemoveButtons() {
      if (!newOrderItems) return;
      const rows = newOrderItems.querySelectorAll('[data-order-item-row]');
      rows.forEach((row) => {
        const removeButton = row.querySelector('.remove-order-item');
        if (removeButton) removeButton.disabled = rows.length === 1;
      });
    }

    newOrderToggle?.addEventListener('click', () => {
      setNewOrderPanel(newOrderToggle.getAttribute('aria-expanded') !== 'true');
    });
    cancelNewOrder?.addEventListener('click', () => setNewOrderPanel(false));

    addOrderItem?.addEventListener('click', () => {
      if (!newOrderItems || !newOrderItemTemplate) return;
      const markup = newOrderItemTemplate.innerHTML.replaceAll('__INDEX__', String(nextOrderItemIndex++));
      newOrderItems.insertAdjacentHTML('beforeend', markup);
      updateOrderItemRemoveButtons();
      newOrderItems.lastElementChild?.querySelector('select')?.focus();
    });

    newOrderItems?.addEventListener('click', (event) => {
      const removeButton = event.target.closest('.remove-order-item');
      if (!removeButton || removeButton.disabled) return;
      removeButton.closest('[data-order-item-row]')?.remove();
      updateOrderItemRemoveButtons();
    });
    updateOrderItemRemoveButtons();

    // Orders accordion: smooth expand/collapse + chevron rotate
    document.querySelectorAll('.toggle-order').forEach((button) => {
      button.addEventListener('click', function () {
        const targetId = this.getAttribute('data-target');
        const panel = document.getElementById(targetId);
        if (!panel) return;

        const chevron = this.querySelector('.chevron');
        const isOpen = this.getAttribute('aria-expanded') === 'true';

        this.setAttribute('aria-expanded', String(!isOpen));

        if (!isOpen) {
          panel.classList.remove('max-h-0');
          panel.style.maxHeight = panel.scrollHeight + 'px';
          if (chevron) chevron.style.transform = 'rotate(180deg)';
        } else {
          panel.style.maxHeight = panel.scrollHeight + 'px';
          requestAnimationFrame(() => {
            panel.style.maxHeight = '0px';
          });
          if (chevron) chevron.style.transform = 'rotate(0deg)';
        }
      });
    });

    const normalizeText = (value) => (value || '').toString().trim().toLowerCase();

    const ordersList = document.getElementById('ordersList');
    const orderSearch = document.getElementById('orderSearch');
    const orderStatusFilter = document.getElementById('orderStatusFilter');
    const orderSort = document.getElementById('orderSort');
    const ordersEmpty = document.getElementById('ordersEmpty');

    function applyOrderControls() {
      if (!ordersList) return;

      const query = normalizeText(orderSearch?.value);
      const status = orderStatusFilter?.value || '';
      const sortBy = orderSort?.value || 'date-desc';
      const rows = Array.from(ordersList.querySelectorAll('.portal-order'));

      rows.sort((a, b) => {
        if (sortBy === 'date-asc' || sortBy === 'date-desc') {
          const diff = new Date(a.dataset.date || 0) - new Date(b.dataset.date || 0);
          return sortBy === 'date-asc' ? diff : -diff;
        }

        const [field, direction] = sortBy.split('-');
        const diff = Number(a.dataset[field] || 0) - Number(b.dataset[field] || 0);
        return direction === 'asc' ? diff : -diff;
      });

      let visibleCount = 0;
      rows.forEach((row) => {
        const matchesSearch = !query || normalizeText(row.dataset.search).includes(query);
        const matchesStatus = !status || row.dataset.status === status;
        const isVisible = matchesSearch && matchesStatus;

        row.classList.toggle('hidden', !isVisible);
        ordersList.appendChild(row);
        if (isVisible) visibleCount += 1;
      });

      if (ordersEmpty) {
        ordersEmpty.classList.toggle('hidden', visibleCount > 0);
      }
    }

    [orderSearch, orderStatusFilter, orderSort].forEach((control) => {
      if (control) {
        control.addEventListener('input', applyOrderControls);
        control.addEventListener('change', applyOrderControls);
      }
    });
    applyOrderControls();

    const portalNoteOrderId = <?= json_encode((int)($portalNoteFlash['order_id'] ?? 0)); ?>;
    const portalCreatedOrderId = <?= json_encode((int)($portalOrderFlash['order_id'] ?? 0)); ?>;
    const orderToOpen = portalNoteOrderId || portalCreatedOrderId;
    if (orderToOpen > 0) {
      const notePanel = document.getElementById('order-' + orderToOpen);
      const noteToggle = document.querySelector('[data-target="order-' + orderToOpen + '"]');
      if (notePanel && noteToggle && noteToggle.getAttribute('aria-expanded') !== 'true') {
        noteToggle.click();
        setTimeout(() => notePanel.scrollIntoView({ behavior: 'smooth', block: 'center' }), 100);
      }
    }

    const productsList = document.getElementById('productsList');
    const productSearch = document.getElementById('productSearch');
    const productStockFilter = document.getElementById('productStockFilter');
    const productSort = document.getElementById('productSort');
    const productsEmpty = document.getElementById('productsEmpty');

    function applyProductControls() {
      if (!productsList) return;

      const query = normalizeText(productSearch?.value);
      const stockFilter = productStockFilter?.value || '';
      const sortBy = productSort?.value || 'name-asc';
      const rows = Array.from(productsList.querySelectorAll('.portal-product'));

      rows.sort((a, b) => {
        if (sortBy === 'name-asc' || sortBy === 'name-desc') {
          const diff = normalizeText(a.dataset.name).localeCompare(normalizeText(b.dataset.name), 'ar');
          return sortBy === 'name-asc' ? diff : -diff;
        }

        const [field, direction] = sortBy.split('-');
        const diff = Number(a.dataset[field] || 0) - Number(b.dataset[field] || 0);
        return direction === 'asc' ? diff : -diff;
      });

      let visibleCount = 0;
      rows.forEach((row) => {
        const stock = Number(row.dataset.stock || 0);
        const matchesSearch = !query || normalizeText(row.dataset.search).includes(query);
        const matchesStock = !stockFilter
          || (stockFilter === 'in-stock' && stock > 0)
          || (stockFilter === 'out-of-stock' && stock <= 0);
        const isVisible = matchesSearch && matchesStock;

        row.classList.toggle('hidden', !isVisible);
        productsList.appendChild(row);
        if (isVisible) visibleCount += 1;
      });

      if (productsEmpty) {
        productsEmpty.classList.toggle('hidden', visibleCount > 0);
      }
    }

    [productSearch, productStockFilter, productSort].forEach((control) => {
      if (control) {
        control.addEventListener('input', applyProductControls);
        control.addEventListener('change', applyProductControls);
      }
    });
    applyProductControls();

    // Optional dark mode toggle (enable button in header to use)
    // const toggle = document.getElementById('themeToggle');
    // if (toggle) {
    //   toggle.addEventListener('click', () => {
    //     document.documentElement.classList.toggle('dark');
    //   });
    // }
  </script>
</body>
</html>

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

/* =========================
   Data & Access Checks
========================= */

$token = $_GET['token'] ?? ($_POST['token'] ?? '');
$token = trim((string)$token);

if (empty($token)) {
    renderMessage('رابط غير صالح. برجاء التواصل مع فريق المبيعات للحصول على رابط جديد.');
}

$stmt = $pdo->prepare("
    SELECT c.*, f.name AS factory_name, f.contact_person, f.contact_phone
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

$walletStmt = $pdo->prepare("
    SELECT amount, type, notes, created_at
    FROM customer_wallet_transactions
    WHERE customer_id = ?
    ORDER BY created_at DESC
    LIMIT 8
");
$walletStmt->execute([$customerId]);
$walletMoves = $walletStmt->fetchAll(PDO::FETCH_ASSOC);

$inventoryStmt = $pdo->prepare("
    SELECT p.name, SUM(ip.quantity) AS qty
    FROM products p
    JOIN inventory_products ip ON ip.product_id = p.id
    WHERE p.customer_id = ?
    GROUP BY p.id, p.name
    ORDER BY p.name
");
$inventoryStmt->execute([$customerId]);
$inventoryLines = $inventoryStmt->fetchAll(PDO::FETCH_ASSOC);

$balanceStmt = $pdo->prepare("
    SELECT SUM(total_amount - paid_amount) AS due
    FROM orders
    WHERE customer_id = ?
");
$balanceStmt->execute([$customerId]);
$dueAmount = (float)($balanceStmt->fetchColumn() ?? 0);

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
              <div class="rounded-2xl bg-slate-50 border border-slate-200 p-4 dark:bg-slate-950 dark:border-slate-800">
                <p class="text-xs text-slate-500 dark:text-slate-400 mb-1">رقم التواصل</p>
                <p class="font-extrabold dir-ltr text-left"><?= htmlspecialchars($customer['contact_phone']); ?></p>
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
        <div class="flex items-center gap-3 mb-5">
          <div class="h-11 w-11 rounded-2xl bg-blue-50 text-blue-700 flex items-center justify-center dark:bg-blue-900/30 dark:text-blue-200">
            <i class="bx bx-receipt text-2xl"></i>
          </div>
          <div>
            <h2 class="text-lg sm:text-xl font-extrabold">طلباتك</h2>
          </div>
        </div>

        <?php if ($orders): ?>
          <div class="space-y-3">
            <?php foreach ($orders as $order): ?>
              <?php
                $orderItems = $orderItemsByOrder[$order['id']] ?? [];
                $shippingAmount = ($order['shipping_cost_type'] === 'manual') ? (float)$order['shipping_cost'] : 0;
                $orderDue = max(0, (float)$order['total_amount'] - (float)$order['paid_amount']);
                $statusClass = $statusBadgeMap[$order['status']] ?? 'bg-slate-600';
                $statusLabel = $statusLabelMap[$order['status']] ?? $order['status'];
                $discountLabel = $discountBasisMap[$order['discount_basis']] ?? 'خصم غير محدد';
              ?>

              <article class="rounded-2xl border border-slate-200 bg-white overflow-hidden dark:border-slate-800 dark:bg-slate-950">
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
                        <p class="font-extrabold text-slate-900 dark:text-slate-100 mb-1">ملاحظات</p>
                        <p class="text-slate-700 dark:text-slate-200"><?= nl2br(htmlspecialchars($order['notes'])); ?></p>
                      </div>
                    <?php endif; ?>
                  </div>
                </div>
              </article>
            <?php endforeach; ?>
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
              <p class="text-sm text-slate-500 dark:text-slate-400">آخر 8 عمليات على المحفظة.</p>
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
                      <td class="px-4 py-3"><?= date('Y-m-d', strtotime($move['created_at'])); ?></td>
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

      <!-- Inventory -->
      <div class="rounded-3xl bg-white border border-slate-200 shadow-soft dark:bg-slate-900 dark:border-slate-800">
        <div class="p-6 sm:p-7">
          <div class="flex items-center gap-3 mb-5">
            <div class="h-11 w-11 rounded-2xl bg-amber-50 text-amber-700 flex items-center justify-center dark:bg-amber-900/30 dark:text-amber-200">
              <i class="bx bx-package text-2xl"></i>
            </div>
            <div>
              <h2 class="text-lg sm:text-xl font-extrabold">كميات المخزون</h2>
              <p class="text-sm text-slate-500 dark:text-slate-400">إجمالي الكميات المتاحة لكل منتج.</p>
            </div>
          </div>

          <?php if ($inventoryLines): ?>
            <div class="divide-y divide-slate-100 text-sm dark:divide-slate-800 rounded-2xl border border-slate-200 overflow-hidden dark:border-slate-800">
              <?php foreach ($inventoryLines as $line): ?>
                <div class="flex items-center justify-between px-4 py-3 bg-white dark:bg-slate-950">
                  <span class="text-slate-700 dark:text-slate-200 font-bold"><?= htmlspecialchars($line['name']); ?></span>
                  <span class="font-extrabold text-slate-900 dark:text-slate-100"><?= number_format((float)$line['qty']); ?></span>
                </div>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <div class="rounded-2xl bg-slate-50 border border-slate-200 p-5 text-slate-600 dark:bg-slate-950 dark:border-slate-800 dark:text-slate-300">
              لا تتوفر بيانات مخزون حالياً.
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

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? $page_title . ' | Gammavet System' : 'Gammavet System'; ?></title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Icon Libraries -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css">
    <!-- DataTables CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <!-- Select2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">

    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <!-- DataTables JS -->
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <!-- Select2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

    <!-- Custom CSS -->
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/style.css">
</head>

<body>
    <?php
    $login_region = $_SESSION['login_region'] ?? 'factory';
    $navbar_bg = 'bg-primary';
    $brand_name = 'GammaVet';

    if ($login_region === 'curva') {
        $navbar_bg = 'bg-success';
        $brand_name = 'GammaVet - CureVet';
    } elseif ($login_region === 'primer') {
        $navbar_bg = 'bg-warning text-dark';
        $brand_name = 'GammaVet - PremiumVet';
    } elseif ($login_region === 'naturous') {
        $brand_name = 'GammaVet - Naturous';
    } elseif ($login_region === 'activita') {
        $brand_name = 'GammaVet - Activita';
    }

    $brand_logo = getBrandLogoFile($login_region);
    ?>
    <!-- Navigation -->
    <?php
    $navGroups = [];
    if (isLoggedIn()) {
        // Ensure freshest role/permissions each request
        if (function_exists('loadUserAccessToSession')) {
            loadUserAccessToSession($_SESSION['user_id']);
        }

        $canSalesDashboard = hasPermission('sales.dashboard.view')
            || hasPermission('sales.dashboard.orders_pending')
            || hasPermission('sales.dashboard.overall_orders')
            || hasPermission('sales.dashboard.this_month')
            || hasPermission('sales.dashboard.recent_orders');
        $canSales = $canSalesDashboard || hasPermission('sales.orders.view_all') || hasPermission('sales.orders.create') || hasPermission('quotations.manage') || hasPermission('sales.portal_orders.manage') || hasPermission('customers.view');
        $canInventory = hasPermission('inventories.view') || hasPermission('inventories.create') || hasPermission('inventories.transfer');
        $canProducts = hasPermission('products.view')
            || hasPermission('products.create')
            || hasPermission('products.bulk_upload')
            || ($login_region === 'factory' && hasPermission('categories.manage'));
        $canPurchases = hasPermission('purchases.view_all') || hasPermission('purchases.create') || hasPermission('vendors.view');
        $canManageUsers = hasPermission('users.manage');
        $canViewActivityLogs = hasPermission('users.activity_logs.view');
        $canTickets = hasPermission('tickets.manage') || hasPermission('tickets.create') || hasPermission('tickets.view') || hasPermission('tickets.update_status');
        $canFinance = hasPermission('finance.deletions.approve')
            || hasPermission('finance.expenses.manage')
            || hasPermission('finance.expenses.categories')
            || hasPermission('finance.customer_wallet.view')
            || hasPermission('finance.balances.settle')
            || hasPermission('finance.customer_payment.process')
            || hasPermission('finance.safes.create')
            || hasPermission('finance.safes.edit')
            || hasPermission('finance.safes.delete')
            || hasPermission('finance.safes.balance.edit')
            || hasPermission('finance.bank_accounts.create')
            || hasPermission('finance.bank_accounts.edit')
            || hasPermission('finance.bank_accounts.delete')
            || hasPermission('finance.bank_accounts.balance.edit')
            || hasPermission('finance.personal_accounts.create')
            || hasPermission('finance.personal_accounts.delete')
            || hasPermission('finance.transfers.create')
            || hasPermission('finance.transfers.approve')
            || ($login_region === 'factory' && (
                hasPermission('finance.po_payment.process')
                || hasPermission('finance.vendor_wallet.view')
            ));
        $isFactory = $login_region === 'factory';

        // Each group: a single link ('url') or a collapsible list of 'items'.
        // 'show' carries the same permission checks the old navbar used.
        $navGroups = [
            ['key' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'fa-gauge-high', 'url' => 'dashboard.php', 'show' => $isFactory],
            ['key' => 'sales', 'label' => 'Sales', 'icon' => 'fa-cart-shopping', 'show' => $canSales, 'items' => [
                ['label' => 'Dashboard', 'icon' => 'fa-chart-line', 'url' => 'modules/sales/', 'show' => $canSalesDashboard],
                ['label' => 'All Orders', 'icon' => 'fa-list', 'url' => 'modules/sales/order_list.php', 'show' => hasPermission('sales.orders.view_all')],
                ['label' => 'Create Order', 'icon' => 'fa-plus', 'url' => 'modules/sales/create_order.php', 'show' => hasPermission('sales.orders.create')],
                ['label' => 'Quotations', 'icon' => 'fa-file-invoice', 'url' => 'modules/sales/quotations/quotation_list.php', 'show' => hasPermission('quotations.manage')],
                ['label' => 'Portal Order Requests', 'icon' => 'fa-clipboard-check', 'url' => 'modules/sales/portal_orders/list.php', 'show' => hasPermission('sales.portal_orders.manage')],
                ['label' => 'Customers', 'icon' => 'fa-users', 'url' => 'modules/customers/', 'show' => hasPermission('customers.view')],
            ]],
            ['key' => 'inventory', 'label' => 'Inventory', 'icon' => 'fa-warehouse', 'show' => $canInventory, 'items' => [
                ['label' => 'All Inventories', 'icon' => 'fa-list', 'url' => 'modules/inventories/', 'show' => hasPermission('inventories.view')],
                ['label' => 'Add Inventory', 'icon' => 'fa-plus', 'url' => 'modules/inventories/create.php', 'show' => hasPermission('inventories.create')],
                ['label' => 'Transfer Items', 'icon' => 'fa-right-left', 'url' => 'modules/inventories/transfer.php', 'show' => hasPermission('inventories.transfer')],
                ['label' => 'Regions', 'icon' => 'fa-globe', 'url' => 'modules/regions/', 'show' => hasPermission('regions.manage')],
            ]],
            ['key' => 'products', 'label' => 'Products', 'icon' => 'fa-boxes-stacked', 'show' => $canProducts, 'items' => [
                ['label' => 'Final Products', 'icon' => 'fa-box', 'url' => 'modules/products/?type=final', 'show' => hasPermission('products.view')],
                ['label' => 'Raw Materials', 'icon' => 'fa-layer-group', 'url' => 'modules/products/?type=material', 'show' => hasPermission('products.view') && $isFactory],
                ['label' => 'Add Product', 'icon' => 'fa-plus', 'url' => 'modules/products/create.php', 'show' => hasPermission('products.create')],
                ['label' => 'Bulk Upload', 'icon' => 'fa-upload', 'url' => 'modules/products/upload.php', 'show' => hasPermission('products.bulk_upload')],
                ['label' => 'Categories', 'icon' => 'fa-tags', 'url' => 'modules/categories/', 'show' => $isFactory && hasPermission('categories.manage')],
            ]],
            ['key' => 'purchases', 'label' => 'Purchases', 'icon' => 'fa-basket-shopping', 'show' => $canPurchases && $isFactory, 'items' => [
                ['label' => 'Purchase Orders', 'icon' => 'fa-list', 'url' => 'modules/purchases/', 'show' => hasPermission('purchases.view_all')],
                ['label' => 'Create PO', 'icon' => 'fa-plus', 'url' => 'modules/purchases/create_po.php', 'show' => hasPermission('purchases.create')],
                ['label' => 'Vendors', 'icon' => 'fa-truck', 'url' => 'modules/vendors/', 'show' => hasPermission('vendors.view')],
            ]],
            ['key' => 'manufacturing', 'label' => 'Manufacturing', 'icon' => 'fa-industry', 'show' => hasPermission('manufacturing.view') && $isFactory, 'items' => [
                ['label' => 'Orders List', 'icon' => 'fa-list', 'url' => 'modules/manufacturing/', 'show' => true],
                ['label' => 'Formulas', 'icon' => 'fa-flask', 'url' => 'modules/manufacturing/formulas.php', 'show' => true],
                ['label' => 'Formula Templates', 'icon' => 'fa-layer-group', 'url' => 'modules/manufacturing/formula_templates.php', 'show' => hasPermission('manufacturing.formula.view_all')],
                ['label' => 'Bottle Sizes', 'icon' => 'fa-wine-bottle', 'url' => 'modules/manufacturing/bottle_sizes.php', 'show' => true],
                ['label' => 'Packaging Options', 'icon' => 'fa-box', 'url' => 'modules/manufacturing/packaging_options.php', 'show' => true],
                ['label' => 'New Order', 'icon' => 'fa-plus', 'url' => 'modules/manufacturing/create.php', 'show' => hasPermission('manufacturing.orders.create')],
            ]],
            ['key' => 'users', 'label' => 'Users', 'icon' => 'fa-users', 'show' => ($canManageUsers || $canViewActivityLogs) && $isFactory, 'items' => [
                ['label' => 'Manage Users', 'icon' => 'fa-user-cog', 'url' => 'modules/users/', 'show' => $canManageUsers],
                ['label' => 'Activity Log', 'icon' => 'fa-history', 'url' => 'modules/users/activity_logs.php', 'show' => $canViewActivityLogs],
            ]],
            ['key' => 'analysis', 'label' => 'Analysis', 'icon' => 'fa-chart-line', 'url' => 'modules/analysis/', 'show' => hasPermission('analysis.view_reports') && $isFactory],
            ['key' => 'tickets', 'label' => 'Tickets', 'icon' => 'fa-ticket-alt', 'url' => 'modules/tickets/', 'show' => $canTickets && $isFactory],
            ['key' => 'finance', 'label' => 'Finance', 'icon' => 'fa-coins', 'show' => $canFinance && $login_region !== 'curva', 'items' => [
                ['label' => 'Finance Dashboard', 'icon' => 'fa-gauge-high', 'url' => 'modules/finance/index.php', 'show' => true],
                ['label' => 'Deletion Requests', 'icon' => 'fa-check-circle', 'url' => 'modules/finance/deletion_requests.php', 'show' => true],
                ['label' => 'Customer Accounts', 'icon' => 'fa-wallet', 'url' => 'modules/finance/customers.php', 'show' => hasPermission('finance.customer_wallet.view') || hasPermission('finance.balances.settle')],
                ['label' => 'Bills & Payments', 'icon' => 'fa-file-invoice-dollar', 'url' => 'modules/finance/bills.php', 'show' => hasPermission('finance.customer_payment.process')],
                ['label' => 'Safes', 'icon' => 'fa-vault', 'url' => 'modules/finance/safes.php', 'show' => hasPermission('finance.safes.create') || hasPermission('finance.safes.edit') || hasPermission('finance.safes.delete') || hasPermission('finance.safes.balance.edit') || hasPermission('finance.balances.settle')],
                ['label' => 'Bank Accounts', 'icon' => 'fa-university', 'url' => 'modules/finance/banks.php', 'show' => hasPermission('finance.bank_accounts.create') || hasPermission('finance.bank_accounts.edit') || hasPermission('finance.bank_accounts.delete') || hasPermission('finance.bank_accounts.balance.edit') || hasPermission('finance.balances.settle')],
                ['label' => 'Personal Accounts', 'icon' => 'fa-user-shield', 'url' => 'modules/finance/personal.php', 'show' => hasPermission('finance.personal_accounts.create') || hasPermission('finance.personal_accounts.delete') || hasPermission('finance.balances.settle')],
                ['label' => 'Transfers', 'icon' => 'fa-right-left', 'url' => 'modules/finance/transfers.php', 'show' => hasPermission('finance.transfers.create') || hasPermission('finance.transfers.approve')],
                ['label' => 'PO Payments', 'icon' => 'fa-file-contract', 'url' => 'modules/finance/po.php', 'show' => $isFactory && hasPermission('finance.po_payment.process')],
                ['label' => 'Expenses Tracking', 'icon' => 'fa-money-bill-wave', 'url' => 'modules/finance/expenses/', 'show' => hasPermission('finance.expenses.view')],
                ['label' => 'Vendor Wallets', 'icon' => 'fa-truck-field', 'url' => 'modules/finance/vendors.php', 'show' => $isFactory && (hasPermission('finance.vendor_wallet.view') || hasPermission('finance.balances.settle'))],
                ['label' => 'Financial Workbook Export', 'icon' => 'fa-file-excel', 'url' => 'modules/analysis/financial_workbook.php', 'show' => $isFactory && hasPermission('analysis.view_reports')],
            ]],
        ];

        // Drop hidden groups/items, then mark the link for the current page.
        $navPath = static function ($url) {
            $path = (string)parse_url($url, PHP_URL_PATH);
            return preg_replace('#/index\.php$#', '/', $path);
        };
        $currentPath = $navPath($_SERVER['REQUEST_URI'] ?? '');
        $currentQuery = [];
        parse_str((string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_QUERY), $currentQuery);
        $basePath = rtrim($navPath(BASE_URL), '/') . '/';
        $navMatches = static function ($url) use ($navPath, $basePath, $currentPath, $currentQuery) {
            if ($navPath($basePath . $url) !== $currentPath) return false;
            $itemQuery = [];
            parse_str((string)parse_url($url, PHP_URL_QUERY), $itemQuery);
            foreach ($itemQuery as $key => $value) {
                if (($currentQuery[$key] ?? null) !== $value) return false;
            }
            return true;
        };
        $navDir = static function ($url) use ($navPath, $basePath) {
            return preg_replace('#[^/]*$#', '', $navPath($basePath . $url));
        };

        $activeGroupKey = null;
        foreach ($navGroups as $index => &$group) {
            if (!$group['show']) { unset($navGroups[$index]); continue; }
            if (isset($group['items'])) {
                $group['items'] = array_values(array_filter($group['items'], static fn($item) => $item['show']));
                if (!$group['items']) { unset($navGroups[$index]); continue; }
                foreach ($group['items'] as &$item) {
                    $item['active'] = $navMatches($item['url']);
                    if ($item['active']) $activeGroupKey = $group['key'];
                }
                unset($item);
            } else {
                $group['active'] = $navMatches($group['url']);
                if ($group['active']) $activeGroupKey = $group['key'];
            }
        }
        unset($group);

        // Detail pages (e.g. safe_details.php) have no menu link: open the group that owns the folder.
        if ($activeGroupKey === null && $currentPath !== $basePath) {
            $bestLength = 0;
            foreach ($navGroups as $group) {
                foreach ($group['items'] ?? [['url' => $group['url']]] as $item) {
                    $dir = $navDir($item['url']);
                    if ($dir !== $basePath && strpos($currentPath, $dir) === 0 && strlen($dir) > $bestLength) {
                        $bestLength = strlen($dir);
                        $activeGroupKey = $group['key'];
                    }
                }
            }
        }
    }
    ?>
    <style>
        :root { --app-topbar-h: 60px; --app-sidebar-w: 250px; --app-sidebar-rail-w: 72px; }
        .app-topbar { height: var(--app-topbar-h); z-index: 1035; }
        .app-sidebar .nav-link { color: var(--bs-body-color); border-radius: .375rem; padding: .5rem .75rem; display: flex; align-items: center; gap: .6rem; }
        .app-sidebar .nav-link:hover { background: var(--bs-tertiary-bg, #f1f3f5); }
        .app-sidebar .nav-link.active { background: rgba(13, 110, 253, .1); color: var(--bs-primary); font-weight: 600; }
        .app-sidebar .nav-link .nav-icon { width: 1.25rem; text-align: center; opacity: .75; }
        .app-sidebar .nav-link.active .nav-icon { opacity: 1; }
        .app-sidebar .nav-group-toggle .nav-caret { margin-left: auto; font-size: .75rem; transition: transform .2s ease; }
        .app-sidebar .nav-group-toggle:not(.collapsed) .nav-caret { transform: rotate(90deg); }
        .app-sidebar .nav-group-toggle.has-active { color: var(--bs-primary); font-weight: 600; }
        .app-sidebar .nav-sub .nav-link { padding: .4rem .75rem .4rem 2.6rem; font-size: .925rem; }
        .app-sidebar .offcanvas-body { display: block; }
        @media (min-width: 992px) {
            .app-sidebar {
                position: fixed !important; top: var(--app-topbar-h); bottom: 0; left: 0;
                width: var(--app-sidebar-w); z-index: 1030; overflow-y: auto;
                background: #fff !important; border-right: 1px solid var(--bs-border-color);
                visibility: visible !important; transform: none !important;
            }
            .app-sidebar .offcanvas-body { padding: 1rem .75rem !important; overflow-y: visible; }
            .app-has-sidebar .app-main { margin-left: var(--app-sidebar-w); }

            /* Collapsed: icon-only rail. Hovering an icon shows its label via the title tooltip. */
            .sidebar-animate .app-sidebar { transition: width .2s ease; }
            .sidebar-animate .app-main { transition: margin-left .2s ease; }
            .sidebar-collapsed .app-sidebar { width: var(--app-sidebar-rail-w); overflow-x: hidden; }
            .sidebar-collapsed .app-sidebar .offcanvas-body { padding: 1rem .5rem !important; }
            .sidebar-collapsed .app-sidebar .nav-label,
            .sidebar-collapsed .app-sidebar .nav-caret,
            .sidebar-collapsed .app-sidebar .collapse { display: none !important; }
            .sidebar-collapsed .app-sidebar .nav-link { justify-content: center; padding: .6rem 0; }
            .sidebar-collapsed .app-sidebar .nav-link .nav-icon { width: auto; font-size: 1.1rem; }
            .sidebar-collapsed .app-sidebar .nav-group-toggle.has-active { background: rgba(13, 110, 253, .1); }
            .app-has-sidebar.sidebar-collapsed .app-main { margin-left: var(--app-sidebar-rail-w); }
        }
    </style>

    <!-- Top bar -->
    <nav class="navbar navbar-dark <?= $navbar_bg ?> shadow-sm sticky-top app-topbar">
        <div class="container-fluid px-3 px-lg-4">
            <div class="d-flex align-items-center gap-2">
                <?php if ($navGroups): ?>
                    <button class="navbar-toggler border-0 px-2 d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#appSidebar" aria-controls="appSidebar" aria-label="Open menu">
                        <span class="navbar-toggler-icon"></span>
                    </button>
                    <button class="btn btn-link text-white px-2 d-none d-lg-inline-flex" type="button" id="sidebarCollapseToggle" aria-controls="appSidebar" aria-expanded="true" aria-label="Collapse menu" title="Collapse menu">
                        <i class="fas fa-bars fs-5"></i>
                    </button>
                <?php endif; ?>
                <a class="navbar-brand d-flex align-items-center gap-2 fw-semibold me-0" href="<?= BASE_URL ?>dashboard.php">
                    <img src="<?= BASE_URL ?><?= $brand_logo ?>" alt="<?= $brand_name ?>" width="32" height="32">
                    <span class="d-none d-sm-inline"><?= $brand_name ?></span>
                </a>
            </div>

            <?php if (isLoggedIn()): ?>
                <ul class="navbar-nav flex-row align-items-center gap-3 ms-auto">
                    <?php $notifCount = function_exists('getUnreadNotificationsCount') ? getUnreadNotificationsCount() : 0; ?>
                    <?php if (hasPermission('notifications.view')): ?>
                        <li class="nav-item" id="notifBell">
                            <a class="nav-link position-relative" href="<?= BASE_URL ?>modules/notifications/index.php" aria-label="Notifications">
                                <i class="fas fa-bell"></i>
                                <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger <?= $notifCount > 0 ? '' : 'd-none' ?>" id="notifBadge">
                                    <?= (int)$notifCount ?>
                                </span>
                            </a>
                        </li>
                    <?php endif; ?>

                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle d-flex align-items-center gap-1" href="#" data-bs-toggle="dropdown">
                            <i class="fas fa-user-circle fs-5"></i>
                            <span class="d-none d-sm-inline"><?= $_SESSION['user_name'] ?></span>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end position-absolute">
                            <li><a class="dropdown-item" href="<?= BASE_URL ?>modules/users/profile.php"><i class="fas fa-user me-2"></i> Profile</a></li>
                            <li>
                                <hr class="dropdown-divider">
                            </li>
                            <li>
                                <a class="dropdown-item text-danger" href="<?= BASE_URL ?>logout.php?logout">
                                    <i class="fas fa-sign-out-alt me-2"></i> Logout
                                </a>
                            </li>
                        </ul>
                    </li>
                </ul>
            <?php endif; ?>
        </div>
    </nav>

    <?php if ($navGroups): ?>
    <!-- Sidebar: fixed on desktop, slide-in panel on mobile -->
    <aside class="offcanvas-lg offcanvas-start app-sidebar" tabindex="-1" id="appSidebar" aria-labelledby="appSidebarLabel">
        <div class="offcanvas-header <?= $navbar_bg ?> text-white">
            <h5 class="offcanvas-title d-flex align-items-center gap-2 mb-0" id="appSidebarLabel">
                <img src="<?= BASE_URL ?><?= $brand_logo ?>" alt="" width="28" height="28"><?= $brand_name ?>
            </h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" data-bs-target="#appSidebar" aria-label="Close"></button>
        </div>
        <div class="offcanvas-body">
            <ul class="nav flex-column gap-1 w-100">
                <?php foreach ($navGroups as $group): ?>
                    <?php if (!isset($group['items'])): ?>
                        <li class="nav-item">
                            <a class="nav-link <?= $group['active'] ? 'active' : '' ?>" href="<?= BASE_URL . $group['url'] ?>" title="<?= $group['label'] ?>" <?= $group['active'] ? 'aria-current="page"' : '' ?>>
                                <i class="fas <?= $group['icon'] ?> nav-icon"></i><span class="nav-label"><?= $group['label'] ?></span>
                            </a>
                        </li>
                    <?php else: $isOpen = $activeGroupKey === $group['key']; ?>
                        <li class="nav-item">
                            <a class="nav-link nav-group-toggle <?= $isOpen ? 'has-active' : 'collapsed' ?>" href="#nav-<?= $group['key'] ?>" data-bs-toggle="collapse" role="button" aria-expanded="<?= $isOpen ? 'true' : 'false' ?>" aria-controls="nav-<?= $group['key'] ?>" title="<?= $group['label'] ?>">
                                <i class="fas <?= $group['icon'] ?> nav-icon"></i><span class="nav-label"><?= $group['label'] ?></span>
                                <i class="fas fa-chevron-right nav-caret"></i>
                            </a>
                            <div class="collapse <?= $isOpen ? 'show' : '' ?>" id="nav-<?= $group['key'] ?>">
                                <ul class="nav flex-column nav-sub gap-1 mt-1">
                                    <?php foreach ($group['items'] as $item): ?>
                                        <li class="nav-item">
                                            <a class="nav-link <?= $item['active'] ? 'active' : '' ?>" href="<?= BASE_URL . $item['url'] ?>" <?= $item['active'] ? 'aria-current="page"' : '' ?>>
                                                <i class="fas <?= $item['icon'] ?> nav-icon"></i><span class="nav-label"><?= $item['label'] ?></span>
                                            </a>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </li>
                    <?php endif; ?>
                <?php endforeach; ?>
            </ul>
        </div>
    </aside>
    <script>
        (function () {
            const body = document.body;
            const storageKey = 'sidebarCollapsed';
            body.classList.add('app-has-sidebar');
            try { if (localStorage.getItem(storageKey) === '1') body.classList.add('sidebar-collapsed'); } catch (e) {}

            function syncToggle() {
                const toggle = document.getElementById('sidebarCollapseToggle');
                if (!toggle) return;
                const collapsed = body.classList.contains('sidebar-collapsed');
                const label = collapsed ? 'Expand menu' : 'Collapse menu';
                toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
                toggle.setAttribute('aria-label', label);
                toggle.title = label;
            }
            function setCollapsed(collapsed) {
                body.classList.toggle('sidebar-collapsed', collapsed);
                try { localStorage.setItem(storageKey, collapsed ? '1' : '0'); } catch (e) {}
                syncToggle();
            }

            document.addEventListener('DOMContentLoaded', function () {
                syncToggle();
                // Animate only user-triggered changes, not the initial restore.
                requestAnimationFrame(function () { body.classList.add('sidebar-animate'); });

                const toggle = document.getElementById('sidebarCollapseToggle');
                if (toggle) {
                    toggle.addEventListener('click', function () {
                        setCollapsed(!body.classList.contains('sidebar-collapsed'));
                    });
                }

                // In the rail, a group icon expands the sidebar and opens that group.
                document.querySelectorAll('#appSidebar .nav-group-toggle').forEach(function (link) {
                    link.addEventListener('click', function (e) {
                        if (!body.classList.contains('sidebar-collapsed') || window.innerWidth < 992) return;
                        e.preventDefault();
                        e.stopPropagation();
                        setCollapsed(false);
                        const target = document.querySelector(link.getAttribute('href'));
                        if (target && window.bootstrap) bootstrap.Collapse.getOrCreateInstance(target, { toggle: false }).show();
                    });
                });
            });
        })();
    </script>
    <?php endif; ?>
    <!-- Notification toast + poller -->
    <?php if (isLoggedIn() && hasPermission('notifications.view')): ?>
        <div class="position-fixed bottom-0 end-0 p-3" style="z-index: 1080">
            <div id="notifToast" class="toast align-items-center text-bg-primary border-0" role="alert" aria-live="assertive" aria-atomic="true">
                <div class="d-flex">
                    <div class="toast-body">
                        <i class="fas fa-bell me-2"></i> New notifications arrived.
                    </div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
                </div>
            </div>
        </div>
        <audio id="notifSound">
            <source src="<?= BASE_URL ?>assets/notify.mp3" type="audio/mpeg">
        </audio>
        <script>
            (function() {
                let last = parseInt(localStorage.getItem('notif_last_count') || '0', 10);
                const bell = document.getElementById('notifBell');
                const badge = document.getElementById('notifBadge');

                function check() {
                    fetch('<?= BASE_URL ?>modules/notifications/unread_count.php').then(r => r.json()).then(d => {
                        const c = parseInt((d && d.count) || 0, 10);
                        if (bell && badge) {
                            badge.textContent = String(c);
                            if (c > 0) {
                                badge.classList.remove('d-none');
                            } else {
                                badge.classList.add('d-none');
                            }
                        }
                        if (c > last) {
                            try {
                                document.getElementById('notifSound').play().catch(() => {});
                            } catch (e) {}
                            const t = new bootstrap.Toast(document.getElementById('notifToast'));
                            t.show();
                        }
                        last = c;
                        localStorage.setItem('notif_last_count', String(c));
                    }).catch(() => {});
                }
                setInterval(check, 30000); // every 30s
                document.addEventListener('DOMContentLoaded', check);
            })();
        </script>
    <?php endif; ?>
    <!-- Floating Ticket Button (permission-gated) -->
    <?php if (isLoggedIn() && (hasPermission('tickets.create') || hasPermission('tickets.manage')) && $login_region === 'factory'): ?>
        <style>
            #ticket-fab {
                position: fixed;
                bottom: 22px;
                right: 22px;
                z-index: 1050;
            }

            #ticket-fab .btn-circle {
                width: 56px;
                height: 56px;
                border-radius: 50%;
            }
        </style>
        <div id="ticket-fab">
            <button class="btn btn-primary shadow btn-circle" data-bs-toggle="offcanvas" data-bs-target="#ticketsOffcanvas" aria-controls="ticketsOffcanvas">
                <i class="fas fa-ticket-alt"></i>
            </button>
        </div>

        <div class="offcanvas offcanvas-end" tabindex="-1" id="ticketsOffcanvas" aria-labelledby="ticketsOffcanvasLabel">
            <div class="offcanvas-header">
                <h5 id="ticketsOffcanvasLabel"><i class="fas fa-ticket-alt me-2"></i>My Tickets</h5>
                <button type="button" class="btn-close text-reset" data-bs-dismiss="offcanvas" aria-label="Close"></button>
            </div>
            <div class="offcanvas-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <div class="text-muted small">Open/Assigned</div>
                    <a href="<?= BASE_URL ?>modules/tickets/create.php" class="btn btn-sm btn-primary"><i class="fas fa-plus"></i> New</a>
                </div>
                <div id="ticketList" class="list-group small"></div>
                <div id="ticketEmpty" class="text-muted small">No tickets assigned.</div>
            </div>
        </div>

        <script>
            (function() {
                const list = document.getElementById('ticketList');
                const empty = document.getElementById('ticketEmpty');
                if (!list || !empty) return;
                fetch('<?= BASE_URL ?>modules/tickets/list_assigned.php')
                    .then(r => r.json())
                    .then(data => {
                        list.innerHTML = '';
                        if (!data || !Array.isArray(data) || data.length === 0) {
                            empty.style.display = 'block';
                            return;
                        }
                        empty.style.display = 'none';
                        data.forEach(t => {
                            const a = document.createElement('a');
                            a.href = '<?= BASE_URL ?>modules/tickets/view.php?id=' + t.id;
                            a.className = 'list-group-item list-group-item-action d-flex justify-content-between align-items-start';
                            a.innerHTML = '<div class="me-auto">' +
                                '<div class="fw-semibold">#' + t.id + ' ' + escapeHtml(t.title) + '</div>' +
                                '<div class="text-muted">' + t.status + ' • ' + t.priority + '</div>' +
                                '</div>' +
                                '<span class="badge bg-secondary">' + (t.assigned_role || '') + '</span>';
                            list.appendChild(a);
                        });
                    }).catch(() => {});

                function escapeHtml(s) {
                    return s ? s.replace(/[&<>\"']/g, m => ({
                        "&": "&amp;",
                        "<": "&lt;",
                        ">": "&gt;",
                        "\"": "&quot;",
                        "'": "&#039;"
                    } [m])) : ''
                }
            })();
        </script>
    <?php endif; ?>



    <div class="app-main">
    <div class="container mt-4">
        <?php displayAlert(); ?>

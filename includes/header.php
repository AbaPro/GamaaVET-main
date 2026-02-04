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
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary shadow-sm">
        <div class="container-fluid px-4">

            <!-- Brand -->
            <a class="navbar-brand d-flex align-items-center gap-2 fw-semibold" href="<?= BASE_URL ?>dashboard.php">
                <img src="<?= BASE_URL ?>logo.png" alt="GammaVet" width="32" height="32">
                <span>GammaVet</span>
            </a>

            <!-- Toggler -->
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNavbar">
                <span class="navbar-toggler-icon"></span>
            </button>

            <!-- Navbar -->
            <div class="collapse navbar-collapse" id="mainNavbar">

                <!-- Left -->
                <ul class="navbar-nav me-auto mb-2 mb-lg-0 gap-lg-1">

                    <?php if (isLoggedIn()): ?>
                        <?php // Ensure freshest role/permissions each request
                        if (function_exists('loadUserAccessToSession')) {
                            loadUserAccessToSession($_SESSION['user_id']);
                        }
                        ?>

                        <li class="nav-item">
                            <a class="nav-link" href="<?= BASE_URL ?>dashboard.php">
                                <i class="fas fa-gauge-high me-1"></i> Dashboard
                            </a>
                        </li>

                        <!-- Sales -->
                        <?php $canSales = hasPermission('sales.orders.view_all') || hasPermission('sales.orders.create') || hasPermission('quotations.manage') || hasPermission('customers.view'); ?>
                        <?php if ($canSales): ?>
                            <li class="nav-item dropdown">
                                <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">
                                    <i class="fas fa-cart-shopping me-1"></i> Sales
                                </a>
                                <ul class="dropdown-menu">
                                    <?php if (hasPermission('sales.orders.view_all')): ?>
                                        <li>
                                            <a class="dropdown-item" href="<?= BASE_URL ?>modules/sales/">
                                                <i class="fas fa-list me-2"></i> All Orders
                                            </a>
                                        </li>
                                    <?php endif; ?>
                                    <?php if (hasPermission('sales.orders.create')): ?>
                                        <li>
                                            <a class="dropdown-item" href="<?= BASE_URL ?>modules/sales/create_order.php">
                                                <i class="fas fa-plus me-2"></i> Create Order
                                            </a>
                                        </li>
                                    <?php endif; ?>
                                    <?php if (hasPermission('quotations.manage')): ?>
                                        <li>
                                            <a class="dropdown-item" href="<?= BASE_URL ?>modules/sales/quotations/quotation_list.php">
                                                <i class="fas fa-file-invoice me-2"></i> Quotations
                                            </a>
                                        </li>
                                    <?php endif; ?>
                                    <li>
                                        <hr class="dropdown-divider">
                                    </li>
                                    <?php if (hasPermission('customers.view')): ?>
                                        <li>
                                            <a class="dropdown-item" href="<?= BASE_URL ?>modules/customers/">
                                                <i class="fas fa-users me-2"></i> Customers
                                            </a>
                                        </li>
                                    <?php endif; ?>
                                </ul>
                            </li>
                        <?php endif; ?>

                        <!-- Inventory -->
                        <?php $canInventory = hasPermission('inventories.view') || hasPermission('inventories.create') || hasPermission('inventories.transfer'); ?>
                        <?php if ($canInventory): ?>
                            <li class="nav-item dropdown">
                                <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">
                                    <i class="fas fa-warehouse me-1"></i> Inventory
                                </a>
                                <ul class="dropdown-menu">
                                    <?php if (hasPermission('inventories.view')): ?>
                                        <li>
                                            <a class="dropdown-item" href="<?= BASE_URL ?>modules/inventories/">
                                                <i class="fas fa-list me-2"></i> All Inventories
                                            </a>
                                        </li>
                                    <?php endif; ?>
                                    <?php if (hasPermission('inventories.create')): ?>
                                        <li>
                                            <a class="dropdown-item" href="<?= BASE_URL ?>modules/inventories/create.php">
                                                <i class="fas fa-plus me-2"></i> Add Inventory
                                            </a>
                                        </li>
                                    <?php endif; ?>
                                    <?php if (hasPermission('inventories.transfer')): ?>
                                        <li>
                                            <a class="dropdown-item" href="<?= BASE_URL ?>modules/inventories/transfer.php">
                                                <i class="fas fa-right-left me-2"></i> Transfer Items
                                            </a>
                                        </li>
                                    <?php endif; ?>
                                </ul>
                            </li>
                        <?php endif; ?>

                        <!-- Products -->
                        <?php $canProducts = hasPermission('products.view') || hasPermission('products.create') || hasPermission('products.bulk_upload') || hasPermission('categories.manage'); ?>
                        <?php if ($canProducts): ?>
                            <li class="nav-item dropdown">
                                <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">
                                    <i class="fas fa-boxes-stacked me-1"></i> Products
                                </a>
                                <ul class="dropdown-menu">
                                    <?php if (hasPermission('products.view')): ?>
                                        <li>
                                            <a class="dropdown-item" href="<?= BASE_URL ?>modules/products/?type=final">
                                                <i class="fas fa-box me-2"></i> Final Products
                                            </a>
                                        </li>
                                        <li>
                                            <a class="dropdown-item" href="<?= BASE_URL ?>modules/products/?type=material">
                                                <i class="fas fa-layer-group me-2"></i> Raw Materials
                                            </a>
                                        </li>
                                        <li>
                                            <hr class="dropdown-divider">
                                        </li>
                                        <li>
                                            <a class="dropdown-item" href="<?= BASE_URL ?>modules/products/">
                                                <i class="fas fa-list me-2"></i> All Products
                                            </a>
                                        </li>
                                    <?php endif; ?>
                                    <?php if (hasPermission('products.create')): ?>
                                        <li>
                                            <a class="dropdown-item" href="<?= BASE_URL ?>modules/products/create.php">
                                                <i class="fas fa-plus me-2"></i> Add Product
                                            </a>
                                        </li>
                                    <?php endif; ?>
                                    <?php if (hasPermission('products.bulk_upload')): ?>
                                        <li>
                                            <a class="dropdown-item" href="<?= BASE_URL ?>modules/products/upload.php">
                                                <i class="fas fa-upload me-2"></i> Bulk Upload
                                            </a>
                                        </li>
                                    <?php endif; ?>
                                    <li>
                                        <hr class="dropdown-divider">
                                    </li>
                                    <?php if (hasPermission('categories.manage')): ?>
                                        <li>
                                            <a class="dropdown-item" href="<?= BASE_URL ?>modules/categories/">
                                                <i class="fas fa-tags me-2"></i> Categories
                                            </a>
                                        </li>
                                    <?php endif; ?>
                                </ul>
                            </li>
                        <?php endif; ?>

                        <!-- Purchases -->
                        <?php $canPurchases = hasPermission('purchases.view_all') || hasPermission('purchases.create') || hasPermission('vendors.view'); ?>
                        <?php if ($canPurchases): ?>
                            <li class="nav-item dropdown">
                                <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">
                                    <i class="fas fa-basket-shopping me-1"></i> Purchases
                                </a>
                                <ul class="dropdown-menu">
                                    <?php if (hasPermission('purchases.view_all')): ?>
                                        <li>
                                            <a class="dropdown-item" href="<?= BASE_URL ?>modules/purchases/">
                                                <i class="fas fa-list me-2"></i> Purchase Orders
                                            </a>
                                        </li>
                                    <?php endif; ?>
                                    <?php if (hasPermission('purchases.create')): ?>
                                        <li>
                                            <a class="dropdown-item" href="<?= BASE_URL ?>modules/purchases/create_po.php">
                                                <i class="fas fa-plus me-2"></i> Create PO
                                            </a>
                                        </li>
                                    <?php endif; ?>
                                    <li>
                                        <hr class="dropdown-divider">
                                    </li>
                                    <?php if (hasPermission('vendors.view')): ?>
                                        <li>
                                            <a class="dropdown-item" href="<?= BASE_URL ?>modules/vendors/">
                                                <i class="fas fa-truck me-2"></i> Vendors
                                            </a>
                                        </li>
                                    <?php endif; ?>
                        </ul>
                    </li>
                <?php endif; ?>

                        <li class="nav-item">
                            <a class="nav-link" href="<?= BASE_URL ?>modules/manufacturing/">
                                <i class="fas fa-industry me-1"></i> Manufacturing
                            </a>
                        </li>

                    <!-- Users -->
                    <?php if (hasPermission('users.manage')): ?>
                        <li class="nav-item">
                                <a class="nav-link" href="<?= BASE_URL ?>modules/users/">
                                    <i class="fas fa-users me-1"></i> Users
                                </a>
                            </li>
                        <?php endif; ?>

                    <?php endif; ?>
                    <?php if (isLoggedIn()): ?>
                        <!-- <li class="nav-item">
                        <a class="nav-link" href="<?= BASE_URL ?>modules/analysis/">
                            <i class="fas fa-chart-line me-1"></i> Analysis
                        </a>
                    </li> -->
                    <?php endif; ?>
                    <!-- Tickets -->
                        <?php if (hasPermission('tickets.manage') || hasPermission('tickets.create') || hasPermission('tickets.view')): ?>
                            <li class="nav-item">
                                <a class="nav-link" href="<?= BASE_URL ?>modules/tickets/">
                                    <i class="fas fa-ticket-alt me-1"></i> Tickets
                                </a>
                            </li>
                        <?php endif; ?>

                        <!-- Finance -->
                        <?php
                        $canFinance = hasPermission('finance.customer_wallet.view')
                            || hasPermission('finance.customer_payment.process')
                            || hasPermission('finance.safes.create')
                            || hasPermission('finance.bank_accounts.create')
                            || hasPermission('finance.personal_accounts.create')
                            || hasPermission('finance.transfers.create')
                            || hasPermission('finance.po_payment.process')
                            || hasPermission('finance.vendor_wallet.view');
                        ?>
                        <?php if ($canFinance): ?>
                            <li class="nav-item dropdown">
                                <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">
                                    <i class="fas fa-coins me-1"></i> Finance
                                </a>
                                <ul class="dropdown-menu">
                                    <?php if (hasPermission('finance.customer_wallet.view')): ?>
                                        <li><a class="dropdown-item" href="<?= BASE_URL ?>modules/finance/customers.php"><i class="fas fa-wallet me-2"></i> Customer Wallets</a></li>
                                    <?php endif; ?>
                                    <?php if (hasPermission('finance.customer_payment.process')): ?>
                                        <li><a class="dropdown-item" href="<?= BASE_URL ?>modules/finance/bills.php"><i class="fas fa-file-invoice-dollar me-2"></i> Bills & Payments</a></li>
                                    <?php endif; ?>
                                    <?php if (hasPermission('finance.safes.create')): ?>
                                        <li><a class="dropdown-item" href="<?= BASE_URL ?>modules/finance/safes.php"><i class="fas fa-vault me-2"></i> Safes</a></li>
                                    <?php endif; ?>
                                    <?php if (hasPermission('finance.bank_accounts.create')): ?>
                                        <li><a class="dropdown-item" href="<?= BASE_URL ?>modules/finance/banks.php"><i class="fas fa-university me-2"></i> Bank Accounts</a></li>
                                    <?php endif; ?>
                                    <?php if (hasPermission('finance.personal_accounts.create')): ?>
                                        <li><a class="dropdown-item" href="<?= BASE_URL ?>modules/finance/personal.php"><i class="fas fa-user-shield me-2"></i> Personal Accounts</a></li>
                                    <?php endif; ?>
                                    <?php if (hasPermission('finance.transfers.create')): ?>
                                        <li><a class="dropdown-item" href="<?= BASE_URL ?>modules/finance/transfers.php"><i class="fas fa-right-left me-2"></i> Transfers</a></li>
                                    <?php endif; ?>
                                    <?php if (hasPermission('finance.po_payment.process')): ?>
                                        <li><a class="dropdown-item" href="<?= BASE_URL ?>modules/finance/po.php"><i class="fas fa-file-contract me-2"></i> PO Payments</a></li>
                                    <?php endif; ?>
                                    <?php if (hasPermission('finance.vendor_wallet.view')): ?>
                                        <li><a class="dropdown-item" href="<?= BASE_URL ?>modules/finance/vendors.php"><i class="fas fa-truck-field me-2"></i> Vendor Wallets</a></li>
                                    <?php endif; ?>
                                </ul>
                            </li>
                        <?php endif; ?>

                </ul>

                <!-- Right -->
                <ul class="navbar-nav ms-auto">
                    <?php if (isLoggedIn()): ?>
                        <?php $notifCount = function_exists('getUnreadNotificationsCount') ? getUnreadNotificationsCount() : 0; ?>
                        <?php if (hasPermission('notifications.view')): ?>
                            <li class="nav-item me-2" id="notifBell" style="<?= $notifCount > 0 ? '' : 'display: none;' ?>">
                                <a class="nav-link position-relative" href="<?= BASE_URL ?>modules/notifications/index.php">
                                    <i class="fas fa-bell"></i>
                                    <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" id="notifBadge">
                                        <?= (int)$notifCount ?>
                                    </span>
                                </a>
                            </li>
                        <?php endif; ?>

                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle d-flex align-items-center gap-1" href="#" data-bs-toggle="dropdown">
                                <i class="fas fa-user-circle fs-5"></i>
                                <span><?= $_SESSION['user_name'] ?></span>
                            </a>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><a class="dropdown-item" href="<?= BASE_URL ?>modules/users/profile.php"><i class="fas fa-user me-2"></i> Profile</a></li>
                                <li><a class="dropdown-item" href="#"><i class="fas fa-cog me-2"></i> Settings</a></li>
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
                    <?php endif; ?>
                </ul>

            </div>
        </div>
    </nav>
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
                            if (c > 0) {
                                bell.style.display = '';
                                badge.textContent = String(c);
                            } else {
                                bell.style.display = 'none';
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
    <?php if (isLoggedIn() && (hasPermission('tickets.create') || hasPermission('tickets.manage'))): ?>
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



    <div class="container mt-4">
        <?php displayAlert(); ?>

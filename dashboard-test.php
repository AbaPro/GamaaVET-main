<?php
require_once 'includes/auth.php';
require_once 'includes/functions.php';

$page_title = 'Dashboard';
require_once 'includes/header-test.php';
?>

<style>
    * {
        font-family: 'Inter';
    }

    body{
      background: white !important;
    }
    
    .module-tabs {
        display: flex;
        gap: 1rem;
        margin-bottom: 2rem;
        overflow-x: auto;
        padding-bottom: 0.5rem;
    }
    
    .module-tab {
        background: white;
        border: 2px solid #e0e0e0;
        padding: 0.75rem 1.5rem;
        border-radius: 12px;
        cursor: pointer;
        transition: all 0.3s ease;
        color: #333;
        font-weight: 600;
        white-space: nowrap;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
    }
    
    .module-tab:hover {
        background: #f8f9fa;
        transform: translateY(-2px);
        box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
    }
    
    .module-tab.active {
        background: #667eea;
        color: white;
        border-color: #667eea;
        box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
    }
    
    .tiles-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
        gap: 1.5rem;
        margin-bottom: 2rem;
    }
    
    .tile {
        background: white;
        border-radius: 12px;
        padding: 2rem;
        cursor: pointer;
        transition: all 0.3s ease;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        text-decoration: none;
        color: inherit;
        overflow: hidden;
        position: relative;
        min-height: 200px;
        border: 1px solid #e9ecef;
    }
    
    .tile:hover {
        transform: translateY(-5px);
        box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
    }
    
    .tile-icon {
        font-size: 3rem;
        margin-bottom: 1rem;
        opacity: 0.9;
    }
    
    .tile-title {
        font-weight: 600;
        font-size: 1.1rem;
        margin-bottom: 0.5rem;
        letter-spacing: -0.02em;
    }
    
    .tile-subtitle {
        font-size: 0.9rem;
        color: #363636ff;
        font-weight: 400;
    }
    
    h2, h3, h4 {
        font-weight: 700;
        letter-spacing: -0.03em;
    }
    
    small {
        font-weight: 500;
    }
    
    .tile-badge {
        position: absolute;
        top: 10px;
        right: 10px;
        background: #dc3545;
        color: white;
        border-radius: 50%;
        width: 24px;
        height: 24px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.75rem;
        font-weight: bold;
    }
    
    .bg-gradient-primary { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; }
    .bg-gradient-success { background: linear-gradient(135deg, #56ab2f 0%, #a8e063 100%); color: white; }
    .bg-gradient-info { background: linear-gradient(135deg, #2193b0 0%, #6dd5ed 100%); color: white; }
    .bg-gradient-warning { background: linear-gradient(135deg, #f46b45 0%, #eea849 100%); color: white; }
    .bg-gradient-danger { background: linear-gradient(135deg, #ee0979 0%, #ff6a00 100%); color: white; }
    .bg-gradient-dark { background: linear-gradient(135deg, #232526 0%, #414345 100%); color: white; }
    .bg-gradient-secondary { background: linear-gradient(135deg, #4e54c8 0%, #8f94fb 100%); color: white; }
    
    .module-content {
        display: none;
    }
    
    .module-content.active {
        display: block;
    }
    
    @media (max-width: 768px) {
        .tiles-grid {
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
        }
    }
</style>

<div class="container-fluid px-4">
    <!-- Module Tabs -->
    <div class="module-tabs">
        <div class="module-tab active" data-module="overview">
            <i class="fas fa-gauge-high"></i> Overview
        </div>
        
        <?php if (hasPermission('sales.orders.view_all') || hasPermission('sales.orders.create') || hasPermission('quotations.manage') || hasPermission('customers.view')): ?>
        <div class="module-tab" data-module="sales">
            <i class="fas fa-cart-shopping"></i> Sales
        </div>
        <?php endif; ?>
        
        <?php if (hasPermission('inventories.view') || hasPermission('inventories.create') || hasPermission('inventories.transfer')): ?>
        <div class="module-tab" data-module="inventory">
            <i class="fas fa-warehouse"></i> Inventory
        </div>
        <?php endif; ?>
        
        <?php if (hasPermission('products.view') || hasPermission('products.create') || hasPermission('categories.manage')): ?>
        <div class="module-tab" data-module="products">
            <i class="fas fa-boxes-stacked"></i> Products
        </div>
        <?php endif; ?>
        
        <?php if (hasPermission('purchases.view_all') || hasPermission('purchases.create') || hasPermission('vendors.view')): ?>
        <div class="module-tab" data-module="purchases">
            <i class="fas fa-basket-shopping"></i> Purchases
        </div>
        <?php endif; ?>
        
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
        <div class="module-tab" data-module="finance">
            <i class="fas fa-coins"></i> Finance
        </div>
        <?php endif; ?>
        
        <?php if (hasPermission('tickets.manage') || hasPermission('tickets.create') || hasPermission('tickets.view')): ?>
        <div class="module-tab" data-module="tickets">
            <i class="fas fa-ticket-alt"></i> Tickets
        </div>
        <?php endif; ?>
        
        <?php if (hasPermission('users.manage')): ?>
        <div class="module-tab" data-module="users">
            <i class="fas fa-users"></i> Users
        </div>
        <?php endif; ?>
    </div>

    <!-- Overview Module -->
    <div class="module-content active" id="overview">
        <div class="tiles-grid">
            <?php if (hasPermission('sales.orders.view_all') || hasPermission('finance.customer_payment.process')): ?>
            <?php
            $sql = "SELECT SUM(total_amount) as total, SUM(paid_amount) as paid FROM orders";
            $result = $conn->query($sql);
            $row = $result->fetch_assoc();
            $total_amount = number_format((float)($row['total'] ?? 0), 2);
            $paid_amount = number_format((float)($row['paid'] ?? 0), 2);
            ?>
            <a href="<?= BASE_URL ?>modules/sales/" class="tile bg-gradient-primary">
                <div>
                    <div class="tile-icon"><i class="fas fa-money-bill-trend-up"></i></div>
                    <div class="tile-title">Total Sales</div>
                    <div class="tile-subtitle">Total / Paid</div>
                </div>
                <h3 class="mb-0"><?php echo $total_amount; ?></h3>
                <small><?php echo $paid_amount; ?> paid</small>
            </a>
            <?php endif; ?>

            <?php if (hasPermission('sales.orders.view_all')): ?>
            <?php
            $sql = "SELECT COUNT(*) as c FROM orders where status = 'new'";
            $sql1 = "SELECT COUNT(*) as c FROM orders where status = 'in-production'";
            $result = $conn->query($sql);
            $result1 = $conn->query($sql1);
            $new_orders = (int)($result->fetch_assoc()['c'] ?? 0);
            $prod_orders = (int)($result1->fetch_assoc()['c'] ?? 0);
            ?>
            <a href="<?= BASE_URL ?>modules/sales/" class="tile bg-gradient-success">
                <div>
                    <div class="tile-icon"><i class="fas fa-clipboard-list"></i></div>
                    <div class="tile-title">Orders Status</div>
                </div>
                <div class="d-flex justify-content-between">
                    <div>
                        <h4><?php echo $new_orders; ?></h4>
                        <small>New</small>
                    </div>
                    <div>
                        <h4><?php echo $prod_orders; ?></h4>
                        <small>In Production</small>
                    </div>
                </div>
            </a>
            <?php endif; ?>

            <?php if (hasPermission('sales.orders.view_all') || hasPermission('sales.orders.create')): ?>
            <?php
            $sql = "SELECT COUNT(*) as total FROM orders WHERE DATE(order_date) = CURDATE()";
            $result = $conn->query($sql);
            $today_orders = (int)($result->fetch_assoc()['total'] ?? 0);
            ?>
            <a href="<?= BASE_URL ?>modules/sales/" class="tile bg-gradient-info">
                <div>
                    <div class="tile-icon"><i class="fas fa-shopping-cart"></i></div>
                    <div class="tile-title">Today's Orders</div>
                </div>
                <h2 class="mb-0"><?php echo $today_orders; ?></h2>
            </a>
            <?php endif; ?>

            <?php if (hasPermission('inventories.view')): ?>
            <?php
            $sql = "SELECT COUNT(*) as total FROM inventory_products ip 
                    JOIN products p ON ip.product_id = p.id 
                    WHERE ip.quantity <= p.min_stock_level";
            $result = $conn->query($sql);
            $low_stock = (int)($result->fetch_assoc()['total'] ?? 0);
            ?>
            <a href="<?= BASE_URL ?>modules/inventories/" class="tile bg-gradient-warning">
                <?php if ($low_stock > 0): ?>
                <div class="tile-badge"><?php echo $low_stock; ?></div>
                <?php endif; ?>
                <div>
                    <div class="tile-icon"><i class="fas fa-exclamation-triangle"></i></div>
                    <div class="tile-title">Low Stock Alert</div>
                </div>
                <h2 class="mb-0"><?php echo $low_stock; ?></h2>
                <small>Items need restocking</small>
            </a>
            <?php endif; ?>

            <?php if (hasPermission('finance.customer_payment.process') || hasPermission('sales.orders.view_all')): ?>
            <?php
            $sql = "SELECT SUM(total_amount - paid_amount) AS ar FROM orders WHERE paid_amount < total_amount";
            $result = $conn->query($sql);
            $ar = (float)($result->fetch_assoc()['ar'] ?? 0);
            ?>
            <a href="<?= BASE_URL ?>modules/finance/bills.php" class="tile bg-gradient-dark">
                <div>
                    <div class="tile-icon"><i class="fas fa-sack-dollar"></i></div>
                    <div class="tile-title">Accounts Receivable</div>
                </div>
                <h3 class="mb-0"><?php echo number_format($ar, 2); ?></h3>
            </a>
            <?php endif; ?>

            <?php if (hasPermission('quotations.manage')): ?>
            <?php
            $sql = "SELECT COUNT(*) AS c FROM quotations WHERE status NOT IN ('converted','rejected')";
            $result = $conn->query($sql);
            $open_q = (int)($result->fetch_assoc()['c'] ?? 0);
            ?>
            <a href="<?= BASE_URL ?>modules/sales/quotations/quotation_list.php" class="tile bg-gradient-secondary">
                <?php if ($open_q > 0): ?>
                <div class="tile-badge"><?php echo $open_q; ?></div>
                <?php endif; ?>
                <div>
                    <div class="tile-icon"><i class="fas fa-file-invoice"></i></div>
                    <div class="tile-title">Open Quotations</div>
                </div>
                <h2 class="mb-0"><?php echo $open_q; ?></h2>
            </a>
            <?php endif; ?>

            <?php if (hasPermission('tickets.manage') || hasPermission('tickets.create')): ?>
            <?php
            if (hasPermission('tickets.manage')) {
                $sql = "SELECT COUNT(*) AS c FROM tickets WHERE status IN ('open','in_progress')";
                $result = $conn->query($sql);
            } else {
                if (!isset($_SESSION['role_id'])) loadUserAccessToSession($_SESSION['user_id']);
                $rid = $_SESSION['role_id'] ?? 0;
                $uid = $_SESSION['user_id'] ?? 0;
                $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM tickets WHERE status IN ('open','in_progress') AND (assigned_to_role_id = ? OR assigned_to_user_id = ? OR created_by = ?)");
                $stmt->bind_param('iii', $rid, $uid, $uid);
                $stmt->execute();
                $result = $stmt->get_result();
                $stmt->close();
            }
            $open_tickets = (int)($result->fetch_assoc()['c'] ?? 0);
            ?>
            <a href="<?= BASE_URL ?>modules/tickets/" class="tile bg-gradient-danger">
                <?php if ($open_tickets > 0): ?>
                <div class="tile-badge"><?php echo $open_tickets; ?></div>
                <?php endif; ?>
                <div>
                    <div class="tile-icon"><i class="fas fa-ticket-alt"></i></div>
                    <div class="tile-title">Open Tickets</div>
                </div>
                <h2 class="mb-0"><?php echo $open_tickets; ?></h2>
            </a>
            <?php endif; ?>

            <?php if (hasPermission('notifications.view')): ?>
            <a href="<?= BASE_URL ?>modules/notifications/" class="tile" style="background: linear-gradient(135deg, #fa709a 0%, #fee140 100%); color: white;">
                <?php $notif_count = (int)getUnreadNotificationsCount(); ?>
                <?php if ($notif_count > 0): ?>
                <div class="tile-badge"><?php echo $notif_count; ?></div>
                <?php endif; ?>
                <div>
                    <div class="tile-icon"><i class="fas fa-bell"></i></div>
                    <div class="tile-title">Notifications</div>
                </div>
                <h2 class="mb-0"><?php echo $notif_count; ?></h2>
            </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Sales Module -->
    <?php if (hasPermission('sales.orders.view_all') || hasPermission('sales.orders.create') || hasPermission('quotations.manage') || hasPermission('customers.view')): ?>
    <div class="module-content" id="sales">
        <div class="tiles-grid">
            <?php if (hasPermission('sales.orders.view_all')): ?>
            <a href="<?= BASE_URL ?>modules/sales/" class="tile bg-gradient-primary">
                <div>
                    <div class="tile-icon"><i class="fas fa-list"></i></div>
                    <div class="tile-title">All Orders</div>
                    <div class="tile-subtitle">View all sales orders</div>
                </div>
            </a>
            <?php endif; ?>

            <?php if (hasPermission('sales.orders.create')): ?>
            <a href="<?= BASE_URL ?>modules/sales/create_order.php" class="tile bg-gradient-success">
                <div>
                    <div class="tile-icon"><i class="fas fa-plus"></i></div>
                    <div class="tile-title">Create Order</div>
                    <div class="tile-subtitle">New sales order</div>
                </div>
            </a>
            <?php endif; ?>

            <?php if (hasPermission('quotations.manage')): ?>
            <a href="<?= BASE_URL ?>modules/sales/quotations/quotation_list.php" class="tile bg-gradient-info">
                <div>
                    <div class="tile-icon"><i class="fas fa-file-invoice"></i></div>
                    <div class="tile-title">Quotations</div>
                    <div class="tile-subtitle">Manage quotations</div>
                </div>
            </a>
            <?php endif; ?>

            <?php if (hasPermission('customers.view')): ?>
            <a href="<?= BASE_URL ?>modules/customers/" class="tile bg-gradient-secondary">
                <div>
                    <div class="tile-icon"><i class="fas fa-users"></i></div>
                    <div class="tile-title">Customers</div>
                    <div class="tile-subtitle">Customer management</div>
                </div>
            </a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Inventory Module -->
    <?php if (hasPermission('inventories.view') || hasPermission('inventories.create') || hasPermission('inventories.transfer')): ?>
    <div class="module-content" id="inventory">
        <div class="tiles-grid">
            <?php if (hasPermission('inventories.view')): ?>
            <a href="<?= BASE_URL ?>modules/inventories/" class="tile bg-gradient-primary">
                <div>
                    <div class="tile-icon"><i class="fas fa-list"></i></div>
                    <div class="tile-title">All Inventories</div>
                    <div class="tile-subtitle">View inventory levels</div>
                </div>
            </a>
            <?php endif; ?>

            <?php if (hasPermission('inventories.create')): ?>
            <a href="<?= BASE_URL ?>modules/inventories/create.php" class="tile bg-gradient-success">
                <div>
                    <div class="tile-icon"><i class="fas fa-plus"></i></div>
                    <div class="tile-title">Add Inventory</div>
                    <div class="tile-subtitle">New inventory item</div>
                </div>
            </a>
            <?php endif; ?>

            <?php if (hasPermission('inventories.transfer')): ?>
            <a href="<?= BASE_URL ?>modules/inventories/transfer.php" class="tile bg-gradient-warning">
                <div>
                    <div class="tile-icon"><i class="fas fa-right-left"></i></div>
                    <div class="tile-title">Transfer Items</div>
                    <div class="tile-subtitle">Move inventory</div>
                </div>
            </a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Products Module -->
    <?php if (hasPermission('products.view') || hasPermission('products.create') || hasPermission('categories.manage')): ?>
    <div class="module-content" id="products">
        <div class="tiles-grid">
            <?php if (hasPermission('products.view')): ?>
            <a href="<?= BASE_URL ?>modules/products/" class="tile bg-gradient-primary">
                <div>
                    <div class="tile-icon"><i class="fas fa-list"></i></div>
                    <div class="tile-title">All Products</div>
                    <div class="tile-subtitle">Product catalog</div>
                </div>
            </a>
            <?php endif; ?>

            <?php if (hasPermission('products.create')): ?>
            <a href="<?= BASE_URL ?>modules/products/create.php" class="tile bg-gradient-success">
                <div>
                    <div class="tile-icon"><i class="fas fa-plus"></i></div>
                    <div class="tile-title">Add Product</div>
                    <div class="tile-subtitle">Create new product</div>
                </div>
            </a>
            <?php endif; ?>

            <?php if (hasPermission('products.bulk_upload')): ?>
            <a href="<?= BASE_URL ?>modules/products/upload.php" class="tile bg-gradient-info">
                <div>
                    <div class="tile-icon"><i class="fas fa-upload"></i></div>
                    <div class="tile-title">Bulk Upload</div>
                    <div class="tile-subtitle">Import products</div>
                </div>
            </a>
            <?php endif; ?>

            <?php if (hasPermission('categories.manage')): ?>
            <a href="<?= BASE_URL ?>modules/categories/" class="tile bg-gradient-secondary">
                <div>
                    <div class="tile-icon"><i class="fas fa-tags"></i></div>
                    <div class="tile-title">Categories</div>
                    <div class="tile-subtitle">Manage categories</div>
                </div>
            </a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Purchases Module -->
    <?php if (hasPermission('purchases.view_all') || hasPermission('purchases.create') || hasPermission('vendors.view')): ?>
    <div class="module-content" id="purchases">
        <div class="tiles-grid">
            <?php if (hasPermission('purchases.view_all')): ?>
            <a href="<?= BASE_URL ?>modules/purchases/" class="tile bg-gradient-primary">
                <div>
                    <div class="tile-icon"><i class="fas fa-list"></i></div>
                    <div class="tile-title">Purchase Orders</div>
                    <div class="tile-subtitle">View all POs</div>
                </div>
            </a>
            <?php endif; ?>

            <?php if (hasPermission('purchases.create')): ?>
            <a href="<?= BASE_URL ?>modules/purchases/create_po.php" class="tile bg-gradient-success">
                <div>
                    <div class="tile-icon"><i class="fas fa-plus"></i></div>
                    <div class="tile-title">Create PO</div>
                    <div class="tile-subtitle">New purchase order</div>
                </div>
            </a>
            <?php endif; ?>

            <?php if (hasPermission('vendors.view')): ?>
            <a href="<?= BASE_URL ?>modules/vendors/" class="tile bg-gradient-info">
                <div>
                    <div class="tile-icon"><i class="fas fa-truck"></i></div>
                    <div class="tile-title">Vendors</div>
                    <div class="tile-subtitle">Vendor management</div>
                </div>
            </a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Finance Module -->
    <?php if ($canFinance): ?>
    <div class="module-content" id="finance">
        <div class="tiles-grid">
            <?php if (hasPermission('finance.customer_wallet.view')): ?>
            <a href="<?= BASE_URL ?>modules/finance/customers.php" class="tile bg-gradient-primary">
                <div>
                    <div class="tile-icon"><i class="fas fa-wallet"></i></div>
                    <div class="tile-title">Customer Wallets</div>
                </div>
            </a>
            <?php endif; ?>

            <?php if (hasPermission('finance.customer_payment.process')): ?>
            <a href="<?= BASE_URL ?>modules/finance/bills.php" class="tile bg-gradient-success">
                <div>
                    <div class="tile-icon"><i class="fas fa-file-invoice-dollar"></i></div>
                    <div class="tile-title">Bills & Payments</div>
                </div>
            </a>
            <?php endif; ?>

            <?php if (hasPermission('finance.safes.create')): ?>
            <a href="<?= BASE_URL ?>modules/finance/safes.php" class="tile bg-gradient-warning">
                <div>
                    <div class="tile-icon"><i class="fas fa-vault"></i></div>
                    <div class="tile-title">Safes</div>
                </div>
            </a>
            <?php endif; ?>

            <?php if (hasPermission('finance.bank_accounts.create')): ?>
            <a href="<?= BASE_URL ?>modules/finance/banks.php" class="tile bg-gradient-info">
                <div>
                    <div class="tile-icon"><i class="fas fa-university"></i></div>
                    <div class="tile-title">Bank Accounts</div>
                </div>
            </a>
            <?php endif; ?>

            <?php if (hasPermission('finance.personal_accounts.create')): ?>
            <a href="<?= BASE_URL ?>modules/finance/personal.php" class="tile bg-gradient-secondary">
                <div>
                    <div class="tile-icon"><i class="fas fa-user-shield"></i></div>
                    <div class="tile-title">Personal Accounts</div>
                </div>
            </a>
            <?php endif; ?>

            <?php if (hasPermission('finance.transfers.create')): ?>
            <a href="<?= BASE_URL ?>modules/finance/transfers.php" class="tile bg-gradient-dark">
                <div>
                    <div class="tile-icon"><i class="fas fa-right-left"></i></div>
                    <div class="tile-title">Transfers</div>
                </div>
            </a>
            <?php endif; ?>

            <?php if (hasPermission('finance.po_payment.process')): ?>
            <a href="<?= BASE_URL ?>modules/finance/po.php" class="tile bg-gradient-danger">
                <div>
                    <div class="tile-icon"><i class="fas fa-file-contract"></i></div>
                    <div class="tile-title">PO Payments</div>
                </div>
            </a>
            <?php endif; ?>

            <?php if (hasPermission('finance.vendor_wallet.view')): ?>
            <a href="<?= BASE_URL ?>modules/finance/vendors.php" class="tile bg-gradient-primary">
                <div>
                    <div class="tile-icon"><i class="fas fa-truck-field"></i></div>
                    <div class="tile-title">Vendor Wallets</div>
                </div>
            </a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Tickets Module -->
    <?php if (hasPermission('tickets.manage') || hasPermission('tickets.create') || hasPermission('tickets.view')): ?>
    <div class="module-content" id="tickets">
        <div class="tiles-grid">
            <a href="<?= BASE_URL ?>modules/tickets/" class="tile bg-gradient-primary">
                <div>
                    <div class="tile-icon"><i class="fas fa-ticket-alt"></i></div>
                    <div class="tile-title">All Tickets</div>
                    <div class="tile-subtitle">View and manage tickets</div>
                </div>
            </a>

            <?php if (hasPermission('tickets.create')): ?>
            <a href="<?= BASE_URL ?>modules/tickets/create.php" class="tile bg-gradient-success">
                <div>
                    <div class="tile-icon"><i class="fas fa-plus"></i></div>
                    <div class="tile-title">Create Ticket</div>
                    <div class="tile-subtitle">New support ticket</div>
                </div>
            </a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Users Module -->
    <?php if (hasPermission('users.manage')): ?>
    <div class="module-content" id="users">
        <div class="tiles-grid">
            <a href="<?= BASE_URL ?>modules/users/" class="tile bg-gradient-primary">
                <div>
                    <div class="tile-icon"><i class="fas fa-users"></i></div>
                    <div class="tile-title">User Management</div>
                    <div class="tile-subtitle">Manage system users</div>
                </div>
            </a>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const tabs = document.querySelectorAll('.module-tab');
    const contents = document.querySelectorAll('.module-content');
    
    tabs.forEach(tab => {
        tab.addEventListener('click', function() {
            const module = this.dataset.module;
            
            tabs.forEach(t => t.classList.remove('active'));
            contents.forEach(c => c.classList.remove('active'));
            
            this.classList.add('active');
            document.getElementById(module).classList.add('active');
        });
    });
});
</script>

<?php require_once 'includes/footer.php'; ?>
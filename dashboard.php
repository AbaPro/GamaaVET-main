<?php
require_once 'includes/auth.php';
require_once 'includes/functions.php';

$page_title = 'Dashboard';
require_once 'includes/header.php';
?>

<div class="row">
    <?php if (hasPermission('sales.orders.view_all') || hasPermission('finance.customer_payment.process')): ?>
    <div class="col-md-6 col-lg-3 mb-4">
        <div class="card bg-primary text-white">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="card-title">Total Sales</h6>
                        <h6 class="card-subtitle mb-2">Total / Paid</h6>
                        <?php
                        $sql = "SELECT SUM(total_amount) as total ,SUM(paid_amount) as paid FROM orders";
                        $result = $conn->query($sql);
                        $row = $result->fetch_assoc();
                        $total_amount = number_format((float)($row['total'] ?? 0), 2);
                        $paid_amount = number_format((float)($row['paid'] ?? 0), 2);
                        ?>
                        <h2 class="mb-0"><?php echo $total_amount . " / " . $paid_amount; ?></h2>
                    </div>
                    <div>
                        <i class="fas fa-money-bill-trend-up fa-3x opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (hasPermission('sales.orders.view_all')): ?>
    <div class="col-md-6 col-lg-3 mb-4">
        <div class="card bg-success text-white">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="card-title">Total Orders</h6>
                        <h6 class="card-subtitle mb-2">New / In Production</h6>
                        <?php
                        $sql = "SELECT COUNT(*) as c FROM orders where status = 'new'";
                        $sql1 = "SELECT COUNT(*) as c FROM orders where status = 'in-production'";
                        $result = $conn->query($sql);
                        $result1 = $conn->query($sql1);
                        $new_orders = (int)($result->fetch_assoc()['c'] ?? 0);
                        $prod_orders = (int)($result1->fetch_assoc()['c'] ?? 0);
                        ?>
                        <h2 class="mb-0"><?php echo $new_orders . " / " . $prod_orders; ?></h2>
                    </div>
                    <div>
                        <i class="fas fa-clipboard-list fa-3x opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (hasPermission('sales.orders.view_all') || hasPermission('sales.orders.create')): ?>
    <div class="col-md-6 col-lg-3 mb-4">
        <div class="card bg-info text-white">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="card-title">Today's Orders</h6>
                        <?php
                        $sql = "SELECT COUNT(*) as total FROM orders WHERE DATE(order_date) = CURDATE()";
                        $result = $conn->query($sql);
                        $today_orders = (int)($result->fetch_assoc()['total'] ?? 0);
                        ?>
                        <h2 class="mb-0"><?php echo $today_orders; ?></h2>
                    </div>
                    <div>
                        <i class="fas fa-shopping-cart fa-3x opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (hasPermission('inventories.view')): ?>
    <div class="col-md-6 col-lg-3 mb-4">
        <div class="card bg-warning text-dark">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="card-title">Low Stock Items</h6>
                        <?php
                        $sql = "SELECT COUNT(*) as total FROM inventory_products ip 
                                JOIN products p ON ip.product_id = p.id 
                                WHERE ip.quantity <= p.min_stock_level";
                        $result = $conn->query($sql);
                        $low_stock = (int)($result->fetch_assoc()['total'] ?? 0);
                        ?>
                        <h2 class="mb-0"><?php echo $low_stock; ?></h2>
                    </div>
                    <div>
                        <i class="fas fa-exclamation-triangle fa-3x opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Extra analytics row -->
<div class="row">
    <?php if (hasPermission('finance.customer_payment.process') || hasPermission('sales.orders.view_all')): ?>
    <div class="col-md-6 col-lg-3 mb-4">
        <div class="card bg-dark text-white">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="card-title">Accounts Receivable</h6>
                        <?php
                        $sql = "SELECT SUM(total_amount - paid_amount) AS ar FROM orders WHERE paid_amount < total_amount";
                        $result = $conn->query($sql);
                        $ar = (float)($result->fetch_assoc()['ar'] ?? 0);
                        ?>
                        <h2 class="mb-0"><?php echo number_format($ar, 2); ?></h2>
                    </div>
                    <div>
                        <i class="fas fa-sack-dollar fa-3x opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (hasPermission('quotations.manage')): ?>
    <div class="col-md-6 col-lg-3 mb-4">
        <div class="card bg-secondary text-white">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="card-title">Open Quotations</h6>
                        <?php
                        $sql = "SELECT COUNT(*) AS c FROM quotations WHERE status NOT IN ('converted','rejected')";
                        $result = $conn->query($sql);
                        $open_q = (int)($result->fetch_assoc()['c'] ?? 0);
                        ?>
                        <h2 class="mb-0"><?php echo $open_q; ?></h2>
                    </div>
                    <div>
                        <i class="fas fa-file-invoice fa-3x opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (hasPermission('tickets.manage') || hasPermission('tickets.create')): ?>
    <div class="col-md-6 col-lg-3 mb-4">
        <div class="card bg-light border">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="card-title">Open Tickets</h6>
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
                        <h2 class="mb-0"><?php echo $open_tickets; ?></h2>
                    </div>
                    <div>
                        <i class="fas fa-ticket-alt fa-3x opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (hasPermission('notifications.view')): ?>
    <div class="col-md-6 col-lg-3 mb-4">
        <div class="card bg-primary-subtle">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="card-title">Unread Notifications</h6>
                        <h2 class="mb-0"><?php echo (int)getUnreadNotificationsCount(); ?></h2>
                    </div>
                    <div>
                        <i class="fas fa-bell fa-3x opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<div class="row">
    <?php if (hasPermission('sales.orders.view_all')): ?>
    <div class="col-md-8 mb-4">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title">Recent Orders</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Order ID</th>
                                <th>Customer</th>
                                <th>Date</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $sql = "SELECT o.id, o.internal_id, o.order_date, o.total_amount, o.status, c.name as customer_name 
                                    FROM orders o 
                                    JOIN customers c ON o.customer_id = c.id 
                                    ORDER BY o.order_date DESC LIMIT 5";
                            $result = $conn->query($sql);
                            if ($result && $result->num_rows > 0) {
                                while ($row = $result->fetch_assoc()) {
                                    echo '<tr>
                                            <td>' . htmlspecialchars($row['internal_id']) . '</td>
                                            <td>' . htmlspecialchars($row['customer_name']) . '</td>
                                            <td>' . date('d M Y', strtotime($row['order_date'])) . '</td>
                                            <td>' . number_format((float)$row['total_amount'], 2) . '</td>
                                            <td><span class="badge bg-' . getStatusColor($row['status']) . '">' . ucfirst(str_replace('-', ' ', $row['status'])) . '</span></td>
                                            <td><a href="modules/sales/order_details.php?id=' . (int)$row['id'] . '" class="btn btn-sm btn-outline-primary">View</a></td>
                                          </tr>';
                                }
                            } else {
                                echo '<tr><td colspan="6" class="text-center">No recent orders found</td></tr>';
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (hasPermission('inventories.view')): ?>
    <div class="col-md-4 mb-4">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title">Inventory Summary</h5>
            </div>
            <div class="card-body">
                <ul class="list-group">
                    <?php
                    $sql = "SELECT i.name, COUNT(ip.product_id) as products, SUM(ip.quantity) as total_qty 
                            FROM inventories i 
                            LEFT JOIN inventory_products ip ON i.id = ip.inventory_id 
                            GROUP BY i.id";
                    $result = $conn->query($sql);
                    if ($result && $result->num_rows > 0) {
                        while ($row = $result->fetch_assoc()) {
                            echo '<li class="list-group-item d-flex justify-content-between align-items-center">
                                    ' . htmlspecialchars($row['name']) . '
                                    <span class="badge bg-primary rounded-pill">' . (int)($row['products'] ?? 0) . ' items (' . (int)($row['total_qty'] ?? 0) . ')</span>
                                  </li>';
                        }
                    } else {
                        echo '<li class="list-group-item">No inventory data found</li>';
                    }
                    ?>
                </ul>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php require_once 'includes/footer.php'; ?>
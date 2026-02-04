<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

if (!hasPermission('customers.details.view')) {
    setAlert('danger', 'You do not have permission to access this page.');
    redirect('../../dashboard.php');
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    setAlert('danger', 'Invalid customer ID.');
    redirect('index.php');
}

$customer_id = sanitize($_GET['id']);
$page_title = 'Customer Details';
require_once '../../includes/header.php';

// Get customer info
$customer_sql = "SELECT c.*, ct.name as type_name 
                 FROM customers c 
                 JOIN customer_types ct ON c.type = ct.id 
                 WHERE c.id = ?";
$customer_stmt = $conn->prepare($customer_sql);
$customer_stmt->bind_param("i", $customer_id);
$customer_stmt->execute();
$customer_result = $customer_stmt->get_result();

if ($customer_result->num_rows === 0) {
    setAlert('danger', 'Customer not found.');
    redirect('index.php');
}

$customer = $customer_result->fetch_assoc();
$customer_stmt->close();

// Get primary contact
$contact_sql = "SELECT * FROM customer_contacts 
                WHERE customer_id = ? AND is_primary = 1 
                LIMIT 1";
$contact_stmt = $conn->prepare($contact_sql);
$contact_stmt->bind_param("i", $customer_id);
$contact_stmt->execute();
$primary_contact = $contact_stmt->get_result()->fetch_assoc();
$contact_stmt->close();

// Get primary address
$address_sql = "SELECT * FROM customer_addresses 
                WHERE customer_id = ? AND address_type = 'primary' 
                LIMIT 1";
$address_stmt = $conn->prepare($address_sql);
$address_stmt->bind_param("i", $customer_id);
$address_stmt->execute();
$primary_address = $address_stmt->get_result()->fetch_assoc();
$address_stmt->close();

// Get recent orders (limit 5)
$orders_sql = "SELECT o.id, o.internal_id, o.order_date, o.total_amount, o.paid_amount, o.status 
               FROM orders o 
               WHERE o.customer_id = ? 
               ORDER BY o.order_date DESC 
               LIMIT 5";
$orders_stmt = $conn->prepare($orders_sql);
$orders_stmt->bind_param("i", $customer_id);
$orders_stmt->execute();
$orders_result = $orders_stmt->get_result();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>
        Customer: <?php echo htmlspecialchars($customer['name']); ?>
        <span class="badge bg-<?php echo $customer['type'] == 1 ? 'info' : 'primary'; ?>">
            <?php echo ucfirst($customer['type_name']); ?>
        </span>
    </h2>
    <div>
        <a href="index.php" class="btn btn-secondary">Back to Customers</a>
    </div>
</div>

<div class="row mb-4">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">Customer Information</h5>
            </div>
            <div class="card-body">
                <p><strong>Email:</strong> <?php echo $customer['email'] ? htmlspecialchars($customer['email']) : '-'; ?></p>
                <p><strong>Phone:</strong> <?php echo htmlspecialchars($customer['phone']); ?></p>
                <p><strong>Tax Number:</strong> <?php echo $customer['tax_number'] ? htmlspecialchars($customer['tax_number']) : '-'; ?></p>
                <p><strong>Wallet Balance:</strong> <?php echo number_format($customer['wallet_balance'], 2); ?></p>
                <p><strong>Created:</strong> <?php echo date('M d, Y H:i', strtotime($customer['created_at'])); ?></p>
                <p><strong>Last Updated:</strong> <?php echo date('M d, Y H:i', strtotime($customer['updated_at'])); ?></p>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">Primary Contact</h5>
            </div>
            <div class="card-body">
                <?php if ($primary_contact): ?>
                    <p><strong>Name:</strong> <?php echo htmlspecialchars($primary_contact['name']); ?></p>
                    <p><strong>Position:</strong> <?php echo $primary_contact['position'] ? htmlspecialchars($primary_contact['position']) : '-'; ?></p>
                    <p><strong>Email:</strong> <?php echo $primary_contact['email'] ? htmlspecialchars($primary_contact['email']) : '-'; ?></p>
                    <p><strong>Phone:</strong> <?php echo htmlspecialchars($primary_contact['phone']); ?></p>
                <?php else: ?>
                    <p class="text-muted">No primary contact found</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="row mb-4">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">Primary Address</h5>
            </div>
            <div class="card-body">
                <?php if ($primary_address): ?>
                    <p><?php echo htmlspecialchars($primary_address['address_line1']); ?></p>
                    <?php if ($primary_address['address_line2']): ?>
                        <p><?php echo htmlspecialchars($primary_address['address_line2']); ?></p>
                    <?php endif; ?>
                    <p>
                        <?php echo htmlspecialchars($primary_address['city']); ?>, 
                        <?php echo htmlspecialchars($primary_address['state']); ?> 
                        <?php echo htmlspecialchars($primary_address['postal_code']); ?>
                    </p>
                    <p><?php echo htmlspecialchars($primary_address['country']); ?></p>
                    <?php if ($primary_address['is_default']): ?>
                        <span class="badge bg-success">Default Address</span>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="text-muted">No primary address found</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0">Recent Orders</h5>
                <a href="../sales/orders/create.php?customer_id=<?php echo $customer_id; ?>" class="btn btn-sm btn-primary">
                    <i class="fas fa-plus"></i> New Order
                </a>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Order ID</th>
                                <th>Date</th>
                                <th>Total Amount</th>
                                <th>Paid Amount</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($orders_result->num_rows > 0): ?>
                                <?php while ($order = $orders_result->fetch_assoc()): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($order['internal_id']); ?></td>
                                        <td><?php echo date('M d, Y', strtotime($order['order_date'])); ?></td>
                                        <td><?php echo number_format($order['total_amount'], 2); ?></td>
                                        <td><?php echo number_format($order['paid_amount'], 2); ?></td>
                                        <td>
                                            <span class="badge bg-<?php echo getStatusColor($order['status']); ?>">
                                                <?php echo ucfirst(str_replace('-', ' ', $order['status'])); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <a href="../sales/order_details.php?id=<?php echo $order['id']; ?>" class="btn btn-sm btn-outline-primary">
                                                <i class="fas fa-eye"></i> View
                                            </a>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="text-center">No recent orders found</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>

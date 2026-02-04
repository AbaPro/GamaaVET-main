<?php
require_once '../../../includes/auth.php';
require_once '../../../includes/header.php';
require_once '../../../config/database.php';

// Permission check
if (!hasPermission('quotations.manage')) {
    $_SESSION['error'] = "You don't have permission to access this page";
    header("Location: ../../../dashboard.php");
    exit();
}

// Filter parameters
$status = $_GET['status'] ?? '';
$customer_id = $_GET['customer_id'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

// Build query
$query = "SELECT q.id, q.quotation_date, q.expiry_date, q.total_amount, 
                 q.status, c.name AS customer_name, q.order_id
          FROM quotations q
          JOIN customers c ON q.customer_id = c.id
          WHERE 1=1";
$params = [];

if (!empty($status)) {
    $query .= " AND q.status = ?";
    $params[] = $status;
}

if (!empty($customer_id)) {
    $query .= " AND q.customer_id = ?";
    $params[] = $customer_id;
}

if (!empty($date_from)) {
    $query .= " AND q.quotation_date >= ?";
    $params[] = $date_from;
}

if (!empty($date_to)) {
    $query .= " AND q.quotation_date <= ?";
    $params[] = $date_to;
}

$query .= " ORDER BY q.quotation_date DESC";

// Get quotations
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$quotations = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get customers for filter dropdown
$customers = $pdo->query("SELECT id, name FROM customers ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="container mt-4">
    <h2>Quotation Management</h2>
    
    <?php include '../../../includes/messages.php'; ?>
    
    <div class="card mb-4">
        <div class="card-header">
            <div class="d-flex justify-content-between align-items-center">
                <h4>Quotation List</h4>
                <a href="create_quotation.php" class="btn btn-primary btn-sm">New Quotation</a>
            </div>
        </div>
        <div class="card-body">
            <form method="get" class="mb-4">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label for="status" class="form-label">Status</label>
                        <select class="form-select" id="status" name="status">
                            <option value="">All Statuses</option>
                            <option value="draft" <?= $status == 'draft' ? 'selected' : '' ?>>Draft</option>
                            <option value="sent" <?= $status == 'sent' ? 'selected' : '' ?>>Sent</option>
                            <option value="accepted" <?= $status == 'accepted' ? 'selected' : '' ?>>Accepted</option>
                            <option value="rejected" <?= $status == 'rejected' ? 'selected' : '' ?>>Rejected</option>
                            <option value="converted" <?= $status == 'converted' ? 'selected' : '' ?>>Converted</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="customer_id" class="form-label">Customer</label>
                        <select class="form-select" id="customer_id" name="customer_id">
                            <option value="">All Customers</option>
                            <?php foreach ($customers as $customer) : ?>
                                <option value="<?= $customer['id'] ?>" <?= $customer_id == $customer['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($customer['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="date_from" class="form-label">From Date</label>
                        <input type="date" class="form-control" id="date_from" name="date_from" value="<?= $date_from ?>">
                    </div>
                    <div class="col-md-3">
                        <label for="date_to" class="form-label">To Date</label>
                        <input type="date" class="form-control" id="date_to" name="date_to" value="<?= $date_to ?>">
                    </div>
                    <div class="col-md-12">
                        <button type="submit" class="btn btn-primary">Filter</button>
                        <a href="quotation_list.php" class="btn btn-secondary">Reset</a>
                    </div>
                </div>
            </form>
            
            <div class="table-responsive">
                <table class="table js-datatable table-striped table-hover">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Customer</th>
                            <th>Date</th>
                            <th>Expiry</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Order</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($quotations as $quotation) : 
                            $status_class = [
                                'draft' => 'bg-secondary',
                                'sent' => 'bg-primary',
                                'accepted' => 'bg-success',
                                'rejected' => 'bg-danger',
                                'converted' => 'bg-info'
                            ];
                        ?>
                            <tr>
                                <td><?= $quotation['id'] ?></td>
                                <td><?= htmlspecialchars($quotation['customer_name']) ?></td>
                                <td><?= date('M d, Y', strtotime($quotation['quotation_date'])) ?></td>
                                <td><?= date('M d, Y', strtotime($quotation['expiry_date'])) ?></td>
                                <td><?= number_format($quotation['total_amount'], 2) ?></td>
                                <td>
                                    <span class="badge <?= $status_class[$quotation['status']] ?>">
                                        <?= ucfirst($quotation['status']) ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($quotation['order_id']) : ?>
                                        <a href="../order_details.php?id=<?= $quotation['order_id'] ?>" class="btn btn-sm btn-info">
                                            View Order
                                        </a>
                                    <?php else : ?>
                                        <span class="text-muted">N/A</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="quotation_details.php?id=<?= $quotation['id'] ?>" class="btn btn-sm btn-primary">View</a>
                                    <?php if ($quotation['status'] == 'draft') : ?>
                                        <a href="create_quotation.php?edit=<?= $quotation['id'] ?>" class="btn btn-sm btn-warning">Edit</a>
                                    <?php elseif ($quotation['status'] == 'accepted' && !$quotation['order_id']) : ?>
                                        <a href="convert_to_order.php?id=<?= $quotation['id'] ?>" class="btn btn-sm btn-success">Convert to Order</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once '../../../includes/footer.php'; ?>

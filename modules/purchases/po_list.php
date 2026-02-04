<?php
require_once '../../includes/auth.php';
require_once '../../includes/header.php';
require_once '../../config/database.php';

// Permission check
if (!hasPermission('purchases.view_all')) {
    $_SESSION['error'] = "You don't have permission to access this page";
    header("Location: ../../dashboard.php");
    exit();
}

// Filter parameters
$status = $_GET['status'] ?? '';
$vendor_id = $_GET['vendor_id'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

// Build query
$query = "SELECT po.id, po.order_date, po.total_amount, po.paid_amount, 
                 po.status, v.name AS vendor_name 
          FROM purchase_orders po
          JOIN vendors v ON po.vendor_id = v.id
          WHERE 1=1";
$params = [];

if (!empty($status)) {
    $query .= " AND po.status = ?";
    $params[] = $status;
}

if (!empty($vendor_id)) {
    $query .= " AND po.vendor_id = ?";
    $params[] = $vendor_id;
}

if (!empty($date_from)) {
    $query .= " AND po.order_date >= ?";
    $params[] = $date_from;
}

if (!empty($date_to)) {
    $query .= " AND po.order_date <= ?";
    $params[] = $date_to;
}

$query .= " ORDER BY po.order_date DESC";

// Get purchase orders
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$purchase_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get vendors for filter dropdown
$vendors = $pdo->query("SELECT id, name FROM vendors ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="container mt-4">
    <h2>Purchase Order Management</h2>
    
    <?php include '../../includes/messages.php'; ?>
    
    <div class="card mb-4">
        <div class="card-header">
            <div class="d-flex justify-content-between align-items-center">
                <h4>Purchase Order List</h4>
                <a href="create_po.php" class="btn btn-primary btn-sm">New PO</a>
            </div>
        </div>
        <div class="card-body">
            <form method="get" class="mb-4">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label for="status" class="form-label">Status</label>
                        <select class="form-select" id="status" name="status">
                            <option value="">All Statuses</option>
                            <option value="new" <?= $status == 'new' ? 'selected' : '' ?>>New</option>
                            <option value="ordered" <?= $status == 'ordered' ? 'selected' : '' ?>>Ordered</option>
                            <option value="partially-received" <?= $status == 'partially-received' ? 'selected' : '' ?>>Partially Received</option>
                            <option value="received" <?= $status == 'received' ? 'selected' : '' ?>>Received</option>
                            <option value="cancelled" <?= $status == 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="vendor_id" class="form-label">Vendor</label>
                        <select class="form-select" id="vendor_id" name="vendor_id">
                            <option value="">All Vendors</option>
                            <?php foreach ($vendors as $vendor) : ?>
                                <option value="<?= $vendor['id'] ?>" <?= $vendor_id == $vendor['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($vendor['name']) ?>
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
                        <a href="po_list.php" class="btn btn-secondary">Reset</a>
                    </div>
                </div>
            </form>
            
            <div class="table-responsive">
                <table class="table js-datatable table-striped table-hover">
                    <thead>
                        <tr>
                            <th>PO #</th>
                            <th>Vendor</th>
                            <th>Date</th>
                            <th>Total</th>
                            <th>Paid</th>
                            <th>Balance</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($purchase_orders as $po) : 
                            $balance = $po['total_amount'] - $po['paid_amount'];
                            $status_class = [
                                'new' => 'bg-secondary',
                                'ordered' => 'bg-primary',
                                'partially-received' => 'bg-info',
                                'received' => 'bg-success',
                                'cancelled' => 'bg-danger'
                            ];
                        ?>
                            <tr>
                                <td>
                                    <button type="button"
                                            class="btn btn-link p-0 text-decoration-none js-po-preview"
                                            data-po-id="<?= (int)$po['id'] ?>"
                                            title="Quick view PO">
                                        PO-<?= $po['id'] ?> <i class="fas fa-caret-down ms-1"></i>
                                    </button>
                                </td>
                                <td><?= htmlspecialchars($po['vendor_name']) ?></td>
                                <td><?= date('M d, Y', strtotime($po['order_date'])) ?></td>
                                <td><?= number_format($po['total_amount'], 2) ?></td>
                                <td><?= number_format($po['paid_amount'], 2) ?></td>
                                <td class="<?= $balance > 0 ? 'text-danger' : 'text-success' ?>">
                                    <?= number_format($balance, 2) ?>
                                </td>
                                <td>
                                    <span class="badge <?= $status_class[$po['status']] ?>">
                                        <?= ucwords(str_replace('-', ' ', $po['status'])) ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="po_details.php?id=<?= $po['id'] ?>" class="btn btn-sm btn-info">View</a>
                                    <?php if ($po['status'] == 'new' || $po['status'] == 'ordered') : ?>
                                        <a href="receive_items.php?po_id=<?= $po['id'] ?>" class="btn btn-sm btn-success">Receive</a>
                                    <?php endif; ?>
                                    <?php if ($balance > 0) : ?>
                                        <a href="process_payment.php?po_id=<?= $po['id'] ?>" class="btn btn-sm btn-primary">Payment</a>
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

<!-- Quick view modal -->
<div class="modal fade" id="poPreviewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">PO Preview</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="text-center py-3 text-muted">Select a PO to preview its details.</div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const modalElement = document.getElementById('poPreviewModal');
    if (!modalElement || typeof bootstrap === 'undefined' || !bootstrap.Modal) return;

    const modalBody = modalElement.querySelector('.modal-body');
    const modalInstance = new bootstrap.Modal(modalElement);
    const spinner = `
        <div class="d-flex justify-content-center py-4">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
        </div>`;

    const escapeHtml = (unsafe = '') => String(unsafe)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');

    const formatRow = (items) => {
        if (!Array.isArray(items) || !items.length) {
            return '<tr><td colspan="5" class="text-center text-muted">No items found</td></tr>';
        }
        return items.map(function (item, idx) {
            return `
                <tr>
                    <td>${idx + 1}</td>
                    <td>${escapeHtml(item.product_name)}</td>
                    <td class="text-center">${item.quantity}</td>
                    <td class="text-end">${item.unit_price}</td>
                    <td class="text-end">${item.total_price}</td>
                </tr>`;
        }).join('');
    };

    document.querySelectorAll('.js-po-preview').forEach(button => {
        button.addEventListener('click', function () {
            const poId = this.dataset.poId;
            if (!poId) return;
            modalBody.innerHTML = spinner;
            modalInstance.show();

            fetch('<?= BASE_URL ?>ajax/get_po_summary.php?id=' + encodeURIComponent(poId))
                .then(resp => resp.json())
                .then(data => {
                    if (!data.success) {
                        modalBody.innerHTML = `<div class="alert alert-danger mb-0">${escapeHtml(data.message || 'Unable to load PO details.')}</div>`;
                        return;
                    }
                    const order = data.order;
                    modalBody.innerHTML = `
                        <div class="mb-3">
                            <h5 class="mb-1">${escapeHtml(order.label)} <span class="badge bg-secondary">${escapeHtml(order.status_label)}</span></h5>
                            <div class="small text-muted">Created on ${escapeHtml(order.order_date)} by ${escapeHtml(order.created_by || 'System')}</div>
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <h6 class="text-uppercase text-muted small mb-1">Vendor</h6>
                                <p class="mb-0">${escapeHtml(order.vendor_name)}</p>
                                <small class="text-muted">${escapeHtml(order.contact_name || 'No contact')} (${escapeHtml(order.contact_phone || 'N/A')})</small>
                            </div>
                            <div class="col-md-6">
                                <h6 class="text-uppercase text-muted small mb-1">Totals</h6>
                                <p class="mb-0"><strong>Total:</strong> ${order.total_amount}</p>
                                <small class="text-muted">Paid: ${order.paid_amount} | Balance: ${order.balance}</small>
                            </div>
                        </div>
                        <div class="table-responsive mb-3">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Product</th>
                                        <th class="text-center">Qty</th>
                                        <th class="text-end">Unit Cost</th>
                                        <th class="text-end">Line Total</th>
                                    </tr>
                                </thead>
                                <tbody>${formatRow(data.items)}</tbody>
                            </table>
                        </div>
                        <div class="border-top pt-3">
                            <div class="mb-2"><strong>Notes:</strong> ${order.notes ? escapeHtml(order.notes) : '<span class="text-muted">No notes</span>'}</div>
                            <a href="po_details.php?id=${order.id}" class="btn btn-sm btn-primary">Open Full PO</a>
                        </div>
                    `;
                })
                .catch(() => {
                    modalBody.innerHTML = '<div class="alert alert-danger mb-0">Unable to load PO details.</div>';
                });
        });
    });
});
</script>

<?php require_once '../../includes/footer.php'; ?>

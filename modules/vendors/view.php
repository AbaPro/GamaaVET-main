<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

if (!hasPermission('vendors.view')) {
    setAlert('danger', 'You do not have permission to access this page.');
    redirect('../../dashboard.php');
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    setAlert('danger', 'Invalid vendor ID.');
    redirect('index.php');
}

$vendor_id = sanitize($_GET['id']);
$page_title = 'Vendor Details';
require_once '../../includes/header.php';

// Get vendor info
$vendor_sql = "SELECT v.*, vt.name as type_name 
               FROM vendors v 
               LEFT JOIN vendor_types vt ON v.type = vt.id 
               WHERE v.id = ?";
$vendor_stmt = $conn->prepare($vendor_sql);
$vendor_stmt->bind_param("i", $vendor_id);
$vendor_stmt->execute();
$vendor_result = $vendor_stmt->get_result();

if ($vendor_result->num_rows === 0) {
    setAlert('danger', 'Vendor not found.');
    redirect('index.php');
}

$vendor = $vendor_result->fetch_assoc();
$vendor_stmt->close();

// Get primary contact
$contact_sql = "SELECT * FROM vendor_contacts 
                WHERE vendor_id = ? AND is_primary = 1 
                LIMIT 1";
$contact_stmt = $conn->prepare($contact_sql);
$contact_stmt->bind_param("i", $vendor_id);
$contact_stmt->execute();
$primary_contact = $contact_stmt->get_result()->fetch_assoc();
$contact_stmt->close();

// Get primary address
$address_sql = "SELECT * FROM vendor_addresses 
                WHERE vendor_id = ? AND address_type = 'primary' 
                LIMIT 1";
$address_stmt = $conn->prepare($address_sql);
$address_stmt->bind_param("i", $vendor_id);
$address_stmt->execute();
$primary_address = $address_stmt->get_result()->fetch_assoc();
$address_stmt->close();

// Get recent purchase orders (limit 5)
$orders_sql = "SELECT po.id, po.order_date, po.total_amount, po.paid_amount, po.status 
               FROM purchase_orders po 
               WHERE po.vendor_id = ? 
               ORDER BY po.order_date DESC 
               LIMIT 5";
$orders_stmt = $conn->prepare($orders_sql);
$orders_stmt->bind_param("i", $vendor_id);
$orders_stmt->execute();
$orders_result = $orders_stmt->get_result();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>
        Vendor: <?php echo htmlspecialchars($vendor['name']); ?>
        <span class="badge bg-<?php echo $vendor['type'] == 1 ? 'info' : ($vendor['type'] == 2 ? 'primary' : 'warning'); ?>">
            <?php echo $vendor['type_name'] ? ucfirst($vendor['type_name']) : 'N/A'; ?>
        </span>
    </h2>
    <div>
        <a href="index.php" class="btn btn-secondary">Back to Vendors</a>
    </div>
</div>

<div class="row mb-4">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">Vendor Information</h5>
            </div>
            <div class="card-body">
                <p><strong>Email:</strong> <?php echo $vendor['email'] ? htmlspecialchars($vendor['email']) : '-'; ?></p>
                <p><strong>Phone:</strong> <?php echo htmlspecialchars($vendor['phone']); ?></p>
                <p><strong>Tax Number:</strong> <?php echo $vendor['tax_number'] ? htmlspecialchars($vendor['tax_number']) : '-'; ?></p>
                <p><strong>Wallet Balance:</strong> <?php echo number_format($vendor['wallet_balance'], 2); ?></p>
                <p><strong>Created:</strong> <?php echo date('M d, Y H:i', strtotime($vendor['created_at'])); ?></p>
                <p><strong>Last Updated:</strong> <?php echo date('M d, Y H:i', strtotime($vendor['updated_at'])); ?></p>
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
                <h5 class="card-title mb-0">Recent Purchase Orders</h5>
                <a href="../purchases/orders/create.php?vendor_id=<?php echo $vendor_id; ?>" class="btn btn-sm btn-primary">
                    <i class="fas fa-plus"></i> New PO
                </a>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>PO #</th>
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
                                        <td>
                                            <button type="button"
                                                    class="btn btn-link p-0 text-decoration-none js-po-preview"
                                                    data-po-id="<?php echo (int)$order['id']; ?>"
                                                    title="Quick view PO">
                                                PO-<?php echo $order['id']; ?> <i class="fas fa-caret-down ms-1"></i>
                                            </button>
                                        </td>
                                        <td><?php echo date('M d, Y', strtotime($order['order_date'])); ?></td>
                                        <td><?php echo number_format($order['total_amount'], 2); ?></td>
                                        <td><?php echo number_format($order['paid_amount'], 2); ?></td>
                                        <td>
                                            <span class="badge bg-<?php echo getStatusColor($order['status']); ?>">
                                                <?php echo ucfirst(str_replace('-', ' ', $order['status'])); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <a href="../purchases/orders/view.php?id=<?php echo $order['id']; ?>" class="btn btn-sm btn-outline-primary">
                                                <i class="fas fa-eye"></i> View
                                            </a>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="text-center">No recent purchase orders found</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Shared PO preview modal -->
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

    const renderItems = (items) => {
        if (!Array.isArray(items) || !items.length) {
            return '<tr><td colspan="5" class="text-center text-muted">No items found</td></tr>';
        }
        return items.map((item, idx) => `
            <tr>
                <td>${idx + 1}</td>
                <td>${escapeHtml(item.product_name)}</td>
                <td class="text-center">${item.quantity}</td>
                <td class="text-end">${item.unit_price}</td>
                <td class="text-end">${item.total_price}</td>
            </tr>`).join('');
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
                    const po = data.order;
                    modalBody.innerHTML = `
                        <div class="mb-3">
                            <h5 class="mb-1">${escapeHtml(po.label)} <span class="badge bg-secondary">${escapeHtml(po.status_label)}</span></h5>
                            <div class="small text-muted">Created on ${escapeHtml(po.order_date)} by ${escapeHtml(po.created_by || 'System')}</div>
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <h6 class="text-uppercase text-muted small mb-1">Vendor</h6>
                                <p class="mb-0">${escapeHtml(po.vendor_name)}</p>
                                <small class="text-muted">${escapeHtml(po.contact_name || 'No contact')} (${escapeHtml(po.contact_phone || 'N/A')})</small>
                            </div>
                            <div class="col-md-6">
                                <h6 class="text-uppercase text-muted small mb-1">Totals</h6>
                                <p class="mb-0"><strong>Total:</strong> ${po.total_amount}</p>
                                <small class="text-muted">Paid: ${po.paid_amount} | Balance: ${po.balance}</small>
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
                                <tbody>${renderItems(data.items)}</tbody>
                            </table>
                        </div>
                        <div class="border-top pt-3">
                            <div class="mb-2"><strong>Notes:</strong> ${po.notes ? escapeHtml(po.notes) : '<span class="text-muted">No notes</span>'}</div>
                            <a href="../purchases/orders/view.php?id=${po.id}" class="btn btn-sm btn-primary">Open Full PO</a>
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

<?php
require_once '../../includes/auth.php';
require_once '../../includes/header.php';
require_once '../../config/database.php';

// Permission check
if (!hasPermission('sales.orders.view_all')) {
    $_SESSION['error'] = "You don't have permission to access this page";
    header("Location: ../../dashboard.php");
    exit();
}

// Filter parameters
// Filter parameters
$status = $_GET['status'] ?? '';
$customer_ids = $_GET['customer_ids'] ?? [];
if (!is_array($customer_ids)) {
    $customer_ids = !empty($customer_ids) ? [$customer_ids] : [];
}
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$sort_by = $_GET['sort_by'] ?? 'date_desc';
$sortOptions = [
    'date_desc' => 'o.order_date DESC',
    'date_asc' => 'o.order_date ASC',
    'customer_asc' => 'c.name ASC',
    'customer_desc' => 'c.name DESC',
    'total_desc' => 'o.total_amount DESC',
    'total_asc' => 'o.total_amount ASC'
];
if (!array_key_exists($sort_by, $sortOptions)) {
    $sort_by = 'date_desc';
}

// Build query
$query = "SELECT o.id, o.internal_id, o.order_date, o.total_amount, o.paid_amount, 
                 o.status, c.name AS customer_name 
          FROM orders o
          JOIN customers c ON o.customer_id = c.id
          WHERE 1=1";
$params = [];

if (!empty($status)) {
    $query .= " AND o.status = ?";
    $params[] = $status;
}

if (!empty($customer_ids)) {
    $placeholders = implode(',', array_fill(0, count($customer_ids), '?'));
    $query .= " AND o.customer_id IN ($placeholders)";
    foreach ($customer_ids as $cid) {
        $params[] = $cid;
    }
}

if (!empty($date_from)) {
    $query .= " AND o.order_date >= ?";
    $params[] = $date_from;
}

if (!empty($date_to)) {
    $query .= " AND o.order_date <= ?";
    $params[] = $date_to;
}

$query .= " ORDER BY " . $sortOptions[$sort_by];

// Get orders
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get customers for filter dropdown
$customers = $pdo->query("SELECT id, name FROM customers ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Check if user has permission to view prices
$canViewPrices = hasPermission('sales.orders.price.view');
$canDeleteOrders = hasPermission('sales.orders.delete');
?>

<div class="container mt-4">
    <h2>Order Management</h2>
    
    <?php include '../../includes/messages.php'; ?>
    
    <div class="card mb-4">
        <div class="card-header">
            <div class="d-flex justify-content-between align-items-center">
                <h4>Order List</h4>
                <a href="create_order.php" class="btn btn-primary btn-sm">New Order</a>
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
                            <option value="in-production" <?= $status == 'in-production' ? 'selected' : '' ?>>In Production</option>
                            <option value="in-packing" <?= $status == 'in-packing' ? 'selected' : '' ?>>In Packing</option>
                            <option value="delivering" <?= $status == 'delivering' ? 'selected' : '' ?>>Delivering</option>
                            <option value="delivered" <?= $status == 'delivered' ? 'selected' : '' ?>>Delivered</option>
                            <option value="returned" <?= $status == 'returned' ? 'selected' : '' ?>>Returned</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="customer_ids" class="form-label">Customer(s)</label>
                        <select class="form-select js-searchable-select" id="customer_ids" name="customer_ids[]" multiple>
                            <?php foreach ($customers as $customer) : ?>
                                <option value="<?= $customer['id'] ?>" <?= in_array($customer['id'], $customer_ids) ? 'selected' : '' ?>>
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
                    <div class="col-md-3">
                        <label for="sort_by" class="form-label">Sort By</label>
                        <select class="form-select" id="sort_by" name="sort_by">
                            <option value="date_desc" <?= $sort_by === 'date_desc' ? 'selected' : '' ?>>Newest First</option>
                            <option value="date_asc" <?= $sort_by === 'date_asc' ? 'selected' : '' ?>>Oldest First</option>
                            <option value="customer_asc" <?= $sort_by === 'customer_asc' ? 'selected' : '' ?>>Customer (A-Z)</option>
                            <option value="customer_desc" <?= $sort_by === 'customer_desc' ? 'selected' : '' ?>>Customer (Z-A)</option>
                            <option value="total_desc" <?= $sort_by === 'total_desc' ? 'selected' : '' ?>>Value (High-Low)</option>
                            <option value="total_asc" <?= $sort_by === 'total_asc' ? 'selected' : '' ?>>Value (Low-High)</option>
                        </select>
                    </div>
                    <div class="col-md-12 d-flex justify-content-between align-items-end">
                        <div class="btn-group">
                            <button type="submit" class="btn btn-primary">Filter</button>
                            <a href="order_list.php" class="btn btn-secondary">Reset</a>
                        </div>
                        <?php if ($canDeleteOrders): ?>
                        <div id="bulkActions" class="d-none">
                            <button type="button" class="btn btn-danger" id="btnBulkDelete">
                                <i class="fas fa-trash me-1"></i> Bulk Delete (<span id="selectedCount">0</span>)
                            </button>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
            
            <div class="table-responsive">
                <table class="table js-datatable table-striped table-hover">
                    <thead>
                        <tr>
                            <th width="40"><?php if ($canDeleteOrders): ?><input type="checkbox" class="form-check-input" id="selectAll"><?php endif; ?></th>
                            <th>Order ID</th>
                            <th>Customer</th>
                            <th>Date</th>
                            <?php if ($canViewPrices): ?>
                            <th>Total</th>
                            <th>Paid</th>
                            <th>Balance</th>
                            <?php endif; ?>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orders as $order) : 
                            $balance = $order['total_amount'] - $order['paid_amount'];
                            $status_class = [
                                'new' => 'bg-primary',
                                'in-production' => 'bg-info',
                                'in-packing' => 'bg-info',
                                'delivering' => 'bg-warning',
                                'delivered' => 'bg-success',
                                'returned' => 'bg-danger',
                                'returned-refunded' => 'bg-secondary',
                                'partially-returned' => 'bg-danger',
                                'partially-returned-refunded' => 'bg-secondary'
                            ];
                        ?>
                            <tr data-id="<?= $order['id'] ?>">
                                <td><?php if ($canDeleteOrders): ?><input type="checkbox" class="form-check-input row-select" name="order_ids[]" value="<?= $order['id'] ?>"><?php endif; ?></td>
                                <td>
                                    <button type="button"
                                            class="btn btn-link p-0 text-decoration-none js-order-preview"
                                            data-order-id="<?= (int)$order['id'] ?>"
                                            title="Quick view order">
                                        <?= htmlspecialchars($order['internal_id']) ?>
                                        <i class="fas fa-caret-down ms-1"></i>
                                    </button>
                                </td>
                                <td><?= htmlspecialchars($order['customer_name']) ?></td>
                                <td><?= date('d/m/Y', strtotime($order['order_date'])) ?></td>
                                <?php if ($canViewPrices): ?>
                                <td><?= number_format($order['total_amount'], 2) ?></td>
                                <td><?= number_format($order['paid_amount'], 2) ?></td>
                                <td class="<?= $balance > 0 ? 'text-danger' : 'text-success' ?>">
                                    <?= number_format($balance * -1, 2) ?>
                                </td>
                                <?php endif; ?>
                                <td>
                                    <span class="badge <?= $status_class[$order['status']] ?>">
                                        <?= ucwords(str_replace('-', ' ', $order['status'])) ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="btn-group">
                                        <a href="order_details.php?id=<?= $order['id'] ?>" class="btn btn-sm btn-info">View</a>
                                        <?php if ($order['status'] == 'new' || $balance > 0) : ?>
                                            <a href="process_payment.php?order_id=<?= $order['id'] ?>" class="btn btn-sm btn-success">Payment</a>
                                        <?php endif; ?>
                                        <a href="generate_invoice.php?id=<?= $order['id'] ?>" class="btn btn-sm btn-secondary" target="_blank">Invoice</a>
                                        <?php if ($canDeleteOrders): ?>
                                            <button type="button" class="btn btn-sm btn-danger js-delete-order" data-id="<?= $order['id'] ?>" data-number="<?= htmlspecialchars($order['internal_id']) ?>">Delete</button>
                                        <?php endif; ?>
                                    </div>
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
<div class="modal fade" id="orderPreviewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Order Preview</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="text-center py-3 text-muted">
                    Select an order to preview its details.
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const modalElement = document.getElementById('orderPreviewModal');
    if (!modalElement || typeof bootstrap === 'undefined' || !bootstrap.Modal) return;
    const modalBody = modalElement.querySelector('.modal-body');
    const modalInstance = new bootstrap.Modal(modalElement);

    const spinner = `
        <div class="d-flex justify-content-center py-4">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
        </div>`;

    const showError = (message) => {
        modalBody.innerHTML = `<div class="alert alert-danger mb-0">${message}</div>`;
    };

    const escapeHtml = (unsafe = '') => {
        return String(unsafe)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    };

    document.querySelectorAll('.js-order-preview').forEach(button => {
        button.addEventListener('click', function () {
            const orderId = this.dataset.orderId;
            if (!orderId) return;
            modalBody.innerHTML = spinner;
            modalInstance.show();

            fetch('<?= BASE_URL ?>ajax/get_order_summary.php?id=' + encodeURIComponent(orderId))
                .then(resp => resp.json())
                .then(data => {
                    if (!data.success) {
                        showError(data.message || 'Unable to load order details.');
                        return;
                    }
                    const order = data.order;
                    const items = data.items || [];
                    let rows = '';
                    items.forEach((item, idx) => {
                        rows += `
                            <tr>
                                <td>${idx + 1}</td>
                                <td>${escapeHtml(item.product_name)}</td>
                                <td class="text-center">${item.quantity}</td>
                                <td class="text-end">${item.unit_price}</td>
                                <td class="text-end">${item.total_price}</td>
                            </tr>`;
                    });
                    if (!rows) {
                        rows = `<tr><td colspan="5" class="text-center text-muted">No items found</td></tr>`;
                    }
                    modalBody.innerHTML = `
                        <div class="mb-3">
                            <h5 class="mb-1">${escapeHtml(order.internal_id)} <span class="badge bg-secondary">${escapeHtml(order.status_label)}</span></h5>
                            <div class="small text-muted">Placed on ${escapeHtml(order.order_date)}</div>
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <h6 class="text-uppercase text-muted small mb-1">Customer</h6>
                                <p class="mb-0">${escapeHtml(order.customer_name)}</p>
                                <small class="text-muted">${escapeHtml(order.customer_phone || 'N/A')}</small>
                            </div>
                            <div class="col-md-6">
                                <h6 class="text-uppercase text-muted small mb-1">Contact &amp; Factory</h6>
                                <p class="mb-0">${escapeHtml(order.contact_name || 'Not set')} (${escapeHtml(order.contact_phone || 'n/a')})</p>
                                <small class="text-muted">${escapeHtml(order.factory_name || 'No factory')}</small>
                            </div>
                        </div>
                        <div class="table-responsive mb-3">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Product</th>
                                        <th class="text-center">Qty</th>
                                        <th class="text-end">Selling Price</th>
                                        <th class="text-end">Line Total</th>
                                    </tr>
                                </thead>
                                <tbody>${rows}</tbody>
                            </table>
                        </div>
                        <div class="d-flex justify-content-between align-items-center border-top pt-3">
                            <div>
                                <div>Total: <strong>${order.total_amount}</strong></div>
                                <div class="small text-muted">Paid: ${order.paid_amount} | Balance: ${order.balance}</div>
                            </div>
                            <a href="order_details.php?id=${order.id}" class="btn btn-sm btn-primary">
                                Open Full Order
                            </a>
                        </div>
                    `;
                })
                .catch(() => {
                    showError('Unable to load order details.');
                });
        });
    });

    // Bulk selection logic
    const selectAll = document.getElementById('selectAll');
    const bulkActions = document.getElementById('bulkActions');
    const selectedCount = document.getElementById('selectedCount');
    const btnBulkDelete = document.getElementById('btnBulkDelete');

    const updateBulkActions = () => {
        const checkedCount = document.querySelectorAll('.row-select:checked').length;
        if (selectedCount) selectedCount.textContent = checkedCount;
        if (bulkActions) {
            if (checkedCount > 0) {
                bulkActions.classList.remove('d-none');
            } else {
                bulkActions.classList.add('d-none');
            }
        }
    };

    if (selectAll) {
        selectAll.addEventListener('change', function() {
            // Dynamically find all row checkboxes because DataTables can add/remove them
            const rowCheckboxes = document.querySelectorAll('.row-select');
            rowCheckboxes.forEach(cb => {
                cb.checked = this.checked;
            });
            updateBulkActions();
        });
    }

    // Use event delegation for individual row checkboxes to support DataTables paging/re-rendering
    document.addEventListener('change', function(e) {
        if (e.target && e.target.classList.contains('row-select')) {
            updateBulkActions();
            
            // Update selectAll state if one is unchecked
            if (!e.target.checked && selectAll) {
                selectAll.checked = false;
            } else if (selectAll) {
                const allChecked = document.querySelectorAll('.row-select:not(:checked)').length === 0;
                selectAll.checked = allChecked;
            }
        }
    });

    if (btnBulkDelete) {
        btnBulkDelete.addEventListener('click', function() {
            const selectedIds = Array.from(document.querySelectorAll('.row-select:checked')).map(cb => cb.value);
            if (selectedIds.length === 0) return;

            if (confirm(`Are you sure you want to delete ${selectedIds.length} selected orders?`)) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'bulk_delete.php';
                
                selectedIds.forEach(id => {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'order_ids[]';
                    input.value = id;
                    form.appendChild(input);
                });

                document.body.appendChild(form);
                form.submit();
            }
        });
    }

    // Individual delete logic
    document.addEventListener('click', function(e) {
        if (e.target && e.target.classList.contains('js-delete-order')) {
            const id = e.target.dataset.id;
            const number = e.target.dataset.number;
            if (confirm(`Are you sure you want to delete order ${number}?`)) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'bulk_delete.php';
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'order_ids[]';
                input.value = id;
                form.appendChild(input);
                document.body.appendChild(form);
                form.submit();
            }
        }
    });
});
</script>

<?php require_once '../../includes/footer.php'; ?>

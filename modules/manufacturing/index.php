<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once 'lib.php';

$page_title = 'Manufacturing Control Center';
require_once '../../includes/header.php';

$statusFilter = sanitize($_GET['status'] ?? '');
$providerFilter = isset($_GET['provider_id']) ? (int)$_GET['provider_id'] : 0;

$customers = [];
$customerResult = $conn->query("SELECT id, name FROM customers ORDER BY name");
if ($customerResult) {
    while ($customerRow = $customerResult->fetch_assoc()) {
        $customers[] = $customerRow;
    }
}
$whereClauses = [];

if ($statusFilter) {
    $whereClauses[] = "mo.status = '" . $conn->real_escape_string($statusFilter) . "'";
}
if ($providerFilter > 0) {
    $whereClauses[] = "mo.customer_id = " . (int)$providerFilter;
}

$ordersQuery = "
    SELECT mo.*, c.name AS customer_name, f.name AS formula_name
    FROM manufacturing_orders mo
    JOIN customers c ON c.id = mo.customer_id
    JOIN manufacturing_formulas f ON f.id = mo.formula_id
";
if (!empty($whereClauses)) {
    $ordersQuery .= ' WHERE ' . implode(' AND ', $whereClauses);
}
$ordersQuery .= ' ORDER BY mo.created_at DESC';

$orders = [];
$orderResult = $conn->query($ordersQuery);
if ($orderResult) {
    while ($orderRow = $orderResult->fetch_assoc()) {
        $orders[] = $orderRow;
    }
}

$stepsByOrder = [];
$stepToOrder = [];
$stepIds = [];
if (!empty($orders)) {
    $orderIds = array_map('intval', array_column($orders, 'id'));
    if (!empty($orderIds)) {
        $orderIdsList = implode(',', $orderIds);
        $stepsSql = "SELECT * FROM manufacturing_order_steps WHERE manufacturing_order_id IN ({$orderIdsList})";
        $stepsResult = $conn->query($stepsSql);
        if ($stepsResult) {
            while ($stepRow = $stepsResult->fetch_assoc()) {
                $stepsByOrder[$stepRow['manufacturing_order_id']][] = $stepRow;
                $stepToOrder[$stepRow['id']] = $stepRow['manufacturing_order_id'];
                $stepIds[] = $stepRow['id'];
            }
        }
    }
}

$orderDocumentCounts = [];
if (!empty($stepIds)) {
    $stepIdsList = implode(',', array_map('intval', $stepIds));
    $docsSql = "
        SELECT manufacturing_order_step_id, COUNT(*) AS total_docs
        FROM manufacturing_step_documents
        WHERE manufacturing_order_step_id IN ({$stepIdsList})
        GROUP BY manufacturing_order_step_id
    ";
    $docsResult = $conn->query($docsSql);
    if ($docsResult) {
        while ($docRow = $docsResult->fetch_assoc()) {
            $orderId = $stepToOrder[$docRow['manufacturing_order_step_id']] ?? null;
            if ($orderId) {
                $orderDocumentCounts[$orderId] = ($orderDocumentCounts[$orderId] ?? 0) + (int)$docRow['total_docs'];
            }
        }
    }
}

?>

<div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-3">
    <div>
        <h2>Manufacturing Control</h2>
        <p class="text-muted mb-0">Track every staged order from getting materials through preparation and delivery, with automatic Excel/PDF handoffs at each transition.</p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="create.php" class="btn btn-primary">
            <i class="fas fa-plus me-2"></i> New Manufacturing Order
        </a>
        <a href="instructions.html" class="btn btn-outline-secondary" target="_blank">
            <i class="fas fa-book me-1"></i> Workflow instructions
        </a>
    </div>
</div>

<form class="row g-3 mb-4" method="get">
    <div class="col-md-3">
        <select class="form-select" name="provider_id">
            <option value="">Filter by provider</option>
            <?php foreach ($customers as $customer): ?>
                <option value="<?php echo $customer['id']; ?>" <?php echo $providerFilter === (int)$customer['id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($customer['name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-3">
        <select class="form-select" name="status">
            <option value="">All statuses</option>
            <?php
            $statusOptions = ['getting' => 'Getting', 'preparing' => 'Preparing', 'delivering' => 'Delivering', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];
            foreach ($statusOptions as $code => $label):
            ?>
                <option value="<?php echo $code; ?>" <?php echo $statusFilter === $code ? 'selected' : ''; ?>>
                    <?php echo $label; ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-4">
        <input type="text" class="form-control" placeholder="Search by order number or formula" id="searchOrder">
    </div>
    <div class="col-md-2 d-flex gap-2">
        <button type="submit" class="btn btn-outline-primary flex-grow-1">Apply</button>
        <a href="index.php" class="btn btn-outline-secondary flex-grow-1">Reset</a>
    </div>
</form>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0" id="manufacturingTable">
                <thead class="table-light">
                    <tr>
                        <th>Order #</th>
                        <th>Provider</th>
                        <th>Formula</th>
                        <th>Priority</th>
                        <th>Status</th>
                        <th>Next Step</th>
                        <th>Progress</th>
                        <th>Docs</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($orders)): ?>
                        <?php foreach ($orders as $order): ?>
                            <?php
                            $orderSteps = $stepsByOrder[$order['id']] ?? [];
                            $totalSteps = max(1, count(manufacturing_get_step_definitions()));
                            $completedSteps = 0;
                            foreach ($orderSteps as $step) {
                                if ($step['status'] === 'completed') {
                                    $completedSteps++;
                                }
                            }
                            $progressPercent = (int)(($completedSteps / $totalSteps) * 100);
                            $nextStepLabel = manufacturing_get_next_step_label($orderSteps);
                            $docCount = $orderDocumentCounts[$order['id']] ?? 0;
                            $badge = manufacturing_order_status_badge($order['status']);
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($order['order_number']); ?></td>
                                <td><?= htmlspecialchars($order['customer_name']); ?></td>
                                <td><?= htmlspecialchars($order['formula_name']); ?></td>
                                <td><?= ucfirst(htmlspecialchars($order['priority'])); ?></td>
                                <td>
                                    <span class="badge <?= $badge['class']; ?> text-uppercase"><?= $badge['label']; ?></span>
                                </td>
                                <td><?= htmlspecialchars($nextStepLabel); ?></td>
                                <td>
                                    <div class="progress" style="height:6px;">
                                        <div class="progress-bar" role="progressbar" style="width: <?= $progressPercent; ?>%;" aria-valuenow="<?= $progressPercent; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                    </div>
                                    <small class="text-muted"><?= $completedSteps; ?>/<?= $totalSteps; ?> steps</small>
                                </td>
                                <td>
                                    <i class="fas fa-file-alt me-1"></i> <?= $docCount; ?>
                                </td>
                                <td>
                                    <a href="order.php?id=<?= $order['id']; ?>" class="btn btn-sm btn-outline-primary">
                                        <i class="fas fa-eye me-1"></i> View
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>

<script>
    $(document).ready(function () {
        if ($.fn.DataTable && !$.fn.DataTable.isDataTable('#manufacturingTable')) {
            $('#manufacturingTable').DataTable({
                responsive: true,
                pageLength: 25,
                lengthMenu: [10, 25, 50, 100],
                order: [[0, 'desc']],
                language: {
                    emptyTable: 'No manufacturing orders found.'
                },
                columnDefs: [
                    { orderable: false, targets: [6, 7, 8] }
                ]
            });
        }

        $('#searchOrder').on('input', function () {
            const table = $('#manufacturingTable').DataTable();
            table.search($(this).val()).draw();
        });
    });
</script>

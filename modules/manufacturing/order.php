<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once 'lib.php';

$orderId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($orderId <= 0) {
    setAlert('danger', 'Invalid manufacturing order specified.');
    redirect('index.php');
}

$orderStmt = $conn->prepare("
    SELECT mo.*, c.name AS customer_name, f.name AS formula_name, f.description AS formula_description, f.components_json
    FROM manufacturing_orders mo
    JOIN customers c ON c.id = mo.customer_id
    JOIN manufacturing_formulas f ON f.id = mo.formula_id
    WHERE mo.id = ?
    LIMIT 1
");
$orderStmt->bind_param('i', $orderId);
$orderStmt->execute();
$orderResult = $orderStmt->get_result();
$order = $orderResult->fetch_assoc();
$orderStmt->close();

if (!$order) {
    setAlert('danger', 'Manufacturing order not found.');
    redirect('index.php');
}

$formulaComponents = json_decode($order['components_json'] ?? '[]', true);
if (!is_array($formulaComponents)) {
    $formulaComponents = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stepKey = $_POST['step_key'] ?? '';
    $action = $_POST['action'] ?? 'update';
    $statusInput = $_POST['status'] ?? '';
    $notesInput = trim($_POST['notes'] ?? '');

    $stepStmt = $pdo->prepare("
        SELECT * FROM manufacturing_order_steps 
        WHERE manufacturing_order_id = ? AND step_key = ? 
        LIMIT 1
    ");
    $stepStmt->execute([$orderId, $stepKey]);
    $stepRow = $stepStmt->fetch(PDO::FETCH_ASSOC);
    $stepStmt = null;

    if (!$stepRow) {
        setAlert('danger', 'Selected step cannot be found.');
        redirect('order.php?id=' . $orderId);
    }

    $allowedStatuses = ['pending', 'in_progress', 'completed'];
    $statusToSave = in_array($statusInput, $allowedStatuses, true) ? $statusInput : $stepRow['status'];
    if ($action === 'regenerate') {
        $statusToSave = $stepRow['status'];
    }

    $startedAt = $stepRow['started_at'];
    $completedAt = $stepRow['completed_at'];
    if ($action !== 'regenerate') {
        if ($statusToSave === 'in_progress' && !$startedAt) {
            $startedAt = date('Y-m-d H:i:s');
        }
        if ($statusToSave === 'completed') {
            if (!$startedAt) {
                $startedAt = date('Y-m-d H:i:s');
            }
            $completedAt = date('Y-m-d H:i:s');
        } elseif ($statusToSave === 'pending') {
            $startedAt = null;
            $completedAt = null;
        } else {
            $completedAt = null;
        }
    }

    try {
        $pdo->beginTransaction();

        $updateStep = $pdo->prepare("
            UPDATE manufacturing_order_steps 
            SET status = ?, notes = ?, started_at = ?, completed_at = ?, updated_at = NOW() 
            WHERE id = ?
        ");
        $updateStep->execute([
            $statusToSave,
            $notesInput,
            $startedAt,
            $completedAt,
            $stepRow['id']
        ]);

        $orderStepsStmt = $conn->prepare("SELECT step_key, status FROM manufacturing_order_steps WHERE manufacturing_order_id = ?");
        $orderStepsStmt->bind_param('i', $orderId);
        $orderStepsStmt->execute();
        $stepsForStatus = [];
        $result = $orderStepsStmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $stepsForStatus[] = $row;
        }
        $orderStepsStmt->close();

        $overallStatus = manufacturing_determine_order_status_from_steps($stepsForStatus);
        $updateOrder = $pdo->prepare("UPDATE manufacturing_orders SET status = ?, updated_at = NOW() WHERE id = ?");
        $updateOrder->execute([$overallStatus, $orderId]);

        $pdo->commit();
    } catch (Exception $ex) {
        $pdo->rollBack();
        setAlert('danger', 'Unable to save the step: ' . $ex->getMessage());
        redirect('order.php?id=' . $orderId);
    }

    $docMessage = '';
    try {
        $freshStepStmt = $conn->prepare("SELECT * FROM manufacturing_order_steps WHERE id = ?");
        $freshStepStmt->bind_param('i', $stepRow['id']);
        $freshStepStmt->execute();
        $updatedStep = $freshStepStmt->get_result()->fetch_assoc();
        $freshStepStmt->close();

        manufacturing_generate_step_documents(
            $conn,
            $order,
            $updatedStep,
            ['name' => $order['formula_name'], 'components_json' => $order['components_json'] ?? '[]'],
            $formulaComponents,
            $notesInput,
            $_SESSION['user_id'] ?? null
        );
    } catch (Exception $docEx) {
        $docMessage = ' Document generation failed: ' . $docEx->getMessage();
    }

    $actionText = $action === 'regenerate' ? 'Regenerated' : 'Saved';
    setAlert('success', "{$actionText} the step and Excel/PDF handoff created.{$docMessage}");
    logActivity("Updated manufacturing step {$stepKey} for order {$order['order_number']}", [
        'order_id' => $orderId,
        'step_key' => $stepKey,
        'status' => $statusToSave,
    ]);

    header('Location: order.php?id=' . $orderId);
    exit;
}

$page_title = 'Manufacturing Order ' . $order['order_number'];
require_once '../../includes/header.php';

$steps = [];
$stepStmt = $conn->prepare("
    SELECT * FROM manufacturing_order_steps 
    WHERE manufacturing_order_id = ? 
    ORDER BY FIELD(step_key, 'sourcing', 'receipt', 'preparation', 'quality', 'packaging', 'dispatch', 'delivering')
");
$stepStmt->bind_param('i', $orderId);
$stepStmt->execute();
$stepResult = $stepStmt->get_result();
while ($stepRow = $stepResult->fetch_assoc()) {
    $steps[] = $stepRow;
}
$stepStmt->close();

$stepsByKey = [];
foreach ($steps as $stepItem) {
    $stepsByKey[$stepItem['step_key']] = $stepItem;
}

$orderDocuments = [];
$totalDocuments = 0;
foreach ($steps as $stepRow) {
    $docs = manufacturing_get_documents_by_step($conn, $stepRow['id']);
    $orderDocuments[$stepRow['id']] = $docs;
    $totalDocuments += count($docs);
}

$stepDefinitions = manufacturing_get_step_definitions();
$totalSteps = max(1, count($stepDefinitions));
$completedSteps = 0;
foreach ($steps as $stepRow) {
    if ($stepRow['status'] === 'completed') {
        $completedSteps++;
    }
}
$progressPercent = (int)(($completedSteps / $totalSteps) * 100);
$nextStepLabel = manufacturing_get_next_step_label($steps);
$orderBadge = manufacturing_order_status_badge($order['status']);
?>

<div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-3">
    <div>
        <h2>Manufacturing Order <?= htmlspecialchars($order['order_number']); ?></h2>
        <p class="text-muted mb-0">Provider: <?= htmlspecialchars($order['customer_name']); ?> | Formula: <?= htmlspecialchars($order['formula_name']); ?></p>
    </div>
    <div class="d-flex gap-2">
        <span class="badge <?= $orderBadge['class']; ?> text-uppercase">
            <?= $orderBadge['label']; ?>
        </span>
        <a href="index.php" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left me-1"></i> Back to dashboard
        </a>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header">Order snapshot</div>
            <div class="card-body">
                <p class="mb-1"><strong>Priority:</strong> <?= ucfirst(htmlspecialchars($order['priority'])); ?></p>
                <p class="mb-1"><strong>Due Date:</strong> <?= $order['due_date'] ? htmlspecialchars($order['due_date']) : '<span class="text-muted">Not set</span>'; ?></p>
                <p class="mb-1"><strong>Batch size:</strong> <?= htmlspecialchars($order['batch_size']); ?></p>
                <p class="mb-1"><strong>Notes:</strong><br><?= nl2br(htmlspecialchars($order['notes'] ?? 'No notes provided.')); ?></p>
                <div class="mt-3">
                    <div class="progress" style="height:10px;">
                        <div class="progress-bar" role="progressbar" style="width: <?= $progressPercent; ?>%;" aria-valuenow="<?= $progressPercent; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                    </div>
                    <small class="text-muted"><?= $completedSteps; ?>/<?= $totalSteps; ?> steps complete • Next: <?= htmlspecialchars($nextStepLabel); ?></small>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header">Formula components</div>
            <div class="card-body">
                <?php if (!empty($formulaComponents)): ?>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead>
                                <tr>
                                    <th class="ps-0">Component</th>
                                    <th>Qty / Ratio</th>
                                    <th>Unit</th>
                                    <th>Notes</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($formulaComponents as $fComponent): ?>
                                    <tr>
                                        <td class="ps-0"><?= htmlspecialchars($fComponent['name'] ?? '-'); ?></td>
                                        <td><?= htmlspecialchars($fComponent['quantity'] ?? $fComponent['ratio'] ?? '-'); ?></td>
                                        <td><?= htmlspecialchars($fComponent['unit'] ?? '-'); ?></td>
                                        <td><?= htmlspecialchars($fComponent['notes'] ?? '-'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-muted small mb-0">No components were defined for this formula.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header">Handoff documentation</div>
            <div class="card-body">
                <p class="mb-1"><strong>Total files:</strong> <?= $totalDocuments; ?> (Excel + PDF per step)</p>
                <p class="mb-1"><strong>Formula description:</strong> <?= htmlspecialchars($order['formula_description'] ?? 'No description'); ?></p>
                <p class="text-muted small mb-0">Every save regenerates the Excel and PDF that travel with the order to the next internal team.</p>
            </div>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header">Workflow timeline</div>
    <div class="card-body">
        <div class="list-group list-group-flush">
            <?php foreach ($stepDefinitions as $stepKey => $definition): ?>
                <?php
                $stepData = $stepsByKey[$stepKey] ?? null;
                $stepStatus = $stepData['status'] ?? 'pending';
                $docCountForStep = $stepData ? count($orderDocuments[$stepData['id']] ?? []) : 0;
                ?>
                <div class="list-group-item border-bottom">
                    <div class="d-flex justify-content-between align-items-start gap-2">
                        <div>
                            <div class="fw-semibold"><?= htmlspecialchars($definition['label']); ?></div>
                            <div class="text-muted small"><?= htmlspecialchars($definition['description']); ?></div>
                        </div>
                        <div class="text-end">
                            <span class="badge <?= manufacturing_status_badge_class($stepStatus); ?> text-uppercase"><?= htmlspecialchars($stepStatus); ?></span>
                            <div class="small text-muted mt-1"><?= $docCountForStep; ?> files</div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="card-footer text-muted small">
        Each status change triggers an Excel + PDF handoff stored with the order so downstream teams always have the latest data.
    </div>
</div>

<div class="card">
    <div class="card-header">Step-by-step manufacturing workflow</div>
    <div class="card-body">
        <div class="accordion" id="manufacturingSteps">
            <?php foreach ($steps as $index => $stepRow): ?>
                <?php $documents = $orderDocuments[$stepRow['id']] ?? []; ?>
                <div class="accordion-item">
                    <h2 class="accordion-header" id="stepHeading<?= $stepRow['id']; ?>">
                        <button class="accordion-button <?= $index === 0 ? '' : 'collapsed'; ?>" type="button" data-bs-toggle="collapse" data-bs-target="#stepCollapse<?= $stepRow['id']; ?>" aria-expanded="<?= $index === 0 ? 'true' : 'false'; ?>">
                            <?= manufacturing_get_step_label($stepRow['step_key']); ?>
                            <span class="badge <?= manufacturing_status_badge_class($stepRow['status']); ?> ms-3 text-uppercase"><?= $stepRow['status']; ?></span>
                        </button>
                    </h2>
                    <div id="stepCollapse<?= $stepRow['id']; ?>" class="accordion-collapse collapse <?= $index === 0 ? 'show' : ''; ?>" aria-labelledby="stepHeading<?= $stepRow['id']; ?>" data-bs-parent="#manufacturingSteps">
                        <div class="accordion-body">
                            <p class="text-muted small mb-3"><?= manufacturing_get_step_instruction($stepRow['step_key']); ?></p>
                            <form method="post">
                                <input type="hidden" name="order_id" value="<?= $order['id']; ?>">
                                <input type="hidden" name="step_key" value="<?= htmlspecialchars($stepRow['step_key']); ?>">
                                <div class="row g-3 mb-3">
                                    <div class="col-md-4">
                                        <label class="form-label">Step status</label>
                                        <select class="form-select" name="status">
                                            <?php foreach (['pending', 'in_progress', 'completed'] as $statusOption): ?>
                                                <option value="<?= $statusOption; ?>" <?= $stepRow['status'] === $statusOption ? 'selected' : ''; ?>>
                                                    <?= ucfirst(str_replace('_', ' ', $statusOption)); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-8">
                                        <label class="form-label">Notes for Excel/PDF handoff</label>
                                        <textarea name="notes" class="form-control" rows="3" placeholder="Detail what should travel to the next team."><?= htmlspecialchars($stepRow['notes']); ?></textarea>
                                    </div>
                                </div>
                                <div class="d-flex gap-2">
                                    <button type="submit" name="action" value="update" class="btn btn-primary btn-sm">
                                        <i class="fas fa-file-export me-1"></i> Save step &amp; generate docs
                                    </button>
                                    <button type="submit" name="action" value="regenerate" class="btn btn-outline-secondary btn-sm">
                                        <i class="fas fa-sync-alt me-1"></i> Regenerate Excel/PDF
                                    </button>
                                </div>
                            </form>
                            <div class="mt-4 border rounded p-3 bg-light">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span class="fw-semibold">Latest handoff files</span>
                                    <span class="text-muted small"><?= count($documents); ?> files</span>
                                </div>
                                <?php if (!empty($documents)): ?>
                                    <ul class="list-group list-group-flush">
                                        <?php foreach (array_slice($documents, 0, 4) as $document): ?>
                                            <li class="list-group-item px-0 py-2 d-flex justify-content-between align-items-center border-0">
                                                <div>
                                                    <span class="badge <?= $document['type'] === 'pdf' ? 'bg-danger' : 'bg-success'; ?> me-2">
                                                        <?= strtoupper($document['type']); ?>
                                                    </span>
                                                    <?= htmlspecialchars($document['file_name']); ?>
                                                    <div class="text-muted small">
                                                        <?= formatDateTime($document['generated_at']); ?>
                                                        <?php if (!empty($document['generated_by_name'])): ?>
                                                            by <?= htmlspecialchars($document['generated_by_name']); ?>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <a href="<?= manufacturing_get_document_url($document['file_path']); ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                                    <i class="fas fa-download"></i>
                                                </a>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php else: ?>
                                    <p class="text-muted small mb-0">No Excel/PDF exports yet.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>

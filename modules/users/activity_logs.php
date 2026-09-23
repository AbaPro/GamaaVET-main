<?php
require_once '../../includes/auth.php';
require_once '../../config/database.php';

if (!hasPermission('users.activity_logs.view')) {
    setAlert('danger', 'You do not have permission to view activity logs.');
    redirect('../../dashboard.php');
}

$page_title = 'Activity Logs';
$perPage = 100;

function humanizeLogKey(string $key): string
{
    $key = str_replace(['_', '-'], ' ', $key);
    return ucwords($key);
}

function formatLogValue($value): string
{
    if (is_bool($value)) {
        return $value ? 'Yes' : 'No';
    }

    if (is_scalar($value) || $value === null) {
        return (string)$value;
    }

    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

purgeOldActivityLogs();

$hasStructuredColumns = tableHasColumn('activity_logs', 'action_type');
if ($hasStructuredColumns) {
    // Rows written before the structured columns existed get classified once, on first view.
    backfillActivityLogMeta(5000);
}

$entityTypes = activityEntityTypes();
$actionTypes = activityActionTypes();

$usersList = $pdo->query("SELECT id, name FROM users ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$filters = [
    'user_id' => trim($_GET['user_id'] ?? ''),
    'action_type' => isset($actionTypes[$_GET['action_type'] ?? '']) ? $_GET['action_type'] : '',
    'entity_type' => isset($entityTypes[$_GET['entity_type'] ?? '']) ? $_GET['entity_type'] : '',
    'entity_id' => isset($_GET['entity_id']) && ctype_digit((string)$_GET['entity_id']) ? (int)$_GET['entity_id'] : null,
    'keyword' => trim($_GET['keyword'] ?? ''),
    'date_from' => normalizeTransactionDate($_GET['date_from'] ?? '') ?? '',
    'date_to' => normalizeTransactionDate($_GET['date_to'] ?? '') ?? '',
];
if (!$hasStructuredColumns) {
    $filters['action_type'] = $filters['entity_type'] = '';
    $filters['entity_id'] = null;
}

// Everything except the action type, so the summary counts stay meaningful while a type is selected.
$baseConditions = [];
$baseParams = [];

if ($filters['user_id'] === 'none') {
    $baseConditions[] = 'al.user_id IS NULL';
} elseif (ctype_digit($filters['user_id'])) {
    $baseConditions[] = 'al.user_id = ?';
    $baseParams[] = (int)$filters['user_id'];
}

if ($filters['entity_type'] !== '') {
    $baseConditions[] = 'al.entity_type = ?';
    $baseParams[] = $filters['entity_type'];
    if ($filters['entity_id'] !== null) {
        $baseConditions[] = 'al.entity_id = ?';
        $baseParams[] = $filters['entity_id'];
    }
}

if ($filters['keyword'] !== '') {
    $baseConditions[] = '(al.action LIKE ? OR al.details LIKE ? OR al.ip_address LIKE ?)';
    $like = '%' . $filters['keyword'] . '%';
    array_push($baseParams, $like, $like, $like);
}

if ($filters['date_from'] !== '') {
    $baseConditions[] = 'al.created_at >= ?';
    $baseParams[] = $filters['date_from'] . ' 00:00:00';
}

if ($filters['date_to'] !== '') {
    $baseConditions[] = 'al.created_at <= ?';
    $baseParams[] = $filters['date_to'] . ' 23:59:59';
}

$conditions = $baseConditions;
$params = $baseParams;
if ($filters['action_type'] !== '') {
    $conditions[] = 'al.action_type = ?';
    $params[] = $filters['action_type'];
}

$whereSql = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
$selectSql = "SELECT al.*, u.name AS user_name
              FROM activity_logs al
              LEFT JOIN users u ON al.user_id = u.id" . $whereSql . "
              ORDER BY al.created_at DESC, al.id DESC";

function describeLogUser(array $log, ?array $details): string
{
    if (!empty($log['user_name'])) {
        return $log['user_name'];
    }
    if (!empty($log['user_id'])) {
        return 'Deleted user #' . $log['user_id'];
    }
    if (($details['source'] ?? null) === 'customer_portal') {
        return 'Customer (portal)';
    }
    if (($log['action_type'] ?? null) === 'login_failed') {
        return 'Not signed in';
    }
    return 'System';
}

if (($_GET['export'] ?? '') === 'csv') {
    $stmt = $pdo->prepare($selectSql . ' LIMIT 50000');
    $stmt->execute($params);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=activity_logs_' . date('Y-m-d_His') . '.csv');
    $output = fopen('php://output', 'w');
    fwrite($output, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows Arabic correctly
    fputcsv($output, ['Timestamp', 'User', 'Action Type', 'Object', 'Object ID', 'Description', 'Details', 'IP Address']);
    while ($log = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $details = !empty($log['details']) ? json_decode($log['details'], true) : null;
        fputcsv($output, [
            $log['created_at'],
            describeLogUser($log, is_array($details) ? $details : null),
            $actionTypes[$log['action_type'] ?? ''][0] ?? '',
            $entityTypes[$log['entity_type'] ?? ''][0] ?? ($log['entity_type'] ?? ''),
            $log['entity_id'] ?? '',
            $log['action'],
            $log['details'],
            $log['ip_address'],
        ]);
    }
    fclose($output);
    exit;
}

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM activity_logs al" . $whereSql);
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page = min($totalPages, max(1, (int)($_GET['page'] ?? 1)));
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare($selectSql . " LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$typeCounts = [];
if ($hasStructuredColumns) {
    $summaryStmt = $pdo->prepare("SELECT al.action_type, COUNT(*) AS total FROM activity_logs al"
        . ($baseConditions ? ' WHERE ' . implode(' AND ', $baseConditions) : '')
        . " GROUP BY al.action_type");
    $summaryStmt->execute($baseParams);
    foreach ($summaryStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $typeCounts[$row['action_type'] ?? 'other'] = (int)$row['total'];
    }
}

function activityLogUrl(array $overrides = []): string
{
    $query = array_merge($_GET, $overrides);
    unset($query['export']);
    $query = array_filter($query, static fn($value) => $value !== null && $value !== '');
    return 'activity_logs.php' . ($query ? '?' . http_build_query($query) : '');
}

$entityLabel = $filters['entity_type'] !== '' ? $entityTypes[$filters['entity_type']][0] : '';

require_once '../../includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h1 class="h4 mb-0">Activity Logs</h1>
            <div class="text-muted small">Who signed in, and who created, edited or deleted what. Entries older than one year are deleted automatically.</div>
        </div>
        <a href="<?= htmlspecialchars(activityLogUrl(['export' => 'csv', 'page' => null])); ?>" class="btn btn-outline-success btn-sm">
            <i class="fas fa-file-csv me-1"></i> Export CSV
        </a>
    </div>

    <?php include '../../includes/messages.php'; ?>

    <?php if (!$hasStructuredColumns): ?>
        <div class="alert alert-warning">
            Apply <code>migrations/20260923_activity_logs_structured.sql</code> to enable filtering by action and object.
        </div>
    <?php endif; ?>

    <?php if ($hasStructuredColumns): ?>
        <div class="d-flex flex-wrap gap-2 mb-3">
            <a href="<?= htmlspecialchars(activityLogUrl(['action_type' => null, 'page' => null])); ?>"
               class="btn btn-sm <?= $filters['action_type'] === '' ? 'btn-dark' : 'btn-outline-dark'; ?>">
                All <span class="badge bg-light text-dark ms-1"><?= number_format(array_sum($typeCounts)); ?></span>
            </a>
            <?php foreach ($actionTypes as $typeKey => [$typeLabel, $typeColor]): ?>
                <?php if (empty($typeCounts[$typeKey]) && $filters['action_type'] !== $typeKey) continue; ?>
                <a href="<?= htmlspecialchars(activityLogUrl(['action_type' => $typeKey, 'page' => null])); ?>"
                   class="btn btn-sm <?= $filters['action_type'] === $typeKey ? 'btn-' . $typeColor : 'btn-outline-' . ($typeColor === 'light' ? 'secondary' : $typeColor); ?>">
                    <?= htmlspecialchars($typeLabel); ?>
                    <span class="badge bg-light text-dark ms-1"><?= number_format($typeCounts[$typeKey] ?? 0); ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="card mb-4">
        <div class="card-body">
            <form class="row g-3">
                <div class="col-md-2">
                    <label class="form-label">User</label>
                    <select name="user_id" class="form-select">
                        <option value="">All users</option>
                        <option value="none" <?= $filters['user_id'] === 'none' ? 'selected' : ''; ?>>No user (portal, failed logins, system)</option>
                        <?php foreach ($usersList as $user): ?>
                            <option value="<?= $user['id']; ?>" <?= $filters['user_id'] === (string)$user['id'] ? 'selected' : ''; ?>>
                                <?= htmlspecialchars($user['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($hasStructuredColumns): ?>
                    <div class="col-md-2">
                        <label class="form-label">Action</label>
                        <select name="action_type" class="form-select">
                            <option value="">All actions</option>
                            <?php foreach ($actionTypes as $typeKey => [$typeLabel]): ?>
                                <option value="<?= $typeKey; ?>" <?= $filters['action_type'] === $typeKey ? 'selected' : ''; ?>><?= htmlspecialchars($typeLabel); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Object</label>
                        <select name="entity_type" class="form-select">
                            <option value="">All objects</option>
                            <?php foreach ($entityTypes as $typeKey => [$typeLabel]): ?>
                                <option value="<?= $typeKey; ?>" <?= $filters['entity_type'] === $typeKey ? 'selected' : ''; ?>><?= htmlspecialchars($typeLabel); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-1">
                        <label class="form-label">Object ID</label>
                        <input type="number" min="1" name="entity_id" class="form-control" value="<?= $filters['entity_id'] !== null ? (int)$filters['entity_id'] : ''; ?>">
                    </div>
                <?php endif; ?>
                <div class="col-md-2">
                    <label class="form-label">Keyword</label>
                    <input type="text" name="keyword" class="form-control" value="<?= htmlspecialchars($filters['keyword']); ?>" placeholder="Text, details or IP">
                </div>
                <div class="col-md-1">
                    <label class="form-label">From</label>
                    <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($filters['date_from']); ?>">
                </div>
                <div class="col-md-1">
                    <label class="form-label">To</label>
                    <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($filters['date_to']); ?>">
                </div>
                <div class="col-md-1 d-flex align-items-end gap-2">
                    <button type="submit" class="btn btn-primary w-100" title="Filter"><i class="fas fa-filter"></i></button>
                    <a href="activity_logs.php" class="btn btn-light" title="Reset"><i class="fas fa-times"></i></a>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <h5 class="mb-0">
                <?php if ($entityLabel !== '' && $filters['entity_id'] !== null): ?>
                    History of <?= htmlspecialchars($entityLabel); ?> #<?= (int)$filters['entity_id']; ?>
                <?php else: ?>
                    Activity
                <?php endif; ?>
            </h5>
            <span class="text-muted small">
                <?php if ($totalRows > 0): ?>
                    Showing <?= number_format($offset + 1); ?>–<?= number_format($offset + count($logs)); ?> of <?= number_format($totalRows); ?>
                <?php else: ?>
                    No results
                <?php endif; ?>
            </span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <!-- Not .table-hover: the global row-click handler in footer.php would navigate away on any click. -->
                <table class="table table-striped table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th scope="col" class="text-nowrap">Timestamp</th>
                            <th scope="col">User</th>
                            <?php if ($hasStructuredColumns): ?>
                                <th scope="col">Action</th>
                                <th scope="col">Object</th>
                            <?php endif; ?>
                            <th scope="col">Description</th>
                            <th scope="col">Details</th>
                            <th scope="col">IP Address</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($logs): ?>
                            <?php foreach ($logs as $log): ?>
                                <?php
                                    $decodedDetails = !empty($log['details']) ? json_decode($log['details'], true) : null;
                                    $detailsArray = is_array($decodedDetails) ? $decodedDetails : null;
                                    $logUserLabel = describeLogUser($log, $detailsArray);
                                    if ($detailsArray !== null) {
                                        unset($detailsArray['source']);
                                    }
                                    $actionType = $log['action_type'] ?? null;
                                    $entityType = $log['entity_type'] ?? null;
                                    $entityId = isset($log['entity_id']) ? (int)$log['entity_id'] : null;
                                    [$entityName, $entityPath] = $entityTypes[$entityType] ?? [$entityType ? humanizeLogKey($entityType) : null, null];
                                ?>
                                <tr>
                                    <td class="text-nowrap"><?= date('Y-m-d H:i', strtotime($log['created_at'])); ?></td>
                                    <td class="text-nowrap">
                                        <?php if (!empty($log['user_id'])): ?>
                                            <a href="<?= htmlspecialchars(activityLogUrl(['user_id' => (int)$log['user_id'], 'page' => null])); ?>" class="text-decoration-none" title="Show only this user">
                                                <?= htmlspecialchars($logUserLabel); ?>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted"><?= htmlspecialchars($logUserLabel); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <?php if ($hasStructuredColumns): ?>
                                        <td>
                                            <?php if ($actionType && isset($actionTypes[$actionType])): ?>
                                                <?php [$badgeLabel, $badgeColor] = $actionTypes[$actionType]; ?>
                                                <span class="badge bg-<?= $badgeColor; ?><?= in_array($badgeColor, ['warning', 'light'], true) ? ' text-dark' : ''; ?>"><?= htmlspecialchars($badgeLabel); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-nowrap">
                                            <?php if ($entityName): ?>
                                                <?php $entityText = $entityName . ($entityId ? ' #' . $entityId : ''); ?>
                                                <?php if ($entityPath && $entityId && $actionType !== 'delete'): ?>
                                                    <a href="<?= BASE_URL . $entityPath . $entityId; ?>"><?= htmlspecialchars($entityText); ?></a>
                                                <?php else: ?>
                                                    <?= htmlspecialchars($entityText); ?>
                                                <?php endif; ?>
                                                <?php if ($entityId): ?>
                                                    <a href="<?= htmlspecialchars(activityLogUrl(['entity_type' => $entityType, 'entity_id' => $entityId, 'action_type' => null, 'page' => null])); ?>"
                                                       class="text-muted ms-1" title="Full history of this record"><i class="fas fa-history small"></i></a>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-muted">&mdash;</span>
                                            <?php endif; ?>
                                        </td>
                                    <?php endif; ?>
                                    <td class="text-wrap" style="min-width: 220px;"><?= htmlspecialchars($log['action']); ?></td>
                                    <td class="text-wrap" style="min-width: 200px;">
                                        <?php if ($detailsArray): ?>
                                            <ul class="list-unstyled small mb-0 text-secondary">
                                                <?php foreach ($detailsArray as $detailKey => $detailValue): ?>
                                                    <li class="mb-1">
                                                        <span class="text-muted"><?= htmlspecialchars(humanizeLogKey((string)$detailKey)); ?>:</span>
                                                        <?php if (is_array($detailValue) && array_key_exists('from', $detailValue) && array_key_exists('to', $detailValue)): ?>
                                                            <span class="text-danger fw-semibold"><?= htmlspecialchars(formatLogValue($detailValue['from'])); ?></span>
                                                            <span class="mx-1 text-muted">&rarr;</span>
                                                            <span class="text-success fw-semibold"><?= htmlspecialchars(formatLogValue($detailValue['to'])); ?></span>
                                                        <?php else: ?>
                                                            <span class="text-dark fw-semibold"><?= htmlspecialchars(formatLogValue($detailValue)); ?></span>
                                                        <?php endif; ?>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php elseif ($decodedDetails === null && !empty($log['details'])): ?>
                                            <span class="small text-secondary"><?= htmlspecialchars($log['details']); ?></span>
                                        <?php elseif (is_scalar($decodedDetails)): ?>
                                            <span class="small text-secondary"><?= htmlspecialchars((string)$decodedDetails); ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">&mdash;</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-nowrap">
                                        <?php if (!empty($log['ip_address'])): ?>
                                            <?= htmlspecialchars($log['ip_address']); ?>
                                        <?php else: ?>
                                            <span class="text-muted">&mdash;</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="<?= $hasStructuredColumns ? 7 : 5; ?>" class="text-center text-muted py-4">No activity matches these filters.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php if ($totalPages > 1): ?>
            <div class="card-footer d-flex justify-content-center">
                <nav aria-label="Activity log pages">
                    <ul class="pagination pagination-sm mb-0">
                        <li class="page-item <?= $page <= 1 ? 'disabled' : ''; ?>">
                            <a class="page-link" href="<?= htmlspecialchars(activityLogUrl(['page' => $page - 1])); ?>">&laquo;</a>
                        </li>
                        <?php
                            $windowStart = max(1, $page - 3);
                            $windowEnd = min($totalPages, $page + 3);
                        ?>
                        <?php if ($windowStart > 1): ?>
                            <li class="page-item"><a class="page-link" href="<?= htmlspecialchars(activityLogUrl(['page' => 1])); ?>">1</a></li>
                            <?php if ($windowStart > 2): ?><li class="page-item disabled"><span class="page-link">&hellip;</span></li><?php endif; ?>
                        <?php endif; ?>
                        <?php for ($p = $windowStart; $p <= $windowEnd; $p++): ?>
                            <li class="page-item <?= $p === $page ? 'active' : ''; ?>">
                                <a class="page-link" href="<?= htmlspecialchars(activityLogUrl(['page' => $p])); ?>"><?= $p; ?></a>
                            </li>
                        <?php endfor; ?>
                        <?php if ($windowEnd < $totalPages): ?>
                            <?php if ($windowEnd < $totalPages - 1): ?><li class="page-item disabled"><span class="page-link">&hellip;</span></li><?php endif; ?>
                            <li class="page-item"><a class="page-link" href="<?= htmlspecialchars(activityLogUrl(['page' => $totalPages])); ?>"><?= $totalPages; ?></a></li>
                        <?php endif; ?>
                        <li class="page-item <?= $page >= $totalPages ? 'disabled' : ''; ?>">
                            <a class="page-link" href="<?= htmlspecialchars(activityLogUrl(['page' => $page + 1])); ?>">&raquo;</a>
                        </li>
                    </ul>
                </nav>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>

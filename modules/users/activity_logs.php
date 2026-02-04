<?php
require_once '../../includes/auth.php';
require_once '../../config/database.php';

if (!hasPermission('users.manage')) {
    setAlert('danger', 'You do not have permission to view activity logs.');
    redirect('../../dashboard.php');
}

$page_title = 'Activity Logs';

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

$usersStmt = $pdo->query("SELECT id, name FROM users ORDER BY name");
$usersList = $usersStmt->fetchAll(PDO::FETCH_ASSOC);

$filters = [
    'user_id' => isset($_GET['user_id']) && is_numeric($_GET['user_id']) ? (int)$_GET['user_id'] : null,
    'keyword' => trim($_GET['keyword'] ?? ''),
    'date_from' => trim($_GET['date_from'] ?? ''),
    'date_to' => trim($_GET['date_to'] ?? '')
];

$conditions = [];
$params = [];

if ($filters['user_id']) {
    $conditions[] = 'al.user_id = ?';
    $params[] = $filters['user_id'];
}

if ($filters['keyword'] !== '') {
    $conditions[] = '(al.action LIKE ? OR al.details LIKE ?)';
    $like = '%' . $filters['keyword'] . '%';
    $params[] = $like;
    $params[] = $like;
}

if ($filters['date_from'] !== '') {
    $conditions[] = 'al.created_at >= ?';
    $params[] = $filters['date_from'] . ' 00:00:00';
}

if ($filters['date_to'] !== '') {
    $conditions[] = 'al.created_at <= ?';
    $params[] = $filters['date_to'] . ' 23:59:59';
}

$sql = "SELECT al.*, u.name AS user_name
        FROM activity_logs al
        LEFT JOIN users u ON al.user_id = u.id";

if ($conditions) {
    $sql .= ' WHERE ' . implode(' AND ', $conditions);
}

$sql .= ' ORDER BY al.created_at DESC LIMIT 200';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

require_once '../../includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h4 mb-0">Activity Logs</h1>
    </div>

    <?php include '../../includes/messages.php'; ?>

    <div class="card mb-4">
        <div class="card-body">
            <form class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">User</label>
                    <select name="user_id" class="form-select">
                        <option value="">All users</option>
                        <?php foreach ($usersList as $user): ?>
                            <option value="<?= $user['id']; ?>" <?= $filters['user_id'] === (int)$user['id'] ? 'selected' : ''; ?>>
                                <?= htmlspecialchars($user['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Keyword</label>
                    <input type="text" name="keyword" class="form-control" value="<?= htmlspecialchars($filters['keyword']); ?>" placeholder="Search action or details">
                </div>
                <div class="col-md-2">
                    <label class="form-label">From</label>
                    <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($filters['date_from']); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">To</label>
                    <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($filters['date_to']); ?>">
                </div>
                <div class="col-md-2 d-flex align-items-end gap-2">
                    <button type="submit" class="btn btn-primary w-100">Filter</button>
                    <a href="activity_logs.php" class="btn btn-light">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h5 class="mb-0">Recent Activity</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table js-datatable table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th scope="col">Timestamp</th>
                            <th scope="col">User</th>
                            <th scope="col">Action</th>
                            <th scope="col">Details</th>
                            <th scope="col">IP Address</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($logs): ?>
                            <?php foreach ($logs as $log): ?>
                                <?php
                                    $decodedDetails = null;
                                    if (!empty($log['details'])) {
                                        $decodedDetails = json_decode($log['details'], true);
                                    }
                                ?>
                                <tr>
                                    <td><?= date('Y-m-d H:i', strtotime($log['created_at'])); ?></td>
                                    <td><?= $log['user_name'] ? htmlspecialchars($log['user_name']) : 'System'; ?></td>
                                    <td><?= htmlspecialchars($log['action']); ?></td>
                                    <td class="text-wrap" style="min-width: 220px;">
                                        <?php if (is_array($decodedDetails)): ?>
                                            <ul class="list-unstyled small mb-0 text-secondary">
                                                <?php foreach ($decodedDetails as $detailKey => $detailValue): ?>
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
                                        <?php elseif (!empty($log['details'])): ?>
                                            <span class="small text-secondary"><?= htmlspecialchars($log['details']); ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">&mdash;</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
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
                                <td colspan="5" class="text-center text-muted py-4">No activity has been recorded yet.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>

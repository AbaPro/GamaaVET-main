<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

if (!hasPermission('notifications.view')) {
    setAlert('danger', 'Access denied.');
    redirect('../../dashboard.php');
}

global $conn;
if (!isset($_SESSION['role_id'])) {
    loadUserAccessToSession($_SESSION['user_id']);
}
$roleId = $_SESSION['role_id'];
$userId = $_SESSION['user_id'];

// Only filter parameters survive action redirects; never replay an action.
$filters = [];
foreach (['read', 'type', 'severity', 'module', 'from', 'to', 'q'] as $key) {
    $filters[$key] = is_string($_GET[$key] ?? null) ? trim($_GET[$key]) : '';
}
foreach (['from', 'to'] as $key) {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $filters[$key]);
    if (!$date || $date->format('Y-m-d') !== $filters[$key]) $filters[$key] = '';
}
if (!in_array($filters['read'], ['', 'read', 'unread'], true)) $filters['read'] = '';
$filterQuery = http_build_query(array_filter($filters, fn($value) => $value !== ''));
$returnUrl = 'index.php' . ($filterQuery ? '?' . $filterQuery : '');

if (isset($_GET['mark']) && is_numeric($_GET['mark'])) {
    $nid = (int)$_GET['mark'];
    $visibleStmt = $conn->prepare('SELECT * FROM notifications WHERE id = ? LIMIT 1');
    $visibleStmt->bind_param('i', $nid);
    $visibleStmt->execute();
    $visibleNotification = $visibleStmt->get_result()->fetch_assoc();
    $visibleStmt->close();
    if (!$visibleNotification || !isNotificationVisibleInCurrentChannel($visibleNotification)) {
        setAlert('danger', 'Notification not found.');
        redirect($returnUrl);
    }
    $roleSlug = $_SESSION['role_slug'] ?? null;
    
    if ($roleSlug === 'admin') {
        $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE id = ?");
        $stmt->bind_param('i', $nid);
    } else {
        $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND (created_for_role_id = ? OR created_for_user_id = ?)");
        $stmt->bind_param('iii', $nid, $roleId, $userId);
    }
    
    $stmt->execute();
    $stmt->close();
    redirect($returnUrl);
}
if (isset($_GET['ticket']) && is_numeric($_GET['ticket']) && hasPermission('tickets.create')) {
    $nid = (int)$_GET['ticket'];
    // Load notification
    $nStmt = $conn->prepare("SELECT * FROM notifications WHERE id = ?");
    $nStmt->bind_param('i', $nid);
    $nStmt->execute();
    $n = $nStmt->get_result()->fetch_assoc();
    $nStmt->close();
    if ($n && isNotificationVisibleInCurrentChannel($n)) {
        // Assign to purchasing supervisor if exists; else to admin
        $assignRoleId = $conn->query("SELECT id FROM roles WHERE slug='purchasing_supervisor'")->fetch_assoc()['id'] ?? null;
        if (!$assignRoleId) {
            $assignRoleId = $conn->query("SELECT id FROM roles WHERE slug='admin'")->fetch_assoc()['id'] ?? null;
        }
        createTicket($nid, $n['title'], $n['message'], 'high', $assignRoleId, null);
        // Mark the source notification as read automatically
        $ms = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE id = ?");
        $ms->bind_param('i', $nid);
        $ms->execute();
        $ms->close();
        setAlert('success', 'Ticket created.');
        redirect($returnUrl);
    }
}

$roleSlug = $_SESSION['role_slug'] ?? null;
if ($roleSlug === 'admin') {
    $result = $conn->prepare("SELECT * FROM notifications ORDER BY created_at DESC");
} else {
    $result = $conn->prepare("SELECT * FROM notifications WHERE (created_for_role_id = ? OR created_for_user_id = ?) ORDER BY created_at DESC");
    $result->bind_param('ii', $roleId, $userId);
}
$result->execute();
$notifications = $result->get_result()->fetch_all(MYSQLI_ASSOC);
$result->close();
if (($_SESSION['login_region'] ?? 'factory') !== 'factory' || userHasRestrictedFinanceAccess()) {
    $notifications = array_values(array_filter($notifications, 'isNotificationVisibleInCurrentChannel'));
}

// Build choices only from notifications this user is authorized to see.
$filterOptions = ['type' => [], 'severity' => [], 'module' => []];
foreach ($notifications as $notification) {
    foreach ($filterOptions as $key => &$options) {
        $value = (string)($notification[$key] ?? '');
        if ($value !== '') $options[$value] = $value;
    }
    unset($options);
}
foreach ($filterOptions as &$options) natcasesort($options);
unset($options);
$totalVisible = count($notifications);
$notifications = array_values(array_filter($notifications, function ($n) use ($filters) {
    if ($filters['read'] === 'read' && !(int)$n['is_read']) return false;
    if ($filters['read'] === 'unread' && (int)$n['is_read']) return false;
    foreach (['type', 'severity', 'module'] as $key) {
        if ($filters[$key] !== '' && ($n[$key] ?? '') !== $filters[$key]) return false;
    }
    $day = substr($n['created_at'], 0, 10);
    if ($filters['from'] !== '' && $day < $filters['from']) return false;
    if ($filters['to'] !== '' && $day > $filters['to']) return false;
    if ($filters['q'] !== '' && mb_stripos(($n['title'] ?? '') . ' ' . ($n['message'] ?? ''), $filters['q']) === false) return false;
    return true;
}));

$page_title = 'Notifications';
require_once '../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <h2>Notifications</h2>
  <a href="../../dashboard.php" class="btn btn-secondary">Back</a>
 </div>

<form method="get" class="card card-body mb-3">
  <div class="row g-3 align-items-end">
    <div class="col-md-3">
      <label class="form-label" for="notification-search">Search title or message</label>
      <input class="form-control" id="notification-search" name="q" value="<?= htmlspecialchars($filters['q']); ?>" placeholder="Search notifications">
    </div>
    <div class="col-md-3">
      <label class="form-label" for="notification-read">Read status</label>
      <select class="form-select" id="notification-read" name="read">
        <?php foreach (['' => 'All notifications', 'unread' => 'Unread', 'read' => 'Read'] as $value => $label): ?>
          <option value="<?= $value; ?>" <?= $filters['read'] === $value ? 'selected' : ''; ?>><?= $label; ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php foreach ($filterOptions as $key => $options): ?>
      <div class="col-md-2">
        <label class="form-label" for="notification-<?= $key; ?>"><?= ucfirst($key); ?></label>
        <select class="form-select" id="notification-<?= $key; ?>" name="<?= $key; ?>">
          <option value="">All</option>
          <?php foreach ($options as $value): ?>
            <option value="<?= htmlspecialchars($value); ?>" <?= $filters[$key] === $value ? 'selected' : ''; ?>><?= htmlspecialchars(ucwords(str_replace('_', ' ', $value))); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    <?php endforeach; ?>
    <div class="col-md-3">
      <label class="form-label" for="notification-from">From date</label>
      <input class="form-control" type="date" id="notification-from" name="from" value="<?= htmlspecialchars($filters['from']); ?>">
    </div>
    <div class="col-md-3">
      <label class="form-label" for="notification-to">To date</label>
      <input class="form-control" type="date" id="notification-to" name="to" value="<?= htmlspecialchars($filters['to']); ?>">
    </div>
    <div class="col-md-6 d-flex gap-2">
      <button class="btn btn-primary" type="submit">Apply filters</button>
      <a class="btn btn-outline-secondary" href="index.php">Reset</a>
    </div>
  </div>
  <div class="text-muted small mt-3"><?= count($notifications); ?> of <?= $totalVisible; ?> notifications</div>
</form>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover mb-0 js-datatable">
      <thead class="table-light">
        <tr>
          <th>When</th>
          <th>Type</th>
          <th>Title</th>
          <th>Message</th>
          <th>Severity</th>
          <th>Current Status</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($notifications)): ?>
          <tr><td colspan="7" class="text-center text-muted py-4">No notifications match your filters.</td></tr>
        <?php else: ?>
          <?php
            // Prepare checker for existing open ticket for product notifications
            $productTicketChk = $conn->prepare("SELECT COUNT(*) AS c FROM tickets t JOIN notifications n2 ON n2.id = t.notification_id WHERE n2.entity_type='product' AND n2.entity_id = ? AND t.status IN ('open','in_progress')");
            // Live re-check for stale low_stock alerts: current total stock vs. min level, as of page render.
            $stockChk = $conn->prepare("SELECT p.min_stock_level, COALESCE(SUM(ip.quantity),0) AS qty FROM products p LEFT JOIN inventory_products ip ON ip.product_id = p.id WHERE p.id = ? GROUP BY p.id, p.min_stock_level");
          ?>
          <?php foreach ($notifications as $n): ?>
            <tr class="<?= (int)$n['is_read']===1 ? '' : 'table-warning' ?>">
              <td><?= formatDateTime($n['created_at']) ?></td>
              <td><span class="badge bg-info text-dark"><?= htmlspecialchars($n['type']) ?></span></td>
              <td><?= htmlspecialchars($n['title']) ?></td>
              <td class="small text-muted" style="max-width:420px;">
                <?= htmlspecialchars($n['message'] ?? '') ?>
              </td>
              <td>
                <?php $sev = $n['severity'] ?? 'warning'; ?>
                <span class="badge bg-<?= $sev === 'danger' ? 'danger' : ($sev==='info'?'info':'warning') ?>"><?= ucfirst($sev) ?></span>
              </td>
              <td>
                <?php if ($n['type'] === 'low_stock' && !empty($n['entity_id'])): ?>
                  <?php
                    $eid = (int)$n['entity_id'];
                    $stockChk->bind_param('i', $eid);
                    $stockChk->execute();
                    $sr = $stockChk->get_result()->fetch_assoc();
                    $stockChk->free_result();
                  ?>
                  <?php if ($sr): ?>
                    <?php $stillLow = (float)$sr['qty'] <= (float)$sr['min_stock_level']; ?>
                    <?php if ($stillLow): ?>
                      <span class="badge bg-danger">Still low: <?= (int)$sr['qty'] ?> / min <?= (int)$sr['min_stock_level'] ?></span>
                    <?php else: ?>
                      <span class="badge bg-success">Resolved: now <?= (int)$sr['qty'] ?> / min <?= (int)$sr['min_stock_level'] ?></span>
                    <?php endif; ?>
                  <?php else: ?>
                    <span class="badge bg-secondary">Product not found</span>
                  <?php endif; ?>
                <?php else: ?>
                  <span class="text-muted">&mdash;</span>
                <?php endif; ?>
              </td>
              <td class="d-flex gap-2">
                <?php if ((int)$n['is_read'] === 0): ?>
                  <a class="btn btn-sm btn-outline-secondary" href="<?= htmlspecialchars($returnUrl . ($filterQuery ? '&' : '?') . 'mark=' . (int)$n['id']); ?>">Mark read</a>
                <?php endif; ?>
                <?php
                  $viewLink = null;
                  if ($n['entity_type'] === 'order' && !empty($n['entity_id'])) {
                      $viewLink = "../sales/order_details.php?id=" . (int)$n['entity_id'];
                  } elseif ($n['entity_type'] === 'product' && !empty($n['entity_id'])) {
                      $viewLink = "../products/view.php?id=" . (int)$n['entity_id'];
                  }
                ?>
                <?php if ($viewLink): ?>
                  <a class="btn btn-sm btn-outline-info" href="<?= $viewLink ?>">View</a>
                <?php endif; ?>
                <?php
                  $showCreate = false;
                  if (hasPermission('tickets.create')) {
                    if (($n['entity_type'] ?? null) === 'product' && !empty($n['entity_id'])) {
                      $eid = (int)$n['entity_id'];
                      $productTicketChk->bind_param('i', $eid);
                      $productTicketChk->execute();
                      $rc = $productTicketChk->get_result()->fetch_assoc();
                      $hasOpen = (int)($rc['c'] ?? 0) > 0;
                      $showCreate = !$hasOpen;
                    } else {
                      // For other notification types, allow create
                      $showCreate = true;
                    }
                  }
                ?>
                <?php if ($showCreate): ?>
                  <a class="btn btn-sm btn-outline-primary" href="<?= htmlspecialchars($returnUrl . ($filterQuery ? '&' : '?') . 'ticket=' . (int)$n['id']); ?>">Create Ticket</a>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php $productTicketChk->close(); ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once '../../includes/footer.php'; ?>

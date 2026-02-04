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

// Handle actions
if (isset($_GET['mark']) && is_numeric($_GET['mark'])) {
    $nid = (int)$_GET['mark'];
    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND (created_for_role_id = ? OR created_for_user_id = ?)");
    $stmt->bind_param('iii', $nid, $roleId, $userId);
    $stmt->execute();
    $stmt->close();
    redirect('index.php');
}
if (isset($_GET['ticket']) && is_numeric($_GET['ticket']) && hasPermission('tickets.create')) {
    $nid = (int)$_GET['ticket'];
    // Load notification
    $n = $conn->query("SELECT * FROM notifications WHERE id=".$nid)->fetch_assoc();
    if ($n) {
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
        redirect('index.php');
    }
}

$result = $conn->prepare("SELECT * FROM notifications WHERE (created_for_role_id = ? OR created_for_user_id = ?) ORDER BY created_at DESC LIMIT 200");
$result->bind_param('ii', $roleId, $userId);
$result->execute();
$notifications = $result->get_result()->fetch_all(MYSQLI_ASSOC);
$result->close();

$page_title = 'Notifications';
require_once '../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <h2>Notifications</h2>
  <a href="../../dashboard.php" class="btn btn-secondary">Back</a>
 </div>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead class="table-light">
        <tr>
          <th>When</th>
          <th>Type</th>
          <th>Title</th>
          <th>Message</th>
          <th>Severity</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($notifications)): ?>
          <tr><td colspan="6" class="text-center text-muted py-4">No notifications</td></tr>
        <?php else: ?>
          <?php
            // Prepare checker for existing open ticket for product notifications
            $productTicketChk = $conn->prepare("SELECT COUNT(*) AS c FROM tickets t JOIN notifications n2 ON n2.id = t.notification_id WHERE n2.entity_type='product' AND n2.entity_id = ? AND t.status IN ('open','in_progress')");
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
              <td class="d-flex gap-2">
                <?php if ((int)$n['is_read'] === 0): ?>
                  <a class="btn btn-sm btn-outline-secondary" href="?mark=<?= (int)$n['id'] ?>">Mark read</a>
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
                  <a class="btn btn-sm btn-outline-primary" href="?ticket=<?= (int)$n['id'] ?>">Create Ticket</a>
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

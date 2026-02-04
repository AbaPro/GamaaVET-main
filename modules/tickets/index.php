<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

if (!hasPermission('tickets.manage') && !hasPermission('tickets.create') && !hasPermission('tickets.view')) {
    setAlert('danger', 'Access denied.');
    redirect('../../dashboard.php');
}

global $conn;
if (!isset($_SESSION['role_id'])) loadUserAccessToSession($_SESSION['user_id']);
$roleId = $_SESSION['role_id'] ?? null;
$userId = $_SESSION['user_id'];

// Scope: if manage -> all, else only assigned to role/user or created_by
if (hasPermission('tickets.manage')) {
    $sql = "SELECT t.*, r.name AS assigned_role
            FROM tickets t
            LEFT JOIN roles r ON r.id = t.assigned_to_role_id
            ORDER BY FIELD(status,'open','in_progress','resolved','closed'), priority DESC, created_at DESC";
    $res = $conn->query($sql);
} else {
    $stmt = $conn->prepare("SELECT t.*, r.name AS assigned_role
                            FROM tickets t
                            LEFT JOIN roles r ON r.id = t.assigned_to_role_id
                            WHERE (t.assigned_to_role_id = ? OR t.assigned_to_user_id = ? OR t.created_by = ?)
                            ORDER BY FIELD(status,'open','in_progress','resolved','closed'), priority DESC, created_at DESC");
    $stmt->bind_param('iii', $roleId, $userId, $userId);
    $stmt->execute();
    $res = $stmt->get_result();
}
$tickets = $res->fetch_all(MYSQLI_ASSOC);

$page_title = 'Tickets';
require_once '../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <h2>Tickets</h2>
  <?php if (hasPermission('tickets.create')): ?>
    <a href="create.php" class="btn btn-primary"><i class="fas fa-plus"></i> New Ticket</a>
  <?php endif; ?>
 </div>

<div class="card">
  <div class="table-responsive">
    <table class="table js-datatable table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th>#</th>
          <th>Title</th>
          <th>Status</th>
          <th>Priority</th>
          <th>Assigned</th>
          <th>Created</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($tickets)): ?>
          <tr><td colspan="7" class="text-center text-muted py-4">No tickets</td></tr>
        <?php else: ?>
          <?php foreach ($tickets as $t): ?>
            <tr>
              <td><?= (int)$t['id'] ?></td>
              <td><?= htmlspecialchars($t['title']) ?></td>
              <td><span class="badge bg-secondary text-capitalize"><?= htmlspecialchars($t['status']) ?></span></td>
              <td><span class="badge bg-<?= $t['priority']==='urgent'?'danger':($t['priority']==='high'?'warning':'info') ?> text-capitalize"><?= htmlspecialchars($t['priority']) ?></span></td>
              <td><?= htmlspecialchars($t['assigned_role'] ?? '—') ?></td>
              <td><?= formatDateTime($t['created_at']) ?></td>
              <td><a href="view.php?id=<?= (int)$t['id'] ?>" class="btn btn-sm btn-outline-primary">Open</a></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
 </div>

<?php require_once '../../includes/footer.php'; ?>


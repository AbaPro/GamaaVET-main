<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../config/database.php';

if (!hasPermission('users.manage')) {
    setAlert('danger', 'Access denied.');
    redirect('../../dashboard.php');
}

$sql = "SELECT r.*, 
               (SELECT COUNT(*) FROM users u WHERE u.role_id = r.id) AS users_count,
               (SELECT COUNT(*) FROM role_permissions rp WHERE rp.role_id = r.id) AS perms_count
        FROM roles r ORDER BY r.name";
$roles = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);

$page_title = 'Roles Management';
require_once '../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Roles</h2>
    <a href="create.php" class="btn btn-primary"><i class="fas fa-plus"></i> New Role</a>
  </div>

<div class="card">
  <div class="card-body table-responsive">
    <table class="table js-datatable table-hover">
      <thead>
        <tr>
          <th>Name</th>
          <th>Slug</th>
          <th>Status</th>
          <th>Users</th>
          <th>Permissions</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($roles as $r): ?>
          <tr>
            <td><?= htmlspecialchars($r['name']) ?></td>
            <td><code><?= htmlspecialchars($r['slug']) ?></code></td>
            <td>
              <span class="badge bg-<?= ((int)$r['is_active']===1?'success':'secondary') ?>">
                <?= ((int)$r['is_active']===1?'Active':'Disabled') ?>
              </span>
            </td>
            <td><?= (int)$r['users_count'] ?></td>
            <td><?= (int)$r['perms_count'] ?></td>
            <td class="d-flex gap-2">
              <a class="btn btn-sm btn-outline-primary" href="edit.php?id=<?= (int)$r['id'] ?>"><i class="fas fa-edit"></i> Edit</a>
              <a class="btn btn-sm btn-outline-danger" href="delete.php?id=<?= (int)$r['id'] ?>" onclick="return confirm('Delete role?');"><i class="fas fa-trash"></i> Delete</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once '../../includes/footer.php'; ?>


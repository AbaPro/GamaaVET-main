<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../config/database.php';
require_once __DIR__ . '/permission_usage.php';

if (!hasPermission('users.manage')) {
    setAlert('danger', 'Access denied.');
    redirect('../../dashboard.php');
}

$role_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($role_id <= 0) { redirect('index.php'); }

// Save changes
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selected = isset($_POST['permissions']) && is_array($_POST['permissions']) ? array_map('intval', $_POST['permissions']) : [];

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare('DELETE FROM role_permissions WHERE role_id=?');
        $stmt->bind_param('i', $role_id);
        $stmt->execute();
        $stmt->close();

        if (!empty($selected)) {
            $ins = $conn->prepare('INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)');
            foreach ($selected as $pid) {
                $ins->bind_param('ii', $role_id, $pid);
                $ins->execute();
            }
            $ins->close();
        }

        $conn->commit();
        setAlert('success', 'Permissions updated.');
    } catch (Throwable $e) {
        $conn->rollback();
        setAlert('danger', 'Failed to update permissions: ' . $e->getMessage());
    }
}

$role = $conn->query('SELECT * FROM roles WHERE id=' . $role_id)->fetch_assoc();
if (!$role) { redirect('index.php'); }

$perms = $conn->query('SELECT * FROM permissions ORDER BY module, name')->fetch_all(MYSQLI_ASSOC);
$assigned = $conn->query('SELECT permission_id FROM role_permissions WHERE role_id=' . $role_id)->fetch_all(MYSQLI_ASSOC);
$assignedIds = array_map(function($r){ return (int)$r['permission_id']; }, $assigned);

$page_title = 'Role Permissions';
require_once '../../includes/header.php';
renderPermissionUsageStyles();
?>

<div class="container mt-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h2>Permissions: <?= htmlspecialchars($role['name']) ?> (<?= htmlspecialchars($role['slug']) ?>)</h2>
    <a href="index.php" class="btn btn-secondary">Back</a>
  </div>

  <form method="post">
    <div class="card">
      <div class="card-body">
        <div class="permission-toolbar mb-3">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="selectAllPermissions">
            <label class="form-check-label" for="selectAllPermissions">Select All Permissions</label>
          </div>
          <div class="input-group input-group-sm permission-filter">
            <span class="input-group-text"><i class="fas fa-search"></i></span>
            <input type="search" class="form-control" id="permissionFilter" placeholder="Filter permissions">
          </div>
        </div>
        <div class="row permission-grid">
          <?php
            $byModule = [];
            foreach ($perms as $p) { $byModule[$p['module'] ?? 'general'][] = $p; }
            ksort($byModule);
          ?>
<?php foreach ($byModule as $module => $permList): ?>
            <div class="col-xl-4 col-lg-6">
              <div class="permission-module-card">
                <div class="permission-module-header">
                  <div>
                    <h5 class="text-capitalize permission-module-title"><?= htmlspecialchars($module) ?></h5>
                    <div class="permission-module-count"><?= (int)count($permList) ?> permission<?= count($permList) === 1 ? '' : 's' ?></div>
                  </div>
                  <div class="form-check form-check-inline m-0">
                    <input class="form-check-input js-select-all-module" type="checkbox" id="selmod_<?= htmlspecialchars($module) ?>" data-module="<?= htmlspecialchars($module) ?>">
                    <label class="form-check-label small" for="selmod_<?= htmlspecialchars($module) ?>">All</label>
                  </div>
                </div>
                <?php foreach ($permList as $p): ?>
                  <div class="form-check permission-option">
                    <input class="form-check-input js-perm" type="checkbox" data-module="<?= htmlspecialchars($module) ?>" id="perm_<?= (int)$p['id'] ?>" name="permissions[]" value="<?= (int)$p['id'] ?>" <?= in_array((int)$p['id'], $assignedIds, true) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="perm_<?= (int)$p['id'] ?>">
                      <span class="permission-title-line">
                        <code><?= htmlspecialchars($p['key']) ?></code>
                        <span class="permission-name"><?= htmlspecialchars($p['name']) ?></span>
                      </span>
                    </label>
                    <?php renderPermissionUsageDetails($p); ?>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="mt-3">
          <button class="btn btn-primary" type="submit"><i class="fas fa-save"></i> Save</button>
        </div>
      </div>
    </div>
  </form>
  <script>
    (function(){
      const allToggle = document.getElementById('selectAllPermissions');
      const filterInput = document.getElementById('permissionFilter');
      const applyPermissionFilter = () => {
        const term = filterInput ? filterInput.value.trim().toLowerCase() : '';
        document.querySelectorAll('.permission-module-card').forEach(card => {
          let visibleRows = 0;
          card.querySelectorAll('.permission-option').forEach(row => {
            const isMatch = term === '' || row.textContent.toLowerCase().includes(term);
            row.style.display = isMatch ? '' : 'none';
            if (isMatch) visibleRows++;
          });
          const column = card.closest('[class*="col-"]');
          if (column) column.style.display = visibleRows > 0 ? '' : 'none';
        });
      };
      const updateGlobal = () => {
        const allPerms = document.querySelectorAll('.js-perm');
        if (allToggle) allToggle.checked = allPerms.length > 0 && Array.from(allPerms).every(cb => cb.checked);
      };
      const updateModule = (mod) => {
        const mToggle = document.querySelector('.js-select-all-module[data-module="'+mod+'"]');
        const groupPerms = document.querySelectorAll('.js-perm[data-module="'+mod+'"]');
        if (mToggle) mToggle.checked = groupPerms.length > 0 && Array.from(groupPerms).every(cb => cb.checked);
      };
      if (allToggle){
        allToggle.addEventListener('change', function(){
          document.querySelectorAll('.js-perm').forEach(cb => cb.checked = this.checked);
          document.querySelectorAll('.js-select-all-module').forEach(cb => cb.checked = this.checked);
        });
      }
      document.querySelectorAll('.js-select-all-module').forEach(modToggle => {
        modToggle.addEventListener('change', function(){
          const mod = this.dataset.module;
          document.querySelectorAll('.js-perm[data-module="'+mod+'"]').forEach(cb => cb.checked = this.checked);
          updateGlobal();
        });
      });
      document.querySelectorAll('.js-perm').forEach(cb => {
        cb.addEventListener('change', function(){
          const mod = this.dataset.module;
          updateModule(mod);
          updateGlobal();
        });
      });
      if (filterInput) filterInput.addEventListener('input', applyPermissionFilter);
      // initialize
      const mods = new Set();
      document.querySelectorAll('.js-perm').forEach(cb => mods.add(cb.dataset.module));
      mods.forEach(updateModule);
      updateGlobal();
      applyPermissionFilter();
    })();
  </script>
</div>

<?php require_once '../../includes/footer.php'; ?>

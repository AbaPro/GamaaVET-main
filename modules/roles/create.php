<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../config/database.php';

if (!hasPermission('users.manage')) {
    setAlert('danger', 'Access denied.');
    redirect('../../dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $slug = trim($_POST['slug'] ?? '');
    // normalize slug
    $slug = strtolower(preg_replace('/[^a-z0-9_-]+/i', '-', $slug));
    $description = trim($_POST['description'] ?? '');
    $is_active = !empty($_POST['is_active']) ? 1 : 0;

    if ($name === '' || $slug === '') {
        setAlert('danger', 'Name and slug are required.');
        redirect('create.php');
    }

    // uniqueness check for slug
    $chk = $conn->prepare("SELECT id FROM roles WHERE slug = ? LIMIT 1");
    $chk->bind_param('s', $slug);
    $chk->execute();
    $chk->store_result();
    if ($chk->num_rows > 0) {
        $chk->close();
        setAlert('danger', 'Slug already exists. Please choose a unique slug.');
        redirect('create.php');
    }
    $chk->close();

    try {
        $stmt = $conn->prepare("INSERT INTO roles (name, slug, description, is_active) VALUES (?, ?, ?, ?)");
        $stmt->bind_param('sssi', $name, $slug, $description, $is_active);
        $stmt->execute();
        $newRoleId = $conn->insert_id;

        // Assign selected permissions
        $selected = isset($_POST['permissions']) && is_array($_POST['permissions']) ? array_map('intval', $_POST['permissions']) : [];
        if (!empty($selected)) {
            $ins = $conn->prepare('INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)');
            foreach ($selected as $pid) { $ins->bind_param('ii', $newRoleId, $pid); $ins->execute(); }
            $ins->close();
        }
        setAlert('success', 'Role created.');
        redirect('index.php');
    } catch (Throwable $e) {
        setAlert('danger', 'Failed to create role: ' . $e->getMessage());
        redirect('create.php');
    }
}

$page_title = 'Create Role';
// Load permissions for selection
$perms = $conn->query('SELECT * FROM permissions ORDER BY module, name')->fetch_all(MYSQLI_ASSOC);
require_once '../../includes/header.php';
?>

<div class="container mt-4">
  <h2>Create Role</h2>
  <form method="post" class="mt-3">
    <div class="row">
      <div class="col-md-6">
        <label class="form-label">Name *</label>
        <input type="text" name="name" class="form-control" required>
      </div>
      <div class="col-md-6">
        <label class="form-label">Slug *</label>
        <input type="text" name="slug" class="form-control" required>
      </div>
    </div>
    <div class="mt-3">
      <label class="form-label">Description</label>
      <textarea name="description" class="form-control" rows="3"></textarea>
    </div>
    <div class="form-check form-switch mt-3">
      <input class="form-check-input" type="checkbox" name="is_active" id="is_active" checked>
      <label class="form-check-label" for="is_active">Active</label>
    </div>
    <hr class="my-4">
    <div class="mb-2">
      <div class="form-check">
        <input class="form-check-input" type="checkbox" id="selectAllPermissions">
        <label class="form-check-label" for="selectAllPermissions">Select All Permissions</label>
      </div>
    </div>
    <div class="row">
      <?php
        $byModule = [];
        foreach ($perms as $p) { $byModule[$p['module'] ?? 'general'][] = $p; }
        ksort($byModule);
      ?>
      <?php foreach ($byModule as $module => $permList): ?>
        <div class="col-md-6 mb-3">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <h5 class="text-capitalize m-0"><?= htmlspecialchars($module) ?></h5>
            <div class="form-check form-check-inline">
              <input class="form-check-input js-select-all-module" type="checkbox" id="selmod_<?= htmlspecialchars($module) ?>" data-module="<?= htmlspecialchars($module) ?>">
              <label class="form-check-label" for="selmod_<?= htmlspecialchars($module) ?>">All</label>
            </div>
          </div>
          <?php foreach ($permList as $p): ?>
            <div class="form-check">
              <input class="form-check-input js-perm" type="checkbox" data-module="<?= htmlspecialchars($module) ?>" id="perm_<?= (int)$p['id'] ?>" name="permissions[]" value="<?= (int)$p['id'] ?>">
              <label class="form-check-label" for="perm_<?= (int)$p['id'] ?>">
                <code><?= htmlspecialchars($p['key']) ?></code> — <?= htmlspecialchars($p['name']) ?>
              </label>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="mt-4">
      <button class="btn btn-primary" type="submit">Create</button>
      <a class="btn btn-secondary" href="index.php">Cancel</a>
    </div>
  </form>
</div>

<script>
  (function(){
    const allToggle = document.getElementById('selectAllPermissions');
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
    const mods = new Set();
    document.querySelectorAll('.js-perm').forEach(cb => mods.add(cb.dataset.module));
    mods.forEach(updateModule);
    updateGlobal();
  })();
 </script>

<?php require_once '../../includes/footer.php'; ?>

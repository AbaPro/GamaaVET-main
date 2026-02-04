<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

if (!isLoggedIn()) {
    setAlert('danger', 'Please login to access your profile');
    redirect('../../index.php');
}

$userId = (int)$_SESSION['user_id'];

// Handle profile update (name, email, username)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = sanitize($_POST['name'] ?? '');
    $email = sanitize($_POST['email'] ?? '');
    $username = sanitize($_POST['username'] ?? '');

    if ($name === '' || $email === '' || $username === '') {
        setAlert('danger', 'Name, Email, and Username are required.');
        redirect('profile.php');
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        setAlert('danger', 'Invalid email format.');
        redirect('profile.php');
    }

    // Unique constraints check for username/email excluding current user
    global $conn;
    $check = $conn->prepare("SELECT id FROM users WHERE (username = ? OR email = ?) AND id != ?");
    $check->bind_param('ssi', $username, $email, $userId);
    $check->execute();
    $check->store_result();
    if ($check->num_rows > 0) {
        setAlert('danger', 'Username or email already in use.');
        redirect('profile.php');
    }
    $check->close();

    // Update
    $upd = $conn->prepare("UPDATE users SET name = ?, email = ?, username = ? WHERE id = ?");
    $upd->bind_param('sssi', $name, $email, $username, $userId);
    if ($upd->execute()) {
        $_SESSION['user_name'] = $name;
        $_SESSION['user_email'] = $email;
        setAlert('success', 'Profile updated successfully.');
    } else {
        setAlert('danger', 'Failed to update profile: ' . $conn->error);
    }
    $upd->close();
    redirect('profile.php');
}

// Load current user
$userStmt = $conn->prepare("SELECT u.*, r.name AS role_name, r.slug AS role_slug, r.id AS role_id
                            FROM users u
                            LEFT JOIN roles r ON r.id = u.role_id
                            WHERE u.id = ?");
$userStmt->bind_param('i', $userId);
$userStmt->execute();
$user = $userStmt->get_result()->fetch_assoc();
$userStmt->close();

// Load permissions for current role
$permissions = [];
if (!empty($user['role_id'])) {
    $permStmt = $conn->prepare("SELECT p.* FROM role_permissions rp INNER JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = ? ORDER BY p.module, p.name");
    $permStmt->bind_param('i', $user['role_id']);
    $permStmt->execute();
    $permissions = $permStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $permStmt->close();
}

// Load recent activity logs for current user
$logsStmt = $conn->prepare("SELECT action, details, ip_address, created_at FROM activity_logs WHERE user_id = ? ORDER BY created_at DESC LIMIT 25");
$logsStmt->bind_param('i', $userId);
$logsStmt->execute();
$logs = $logsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$logsStmt->close();

$page_title = 'My Profile';
require_once '../../includes/header.php';
?>

<div class="container py-4">

  <!-- Page Header -->
  <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-2 mb-4">
    <div>
      <h3 class="mb-1">My Profile</h3>
      <div class="text-muted small">
        Manage your account details, role, permissions, and recent activity.
      </div>
    </div>
    <div class="d-flex gap-2">
      <span class="badge text-bg-light border">
        <i class="fa-solid fa-user me-1"></i>
        ID: <?= (int)$userId ?>
      </span>
      <?php if (!empty($user['role_name']) || !empty($user['role'])): ?>
        <span class="badge text-bg-primary">
          <i class="fa-solid fa-shield-halved me-1"></i>
          <?= htmlspecialchars($user['role_name'] ?? ucfirst($user['role'] ?? '')) ?>
        </span>
      <?php endif; ?>
    </div>
  </div>

  <div class="row g-4">
    <!-- Left column -->
    <div class="col-lg-5">
      <!-- Profile Card -->
      <div class="card border-0 shadow-sm">
        <div class="card-header bg-body border-0 py-3">
          <div class="d-flex align-items-center justify-content-between">
            <div class="d-flex align-items-center gap-2">
              <div class="rounded-circle bg-primary bg-opacity-10 text-primary d-inline-flex align-items-center justify-content-center"
                   style="width:40px;height:40px;">
                <i class="fa-solid fa-id-card"></i>
              </div>
              <div>
                <div class="fw-semibold">Profile details</div>
                <div class="text-muted small">Update your public account info</div>
              </div>
            </div>
          </div>
        </div>

        <div class="card-body p-4">
          <form method="post" class="vstack gap-3">
            <div class="form-floating">
              <input type="text" class="form-control" id="name" name="name"
                     value="<?= htmlspecialchars($user['name']) ?>" required>
              <label for="name">Full name</label>
            </div>

            <div class="form-floating">
              <input type="email" class="form-control" id="email" name="email"
                     value="<?= htmlspecialchars($user['email']) ?>" required>
              <label for="email">Email address</label>
            </div>

            <div class="form-floating">
              <input type="text" class="form-control" id="username" name="username"
                     value="<?= htmlspecialchars($user['username']) ?>" required>
              <label for="username">Username</label>
            </div>

            <div class="d-flex align-items-center justify-content-between pt-1">
              <div class="text-muted small">
                <i class="fa-solid fa-circle-info me-1"></i>
                Changes apply immediately.
              </div>
              <button class="btn btn-primary px-4" type="submit">
                <i class="fa-solid fa-floppy-disk me-2"></i>Save changes
              </button>
            </div>
          </form>
        </div>
      </div>

      <!-- Role Card -->
      <div class="card border-0 shadow-sm mt-4">
        <div class="card-body p-4">
          <?php
            $roleSlug = $user['role_slug'] ?? ($user['role'] ?? '');
            $roleName = $user['role_name'] ?? ucfirst($roleSlug);
          ?>
          <div class="d-flex align-items-start gap-3">
            <div class="rounded-circle bg-success bg-opacity-10 text-success d-inline-flex align-items-center justify-content-center"
                 style="width:40px;height:40px;">
              <i class="fa-solid fa-user-shield"></i>
            </div>

            <div class="flex-grow-1">
              <div class="d-flex justify-content-between align-items-center">
                <div>
                  <div class="fw-semibold">Role</div>
                  <div class="text-muted small">Assigned access level</div>
                </div>
                <span class="badge text-bg-success border border-success rounded-full">
                  Active
                </span>
              </div>

              <hr class="my-3">

              <dl class="row mb-0 small">
                <dt class="col-4 text-muted">Name</dt>
                <dd class="col-8 mb-2 fw-medium"><?= htmlspecialchars($roleName ?: 'N/A') ?></dd>

                <dt class="col-4 text-muted">Slug</dt>
                <dd class="col-8 mb-0">
                  <code class="px-2 py-1 bg-body-secondary rounded"><?= htmlspecialchars($roleSlug ?: 'N/A') ?></code>
                </dd>
              </dl>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Right column -->
    <div class="col-lg-7">
      <!-- Permissions -->
      <div class="card border-0 shadow-sm">
        <div class="card-header bg-body border-0 py-3">
          <div class="d-flex align-items-center justify-content-between">
            <div>
              <div class="fw-semibold">My Permissions</div>
              <div class="text-muted small">Permissions granted through your role</div>
            </div>
            <span class="badge text-bg-info">
              <?= count($permissions) ?>
            </span>
          </div>
        </div>

        <div class="card-body p-4">
          <?php if (empty($permissions)): ?>
            <div class="alert alert-light border mb-0">
              <i class="fa-solid fa-lock me-2"></i>
              No permissions assigned to your role.
            </div>
          <?php else: ?>
            <?php
              $byModule = [];
              foreach ($permissions as $p) { $byModule[$p['module'] ?? 'general'][] = $p; }
              ksort($byModule);
              $accId = 'permAcc';
              $i = 0;
            ?>

            <div class="accordion" id="<?= $accId ?>">
              <?php foreach ($byModule as $module => $permList): $i++; $collapseId="permCollapse".$i; ?>
                <div class="accordion-item border rounded mb-2">
                  <h2 class="accordion-header" id="heading<?= $i ?>">
                    <button class="accordion-button collapsed" type="button"
                            data-bs-toggle="collapse" data-bs-target="#<?= $collapseId ?>"
                            aria-expanded="false" aria-controls="<?= $collapseId ?>">
                      <span class="text-capitalize fw-semibold"><?= htmlspecialchars($module) ?></span>
                      <span class="badge text-bg-light border ms-2"><?= count($permList) ?></span>
                    </button>
                  </h2>
                  <div id="<?= $collapseId ?>" class="accordion-collapse collapse"
                       aria-labelledby="heading<?= $i ?>" data-bs-parent="#<?= $accId ?>">
                    <div class="accordion-body">
                      <div class="row g-2">
                        <?php foreach ($permList as $p): ?>
                          <div class="col-12">
                            <div class="d-flex align-items-start justify-content-between gap-3 p-2 rounded border bg-body-tertiary">
                              <div>
                                <div class="fw-semibold small"><?= htmlspecialchars($p['name']) ?></div>
                                <div class="text-muted small"><?= htmlspecialchars($p['module'] ?? 'general') ?></div>
                              </div>
                              <code class="px-2 py-1 bg-body rounded border small">
                                <?= htmlspecialchars($p['key']) ?>
                              </code>
                            </div>
                          </div>
                        <?php endforeach; ?>
                      </div>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Activity -->
      <div class="card border-0 shadow-sm mt-4">
        <div class="card-header bg-body border-0 py-3">
          <div class="d-flex align-items-center justify-content-between">
            <div>
              <div class="fw-semibold">Recent Activity</div>
              <div class="text-muted small">Latest 25 actions on your account</div>
            </div>
            <span class="badge text-bg-secondary">Last 25</span>
          </div>
        </div>

        <div class="card-body p-0">
          <?php if (empty($logs)): ?>
            <div class="p-4">
              <div class="alert alert-light border mb-0">
                <i class="fa-solid fa-clock-rotate-left me-2"></i>
                No recent activity.
              </div>
            </div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                  <tr>
                    <th class="ps-4">When</th>
                    <th>Action</th>
                    <th>Details</th>
                    <th class="text-end pe-4">IP</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($logs as $log): ?>
                    <tr>
                      <td class="ps-4 text-muted small" style="white-space: nowrap;">
                        <?= formatDateTime($log['created_at']) ?>
                      </td>
                      <td class="fw-semibold">
                        <?= htmlspecialchars($log['action']) ?>
                      </td>
                      <td class="small">
                        <?php
                          $d = $log['details'];
                          $pretty = '';
                          if ($d) {
                              $decoded = json_decode($d, true);
                              if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                                  $pretty = htmlspecialchars(json_encode($decoded, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
                              } else {
                                  $pretty = htmlspecialchars($d);
                              }
                          }
                        ?>
                        <?php if ($pretty): ?>
                          <div class="text-truncate" style="max-width: 420px;">
                            <code class="bg-body-secondary border rounded px-2 py-1 d-inline-block">
                              <?= $pretty ?>
                            </code>
                          </div>
                        <?php else: ?>
                          <span class="text-muted">—</span>
                        <?php endif; ?>
                      </td>
                      <td class="text-end pe-4 text-muted small" style="white-space: nowrap;">
                        <span class="font-monospace"><?= htmlspecialchars($log['ip_address'] ?? '') ?></span>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>

    </div>
  </div>
</div>


<?php require_once '../../includes/footer.php'; ?>

<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);


// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Correct require paths (remove leading slashes)
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/users_functions.php';

// Check permission
if (!hasPermission('users.manage')) {
    header("Location: /dashboard.php");
    exit();
}

// Handle delete request
if (isset($_GET['delete']) && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    if (deleteUser($id)) {
        $_SESSION['message'] = ['type' => 'success', 'text' => 'User deleted successfully'];
    } else {
        $_SESSION['message'] = ['type' => 'danger', 'text' => 'Failed to delete user'];
    }
    header("Location: index.php");
    exit();
}

// Get all users
$users = getAllUsers();

// Set page title
$pageTitle = "Users Management";

// Include header
include __DIR__ . '/../../includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="row">
        <div class="col-12">
            <?php displayMessage(); ?>
            <div class="card mb-4">
                <div class="card-header pb-0 d-flex justify-content-between align-items-center">
                    <h6>Users List</h6>
                    <div class="d-flex gap-2">
                        <a href="../roles/index.php" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-shield-halved"></i> Roles
                        </a>
                        <a href="transfer_data.php" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-random"></i> Transfer Data
                        </a>
                        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#createUserModal">
                            <i class="fas fa-plus"></i> Add New User
                        </button>
                    </div>
                </div>
                <div class="card-body px-0 pt-0 pb-2">
                    <div class="table-responsive p-0">
                        <table class="table js-datatable align-items-center mb-0">
                            <thead>
                                <tr>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Name</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Username</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Email</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Role</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Status</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Last Login</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($users)): ?>
                                    <?php foreach ($users as $user): ?>
                                    <tr>
                                        <td>
                                            <div class="d-flex px-2 py-1">
                                                <div class="d-flex flex-column justify-content-center">
                                                    <h6 class="mb-0 text-sm"><?= htmlspecialchars($user['name']) ?></h6>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <p class="text-xs font-weight-bold mb-0"><?= htmlspecialchars($user['username']) ?></p>
                                        </td>
                                        <td>
                                            <p class="text-xs font-weight-bold mb-0"><?= htmlspecialchars($user['email']) ?></p>
                                        </td>
                                        <td>
                                            <?php $roleSlug = $user['role_slug'] ?? $user['role']; ?>
                                            <span class="badge badge-sm bg-<?= getRoleColor($roleSlug) ?>">
                                                <?= ucfirst($roleSlug) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge badge-sm bg-<?= $user['is_active'] ? 'success' : 'danger' ?>">
                                                <?= $user['is_active'] ? 'Active' : 'Inactive' ?>
                                            </span>
                                        </td>
                                        <td>
                                            <p class="text-xs font-weight-bold mb-0">
                                                <?= $user['last_login'] ? formatDateTime($user['last_login']) : 'Never' ?>
                                            </p>
                                        </td>
                                        <td class="align-middle d-flex gap-1 flex-wrap">
                                            <a href="edit.php?id=<?= $user['id'] ?>" class="btn btn-sm btn-warning" data-toggle="tooltip" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <a href="index.php?delete=1&id=<?= $user['id'] ?>" class="btn btn-sm btn-danger" data-toggle="tooltip" title="Delete" onclick="return confirm('Are you sure you want to delete this user?')">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                            <a href="activity_logs.php?user_id=<?= $user['id'] ?>" class="btn btn-sm btn-info" data-toggle="tooltip" title="View Activity">
                                                <i class="fas fa-clipboard-list"></i>
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="7" class="text-center">No users found</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Create User Modal -->
<div class="modal fade" id="createUserModal" tabindex="-1" role="dialog" aria-labelledby="createUserModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="createUserModalLabel">Create New User</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="create.php" method="POST">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="name">Full Name</label>
                                <input type="text" class="form-control" id="name" name="name" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="username">Username</label>
                                <input type="text" class="form-control" id="username" name="username" required>
                            </div>
                        </div>
                    </div>
                    <div class="row mt-3">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="email">Email</label>
                                <input type="email" class="form-control" id="email" name="email" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="role_id">Role</label>
                                <select class="form-control" id="role_id" name="role_id" required>
                                    <option value="">Select Role</option>
                                    <?php foreach (getAllRoles() as $role): ?>
                                        <option value="<?= $role['id'] ?>"><?= htmlspecialchars($role['name']) ?> (<?= htmlspecialchars($role['slug']) ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="row mt-3">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="password">Password</label>
                                <input type="password" class="form-control" id="password" name="password" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="confirm_password">Confirm Password</label>
                                <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                            </div>
                        </div>
                    </div>
                    <div class="row mt-3">
                        <div class="col-md-6">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="is_active" name="is_active" checked>
                                <label class="form-check-label" for="is_active">Active</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary">Create User</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

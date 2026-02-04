<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/users_functions.php';

// Check permission
if (!hasPermission('users.manage')) {
    $_SESSION['message'] = ['type' => 'danger', 'text' => 'You do not have permission to access this page'];
    header("Location: /dashboard.php");
    exit();
}

// Check if ID is provided
if (!isset($_GET['id'])) {
    $_SESSION['message'] = ['type' => 'danger', 'text' => 'User ID not specified'];
    header("Location: index.php");
    exit();
}

$id = intval($_GET['id']);
$user = getUserById($id);

if (!$user) {
    $_SESSION['message'] = ['type' => 'danger', 'text' => 'User not found'];
    header("Location: index.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate input
    $required = ['name', 'username', 'email'];
    foreach ($required as $field) {
        if (empty($_POST[$field])) {
            $_SESSION['message'] = ['type' => 'danger', 'text' => 'All required fields must be filled'];
            header("Location: edit.php?id=$id");
            exit();
        }
    }
    
    // Sanitize inputs
    $name = trim($_POST['name']);
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $role_id = isset($_POST['role_id']) ? (int)$_POST['role_id'] : 0;
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    // Validate email format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['message'] = ['type' => 'danger', 'text' => 'Invalid email format'];
        header("Location: edit.php?id=$id");
        exit();
    }
    
    // Password validation if being changed
    if (!empty($_POST['password']) || !empty($_POST['confirm_password'])) {
        if ($_POST['password'] !== $_POST['confirm_password']) {
            $_SESSION['message'] = ['type' => 'danger', 'text' => 'Passwords do not match'];
            header("Location: edit.php?id=$id");
            exit();
        }
        if (strlen($_POST['password']) < 8) {
            $_SESSION['message'] = ['type' => 'danger', 'text' => 'Password must be at least 8 characters'];
            header("Location: edit.php?id=$id");
            exit();
        }
    }
    
    // Check if username or email already exists (excluding current user)
    $check = $conn->prepare("SELECT id FROM users WHERE (username = ? OR email = ?) AND id != ?");
    $check->bind_param("ssi", $username, $email, $id);
    $check->execute();
    $check->store_result();
    
    if ($check->num_rows > 0) {
        $_SESSION['message'] = ['type' => 'danger', 'text' => 'Username or email already exists'];
        header("Location: edit.php?id=$id");
        exit();
    }
    
    // Prepare data for update
    $updateData = [
        'name' => $name,
        'username' => $username,
        'email' => $email,
        'role_id' => $role_id,
        'is_active' => $is_active
    ];
    
    // Only include password if it's being changed
    if (!empty($_POST['password'])) {
        $updateData['password'] = $_POST['password'];
        $updateData['confirm_password'] = $_POST['confirm_password'];
    }
    
    // Update user
    if (updateUser($id, $updateData)) {
        $_SESSION['message'] = ['type' => 'success', 'text' => 'User updated successfully'];
        header("Location: index.php");
        exit();
    } else {
        $_SESSION['message'] = ['type' => 'danger', 'text' => 'Failed to update user'];
        header("Location: edit.php?id=$id");
        exit();
    }
}

// Set page title
$pageTitle = "Edit User: " . htmlspecialchars($user['name']);

// Include header
include __DIR__ . '/../../includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="row">
        <div class="col-12">
            <?php displayMessage(); ?>
            <div class="card mb-4">
                <div class="card-header pb-0 d-flex justify-content-between align-items-center">
                    <h6>Edit User</h6>
                    <a href="index.php" class="btn btn-secondary btn-sm">Back to List</a>
                </div>
                <div class="card-body">
                    <form method="POST" id="editUserForm">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="name">Full Name *</label>
                                    <input type="text" class="form-control" id="name" name="name" 
                                           value="<?= htmlspecialchars($user['name']) ?>" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="username">Username *</label>
                                    <input type="text" class="form-control" id="username" name="username" 
                                           value="<?= htmlspecialchars($user['username']) ?>" required>
                                </div>
                            </div>
                        </div>
                        <div class="row mt-3">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="email">Email *</label>
                                    <input type="email" class="form-control" id="email" name="email" 
                                           value="<?= htmlspecialchars($user['email']) ?>" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
    <label for="role_id">Role *</label>
    <select class="form-control" id="role_id" name="role_id" required>
        <?php foreach (getAllRoles(true) as $role): ?>
            <option value="<?= $role['id'] ?>" <?= ((int)($user['role_id'] ?? 0) === (int)$role['id']) ? 'selected' : '' ?>>
                <?= htmlspecialchars($role['name']) ?> (<?= htmlspecialchars($role['slug']) ?>)
            </option>
        <?php endforeach; ?>
    </select>
</div>
                            </div>
                        </div>
                        <div class="row mt-3">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="password">New Password (leave blank to keep current)</label>
                                    <input type="password" class="form-control" id="password" name="password"
                                           placeholder="Enter new password">
                                    <small class="text-muted">Minimum 8 characters</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="confirm_password">Confirm New Password</label>
                                    <input type="password" class="form-control" id="confirm_password" name="confirm_password"
                                           placeholder="Confirm new password">
                                </div>
                            </div>
                        </div>
                        <div class="row mt-3">
                            <div class="col-md-6">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="is_active" name="is_active" 
                                           <?= $user['is_active'] ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="is_active">Active User</label>
                                </div>
                            </div>
                        </div>
                        <div class="row mt-4">
                            <div class="col-12">
                                <button type="submit" class="btn btn-primary">Update User</button>
                                <a href="index.php" class="btn btn-secondary">Cancel</a>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Client-side validation
document.getElementById('editUserForm').addEventListener('submit', function(e) {
    const password = document.getElementById('password').value;
    const confirmPassword = document.getElementById('confirm_password').value;
    
    if (password || confirmPassword) {
        if (password !== confirmPassword) {
            alert('Passwords do not match');
            e.preventDefault();
            return false;
        }
        if (password.length < 8) {
            alert('Password must be at least 8 characters');
            e.preventDefault();
            return false;
        }
    }
    
    return true;
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>


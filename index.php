<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Define the root path
define('ROOT_PATH', __DIR__);

// Verify the database.php path exists
$db_path = ROOT_PATH . '/config/database.php';
if (!file_exists($db_path)) {
    die("Error: database.php not found at $db_path");
}

// Load configuration and functions
require_once $db_path;
require_once ROOT_PATH . '/includes/auth.php';

// Set default page title
$page_title = 'Login';

// Check if user is already logged in
if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit();
}

// Load header
require_once ROOT_PATH . '/includes/header.php';
?>

<div class="container mt-5">
    <div class="row justify-content-center">
        <div class="col-md-6 col-lg-4">
            <div class="card shadow">
                <div class="card-body">
                    <h3 class="card-title text-center mb-4">Inventory System</h3>
                    
                    <!-- Display any alerts/messages -->
                    <?php if (isset($_SESSION['alert'])): ?>
                        <div class="alert alert-<?= $_SESSION['alert']['type'] ?>">
                            <?= $_SESSION['alert']['message'] ?>
                        </div>
                        <?php unset($_SESSION['alert']); ?>
                    <?php endif; ?>
                    
                    <form action="index.php" method="POST">
                        <div class="mb-3">
                            <label for="username" class="form-label">Username</label>
                            <input type="text" class="form-control" id="username" name="username" required>
                        </div>
                        <div class="mb-3">
                            <label for="password" class="form-label">Password</label>
                            <input type="password" class="form-control" id="password" name="password" required>
                        </div>
                        <div class="d-grid">
                            <button type="submit" name="login" class="btn btn-primary">Login</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
// Load footer
require_once ROOT_PATH . '/includes/footer.php';
?>
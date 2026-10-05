<?php
require_once '../../includes/auth.php';
require_once '../../includes/formula_access.php';

if (!isAdminUser()) {
    setAlert('danger', 'Access denied. Only admins can reset the formula password.');
    redirect('../../dashboard.php');
}

if (empty($_SESSION['formula_password_reset_token'])) {
    $_SESSION['formula_password_reset_token'] = bin2hex(random_bytes(32));
}
$storageAvailable = formulaPasswordStorageAvailable();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    $password = $_POST['new_password'] ?? '';
    $confirmation = $_POST['confirm_password'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['formula_password_reset_token'], $token)) {
        $error = 'Invalid request. Please try again.';
    } elseif (!$storageAvailable) {
        $error = 'Apply migration 20261005_add_formula_password.sql before resetting the password.';
    } elseif (!is_string($password) || strlen($password) < 6 || strlen($password) > 72) {
        $error = 'Use a password between 6 and 72 bytes.';
    } elseif (!is_string($confirmation) || $password !== $confirmation) {
        $error = 'Passwords do not match.';
    } else {
        try {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare('INSERT INTO manufacturing_formula_password (id, password_hash, updated_by)
                VALUES (1, ?, ?) ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), updated_by = VALUES(updated_by), updated_at = CURRENT_TIMESTAMP');
            $stmt->execute([$hash, (int)$_SESSION['user_id']]);
        } catch (Exception $e) {
            error_log('Formula password reset failed: ' . $e->getMessage());
            $error = 'Unable to save the formula password. Please try again.';
        }
        if ($error === '') {
            unset($_SESSION['formula_unlocked'], $_SESSION['formula_password_version'], $_SESSION['formula_password_reset_token']);
            logActivity('Reset formula password', null, 'update', 'formula_password', 1);
            setAlert('success', 'Formula password reset. Use the new password to unlock formulas.');
            redirect('formulas.php');
        }
    }
}

$page_title = 'Reset Formula Password';
require_once '../../includes/header.php';
?>
<div class="row justify-content-center mt-4">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header"><h5 class="mb-0">Reset Formula Password</h5></div>
            <div class="card-body">
                <p class="text-muted">Set a new shared password for formulas and formula components in manufacturing orders. Users must unlock formulas again after a reset.</p>
                <?php if (!$storageAvailable): ?>
                    <div class="alert alert-warning">Apply migration 20261005_add_formula_password.sql before resetting the password.</div>
                <?php endif; ?>
                <?php if ($error !== ''): ?>
                    <div class="alert alert-danger"><?= e($error); ?></div>
                <?php endif; ?>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['formula_password_reset_token']); ?>">
                    <div class="mb-3">
                        <label for="new_password" class="form-label">New password</label>
                        <input id="new_password" type="password" name="new_password" class="form-control" minlength="6" maxlength="72" autocomplete="new-password" required>
                    </div>
                    <div class="mb-3">
                        <label for="confirm_password" class="form-label">Confirm new password</label>
                        <input id="confirm_password" type="password" name="confirm_password" class="form-control" minlength="6" maxlength="72" autocomplete="new-password" required>
                    </div>
                    <button type="submit" class="btn btn-primary" <?= !$storageAvailable ? 'disabled' : ''; ?>>Reset Password</button>
                    <a href="formulas.php" class="btn btn-outline-secondary">Back to Formulas</a>
                </form>
            </div>
        </div>
    </div>
</div>
<?php require_once '../../includes/footer.php'; ?>

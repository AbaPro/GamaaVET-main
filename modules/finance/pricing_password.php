<?php
require_once '../../includes/auth.php';
require_once __DIR__ . '/pricing_helpers.php';

if (!isAdminUser()) {
    setAlert('danger', 'Access denied. Only admins can change the order pricing password.');
    redirect('../../dashboard.php');
}

$storageReady = pricingPasswordStorageReady();
$isFirstSet = getPricingPasswordHash() === null;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['new_password'] ?? '';
    $confirmation = $_POST['confirm_password'] ?? '';
    if (!pricingValidFormToken()) {
        $error = 'Invalid request. Please try again.';
    } elseif (!$storageReady) {
        $error = 'Apply migration 20261017_order_pricing_password.sql before setting the password.';
    } elseif (!is_string($password) || strlen($password) < 6 || strlen($password) > 72) {
        $error = 'Use a password between 6 and 72 bytes.';
    } elseif (!is_string($confirmation) || $password !== $confirmation) {
        $error = 'Passwords do not match.';
    } else {
        try {
            setPricingPassword($password);
        } catch (Exception $e) {
            error_log('Order pricing password change failed: ' . $e->getMessage());
            $error = 'Unable to save the pricing password. Please try again.';
        }
        if ($error === '') {
            unset($_SESSION['pricing_unlocked'], $_SESSION['pricing_password_version']);
            logActivity($isFirstSet ? 'Set order pricing password' : 'Changed order pricing password', null, 'update', 'pricing_password', 1);
            setAlert('success', 'Order pricing password saved. Use the new password to unlock pricing.');
            redirect('pricing.php');
        }
    }
}

$page_title = $isFirstSet ? 'Set Pricing Password' : 'Change Pricing Password';
require_once '../../includes/header.php';
?>
<div class="row justify-content-center mt-4">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header"><h5 class="mb-0"><?= e($page_title); ?></h5></div>
            <div class="card-body">
                <p class="text-muted">
                    This password locks Finance &gt; Order Pricing only. It is separate from the formula password.
                    <?php if (!$isFirstSet): ?>Everyone must unlock pricing again after a change.<?php endif; ?>
                </p>
                <?php if (!$storageReady): ?>
                    <div class="alert alert-warning">Apply migration 20261017_order_pricing_password.sql before setting the password.</div>
                <?php endif; ?>
                <?php if ($error !== ''): ?>
                    <div class="alert alert-danger"><?= e($error); ?></div>
                <?php endif; ?>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= e(pricingFormToken()); ?>">
                    <div class="mb-3">
                        <label for="new_password" class="form-label">New password</label>
                        <input id="new_password" type="password" name="new_password" class="form-control" minlength="6" maxlength="72" autocomplete="new-password" required>
                    </div>
                    <div class="mb-3">
                        <label for="confirm_password" class="form-label">Confirm new password</label>
                        <input id="confirm_password" type="password" name="confirm_password" class="form-control" minlength="6" maxlength="72" autocomplete="new-password" required>
                    </div>
                    <button type="submit" class="btn btn-primary" <?= !$storageReady ? 'disabled' : ''; ?>>Save Password</button>
                    <a href="pricing.php" class="btn btn-outline-secondary">Back to Pricing</a>
                </form>
            </div>
        </div>
    </div>
</div>
<?php require_once '../../includes/footer.php'; ?>

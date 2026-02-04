<?php
require_once '../../includes/auth.php';
require_once '../../config/database.php';

if (!hasPermission('customers.whatsapp_portal')) {
    setAlert('danger', 'You do not have permission to manage portal access.');
    redirect('../../dashboard.php');
}

$customerId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($customerId <= 0) {
    setAlert('danger', 'Invalid customer ID.');
    redirect('index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'update';

    try {
        if ($action === 'clear') {
            $stmt = $pdo->prepare("
                UPDATE customers
                SET portal_password_hash = NULL,
                    portal_password_hint = NULL,
                    portal_password_updated_at = NULL
                WHERE id = ?
            ");
            $stmt->execute([$customerId]);

            logActivity('Cleared portal password', ['customer_id' => $customerId]);
            $_SESSION['success'] = 'Portal password removed. The portal link no longer requires a password.';
        } else {
            $rawPassword = trim($_POST['portal_password'] ?? '');

            if ($rawPassword === '') {
                $rawPassword = strtoupper(bin2hex(random_bytes(3)));
            } elseif (strlen($rawPassword) < 4) {
                throw new Exception('Password must be at least 4 characters long.');
            }

            $passwordHash = password_hash($rawPassword, PASSWORD_BCRYPT);
            $hint = trim($_POST['portal_password_hint'] ?? '');
            $hint = $hint !== '' ? mb_substr($hint, 0, 120) : null;

            $stmt = $pdo->prepare("
                UPDATE customers
                SET portal_password_hash = ?,
                    portal_password_hint = ?,
                    portal_password_updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$passwordHash, $hint, $customerId]);

            logActivity('Updated portal password', ['customer_id' => $customerId]);
            $_SESSION['success'] = 'Portal password updated successfully.';
            $_SESSION['portal_password_plain'] = $rawPassword;
        }
    } catch (Exception $e) {
        $_SESSION['error'] = $e->getMessage();
    }

    header('Location: portal_access.php?id=' . $customerId);
    exit();
}

$stmt = $pdo->prepare("
    SELECT id, name, portal_token, portal_token_expires, portal_password_hint,
           portal_password_hash IS NOT NULL AS has_password,
           portal_password_updated_at, portal_last_access_at
    FROM customers
    WHERE id = ?
");
$stmt->execute([$customerId]);
$customer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$customer) {
    setAlert('danger', 'Customer not found.');
    redirect('index.php');
}

$page_title = 'Portal Access - ' . $customer['name'];
require_once '../../includes/header.php';
?>

<div class="container-fluid py-4">
    <a href="index.php" class="btn btn-link px-0 mb-3">
        <i class="fas fa-arrow-left me-1"></i> Back to Customers
    </a>

    <?php include '../../includes/messages.php'; ?>

    <?php if (!empty($_SESSION['portal_password_plain'])): ?>
        <div class="alert alert-warning">
            <strong>New Portal Password:</strong>
            <span class="text-monospace"><?= htmlspecialchars($_SESSION['portal_password_plain']) ?></span>
            <p class="mb-0">Share this password with the customer. It will not be shown again.</p>
        </div>
        <?php unset($_SESSION['portal_password_plain']); ?>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-lg-5">
            <div class="card h-100">
                <div class="card-header">
                    <h5 class="mb-0">Portal Status</h5>
                </div>
                <div class="card-body">
                    <p><strong>Customer:</strong> <?= htmlspecialchars($customer['name']); ?></p>
                    <p>
                        <strong>Password Required:</strong>
                        <span class="badge <?= $customer['has_password'] ? 'bg-success' : 'bg-secondary'; ?>">
                            <?= $customer['has_password'] ? 'Enabled' : 'Disabled'; ?>
                        </span>
                    </p>
                    <p><strong>Password Hint:</strong> <?= $customer['portal_password_hint'] ? htmlspecialchars($customer['portal_password_hint']) : '—'; ?></p>
                    <p><strong>Last Set:</strong> <?= $customer['portal_password_updated_at'] ? date('M d, Y H:i', strtotime($customer['portal_password_updated_at'])) : 'Never'; ?></p>
                    <p><strong>Last Portal Access:</strong> <?= $customer['portal_last_access_at'] ? date('M d, Y H:i', strtotime($customer['portal_last_access_at'])) : 'Never'; ?></p>
                    <p><strong>Token Expires:</strong> <?= $customer['portal_token_expires'] ? date('M d, Y H:i', strtotime($customer['portal_token_expires'])) : 'Not generated'; ?></p>
                </div>
            </div>
        </div>
        <div class="col-lg-7">
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Update Portal Password</h5>
                </div>
                <div class="card-body">
                    <form method="post">
                        <input type="hidden" name="action" value="update">
                        <div class="mb-3">
                            <label for="portal_password" class="form-label">New Password</label>
                            <input
                                type="text"
                                name="portal_password"
                                id="portal_password"
                                class="form-control"
                                placeholder="Leave blank to auto-generate a secure password">
                        </div>
                        <div class="mb-3">
                            <label for="portal_password_hint" class="form-label">Password Hint (optional)</label>
                            <input
                                type="text"
                                name="portal_password_hint"
                                id="portal_password_hint"
                                class="form-control"
                                value="<?= htmlspecialchars($customer['portal_password_hint'] ?? '') ?>"
                                maxlength="120">
                        </div>
                        <button type="submit" class="btn btn-primary">Save Password</button>
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Disable Protection</h5>
                </div>
                <div class="card-body">
                    <p class="text-muted mb-3">Removing the password will make the customer portal accessible with the token link only.</p>
                    <form method="post" onsubmit="return confirm('Remove portal password for this customer?');">
                        <input type="hidden" name="action" value="clear">
                        <button type="submit" class="btn btn-outline-danger" <?= $customer['has_password'] ? '' : 'disabled'; ?>>
                            Remove Password Requirement
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>

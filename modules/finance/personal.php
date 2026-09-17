<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once __DIR__ . '/account_deletion.php';

$canCreate = hasPermission('finance.personal_accounts.create');
$canDelete = hasPermission('finance.personal_accounts.delete');

if (!$canCreate && !$canDelete) {
    setAlert('danger', 'Access denied.');
    redirect('../../dashboard.php');
}

handleFinanceAccountDeletion('personal', $canDelete, 'personal.php');

$currentAccountId = getCurrentAccountId();
$accountStmt = $conn->prepare('SELECT id, name FROM accounts WHERE id = ? AND is_active = 1 LIMIT 1');
$accountStmt->bind_param('i', $currentAccountId);
$accountStmt->execute();
$accounts = $accountStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$accountStmt->close();
$allowedAccountIds = array_fill_keys(array_map('intval', array_column($accounts, 'id')), true);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_personal_account'])) {
    if (!$canCreate) {
        setAlert('danger', 'Access denied.');
        redirect('personal.php');
    }
    $name = trim(strip_tags((string)($_POST['name'] ?? '')));
    $email = trim((string)($_POST['email'] ?? ''));
    $description = trim(strip_tags((string)($_POST['description'] ?? '')));
    $accountId = (int)($_POST['account_id'] ?? 0);
    $holderUserId = !empty($_POST['holder_user_id']) ? (int)$_POST['holder_user_id'] : null;
    $createdBy = (int)($_SESSION['user_id'] ?? 0);

    if ($name === '') {
        setAlert('danger', 'Personal account name is required.');
    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        setAlert('danger', 'Enter a valid email address.');
    } elseif (!$accountId || !isset($allowedAccountIds[$accountId])) {
        setAlert('danger', 'Please select an available brand.');
    } else {
        if ($holderUserId) {
            $holderStmt = $conn->prepare("
                SELECT u.id
                FROM users u
                LEFT JOIN personal_accounts pa ON pa.holder_user_id = u.id
                WHERE u.id = ? AND u.is_active = 1 AND pa.id IS NULL
                LIMIT 1
            ");
            $holderStmt->bind_param('i', $holderUserId);
            $holderStmt->execute();
            $validHolder = $holderStmt->get_result()->num_rows === 1;
            $holderStmt->close();
            $loginRegion = $_SESSION['login_region'] ?? 'factory';
            if ($validHolder && $loginRegion !== 'factory') {
                $validHolder = userHasPermissionKey($holderUserId, 'region.' . $loginRegion);
            }
            if (!$validHolder) {
                setAlert('danger', 'Selected staff member already has an account or is unavailable.');
                redirect('personal.php');
            }
        }

        $stmt = $conn->prepare("
            INSERT INTO personal_accounts
                (account_id, holder_user_id, name, email, description, balance, is_active, created_by)
            VALUES (?, ?, ?, ?, ?, 0, 1, ?)
        ");
        $stmt->bind_param('iisssi', $accountId, $holderUserId, $name, $email, $description, $createdBy);
        if ($stmt->execute()) {
            $personalAccountId = $stmt->insert_id;
            $stmt->close();
            logActivity('Created personal finance account', [
                'personal_account_id' => $personalAccountId,
                'name' => $name,
                'account_id' => $accountId,
            ]);
            setAlert('success', 'Personal account created.');
        } else {
            $error = $stmt->error;
            $stmt->close();
            setAlert('danger', 'Unable to create personal account: ' . $error);
        }
    }

    redirect('personal.php');
}

$personalScope = getAccountScopeSql('pa');
$result = $conn->query("
    SELECT pa.*, a.name AS account_name,
           COALESCE(r.name, u.role) AS holder_role
    FROM personal_accounts pa
    LEFT JOIN accounts a ON a.id = pa.account_id
    LEFT JOIN users u ON u.id = pa.holder_user_id
    LEFT JOIN roles r ON r.id = u.role_id
    WHERE pa.is_active = 1 AND $personalScope
    ORDER BY pa.name
");
$availableHolders = $conn->query("
    SELECT u.id, u.name, u.email
    FROM users u
    LEFT JOIN personal_accounts pa ON pa.holder_user_id = u.id
    WHERE u.is_active = 1 AND pa.id IS NULL
    ORDER BY u.name
")->fetch_all(MYSQLI_ASSOC);
$loginRegion = $_SESSION['login_region'] ?? 'factory';
if ($loginRegion !== 'factory') {
    $regionPermission = 'region.' . $loginRegion;
    $availableHolders = array_values(array_filter($availableHolders, static function ($holder) use ($regionPermission) {
        return userHasPermissionKey((int)$holder['id'], $regionPermission);
    }));
}

$page_title = 'Personal Accounts';
require_once '../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Personal Accounts</h2>
    <?php if ($canCreate): ?>
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addPersonalAccountModal">
        <i class="fas fa-plus me-1"></i>Add Personal Account
    </button>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table js-datatable table-striped table-hover align-middle">
                <thead>
                    <tr>
                        <th>ID</th><th>Name</th><th>Email</th><th>Brand</th><th>Role / Type</th>
                        <th class="text-end">Balance</th><th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($row = $result->fetch_assoc()): ?>
                        <tr>
                            <td><?= (int)$row['id']; ?></td>
                            <td>
                                <a href="personal_details.php?id=<?= (int)$row['id']; ?>" class="fw-semibold text-decoration-none">
                                    <i class="fas fa-user-shield me-1"></i><?= e($row['name']); ?>
                                </a>
                            </td>
                            <td><?= $row['email'] ? e($row['email']) : '-'; ?></td>
                            <td><?= e($row['account_name'] ?: 'GammaVet'); ?></td>
                            <td><span class="badge bg-info"><?= e($row['holder_role'] ?: 'Standalone Account'); ?></span></td>
                            <td class="text-end <?= (float)$row['balance'] < 0 ? 'text-danger' : 'text-success'; ?> fw-semibold">
                                <?= number_format((float)$row['balance'], 2); ?>
                            </td>
                            <td>
                                <a href="personal_details.php?id=<?= (int)$row['id']; ?>" class="btn btn-sm btn-outline-primary">
                                    <i class="fas fa-history me-1"></i>History
                                </a>
                                <?php if ($canDelete) renderFinanceAccountDeleteButton($row); ?>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if ($canCreate): ?>
<div class="modal fade" id="addPersonalAccountModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <input type="hidden" name="create_personal_account" value="1">
                <div class="modal-header">
                    <h5 class="modal-title">Add Personal Account</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="personal_name" class="form-label">Account / Holder Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="personal_name" name="name" maxlength="150" required>
                    </div>
                    <div class="mb-3">
                        <label for="personal_email" class="form-label">Email</label>
                        <input type="email" class="form-control" id="personal_email" name="email" maxlength="150">
                    </div>
                    <div class="mb-3">
                        <label for="holder_user_id" class="form-label">Linked Staff User</label>
                        <select class="form-select" id="holder_user_id" name="holder_user_id">
                            <option value="">-- Standalone account --</option>
                            <?php foreach ($availableHolders as $holder): ?>
                                <option value="<?= (int)$holder['id']; ?>"><?= e($holder['name']); ?><?= $holder['email'] ? ' — ' . e($holder['email']) : ''; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="personal_brand" class="form-label">Brand <span class="text-danger">*</span></label>
                        <select class="form-select" id="personal_brand" name="account_id" required>
                            <?php foreach ($accounts as $account): ?>
                                <option value="<?= (int)$account['id']; ?>"><?= e($account['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="personal_description" class="form-label">Description / Responsibility</label>
                        <textarea class="form-control" id="personal_description" name="description" rows="3" maxlength="1000"></textarea>
                    </div>
                    <div class="alert alert-info mb-0 py-2">
                        The opening balance is zero. Money enters or leaves this account through approved Finance Transfers and PO payments.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary">Create Account</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php endif; ?>

<?php require_once '../../includes/footer.php'; ?>

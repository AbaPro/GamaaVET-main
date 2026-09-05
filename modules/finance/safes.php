<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once __DIR__ . '/account_deletion.php';

$canCreate = hasPermission('finance.safes.create');
$canDelete = hasPermission('finance.safes.delete');

if (!$canCreate && !$canDelete) {
    setAlert('danger', 'Access denied.');
    redirect('../../dashboard.php');
}

$page_title = 'Safes';

handleFinanceAccountDeletion('safe', $canDelete, 'safes.php');

$accounts = $conn->query("SELECT id, name FROM accounts WHERE is_active = 1 AND slug <> 'curva' ORDER BY id ASC")->fetch_all(MYSQLI_ASSOC);
$allowedAccountIds = array_fill_keys(array_map('intval', array_column($accounts, 'id')), true);

// Create safe
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['name'])) {
    if (!$canCreate) {
        setAlert('danger', 'Access denied.');
        redirect('safes.php');
    }
    $name = sanitize($_POST['name']);
    $account_id = !empty($_POST['account_id']) ? (int)$_POST['account_id'] : null;
    if (!$account_id || !isset($allowedAccountIds[$account_id])) {
        setAlert('danger', 'Please select an available brand.');
        redirect('safes.php');
    }
    $stmt = $conn->prepare("INSERT INTO safes (name, balance, account_id) VALUES (?, 0, ?)");
    $stmt->bind_param("si", $name, $account_id);
    $stmt->execute();
    setAlert('success', 'Safe added.');
    redirect('safes.php');
}

$result = $conn->query("SELECT s.*, a.name AS account_name
                        FROM safes s
                        LEFT JOIN accounts a ON a.id = s.account_id
                        ORDER BY s.id");
require_once '../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Safes</h2>
    <?php if ($canCreate): ?>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addSafeModal"><i class="fas fa-plus"></i> Add Safe</button>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-body">
        <table class="table js-datatable table-striped table-hover">
            <thead><tr><th>ID</th><th>Name</th><th>Brand</th><th>Balance</th><th>Actions</th></tr></thead>
            <tbody>
                <?php while ($row=$result->fetch_assoc()): ?>
                    <tr>
                        <td><?= $row['id']; ?></td>
                        <td>
                            <a href="safe_details.php?id=<?= (int)$row['id']; ?>" class="fw-semibold text-decoration-none">
                                <i class="fas fa-vault me-1"></i><?= htmlspecialchars($row['name']); ?>
                            </a>
                        </td>
                        <td><?= $row['account_name'] ? htmlspecialchars($row['account_name']) : 'GammaVet'; ?></td>
                        <td><?= number_format($row['balance'],2); ?></td>
                        <td>
                            <a href="safe_details.php?id=<?= (int)$row['id']; ?>" class="btn btn-sm btn-outline-primary me-1">
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

<?php if ($canCreate): ?>
<div class="modal fade" id="addSafeModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <div class="modal-header"><h5 class="modal-title">Add Safe</h5></div>
        <div class="modal-body">
          <div class="mb-3">
            <input type="text" class="form-control" name="name" placeholder="Safe name" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Brand</label>
            <select class="form-select" name="account_id" required>
                <?php foreach ($accounts as $acc): ?>
                    <option value="<?= $acc['id']; ?>"><?= htmlspecialchars($acc['name']); ?></option>
                <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
          <button type="submit" class="btn btn-primary">Save</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php endif; ?>

<?php require_once '../../includes/footer.php'; ?>

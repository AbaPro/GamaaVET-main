<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once __DIR__ . '/account_deletion.php';

$canCreate = hasPermission('finance.bank_accounts.create');
$canDelete = hasPermission('finance.bank_accounts.delete');

if (!$canCreate && !$canDelete) {
    setAlert('danger', 'Access denied.');
    redirect('../../dashboard.php');
}

$page_title = 'Bank Accounts';

handleFinanceAccountDeletion('bank', $canDelete, 'banks.php');

$accounts = $conn->query("SELECT id, name FROM accounts WHERE is_active = 1 AND slug <> 'curva' ORDER BY id ASC")->fetch_all(MYSQLI_ASSOC);
$allowedAccountIds = array_fill_keys(array_map('intval', array_column($accounts, 'id')), true);

// Add bank
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bank_name'])) {
    if (!$canCreate) {
        setAlert('danger', 'Access denied.');
        redirect('banks.php');
    }
    $bank_name = sanitize($_POST['bank_name']);
    $acc_no = sanitize($_POST['account_number']);
    $account_id = !empty($_POST['account_id']) ? (int)$_POST['account_id'] : null;
    if (!$account_id || !isset($allowedAccountIds[$account_id])) {
        setAlert('danger', 'Please select an available brand.');
        redirect('banks.php');
    }
    $stmt = $conn->prepare("INSERT INTO bank_accounts (bank_name, account_number, balance, account_id) VALUES (?, ?, 0, ?)");
    $stmt->bind_param("ssi", $bank_name, $acc_no, $account_id);
    $stmt->execute();
    setAlert('success', 'Bank account added.');
    redirect('banks.php');
}

$result = $conn->query("SELECT b.*, a.name AS account_name FROM bank_accounts b LEFT JOIN accounts a ON a.id = b.account_id ORDER BY b.id");
require_once '../../includes/header.php';
?>

<div class="d-flex justify-content-between mb-4">
    <h2>Bank Accounts</h2>
    <?php if ($canCreate): ?>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addBankModal"><i class="fas fa-plus"></i> Add Bank</button>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-body">
        <table class="table js-datatable table-hover">
            <thead><tr><th>ID</th><th>Bank Name</th><th>Account #</th><th>Brand</th><th>Balance</th><th>Actions</th></tr></thead>
            <tbody>
                <?php while ($row=$result->fetch_assoc()): ?>
                    <tr>
                        <td><?= $row['id']; ?></td>
                        <td>
                            <a href="bank_details.php?id=<?= (int)$row['id']; ?>" class="fw-semibold text-decoration-none">
                                <i class="fas fa-university me-1"></i><?= htmlspecialchars($row['bank_name']); ?>
                            </a>
                        </td>
                        <td><?= htmlspecialchars($row['account_number']); ?></td>
                        <td><?= $row['account_name'] ? htmlspecialchars($row['account_name']) : 'GammaVet'; ?></td>
                        <td><?= number_format($row['balance'],2); ?></td>
                        <td>
                            <a href="bank_details.php?id=<?= (int)$row['id']; ?>" class="btn btn-sm btn-outline-primary me-1">
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
<div class="modal fade" id="addBankModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <div class="modal-header"><h5 class="modal-title">Add Bank</h5></div>
        <div class="modal-body">
          <div class="mb-3"><label class="form-label">Bank Name</label>
            <input type="text" class="form-control" name="bank_name" required>
          </div>
          <div class="mb-3"><label class="form-label">Account Number</label>
            <input type="text" class="form-control" name="account_number" required>
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

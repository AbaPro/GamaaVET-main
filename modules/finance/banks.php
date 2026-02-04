<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

if (!hasPermission('finance.bank_accounts.create')) {
    setAlert('danger', 'Access denied.');
    redirect('../../dashboard.php');
}

$page_title = 'Bank Accounts';
require_once '../../includes/header.php';

// Add bank
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bank_name'])) {
    $bank_name = sanitize($_POST['bank_name']);
    $acc_no = sanitize($_POST['account_number']);
    $stmt = $conn->prepare("INSERT INTO bank_accounts (bank_name, account_number, balance) VALUES (?, ?, 0)");
    $stmt->bind_param("ss", $bank_name, $acc_no);
    $stmt->execute();
    setAlert('success', 'Bank account added.');
    redirect('banks.php');
}

// Delete bank only if empty
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    $check = $conn->query("SELECT balance FROM bank_accounts WHERE id=$id")->fetch_assoc();
    if ($check && $check['balance'] == 0) {
        $conn->query("DELETE FROM bank_accounts WHERE id=$id");
        setAlert('success', 'Bank account deleted.');
    } else {
        setAlert('danger', 'Cannot delete bank account with balance.');
    }
    redirect('banks.php');
}

$result = $conn->query("SELECT * FROM bank_accounts");
?>

<div class="d-flex justify-content-between mb-4">
    <h2>Bank Accounts</h2>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addBankModal"><i class="fas fa-plus"></i> Add Bank</button>
</div>

<div class="card">
    <div class="card-body">
        <table class="table js-datatable table-hover">
            <thead><tr><th>ID</th><th>Bank Name</th><th>Account #</th><th>Balance</th><th>Actions</th></tr></thead>
            <tbody>
                <?php while ($row=$result->fetch_assoc()): ?>
                    <tr>
                        <td><?= $row['id']; ?></td>
                        <td><?= htmlspecialchars($row['bank_name']); ?></td>
                        <td><?= htmlspecialchars($row['account_number']); ?></td>
                        <td><?= number_format($row['balance'],2); ?></td>
                        <td>
                            <?php if ($row['balance']==0): ?>
                                <a href="banks.php?delete=<?= $row['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete this account?')">Delete</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>

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
        </div>
        <div class="modal-footer">
          <button class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
          <button type="submit" class="btn btn-primary">Save</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php require_once '../../includes/footer.php'; ?>

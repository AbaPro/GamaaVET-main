<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

if (!hasPermission('finance.safes.create')) {
    setAlert('danger', 'Access denied.');
    redirect('../../dashboard.php');
}

$page_title = 'Safes';
require_once '../../includes/header.php';

$accounts = $conn->query("SELECT id, name FROM accounts WHERE is_active = 1 ORDER BY id ASC")->fetch_all(MYSQLI_ASSOC);

// Create safe
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['name'])) {
    $name = sanitize($_POST['name']);
    $account_id = !empty($_POST['account_id']) ? (int)$_POST['account_id'] : null;
    $stmt = $conn->prepare("INSERT INTO safes (name, balance, account_id) VALUES (?, 0, ?)");
    $stmt->bind_param("si", $name, $account_id);
    $stmt->execute();
    setAlert('success', 'Safe added.');
    redirect('safes.php');
}

// Delete safe if empty
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    $check = $conn->query("SELECT balance FROM safes WHERE id=$id")->fetch_assoc();
    if ($check && $check['balance'] == 0) {
        $conn->query("DELETE FROM safes WHERE id=$id");
        setAlert('success', 'Safe deleted.');
    } else {
        setAlert('danger', 'Cannot delete safe with balance.');
    }
    redirect('safes.php');
}

$result = $conn->query("SELECT s.*, a.name AS account_name FROM safes s LEFT JOIN accounts a ON a.id = s.account_id ORDER BY s.id");
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Safes</h2>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addSafeModal"><i class="fas fa-plus"></i> Add Safe</button>
</div>

<div class="card">
    <div class="card-body">
        <table class="table js-datatable table-striped">
            <thead><tr><th>ID</th><th>Name</th><th>Brand</th><th>Balance</th><th>Actions</th></tr></thead>
            <tbody>
                <?php while ($row=$result->fetch_assoc()): ?>
                    <tr>
                        <td><?= $row['id']; ?></td>
                        <td><?= htmlspecialchars($row['name']); ?></td>
                        <td><?= $row['account_name'] ? htmlspecialchars($row['account_name']) : 'GammaVet'; ?></td>
                        <td><?= number_format($row['balance'],2); ?></td>
                        <td>
                            <?php if ($row['balance']==0): ?>
                                <a href="safes.php?delete=<?= $row['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete this safe?')">Delete</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>

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
            <select class="form-select" name="account_id">
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

<?php require_once '../../includes/footer.php'; ?>

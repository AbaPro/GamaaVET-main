<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

if (!hasPermission('finance.personal_accounts.create')) {
    setAlert('danger', 'Access denied.');
    redirect('../../dashboard.php');
}

$page_title = 'Staff Personal Accounts';
require_once '../../includes/header.php';

$result = $conn->query("SELECT id, name, email, role, personal_balance FROM users WHERE role != 'admin'");
?>

<div class="d-flex justify-content-between mb-4">
    <h2>Personal Accounts</h2>
</div>

<div class="card">
    <div class="card-body">
        <table class="table js-datatable table-striped">
            <thead><tr><th>ID</th><th>Name</th><th>Email</th><th>Role</th><th>Balance</th></tr></thead>
            <tbody>
                <?php while ($row=$result->fetch_assoc()): ?>
                    <tr>
                        <td><?= $row['id']; ?></td>
                        <td><?= htmlspecialchars($row['name']); ?></td>
                        <td><?= htmlspecialchars($row['email']); ?></td>
                        <td><span class="badge bg-info"><?= $row['role']; ?></span></td>
                        <td><?= number_format($row['personal_balance'],2); ?></td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>

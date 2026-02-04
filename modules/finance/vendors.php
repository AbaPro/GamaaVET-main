<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

if (!hasPermission('finance.vendor_wallet.view')) {
    setAlert('danger', 'Access denied.');
    redirect('../../dashboard.php');
}

$page_title = 'Vendor Wallets';
require_once '../../includes/header.php';

$sql = "SELECT * FROM vendors ORDER BY name";
$result = $conn->query($sql);
?>

<div class="d-flex justify-content-between mb-4">
    <h2>Vendor Wallets</h2>
</div>

<div class="card">
    <div class="card-body">
        <table class="table js-datatable table-hover">
            <thead><tr><th>ID</th><th>Name</th><th>Email</th><th>Phone</th><th>Wallet Balance</th><th>Actions</th></tr></thead>
            <tbody>
                <?php while($row=$result->fetch_assoc()): ?>
                    <tr>
                        <td><?= $row['id']; ?></td>
                        <td><?= htmlspecialchars($row['name']); ?></td>
                        <td><?= htmlspecialchars($row['email']); ?></td>
                        <td><?= htmlspecialchars($row['phone']); ?></td>
                        <td><?= number_format($row['wallet_balance'],2); ?></td>
                        <td>
                            <a href="../../modules/vendors/wallet.php?id=<?= $row['id']; ?>" class="btn btn-sm btn-primary">
                                <i class="fas fa-wallet"></i> View Wallet
                            </a>
                        </td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>

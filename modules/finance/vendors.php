<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once __DIR__ . '/deletion_approval.php';
$showClosed = isset($_GET['show_closed']);

$canSettleBalances = hasPermission('finance.balances.settle');
if (($_SESSION['login_region'] ?? 'factory') !== 'factory'
    || (!hasPermission('finance.vendor_wallet.view') && !$canSettleBalances)) {
    setAlert('danger', 'Access denied.');
    redirect('../../dashboard.php');
}

$page_title = 'Vendor Wallets';
$canViewPhoneNumbers = hasPermission('contacts.phone.view');
require_once '../../includes/header.php';

$sql = "SELECT * FROM vendors";
if (!$showClosed) $sql .= " WHERE finance_account_closed_at IS NULL OR wallet_balance <> 0 OR EXISTS (SELECT 1 FROM purchase_orders po WHERE po.vendor_id = vendors.id AND po.total_amount > po.paid_amount)";
$sql .= " ORDER BY name";
$result = $conn->query($sql);
?>

<div class="d-flex justify-content-between mb-4">
    <h2>Vendor Wallets</h2>
    <a href="?<?= $showClosed ? '' : 'show_closed=1' ?>" class="btn btn-outline-secondary"><?= $showClosed ? 'Hide closed accounts' : 'Show closed accounts' ?></a>
</div>

<div class="card">
    <div class="card-body">
        <table class="table js-datatable table-hover">
            <thead><tr><th>ID</th><th>Name</th><th>Email</th><?php if ($canViewPhoneNumbers): ?><th>Phone</th><?php endif; ?><th>Wallet Balance</th><th>Actions</th></tr></thead>
            <tbody>
                <?php while($row=$result->fetch_assoc()): ?>
                    <tr>
                        <td><?= $row['id']; ?></td>
                        <td><?= htmlspecialchars($row['name']); ?></td>
                        <td><?= htmlspecialchars($row['email']); ?></td>
                        <?php if ($canViewPhoneNumbers): ?>
                            <td><?= htmlspecialchars($row['phone']); ?></td>
                        <?php endif; ?>
                        <td><?= number_format($row['wallet_balance'],2); ?></td>
                        <td>
                            <a href="../../modules/vendors/wallet.php?id=<?= $row['id']; ?>" class="btn btn-sm btn-primary">
                                <i class="fas fa-wallet"></i> View Wallet
                            </a>
                            <?php renderFinanceAccountClosure('vendor_account', $row); ?>
                                <?php if ($canSettleBalances): ?><a href="../../modules/vendors/wallet.php?id=<?= $row['id']; ?>#set-balance" class="btn btn-sm btn-outline-warning"><i class="fas fa-scale-balanced"></i> Set Balance</a><?php endif; ?>
                        </td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>

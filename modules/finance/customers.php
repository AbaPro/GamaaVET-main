<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

$canSettleBalances = hasPermission('finance.balances.settle');
if (!hasPermission('finance.customer_wallet.view') && !$canSettleBalances) {
    setAlert('danger', 'You do not have permission to access this page.');
    redirect('../../dashboard.php');
}

$page_title = 'Customer Accounts';
$canViewPhoneNumbers = hasPermission('contacts.phone.view');
require_once '../../includes/header.php';

$sql = "SELECT c.*,
               COALESCE(order_totals.outstanding_orders, 0) AS outstanding_orders,
               GREATEST(
                   COALESCE(order_totals.outstanding_orders, 0),
                   GREATEST(-COALESCE(c.wallet_balance, 0), 0)
               ) AS receivable
        FROM customers c
        LEFT JOIN factories f ON f.id = c.factory_id
        LEFT JOIN (
            SELECT customer_id,
                   SUM(GREATEST(total_amount - paid_amount, 0)) AS outstanding_orders
            FROM orders
            GROUP BY customer_id
        ) order_totals ON order_totals.customer_id = c.id
        WHERE " . getCustomerChannelScopeSql('c', 'f') . "
        ORDER BY c.name";
$result = $conn->query($sql);
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Customer Accounts</h2>
</div>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table js-datatable table-hover mb-0">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Email</th>
                        <?php if ($canViewPhoneNumbers): ?>
                            <th>Phone</th>
                        <?php endif; ?>
                        <th>Wallet / Manual Balance</th>
                        <th>Outstanding Orders</th>
                        <th>Accounts Receivable</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($row = $result->fetch_assoc()): ?>
                        <tr>
                            <td><?= $row['id']; ?></td>
                            <td><?= htmlspecialchars($row['name']); ?></td>
                            <td><?= htmlspecialchars($row['email']); ?></td>
                            <?php if ($canViewPhoneNumbers): ?>
                                <td><?= htmlspecialchars($row['phone']); ?></td>
                            <?php endif; ?>
                            <td class="<?= (float)$row['wallet_balance'] < 0 ? 'text-danger' : 'text-success'; ?>">
                                <?= number_format($row['wallet_balance'], 2); ?>
                            </td>
                            <td class="<?= (float)$row['outstanding_orders'] > 0 ? 'text-danger' : ''; ?>">
                                <?= number_format($row['outstanding_orders'], 2); ?>
                            </td>
                            <td class="fw-bold <?= (float)$row['receivable'] > 0 ? 'text-danger' : 'text-success'; ?>">
                                <?= number_format($row['receivable'], 2); ?>
                            </td>
                            <td>
                                <a href="../../modules/customers/wallet.php?id=<?= $row['id']; ?>" class="btn btn-sm btn-primary">
                                    <i class="fas fa-wallet"></i> View Account
                                </a>
                                <?php if ($canSettleBalances): ?><a href="../../modules/customers/wallet.php?id=<?= $row['id']; ?>#set-balance" class="btn btn-sm btn-outline-warning"><i class="fas fa-scale-balanced"></i> Set Balance</a><?php endif; ?>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>

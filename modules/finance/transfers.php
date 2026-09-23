<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once 'transfer_helpers.php';

$canCreateTransfers = hasPermission('finance.transfers.create');
$canApproveTransfers = hasPermission('finance.transfers.approve');
if (!$canCreateTransfers && !$canApproveTransfers) {
    setAlert('danger', 'Access denied.');
    redirect('../../dashboard.php');
}

$accountTypes = ['safe', 'bank', 'personal'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canCreateTransfers) {
        setAlert('danger', 'You do not have permission to create finance transfers.');
        redirect('transfers.php');
    }

    $fromType = (string)($_POST['from_type'] ?? '');
    $fromId = (int)($_POST['from_id'] ?? 0);
    $toType = (string)($_POST['to_type'] ?? '');
    $toId = (int)($_POST['to_id'] ?? 0);
    $amount = round((float)($_POST['amount'] ?? 0), 2);
    $transactionDate = normalizeTransactionDate($_POST['transaction_date'] ?? '');
    $reason = trim(strip_tags((string)($_POST['reason'] ?? '')));
    $notes = trim(strip_tags((string)($_POST['notes'] ?? '')));
    $purchaseOrderId = !empty($_POST['purchase_order_id']) ? (int)$_POST['purchase_order_id'] : null;
    $ticketId = !empty($_POST['ticket_id']) ? (int)$_POST['ticket_id'] : null;
    $assignedApproverId = (int)($_POST['assigned_approver_id'] ?? 0);
    $createdBy = (int)($_SESSION['user_id'] ?? 0);
    $uploadedImages = [];

    if (($_SESSION['login_region'] ?? 'factory') !== 'factory') {
        $purchaseOrderId = null;
        $ticketId = null;
    }

    if (!in_array($fromType, $accountTypes, true) || !in_array($toType, $accountTypes, true)) {
        setAlert('danger', 'Select valid sender and receiver account types.');
        redirect('transfers.php');
    }
    if ($transactionDate === null) {
        setAlert('danger', 'Enter a valid transaction date.');
        redirect('transfers.php');
    }
    $fromAccount = $fromId > 0 ? financeTransferGetAccount($fromType, $fromId) : null;
    $toAccount = $toId > 0 ? financeTransferGetAccount($toType, $toId) : null;
    if (!$fromAccount) {
        setAlert('danger', 'Select a valid sender account.');
        redirect('transfers.php');
    }
    if (!$toAccount) {
        setAlert('danger', 'Select a valid receiver account.');
        redirect('transfers.php');
    }
    if ($fromType === $toType && $fromId === $toId) {
        setAlert('danger', 'Sender and receiver accounts must be different.');
        redirect('transfers.php');
    }
    if (($fromAccount['currency'] ?? 'EGP') !== ($toAccount['currency'] ?? 'EGP')) {
        setAlert('danger', 'Sender and receiver must use the same currency. Exchange transactions require separate reconciliation entries.');
        redirect('transfers.php');
    }
    $transferCurrency = $fromAccount['currency'] ?? 'EGP';
    if ($amount <= 0) {
        setAlert('danger', 'Transfer amount must be greater than zero.');
        redirect('transfers.php');
    }
    if ($reason === '') {
        setAlert('danger', 'Reason for transfer is required.');
        redirect('transfers.php');
    }
    $loginRegion = $_SESSION['login_region'] ?? 'factory';
    $approverCanAccessRegion = $loginRegion === 'factory'
        || userHasPermissionKey($assignedApproverId, 'region.' . $loginRegion);
    if ($assignedApproverId <= 0
        || !userHasPermissionKey($assignedApproverId, 'finance.transfers.approve')
        || !$approverCanAccessRegion) {
        setAlert('danger', 'Select a user who is allowed to approve finance transfers.');
        redirect('transfers.php');
    }
    if ($assignedApproverId === $createdBy && !isAdminUser()) {
        setAlert('danger', 'The requester cannot approve their own transfer. Select another approver.');
        redirect('transfers.php');
    }
    if ($purchaseOrderId) {
        $poStmt = $conn->prepare('SELECT id FROM purchase_orders WHERE id = ?');
        $poStmt->bind_param('i', $purchaseOrderId);
        $poStmt->execute();
        $validPo = $poStmt->get_result()->num_rows === 1;
        $poStmt->close();
        if (!$validPo) {
            setAlert('danger', 'Selected purchase order was not found.');
            redirect('transfers.php');
        }
    }
    if ($ticketId) {
        $ticketStmt = $conn->prepare('SELECT id FROM tickets WHERE id = ?');
        $ticketStmt->bind_param('i', $ticketId);
        $ticketStmt->execute();
        $validTicket = $ticketStmt->get_result()->num_rows === 1;
        $ticketStmt->close();
        if (!$validTicket) {
            setAlert('danger', 'Selected ticket was not found.');
            redirect('transfers.php');
        }
    }

    $reference = 'FT-' . date('Ymd', strtotime($transactionDate)) . '-' . strtoupper(generateRandomString(6));
    $conn->begin_transaction();

    try {
        $imageError = null;
        $uploadedImages = uploadImageAttachments(
            'transfer_image',
            'assets/uploads/finance_transfers',
            'finance_transfer_' . $reference,
            1,
            $imageError
        );
        if ($imageError !== null) {
            throw new Exception($imageError);
        }

        $stmt = $conn->prepare("
            INSERT INTO finance_transfers
                (transfer_reference, from_type, from_id, to_type, to_id, amount,
                 transaction_date, status, reason, notes, purchase_order_id, ticket_id,
                 assigned_approver_id, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param(
            'ssisidsssiiii',
            $reference,
            $fromType,
            $fromId,
            $toType,
            $toId,
            $amount,
            $transactionDate,
            $reason,
            $notes,
            $purchaseOrderId,
            $ticketId,
            $assignedApproverId,
            $createdBy
        );
        if (!$stmt->execute()) {
            throw new Exception('Unable to create transfer: ' . $stmt->error);
        }
        $transferId = $stmt->insert_id;
        $stmt->close();

        $imageStmt = $conn->prepare("
            INSERT INTO finance_transfer_images
                (finance_transfer_id, file_path, original_name, created_by)
            VALUES (?, ?, ?, ?)
        ");
        foreach ($uploadedImages as $file) {
            $imageStmt->bind_param('issi', $transferId, $file['path'], $file['original_name'], $createdBy);
            if (!$imageStmt->execute()) {
                throw new Exception('Unable to save a transfer image.');
            }
        }
        $imageStmt->close();

        logFinanceTransferHistory($transferId, 'created', 'Transfer requested and assigned for approval', $createdBy);
        $conn->commit();

        createNotification(
            'finance_transfer_approval',
            'Finance transfer awaiting your approval',
            $reference . ' for ' . number_format($amount, 2) . ' ' . $transferCurrency . ' requires approval.',
            'finance',
            'finance_transfer',
            $transferId,
            'warning',
            null,
            $assignedApproverId,
            $createdBy
        );
        logActivity('Created pending finance transfer', [
            'finance_transfer_id' => $transferId,
            'reference' => $reference,
            'amount' => $amount,
            'assigned_approver_id' => $assignedApproverId,
        ], 'create', 'finance_transfer', $transferId);
        setAlert('success', 'Transfer submitted for approval. No balances have moved yet. Reference: ' . $reference);
    } catch (Throwable $e) {
        $conn->rollback();
        foreach ($uploadedImages as $file) {
            $fullPath = ROOT_PATH . '/' . $file['path'];
            if (is_file($fullPath)) unlink($fullPath);
        }
        setAlert('danger', $e->getMessage());
    }

    redirect('transfers.php');
}

$transferScope = financeTransferScopeSql('f');
$transferSql = financeTransferSelectSql() . " WHERE $transferScope ORDER BY f.transaction_date DESC, f.created_at DESC, f.id DESC";
$result = $conn->query($transferSql);

$transferImages = [];
$imageResult = $conn->query("
    SELECT finance_transfer_id, file_path, original_name
    FROM finance_transfer_images
    ORDER BY created_at, id
");
if ($imageResult) {
    while ($image = $imageResult->fetch_assoc()) {
        $transferImages[(int)$image['finance_transfer_id']][] = $image;
    }
}

$safeAccounts = [];
$accountScope = getAccountScopeSql();
$safeResult = $conn->query("SELECT id, name, balance, currency FROM safes WHERE $accountScope ORDER BY name");
while ($row = $safeResult->fetch_assoc()) {
    $safeAccounts[] = ['id' => (int)$row['id'], 'label' => $row['name'] . ' — ' . number_format((float)$row['balance'], 2) . ' ' . ($row['currency'] ?: 'EGP')];
}

$bankAccounts = [];
$bankResult = $conn->query("SELECT id, bank_name, account_number, balance, currency FROM bank_accounts WHERE $accountScope ORDER BY bank_name");
while ($row = $bankResult->fetch_assoc()) {
    $bankAccounts[] = [
        'id' => (int)$row['id'],
        'label' => $row['bank_name'] . ' (#' . $row['account_number'] . ') — ' . number_format((float)$row['balance'], 2) . ' ' . ($row['currency'] ?: 'EGP'),
    ];
}

$personalAccounts = [];
$personalResult = $conn->query("SELECT id, name, email, balance FROM personal_accounts WHERE is_active = 1 AND $accountScope ORDER BY name");
while ($row = $personalResult->fetch_assoc()) {
    $personalAccounts[] = [
        'id' => (int)$row['id'],
        'label' => $row['name'] . ($row['email'] ? ' (' . $row['email'] . ')' : '') . ' — ' . number_format((float)$row['balance'], 2),
    ];
}

$accountOptions = ['safe' => $safeAccounts, 'bank' => $bankAccounts, 'personal' => $personalAccounts];
$approvers = getUsersWithPermission('finance.transfers.approve');
$loginRegion = $_SESSION['login_region'] ?? 'factory';
if ($loginRegion !== 'factory') {
    $regionPermission = 'region.' . $loginRegion;
    $approvers = array_values(array_filter($approvers, static function ($approver) use ($regionPermission) {
        return userHasPermissionKey((int)$approver['id'], $regionPermission);
    }));
}
$purchaseOrders = $loginRegion === 'factory' ? $conn->query("
    SELECT po.id, po.status, v.name AS vendor_name
    FROM purchase_orders po
    JOIN vendors v ON v.id = po.vendor_id
    ORDER BY po.created_at DESC
")->fetch_all(MYSQLI_ASSOC) : [];
$tickets = $loginRegion === 'factory'
    ? $conn->query("SELECT id, title, status FROM tickets ORDER BY created_at DESC")->fetch_all(MYSQLI_ASSOC)
    : [];

$statusColors = ['pending' => 'warning text-dark', 'approved' => 'success', 'rejected' => 'danger', 'reversed' => 'secondary'];
$currentUserId = (int)($_SESSION['user_id'] ?? 0);
$page_title = 'Finance Transfers';
require_once '../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1">Finance Transfers</h2>
        <div class="text-muted">Every movement is documented and approved before balances change.</div>
    </div>
    <?php if ($canCreateTransfers): ?>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#transferModal">
            <i class="fas fa-right-left me-1"></i>New Transfer
        </button>
    <?php endif; ?>
</div>

<?php displayAlert(); ?>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table js-datatable table-hover align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Reference</th><th>Sender</th><th>Receiver</th><th>Amount</th><th>Status</th>
                        <th>Reason</th><th>PO / Ticket</th><th>Requested By</th><th>Approved By</th>
                        <th>Images</th><th>Date</th><th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($result && $row = $result->fetch_assoc()): ?>
                        <?php
                        $status = $row['status'] ?: 'approved';
                        $fromUrl = financeTransferAccountUrl($row['from_type'], $row['from_id']);
                        $toUrl = financeTransferAccountUrl($row['to_type'], $row['to_id']);
                        $approvalDisplay = $row['approved_by_name'] ?: (
                            $status === 'pending' && $row['assigned_approver_name']
                                ? 'Waiting: ' . $row['assigned_approver_name']
                                : '-'
                        );
                        $mayApprove = $status === 'pending' && $canApproveTransfers
                            && ((int)$row['assigned_approver_id'] === $currentUserId || isAdminUser())
                            && ((int)$row['created_by'] !== $currentUserId || isAdminUser());
                        ?>
                        <tr>
                            <td class="fw-semibold"><?= e($row['transfer_reference']); ?></td>
                            <td>
                                <span class="badge bg-secondary"><?= e(financeTransferTypeLabel($row['from_type'])); ?></span>
                                <div>
                                    <?php if ($fromUrl): ?><a href="<?= e($fromUrl); ?>"><?= e(financeTransferAccountName($row, 'from')); ?></a>
                                    <?php else: ?><?= e(financeTransferAccountName($row, 'from')); ?><?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <span class="badge bg-secondary"><?= e(financeTransferTypeLabel($row['to_type'])); ?></span>
                                <div>
                                    <?php if ($toUrl): ?><a href="<?= e($toUrl); ?>"><?= e(financeTransferAccountName($row, 'to')); ?></a>
                                    <?php else: ?><?= e(financeTransferAccountName($row, 'to')); ?><?php endif; ?>
                                </div>
                            </td>
                            <td class="fw-bold"><?= e(formatCurrency((float)$row['amount'], $row['currency'] ?? 'EGP')); ?></td>
                            <td><span class="badge bg-<?= $statusColors[$status] ?? 'secondary'; ?>"><?= e(ucfirst($status)); ?></span></td>
                            <td><?= e($row['reason'] ?: $row['notes']); ?></td>
                            <td>
                                <?php if ($loginRegion === 'factory'): ?>
                                <?php if ($row['purchase_order_id']): ?>
                                    <a href="../purchases/po_details.php?id=<?= (int)$row['purchase_order_id']; ?>">PO #<?= (int)$row['purchase_order_id']; ?></a>
                                <?php endif; ?>
                                <?php if ($row['ticket_id']): ?>
                                    <div><a href="../tickets/view.php?id=<?= (int)$row['ticket_id']; ?>">Ticket #<?= (int)$row['ticket_id']; ?></a></div>
                                <?php endif; ?>
                                <?php if (!$row['purchase_order_id'] && !$row['ticket_id']): ?><span class="text-muted">-</span><?php endif; ?>
                                <?php else: ?><span class="text-muted">-</span><?php endif; ?>
                            </td>
                            <td><?= e($row['requested_by_name'] ?: 'System'); ?></td>
                            <td><?= e($approvalDisplay); ?></td>
                            <td><?= renderAttachmentThumbnails($transferImages[(int)$row['id']] ?? []); ?></td>
                            <td><?= date('M d, Y', strtotime($row['transaction_date'])); ?></td>
                            <td>
                                <a href="transfer_details.php?id=<?= (int)$row['id']; ?>" class="btn btn-sm btn-outline-info mb-1">Details</a>
                                <?php if ($mayApprove): ?>
                                    <form action="approve_transfer.php" method="post" class="d-inline" onsubmit="return confirm('Approve this transfer and move the money now?');">
                                        <input type="hidden" name="id" value="<?= (int)$row['id']; ?>">
                                        <button class="btn btn-sm btn-success mb-1">Approve</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if ($canCreateTransfers): ?>
<div class="modal fade" id="transferModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <form method="post" enctype="multipart/form-data" id="financeTransferForm">
                <div class="modal-header">
                    <h5 class="modal-title">New Finance Transfer</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">Sender Type <span class="text-danger">*</span></label>
                            <select class="form-select js-account-type" name="from_type" data-target="from_id" required>
                                <option value="safe">Safe</option><option value="bank">Bank</option><option value="personal">Personal</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Sender Account <span class="text-danger">*</span></label>
                            <select class="form-select" name="from_id" id="from_id" required></select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Receiver Type <span class="text-danger">*</span></label>
                            <select class="form-select js-account-type" name="to_type" data-target="to_id" required>
                                <option value="safe">Safe</option><option value="bank">Bank</option><option value="personal">Personal</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Receiver Account <span class="text-danger">*</span></label>
                            <select class="form-select" name="to_id" id="to_id" required></select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Amount <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="amount" min="0.01" step="0.01" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Transaction Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="transaction_date" value="<?= date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Approver <span class="text-danger">*</span></label>
                            <select class="form-select" name="assigned_approver_id" required>
                                <option value="">-- Select Approver --</option>
                                <?php foreach ($approvers as $approver): ?>
                                    <option value="<?= (int)$approver['id']; ?>"><?= e($approver['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php if ($loginRegion === 'factory'): ?><div class="col-md-3">
                            <label class="form-label">Link Purchase Order</label>
                            <select class="form-select js-searchable-select" name="purchase_order_id">
                                <option value="">-- No PO --</option>
                                <?php foreach ($purchaseOrders as $po): ?>
                                    <option value="<?= (int)$po['id']; ?>">PO #<?= (int)$po['id']; ?> — <?= e($po['vendor_name']); ?> (<?= e($po['status']); ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Link Ticket</label>
                            <select class="form-select js-searchable-select" name="ticket_id">
                                <option value="">-- No Ticket --</option>
                                <?php foreach ($tickets as $ticket): ?>
                                    <option value="<?= (int)$ticket['id']; ?>">#<?= (int)$ticket['id']; ?> — <?= e($ticket['title']); ?> (<?= e($ticket['status']); ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div><?php endif; ?>
                        <div class="col-md-6">
                            <label class="form-label">Reason for Transfer <span class="text-danger">*</span></label>
                            <textarea class="form-control" name="reason" rows="3" maxlength="2000" required></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Additional Notes</label>
                            <textarea class="form-control" name="notes" rows="3" maxlength="2000"></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Transfer Images / Receipts <span class="text-danger">*</span></label>
                            <input type="file" class="form-control" name="transfer_image[]" accept="image/jpeg,image/png,image/gif,image/webp" multiple required>
                            <div class="form-text">At least one image is required. JPG, PNG, GIF or WEBP; maximum 5MB each.</div>
                        </div>
                    </div>
                    <div class="alert alert-warning mt-3 mb-0">
                        Submitting creates a pending request only. Balances move atomically when the assigned approver approves it.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary">Submit for Approval</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const accountOptions = <?= json_encode($accountOptions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

    function populateAccountSelect(typeSelect) {
        const target = document.getElementById(typeSelect.dataset.target);
        if (!target) return;
        target.innerHTML = '<option value="">-- Select Account --</option>';
        (accountOptions[typeSelect.value] || []).forEach(function (account) {
            const option = document.createElement('option');
            option.value = account.id;
            option.textContent = account.label;
            target.appendChild(option);
        });
    }

    document.querySelectorAll('.js-account-type').forEach(function (select) {
        select.addEventListener('change', function () { populateAccountSelect(select); });
        populateAccountSelect(select);
    });

    if (window.jQuery && jQuery.fn.select2) {
        jQuery('.js-searchable-select').select2({theme: 'bootstrap-5', dropdownParent: jQuery('#transferModal')});
    }
});
</script>

<?php require_once '../../includes/footer.php'; ?>

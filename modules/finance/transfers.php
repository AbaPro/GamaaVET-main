<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

if (!hasPermission('finance.transfers.create')) {
    setAlert('danger', 'Access denied.');
    redirect('../../dashboard.php');
}

$page_title = 'Finance Transfers';
require_once '../../includes/header.php';

$financeAccountTypes = [
    'safe' => 'Safe',
    'bank' => 'Bank',
    'personal' => 'Personal'
];

function financeTransferEscape($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function financeTransferTypeLabel($type) {
    global $financeAccountTypes;
    return $financeAccountTypes[$type] ?? ucfirst((string)$type);
}

function financeTransferAccountName($row, $side) {
    $type = $row[$side . '_type'] ?? '';
    $id = (int)($row[$side . '_id'] ?? 0);

    if ($type === 'safe' && !empty($row[$side . '_safe_name'])) {
        return $row[$side . '_safe_name'];
    }

    if ($type === 'bank' && !empty($row[$side . '_bank_name'])) {
        return $row[$side . '_bank_name'];
    }

    if ($type === 'personal') {
        if ($id === 0) {
            return 'External / Customer Payment';
        }
        if (!empty($row[$side . '_personal_name'])) {
            return $row[$side . '_personal_name'];
        }
    }

    return financeTransferTypeLabel($type) . ' #' . $id;
}

function financeTransferAccountMeta($row, $side) {
    $type = $row[$side . '_type'] ?? '';
    $id = (int)($row[$side . '_id'] ?? 0);

    if ($type === 'bank' && !empty($row[$side . '_bank_account_number'])) {
        return 'Account #' . $row[$side . '_bank_account_number'];
    }

    if ($type === 'personal' && !empty($row[$side . '_personal_email'])) {
        return $row[$side . '_personal_email'];
    }

    if ($id > 0) {
        return financeTransferTypeLabel($type) . ' #' . $id;
    }

    return financeTransferTypeLabel($type);
}

// Handle new transfer
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $from_type = $_POST['from_type'];
    $from_id = intval($_POST['from_id']);
    $to_type = $_POST['to_type'];
    $to_id = intval($_POST['to_id']);
    $amount = floatval($_POST['amount']);
    $notes = sanitize($_POST['notes']);
    $uid = $_SESSION['user_id'];

    $transferImageError = null;
    $uploadedTransferImages = uploadImageAttachments(
        'transfer_image',
        'assets/uploads/finance_transfers',
        'finance_transfer_' . date('Ymd_His'),
        0, // optional
        $transferImageError
    );

    if ($transferImageError !== null) {
        setAlert('danger', $transferImageError);
        redirect('transfers.php');
    }

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("INSERT INTO finance_transfers (from_type, from_id, to_type, to_id, amount, notes, created_by) VALUES (?,?,?,?,?,?,?)");
        if (!$stmt) {
            throw new Exception('Error preparing transfer: ' . $conn->error);
        }
        $stmt->bind_param("sisidsi", $from_type, $from_id, $to_type, $to_id, $amount, $notes, $uid);
        if (!$stmt->execute()) {
            throw new Exception('Error recording transfer: ' . $stmt->error);
        }
        $transfer_id = $stmt->insert_id;
        $stmt->close();

        if (!empty($uploadedTransferImages)) {
            $imgStmt = $conn->prepare("INSERT INTO finance_transfer_images (finance_transfer_id, file_path, original_name, created_by) VALUES (?, ?, ?, ?)");
            foreach ($uploadedTransferImages as $file) {
                $imgStmt->bind_param("issi", $transfer_id, $file['path'], $file['original_name'], $uid);
                $imgStmt->execute();
            }
            $imgStmt->close();
        }

        $conn->commit();
        setAlert('success', 'Transfer recorded.');
        redirect('transfers.php');
    } catch (Exception $e) {
        $conn->rollback();
        foreach ($uploadedTransferImages as $file) {
            $full = ROOT_PATH . '/' . $file['path'];
            if (is_file($full)) {
                unlink($full);
            }
        }
        setAlert('danger', $e->getMessage());
        redirect('transfers.php');
    }
}

$result = $conn->query("
    SELECT f.*,
           creator.name AS created_by_name,
           from_safe.name AS from_safe_name,
           from_bank.bank_name AS from_bank_name,
           from_bank.account_number AS from_bank_account_number,
           from_personal.name AS from_personal_name,
           from_personal.email AS from_personal_email,
           to_safe.name AS to_safe_name,
           to_bank.bank_name AS to_bank_name,
           to_bank.account_number AS to_bank_account_number,
           to_personal.name AS to_personal_name,
           to_personal.email AS to_personal_email
    FROM finance_transfers f
    LEFT JOIN users creator ON f.created_by = creator.id
    LEFT JOIN safes from_safe ON f.from_type = 'safe' AND f.from_id = from_safe.id
    LEFT JOIN bank_accounts from_bank ON f.from_type = 'bank' AND f.from_id = from_bank.id
    LEFT JOIN users from_personal ON f.from_type = 'personal' AND f.from_id = from_personal.id
    LEFT JOIN safes to_safe ON f.to_type = 'safe' AND f.to_id = to_safe.id
    LEFT JOIN bank_accounts to_bank ON f.to_type = 'bank' AND f.to_id = to_bank.id
    LEFT JOIN users to_personal ON f.to_type = 'personal' AND f.to_id = to_personal.id
    ORDER BY f.created_at DESC
");

$financeTransferImagesByTransfer = [];
$ftiRes = $conn->query("SELECT finance_transfer_id, file_path, original_name FROM finance_transfer_images ORDER BY created_at ASC");
if ($ftiRes) {
    while ($ftiRow = $ftiRes->fetch_assoc()) {
        $financeTransferImagesByTransfer[$ftiRow['finance_transfer_id']][] = $ftiRow;
    }
}

$bankAccounts = [];
$bankRes = $conn->query("SELECT id, bank_name, account_number, balance FROM bank_accounts ORDER BY bank_name");
if ($bankRes) {
    while ($row = $bankRes->fetch_assoc()) {
        $bankAccounts[] = [
            'id' => $row['id'],
            'label' => $row['bank_name'] . ' (#' . $row['account_number'] . ') - Balance: ' . number_format((float)$row['balance'], 2)
        ];
    }
}

$safeAccounts = [];
$safeRes = $conn->query("SELECT id, name, balance FROM safes ORDER BY name");
if ($safeRes) {
    while ($row = $safeRes->fetch_assoc()) {
        $safeAccounts[] = [
            'id' => $row['id'],
            'label' => $row['name'] . ' - Balance: ' . number_format((float)$row['balance'], 2)
        ];
    }
}

$personalAccounts = [];
$personalRes = $conn->query("SELECT id, name, email, personal_balance FROM users WHERE role != 'admin' ORDER BY name");
if ($personalRes) {
    while ($row = $personalRes->fetch_assoc()) {
        $personalAccounts[] = [
            'id' => $row['id'],
            'label' => $row['name'] . ' (' . $row['email'] . ') - Balance: ' . number_format((float)$row['personal_balance'], 2)
        ];
    }
}

$accountOptions = [
    'bank' => $bankAccounts,
    'safe' => $safeAccounts,
    'personal' => $personalAccounts
];
?>

<div class="d-flex justify-content-between mb-4">
    <h2>Finance Transfers</h2>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#transferModal"><i class="fas fa-exchange-alt"></i> New Transfer</button>
</div>

<div class="card">
    <div class="card-body">
        <table class="table js-datatable table-bordered">
            <thead><tr><th>ID</th><th>From</th><th>To</th><th>Amount</th><th>Image</th><th>Notes</th><th>Recorded By</th><th>Date</th></tr></thead>
            <tbody>
                <?php while($result && $row=$result->fetch_assoc()): ?>
                    <tr>
                        <td><?= $row['id']; ?></td>
                        <td>
                            <span class="badge bg-secondary mb-1"><?= financeTransferEscape(financeTransferTypeLabel($row['from_type'])); ?></span>
                            <div class="fw-semibold"><?= financeTransferEscape(financeTransferAccountName($row, 'from')); ?></div>
                            <div class="text-muted small"><?= financeTransferEscape(financeTransferAccountMeta($row, 'from')); ?></div>
                        </td>
                        <td>
                            <span class="badge bg-secondary mb-1"><?= financeTransferEscape(financeTransferTypeLabel($row['to_type'])); ?></span>
                            <div class="fw-semibold"><?= financeTransferEscape(financeTransferAccountName($row, 'to')); ?></div>
                            <div class="text-muted small"><?= financeTransferEscape(financeTransferAccountMeta($row, 'to')); ?></div>
                        </td>
                        <td><?= number_format($row['amount'],2); ?></td>
                        <td>
                            <?php echo renderAttachmentThumbnails($financeTransferImagesByTransfer[$row['id']] ?? []); ?>
                        </td>
                        <td>
                            <?php if (!empty($row['notes'])): ?>
                                <?= nl2br(financeTransferEscape($row['notes'])); ?>
                            <?php else: ?>
                                <span class="text-muted">No notes</span>
                            <?php endif; ?>
                        </td>
                        <td><?= financeTransferEscape($row['created_by_name'] ?? 'System'); ?></td>
                        <td><?= date('M d, Y H:i', strtotime($row['created_at'])); ?></td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="transferModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" enctype="multipart/form-data">
        <div class="modal-header"><h5 class="modal-title">New Transfer</h5></div>
        <div class="modal-body">
          <div class="mb-3"><label>From Type</label>
            <select name="from_type" class="form-select" required>
              <option value="safe">Safe</option>
              <option value="bank">Bank</option>
              <option value="personal">Personal</option>
            </select>
          </div>
          <div class="mb-3"><label>From Account</label>
            <select class="form-select" name="from_id" id="from_account_id" required></select>
          </div>
          <div class="mb-3"><label>To Type</label>
            <select name="to_type" class="form-select" required>
              <option value="safe">Safe</option>
              <option value="bank">Bank</option>
              <option value="personal">Personal</option>
            </select>
          </div>
          <div class="mb-3"><label>To Account</label>
            <select class="form-select" name="to_id" id="to_account_id" required></select>
          </div>
          <div class="mb-3"><label>Amount</label>
            <input type="number" step="0.01" class="form-control" name="amount" required>
          </div>
          <div class="mb-3">
            <label for="transfer_image" class="form-label">Transfer Image</label>
            <input type="file" class="form-control" id="transfer_image" name="transfer_image[]" accept="image/jpeg,image/png,image/gif,image/webp" multiple>
            <small class="text-muted">Optional receipt or transfer screenshot. JPG, PNG, GIF, WEBP, max 5MB.</small>
          </div>
          <div class="mb-3"><label>Notes</label>
            <textarea class="form-control" name="notes"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
          <button type="submit" class="btn btn-primary">Save</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const accountOptions = <?= json_encode($accountOptions, JSON_UNESCAPED_UNICODE); ?>;

    function renderAccounts(prefix) {
        const typeSelect = document.querySelector(`select[name="${prefix}_type"]`);
        const accountSelect = document.getElementById(`${prefix}_account_id`);
        const type = typeSelect.value;
        const list = accountOptions[type] || [];

        accountSelect.innerHTML = '';

        if (!list.length) {
            const option = document.createElement('option');
            option.value = '';
            option.textContent = 'No accounts found';
            accountSelect.appendChild(option);
            accountSelect.disabled = true;
            return;
        }

        list.forEach(item => {
            const option = document.createElement('option');
            option.value = item.id;
            option.textContent = item.label;
            accountSelect.appendChild(option);
        });
        accountSelect.disabled = false;
    }

    ['from', 'to'].forEach(prefix => {
        const typeSelect = document.querySelector(`select[name="${prefix}_type"]`);
        typeSelect.addEventListener('change', () => renderAccounts(prefix));
        renderAccounts(prefix);
    });
});
</script>

<?php require_once '../../includes/footer.php'; ?>

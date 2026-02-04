<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

if (!hasPermission('finance.transfers.create')) {
    setAlert('danger', 'Access denied.');
    redirect('../../dashboard.php');
}

$page_title = 'Finance Transfers';
require_once '../../includes/header.php';

// Handle new transfer
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $from_type = $_POST['from_type'];
    $from_id = intval($_POST['from_id']);
    $to_type = $_POST['to_type'];
    $to_id = intval($_POST['to_id']);
    $amount = floatval($_POST['amount']);
    $notes = sanitize($_POST['notes']);
    $uid = $_SESSION['user_id'];

    $stmt = $conn->prepare("INSERT INTO finance_transfers (from_type, from_id, to_type, to_id, amount, notes, created_by) VALUES (?,?,?,?,?,?,?)");
    $stmt->bind_param("sisiisi",$from_type,$from_id,$to_type,$to_id,$amount,$notes,$uid);
    $stmt->execute();
    setAlert('success','Transfer recorded.');
    redirect('transfers.php');
}

$result = $conn->query("SELECT f.*, u.name as user FROM finance_transfers f JOIN users u ON f.created_by=u.id ORDER BY f.created_at DESC");

$bankAccounts = [];
$bankRes = $conn->query("SELECT id, bank_name, account_number FROM bank_accounts ORDER BY bank_name");
if ($bankRes) {
    while ($row = $bankRes->fetch_assoc()) {
        $bankAccounts[] = [
            'id' => $row['id'],
            'label' => $row['bank_name'] . ' (#' . $row['account_number'] . ')'
        ];
    }
}

$safeAccounts = [];
$safeRes = $conn->query("SELECT id, name FROM safes ORDER BY name");
if ($safeRes) {
    while ($row = $safeRes->fetch_assoc()) {
        $safeAccounts[] = [
            'id' => $row['id'],
            'label' => $row['name']
        ];
    }
}

$personalAccounts = [];
$personalRes = $conn->query("SELECT id, name FROM users WHERE role != 'admin' ORDER BY name");
if ($personalRes) {
    while ($row = $personalRes->fetch_assoc()) {
        $personalAccounts[] = [
            'id' => $row['id'],
            'label' => $row['name']
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
            <thead><tr><th>ID</th><th>From</th><th>To</th><th>Amount</th><th>Notes</th><th>By</th><th>Date</th></tr></thead>
            <tbody>
                <?php while($row=$result->fetch_assoc()): ?>
                    <tr>
                        <td><?= $row['id']; ?></td>
                        <td><?= $row['from_type']." #".$row['from_id']; ?></td>
                        <td><?= $row['to_type']." #".$row['to_id']; ?></td>
                        <td><?= number_format($row['amount'],2); ?></td>
                        <td><?= htmlspecialchars($row['notes']); ?></td>
                        <td><?= $row['user']; ?></td>
                        <td><?= $row['created_at']; ?></td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="transferModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
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
          <div class="mb-3"><label>Notes</label>
            <textarea class="form-control" name="notes"></textarea>
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

<script>
document.addEventListener('DOMContentLoaded', function() {
    const accountOptions = <?= json_encode($accountOptions, JSON_UNESCAPED_UNICODE); ?>;

    function renderAccounts(prefix) {
        const typeSelect = document.querySelector(`select[name="${prefix}_type"]`);
        const accountSelect = document.getElementById(`${prefix}_account_id`);
        const type = typeSelect.value;
        const list = accountOptions[type] || [];

        if (!list.length) {
            accountSelect.innerHTML = '<option value="">No accounts found</option>';
            accountSelect.disabled = true;
            return;
        }

        let options = '';
        list.forEach(item => {
            options += `<option value="${item.id}">${item.label}</option>`;
        });
        accountSelect.innerHTML = options;
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

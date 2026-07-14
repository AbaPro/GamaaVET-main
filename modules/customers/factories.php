<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

if (!hasPermission('customers.factories.manage')) {
    setAlert('danger', 'Access denied.');
    redirect('../../dashboard.php');
}

$isAdmin = isAdminUser();
$isSalesPerson = isSalesPersonUser();
$salesPersons = $isAdmin ? getActiveSalesPersons() : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'create';
    $name = sanitize($_POST['name']);
    $contact_person = !empty($_POST['contact_person']) ? sanitize($_POST['contact_person']) : null;
    $contact_phone = !empty($_POST['contact_phone']) ? sanitize($_POST['contact_phone']) : null;
    $whatsapp_number = !empty($_POST['whatsapp_number']) ? sanitize($_POST['whatsapp_number']) : null;
    $notes = !empty($_POST['notes']) ? sanitize($_POST['notes']) : null;
    $salesPersonId = null;
    if ($isAdmin) {
        $salesPersonId = !empty($_POST['sales_person_id']) ? (int)$_POST['sales_person_id'] : null;
        if ($salesPersonId !== null && !isValidSalesPersonId($salesPersonId)) {
            setAlert('danger', 'Please select a valid active sales person.');
            redirect('factories.php');
        }
    } elseif ($isSalesPerson) {
        $salesPersonId = (int)$_SESSION['user_id'];
    }

    if ($action === 'update') {
        $id = (int)$_POST['id'];
        if (!canAccessFactory($id)) {
            setAlert('danger', 'You do not have permission to edit this factory.');
            redirect('factories.php');
        }

        $conn->begin_transaction();
        try {
            if ($isAdmin || $isSalesPerson) {
                $stmt = $conn->prepare("UPDATE factories SET name=?, sales_person_id=?, contact_person=?, contact_phone=?, whatsapp_number=?, notes=? WHERE id=?");
                $stmt->bind_param("sissssi", $name, $salesPersonId, $contact_person, $contact_phone, $whatsapp_number, $notes, $id);
            } else {
                $stmt = $conn->prepare("UPDATE factories SET name=?, contact_person=?, contact_phone=?, whatsapp_number=?, notes=? WHERE id=?");
                $stmt->bind_param("sssssi", $name, $contact_person, $contact_phone, $whatsapp_number, $notes, $id);
            }
            if (!$stmt->execute()) {
                throw new Exception($stmt->error);
            }
            $stmt->close();

            if ($isAdmin && !empty($_POST['reassign_customers'])) {
                $customerStmt = $conn->prepare("UPDATE customers SET sales_person_id = ? WHERE factory_id = ?");
                $customerStmt->bind_param('ii', $salesPersonId, $id);
                if (!$customerStmt->execute()) {
                    throw new Exception($customerStmt->error);
                }
                $customerStmt->close();
            }

            $conn->commit();
            setAlert('success', 'Factory updated.');
            logActivity('Updated factory assignment', ['id' => $id, 'sales_person_id' => $salesPersonId]);
        } catch (Exception $e) {
            $conn->rollback();
            setAlert('danger', 'Failed to update factory: ' . $e->getMessage());
        }
    } else {
        $stmt = $conn->prepare("INSERT INTO factories (name, sales_person_id, contact_person, contact_phone, whatsapp_number, notes) VALUES (?,?,?,?,?,?)");
        $stmt->bind_param("sissss", $name, $salesPersonId, $contact_person, $contact_phone, $whatsapp_number, $notes);
        if ($stmt->execute()) {
            setAlert('success', 'Factory added.');
            logActivity('Created factory', ['id' => $stmt->insert_id]);
        } else {
            setAlert('danger', 'Failed to add factory: ' . $conn->error);
        }
        $stmt->close();
    }

    redirect('factories.php');
}

if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    if (!canAccessFactory($id)) {
        setAlert('danger', 'You do not have permission to delete this factory.');
        redirect('factories.php');
    }
    $stmt = $conn->prepare("DELETE FROM factories WHERE id=?");
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        setAlert('success', 'Factory removed.');
        logActivity('Deleted factory', ['id' => $id]);
    } else {
        setAlert('danger', 'Cannot delete factory in use.');
    }
    $stmt->close();
    redirect('factories.php');
}

$factoriesSql = "SELECT f.*, u.name AS sales_person_name
                 FROM factories f
                 LEFT JOIN users u ON u.id = f.sales_person_id";
if ($isSalesPerson) {
    $factoriesSql .= " WHERE f.sales_person_id = " . (int)$_SESSION['user_id'];
}
$factories = $conn->query($factoriesSql . " ORDER BY f.name");
$page_title = 'Factories';
require_once '../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Factories</h2>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#factoryModal">
        <i class="fas fa-plus"></i> Add Factory
    </button>
</div>

<div class="card">
    <div class="card-body table-responsive">
        <table class="table js-datatable table-striped align-middle">
            <thead>
            <tr>
                <th>Name</th>
                <th>Sales Person</th>
                <th>Contact</th>
                <th>Phone</th>
                <th>WhatsApp</th>
                <th>Notes</th>
                <th>Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($factories && $factories->num_rows): ?>
                <?php while ($factory = $factories->fetch_assoc()): ?>
                    <tr>
                        <td><?= e($factory['name']); ?></td>
                        <td><?= !empty($factory['sales_person_name']) ? e($factory['sales_person_name']) : '<span class="text-muted">Unassigned</span>'; ?></td>
                        <td><?= e($factory['contact_person']); ?></td>
                        <td><?= e($factory['contact_phone']); ?></td>
                        <td><?= e($factory['whatsapp_number']); ?></td>
                        <td><?= e($factory['notes']); ?></td>
                        <td>
                            <button class="btn btn-sm btn-outline-primary edit-factory"
                                    data-id="<?= $factory['id']; ?>"
                                    data-name="<?= e($factory['name']); ?>"
                                    data-sales-person-id="<?= (int)($factory['sales_person_id'] ?? 0); ?>"
                                    data-contact="<?= e($factory['contact_person']); ?>"
                                    data-phone="<?= e($factory['contact_phone']); ?>"
                                    data-whatsapp="<?= e($factory['whatsapp_number']); ?>"
                                    data-notes="<?= e($factory['notes']); ?>">
                                <i class="fas fa-edit"></i>
                            </button>
                            <a href="factories.php?delete=<?= $factory['id']; ?>" class="btn btn-sm btn-outline-danger"
                               onclick="return confirm('Delete this factory?');">
                                <i class="fas fa-trash"></i>
                            </a>
                        </td>
                    </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr>
                    <td colspan="7" class="text-center text-muted">No factories recorded.</td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Factory Modal -->
<div class="modal fade" id="factoryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" id="factoryForm">
                <input type="hidden" name="action" value="create" id="factory_action">
                <input type="hidden" name="id" id="factory_id">
                <div class="modal-header">
                    <h5 class="modal-title">Factory</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Name*</label>
                        <input type="text" class="form-control" name="name" id="factory_name" required>
                    </div>
                    <?php if ($isAdmin): ?>
                    <div class="mb-3">
                        <label class="form-label">Assigned Sales Person</label>
                        <select class="form-select" name="sales_person_id" id="factory_sales_person_id">
                            <option value="">-- Unassigned --</option>
                            <?php foreach ($salesPersons as $salesPerson): ?>
                                <option value="<?= (int)$salesPerson['id']; ?>"><?= e($salesPerson['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-check mb-3" id="factory_reassign_customers_wrap" style="display:none;">
                        <input class="form-check-input" type="checkbox" name="reassign_customers" value="1" id="factory_reassign_customers" checked>
                        <label class="form-check-label" for="factory_reassign_customers">Move all customers linked to this factory to the selected sales person</label>
                    </div>
                    <?php endif; ?>
                    <div class="mb-3">
                        <label class="form-label">Contact Person</label>
                        <input type="text" class="form-control" name="contact_person" id="factory_contact_person">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Phone</label>
                        <input type="text" class="form-control" name="contact_phone" id="factory_contact_phone">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">WhatsApp</label>
                        <input type="text" class="form-control" name="whatsapp_number" id="factory_whatsapp_number">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea class="form-control" name="notes" id="factory_notes" rows="2"></textarea>
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
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.edit-factory').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.getElementById('factory_action').value = 'update';
            document.getElementById('factory_id').value = this.dataset.id;
            document.getElementById('factory_name').value = this.dataset.name;
            var salesPersonSelect = document.getElementById('factory_sales_person_id');
            if (salesPersonSelect) salesPersonSelect.value = this.dataset.salesPersonId || '';
            var reassignWrap = document.getElementById('factory_reassign_customers_wrap');
            if (reassignWrap) reassignWrap.style.display = '';
            document.getElementById('factory_contact_person').value = this.dataset.contact || '';
            document.getElementById('factory_contact_phone').value = this.dataset.phone || '';
            document.getElementById('factory_whatsapp_number').value = this.dataset.whatsapp || '';
            document.getElementById('factory_notes').value = this.dataset.notes || '';
            var modal = new bootstrap.Modal(document.getElementById('factoryModal'));
            modal.show();
        });
    });

    document.getElementById('factoryModal').addEventListener('hidden.bs.modal', function () {
        document.getElementById('factory_action').value = 'create';
        document.getElementById('factoryForm').reset();
        var reassignWrap = document.getElementById('factory_reassign_customers_wrap');
        if (reassignWrap) reassignWrap.style.display = 'none';
    });
});
</script>

<?php require_once '../../includes/footer.php'; ?>

<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

if (!hasPermission('customers.view')) {
    setAlert('danger', 'Access denied.');
    redirect('../../dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'create';
    $name = sanitize($_POST['name']);
    $contact_person = !empty($_POST['contact_person']) ? sanitize($_POST['contact_person']) : null;
    $contact_phone = !empty($_POST['contact_phone']) ? sanitize($_POST['contact_phone']) : null;
    $whatsapp_number = !empty($_POST['whatsapp_number']) ? sanitize($_POST['whatsapp_number']) : null;
    $notes = !empty($_POST['notes']) ? sanitize($_POST['notes']) : null;

    if ($action === 'update') {
        $id = (int)$_POST['id'];
        $stmt = $conn->prepare("UPDATE factories SET name=?, contact_person=?, contact_phone=?, whatsapp_number=?, notes=? WHERE id=?");
        $stmt->bind_param("sssssi", $name, $contact_person, $contact_phone, $whatsapp_number, $notes, $id);
        if ($stmt->execute()) {
            setAlert('success', 'Factory updated.');
            logActivity('Updated factory', ['id' => $id]);
        } else {
            setAlert('danger', 'Failed to update factory: ' . $conn->error);
        }
        $stmt->close();
    } else {
        $stmt = $conn->prepare("INSERT INTO factories (name, contact_person, contact_phone, whatsapp_number, notes) VALUES (?,?,?,?,?)");
        $stmt->bind_param("sssss", $name, $contact_person, $contact_phone, $whatsapp_number, $notes);
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

$factories = $conn->query("SELECT * FROM factories ORDER BY name");
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
                        <td><?= htmlspecialchars($factory['name']); ?></td>
                        <td><?= htmlspecialchars($factory['contact_person']); ?></td>
                        <td><?= htmlspecialchars($factory['contact_phone']); ?></td>
                        <td><?= htmlspecialchars($factory['whatsapp_number']); ?></td>
                        <td><?= htmlspecialchars($factory['notes']); ?></td>
                        <td>
                            <button class="btn btn-sm btn-outline-primary edit-factory"
                                    data-id="<?= $factory['id']; ?>"
                                    data-name="<?= htmlspecialchars($factory['name']); ?>"
                                    data-contact="<?= htmlspecialchars($factory['contact_person']); ?>"
                                    data-phone="<?= htmlspecialchars($factory['contact_phone']); ?>"
                                    data-whatsapp="<?= htmlspecialchars($factory['whatsapp_number']); ?>"
                                    data-notes="<?= htmlspecialchars($factory['notes']); ?>">
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
                    <td colspan="6" class="text-center text-muted">No factories recorded.</td>
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
    });
});
</script>

<?php require_once '../../includes/footer.php'; ?>

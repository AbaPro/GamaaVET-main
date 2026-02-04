<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

if (!hasPermission('customers.manage')) {
    setAlert('danger', 'You do not have permission to access this page.');
    redirect('../../dashboard.php');
}

$page_title = 'Customer Types';
require_once '../../includes/header.php';

// Handle delete request
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $id = sanitize($_GET['delete']);

    $check_sql = "SELECT COUNT(*) AS count FROM customers WHERE type = ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("i", $id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    $has_customers = $check_result->fetch_assoc()['count'] > 0;
    $check_stmt->close();

    if ($has_customers) {
        setAlert('danger', 'Cannot delete customer type with assigned customers.');
    } else {
        $delete_sql = "DELETE FROM customer_types WHERE id = ?";
        $delete_stmt = $conn->prepare($delete_sql);
        $delete_stmt->bind_param("i", $id);

        if ($delete_stmt->execute()) {
            setAlert('success', 'Customer type deleted successfully.');
            logActivity("Deleted customer type ID: $id");
        } else {
            setAlert('danger', 'Error deleting customer type: ' . $conn->error);
        }
        $delete_stmt->close();
    }
    redirect('types.php');
}

$result = $conn->query("SELECT * FROM customer_types ORDER BY name");
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Customer Types</h2>
    <div class="d-flex gap-2">
        <a href="index.php" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left"></i> Back to Customers
        </a>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addTypeModal">
            <i class="fas fa-plus"></i> Add Type
        </button>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table js-datatable table-hover">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Description</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($result && $result->num_rows > 0): ?>
                        <?php while ($row = $result->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo $row['id']; ?></td>
                                <td><?php echo e($row['name']); ?></td>
                                <td><?php echo $row['description'] ? e($row['description']) : '-'; ?></td>
                                <td>
                                    <button class="btn btn-sm btn-outline-primary edit-type"
                                            data-id="<?php echo $row['id']; ?>"
                                            data-name="<?php echo e($row['name']); ?>"
                                            data-description="<?php echo e($row['description']); ?>">
                                        <i class="fas fa-edit"></i> Edit
                                    </button>
                                    <a href="types.php?delete=<?php echo $row['id']; ?>" class="btn btn-sm btn-outline-danger"
                                       onclick="return confirm('Are you sure you want to delete this type?')">
                                        <i class="fas fa-trash"></i> Delete
                                    </a>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            <?php if (!$result || $result->num_rows === 0): ?>
                <div class="text-center text-muted py-3">No customer types found</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Add Type Modal -->
<div class="modal fade" id="addTypeModal" tabindex="-1" aria-labelledby="addTypeModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="types_create.php" method="POST">
                <div class="modal-header">
                    <h5 class="modal-title" id="addTypeModalLabel">Add Customer Type</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="name" class="form-label">Type Name</label>
                        <input type="text" class="form-control" id="name" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label for="description" class="form-label">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary">Save Type</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Type Modal -->
<div class="modal fade" id="editTypeModal" tabindex="-1" aria-labelledby="editTypeModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="types_edit.php" method="POST">
                <input type="hidden" id="edit_id" name="id">
                <div class="modal-header">
                    <h5 class="modal-title" id="editTypeModalLabel">Edit Customer Type</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="edit_name" class="form-label">Type Name</label>
                        <input type="text" class="form-control" id="edit_name" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label for="edit_description" class="form-label">Description</label>
                        <textarea class="form-control" id="edit_description" name="description" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary">Update Type</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    $(document).on('click', '.edit-type', function() {
        $('#edit_id').val($(this).data('id'));
        $('#edit_name').val($(this).data('name'));
        $('#edit_description').val($(this).data('description'));
        $('#editTypeModal').modal('show');
    });
});
</script>

<?php require_once '../../includes/footer.php'; ?>

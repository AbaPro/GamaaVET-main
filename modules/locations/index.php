<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

if (!hasPermission('locations.view')) {
    setAlert('danger', 'You do not have permission to access this page.');
    redirect('../../dashboard.php');
}

// Handle delete request BEFORE headers are sent
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $id = sanitize($_GET['delete']);
    
    // Check if location has inventories
    $check_sql = "SELECT COUNT(*) as count FROM inventories WHERE location_id = ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("i", $id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    $has_inventories = $check_result->fetch_assoc()['count'] > 0;
    $check_stmt->close();
    
    if ($has_inventories) {
        setAlert('danger', 'Cannot delete location as it has inventories. Please update inventories first.');
    } else {
        $delete_sql = "DELETE FROM locations WHERE id = ?";
        $delete_stmt = $conn->prepare($delete_sql);
        $delete_stmt->bind_param("i", $id);
        
        if ($delete_stmt->execute()) {
            setAlert('success', 'Location deleted successfully.');
            logActivity("Deleted location ID: $id");
        } else {
            setAlert('danger', 'Error deleting location: ' . $conn->error);
        }
        $delete_stmt->close();
    }
    redirect('index.php');
}

$page_title = 'Locations Management';
require_once '../../includes/header.php';

// Fetch all locations
$sql = "SELECT * FROM locations ORDER BY name";
$result = $conn->query($sql);
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Locations</h2>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addLocationModal">
        <i class="fas fa-plus"></i> Add Location
    </button>
</div>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table js-datatable table-hover">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Address</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($result->num_rows > 0): ?>
                        <?php while ($row = $result->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo $row['id']; ?></td>
                                <td><?php echo e($row['name']); ?></td>
                                <td><?php echo e($row['address']); ?></td>
                                <td>
                                    <span class="badge bg-<?php echo $row['is_active'] ? 'success' : 'secondary'; ?>">
                                        <?php echo $row['is_active'] ? 'Active' : 'Inactive'; ?>
                                    </span>
                                </td>
                                <td>
                                    <button class="btn btn-sm btn-outline-warning edit-location" 
                                            data-id="<?php echo $row['id']; ?>"
                                            data-name="<?php echo e($row['name']); ?>"
                                            data-address="<?php echo e($row['address']); ?>"
                                            data-description="<?php echo e($row['description']); ?>"
                                            data-active="<?php echo $row['is_active']; ?>">
                                        <i class="fas fa-edit"></i> Edit
                                    </button>
                                    <a href="index.php?delete=<?php echo $row['id']; ?>" class="btn btn-sm btn-outline-danger" 
                                       onclick="return confirm('Are you sure you want to delete this location?')">
                                        <i class="fas fa-trash"></i> Delete
                                    </a>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" class="text-center">No locations found</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add Location Modal -->
<div class="modal fade" id="addLocationModal" tabindex="-1" aria-labelledby="addLocationModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="create.php" method="POST">
                <div class="modal-header">
                    <h5 class="modal-title" id="addLocationModalLabel">Add New Location</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="name" class="form-label">Location Name</label>
                        <input type="text" class="form-control" id="name" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label for="address" class="form-label">Address</label>
                        <textarea class="form-control" id="address" name="address" rows="2"></textarea>
                    </div>
                    <div class="mb-3">
                        <label for="description" class="form-label">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="2"></textarea>
                    </div>
                    <div class="mb-3 form-check">
                        <input type="checkbox" class="form-check-input" id="is_active" name="is_active" checked>
                        <label class="form-check-label" for="is_active">Active</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary">Save Location</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Location Modal -->
<div class="modal fade" id="editLocationModal" tabindex="-1" aria-labelledby="editLocationModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="edit.php" method="POST">
                <input type="hidden" id="edit_id" name="id">
                <div class="modal-header">
                    <h5 class="modal-title" id="editLocationModalLabel">Edit Location</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="edit_name" class="form-label">Location Name</label>
                        <input type="text" class="form-control" id="edit_name" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label for="edit_address" class="form-label">Address</label>
                        <textarea class="form-control" id="edit_address" name="address" rows="2"></textarea>
                    </div>
                    <div class="mb-3">
                        <label for="edit_description" class="form-label">Description</label>
                        <textarea class="form-control" id="edit_description" name="description" rows="2"></textarea>
                    </div>
                    <div class="mb-3 form-check">
                        <input type="checkbox" class="form-check-input" id="edit_is_active" name="is_active">
                        <label class="form-check-label" for="edit_is_active">Active</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary">Update Location</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>

<script>
$(document).ready(function() {
    // Handle edit button click
    $('.edit-location').click(function() {
        var id = $(this).data('id');
        var name = $(this).data('name');
        var address = $(this).data('address');
        var description = $(this).data('description');
        var is_active = $(this).data('active');
        
        $('#edit_id').val(id);
        $('#edit_name').val(name);
        $('#edit_address').val(address);
        $('#edit_description').val(description);
        $('#edit_is_active').prop('checked', is_active == 1);
        
        $('#editLocationModal').modal('show');
    });
});
</script>

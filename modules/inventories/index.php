<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

if (!hasPermission('inventories.view')) {
    setAlert('danger', 'You do not have permission to access this page.');
    redirect('../../dashboard.php');
}

$page_title = 'Inventory Management';
require_once '../../includes/header.php';

// Fetch all locations
$locations = [];
$locationsResult = $conn->query("SELECT id, name, address FROM locations WHERE is_active = 1 ORDER BY name");
if ($locationsResult) {
    while ($locRow = $locationsResult->fetch_assoc()) {
        $locations[$locRow['id']] = $locRow;
    }
}

// Handle delete request
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $id = sanitize($_GET['delete']);
    
    // Check if inventory has products
    $check_sql = "SELECT COUNT(*) as count FROM inventory_products WHERE inventory_id = ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("i", $id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    $has_products = $check_result->fetch_assoc()['count'] > 0;
    $check_stmt->close();
    
    if ($has_products) {
        setAlert('danger', 'Cannot delete inventory as it contains products. Remove products first.');
    } else {
        $delete_sql = "DELETE FROM inventories WHERE id = ?";
        $delete_stmt = $conn->prepare($delete_sql);
        $delete_stmt->bind_param("i", $id);
        
        if ($delete_stmt->execute()) {
            setAlert('success', 'Inventory deleted successfully.');
            logActivity("Deleted inventory ID: $id");
        } else {
            setAlert('danger', 'Error deleting inventory: ' . $conn->error);
        }
        $delete_stmt->close();
    }
    redirect('index.php');
}

// Fetch all inventories
$login_region = $_SESSION['login_region'] ?? 'factory';

if ($login_region === 'factory') {
    $sql = "SELECT * FROM inventories WHERE direct_sale IS NULL ORDER BY name";
} else {
    $sql = "SELECT * FROM inventories WHERE direct_sale = ? ORDER BY name";
}

$stmt = $conn->prepare($sql);
if ($login_region !== 'factory') {
    $stmt->bind_param("s", $login_region);
}
$stmt->execute();
$result = $stmt->get_result();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Inventories</h2>
    <div>
        <a href="upload.php" class="btn btn-outline-primary">
            <i class="fas fa-file-upload"></i> Bulk Upload Products Quantity
        </a>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addInventoryModal">
            <i class="fas fa-plus"></i> Add Inventory
        </button>
        <a href="transfer.php" class="btn btn-success">
            <i class="fas fa-exchange-alt"></i> Transfer Items
        </a>
        <a href="../locations/" class="btn btn-info">
            <i class="fas fa-map-marker-alt"></i> Manage Locations
        </a>
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
                        <th>Location</th>
                        <?php if ($login_region !== 'factory'): ?>
                            <th>Region</th>
                        <?php endif; ?>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($result->num_rows > 0): ?>
                        <?php while ($row = $result->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo $row['id']; ?></td>
                                <td><?php echo htmlspecialchars($row['name']); ?></td>
                                <td><?php echo isset($locations[$row['location_id']]) ? htmlspecialchars($locations[$row['location_id']]['name']) : 'N/A'; ?></td>
                                <?php if ($login_region !== 'factory'): ?>
                                    <td><?php echo htmlspecialchars($row['region'] ?: 'N/A'); ?></td>
                                <?php endif; ?>
                                <td>
                                    <span class="badge bg-<?php echo $row['is_active'] ? 'success' : 'secondary'; ?>">
                                        <?php echo $row['is_active'] ? 'Active' : 'Inactive'; ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="view.php?id=<?php echo $row['id']; ?>" class="btn btn-sm btn-outline-primary">
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                    <button class="btn btn-sm btn-outline-warning edit-inventory" 
                                            data-id="<?php echo $row['id']; ?>"
                                            data-name="<?php echo htmlspecialchars($row['name']); ?>"
                                            data-location-id="<?php echo $row['location_id']; ?>"
                                            data-description="<?php echo htmlspecialchars($row['description']); ?>"
                                            data-region="<?php echo htmlspecialchars($row['region'] ?? ''); ?>"
                                            data-direct_sale="<?php echo htmlspecialchars($row['direct_sale'] ?? ''); ?>"
                                            data-active="<?php echo $row['is_active']; ?>">
                                        <i class="fas fa-edit"></i> Edit
                                    </button>
                                    <a href="index.php?delete=<?php echo $row['id']; ?>" class="btn btn-sm btn-outline-danger" 
                                       onclick="return confirm('Are you sure you want to delete this inventory?')">
                                        <i class="fas fa-trash"></i> Delete
                                    </a>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" class="text-center">No inventories found</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add Inventory Modal -->
<div class="modal fade" id="addInventoryModal" tabindex="-1" aria-labelledby="addInventoryModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="create.php" method="POST">
                <div class="modal-header">
                    <h5 class="modal-title" id="addInventoryModalLabel">Add New Inventory</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="name" class="form-label">Inventory Name</label>
                        <input type="text" class="form-control" id="name" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label for="location_id" class="form-label">Location</label>
                        <select class="form-select" id="location_id" name="location_id" required>
                            <option value="">Select location</option>
                            <?php foreach ($locations as $locId => $loc): ?>
                                <option value="<?php echo $locId; ?>"><?php echo htmlspecialchars($loc['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="description" class="form-label">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="3"></textarea>
                    </div>
                    <?php if ($login_region !== 'factory'): ?>
                    <div class="mb-3">
                        <label for="region" class="form-label">Region</label>
                        <select class="form-select" id="region" name="region">
                            <option value="">-- None --</option>
                            <?php
                            $regions_query = $conn->query("SELECT name FROM regions ORDER BY name ASC");
                            while ($r = $regions_query->fetch_assoc()) {
                                echo '<option value="' . htmlspecialchars($r['name']) . '">' . htmlspecialchars($r['name']) . '</option>';
                            }
                            ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <div class="mb-3">
                        <?php if ($login_region === 'factory'): ?>
                            <input type="hidden" name="direct_sale" value="">
                        <?php else: ?>
                            <label for="direct_sale" class="form-label">Direct Sale</label>
                            <input type="text" class="form-control" value="<?= ucfirst($login_region) ?>" readonly>
                            <input type="hidden" name="direct_sale" value="<?= $login_region ?>">
                        <?php endif; ?>
                    </div>
                    <div class="mb-3 form-check">
                        <input type="checkbox" class="form-check-input" id="is_active" name="is_active" checked>
                        <label class="form-check-label" for="is_active">Active</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary">Save Inventory</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Inventory Modal -->
<div class="modal fade" id="editInventoryModal" tabindex="-1" aria-labelledby="editInventoryModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="edit.php" method="POST">
                <input type="hidden" id="edit_id" name="id">
                <div class="modal-header">
                    <h5 class="modal-title" id="editInventoryModalLabel">Edit Inventory</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="edit_name" class="form-label">Inventory Name</label>
                        <input type="text" class="form-control" id="edit_name" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label for="edit_location_id" class="form-label">Location</label>
                        <select class="form-select" id="edit_location_id" name="location_id" required>
                            <option value="">Select location</option>
                            <?php foreach ($locations as $locId => $loc): ?>
                                <option value="<?php echo $locId; ?>"><?php echo htmlspecialchars($loc['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="edit_description" class="form-label">Description</label>
                        <textarea class="form-control" id="edit_description" name="description" rows="3"></textarea>
                    </div>
                    <?php if ($login_region !== 'factory'): ?>
                    <div class="mb-3">
                        <label for="edit_region" class="form-label">Region</label>
                        <select class="form-select" id="edit_region" name="region">
                            <option value="">-- None --</option>
                            <?php
                            $regions_query = $conn->query("SELECT name FROM regions ORDER BY name ASC");
                            while ($r = $regions_query->fetch_assoc()) {
                                echo '<option value="' . htmlspecialchars($r['name']) . '">' . htmlspecialchars($r['name']) . '</option>';
                            }
                            ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <div class="mb-3">
                        <?php if ($login_region === 'factory'): ?>
                            <input type="hidden" name="direct_sale" id="edit_direct_sale" value="">
                        <?php else: ?>
                            <label for="edit_direct_sale" class="form-label">Direct Sale</label>
                            <input type="text" class="form-control" value="<?= ucfirst($login_region) ?>" readonly>
                            <input type="hidden" name="direct_sale" id="edit_direct_sale" value="<?= $login_region ?>">
                        <?php endif; ?>
                    </div>
                    <div class="mb-3 form-check">
                        <input type="checkbox" class="form-check-input" id="edit_is_active" name="is_active">
                        <label class="form-check-label" for="edit_is_active">Active</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary">Update Inventory</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>

<script>
$(document).ready(function() {
    // Handle edit button click
    $('.edit-inventory').click(function() {
        var id = $(this).data('id');
        var name = $(this).data('name');
        var locationId = $(this).data('location-id');
        var description = $(this).data('description');
        var region = $(this).data('region');
        var direct_sale = $(this).data('direct_sale');
        var is_active = $(this).data('active');
        
        $('#edit_id').val(id);
        $('#edit_name').val(name);
        $('#edit_location_id').val(locationId);
        $('#edit_description').val(description);
        $('#edit_region').val(region);
        $('#edit_direct_sale').val(direct_sale);
        $('#edit_is_active').prop('checked', is_active == 1);
        
        $('#editInventoryModal').modal('show');
    });
});
</script>


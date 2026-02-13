<?php
require_once '../../includes/auth.php';
require_once '../../config/database.php';

$page_title = 'Manage Regions';

// Handle Regional Action (Create)
if (isset($_POST['create_region'])) {
    if (hasPermission('regions.create')) {
        $name = sanitize($_POST['name']);
        
        $stmt = $conn->prepare("INSERT INTO regions (name) VALUES (?)");
        $stmt->bind_param("s", $name);
        
        if ($stmt->execute()) {
            $_SESSION['success'] = "Region created successfully.";
        } else {
            $_SESSION['error'] = "Error creating region: " . $conn->error;
        }
    } else {
        $_SESSION['error'] = "You don't have permission to create regions.";
    }
    header("Location: index.php");
    exit();
}

// Handle Delete
if (isset($_GET['delete'])) {
    if (hasPermission('regions.delete')) {
        $id = intval($_GET['delete']);
        $stmt = $conn->prepare("DELETE FROM regions WHERE id = ?");
        $stmt->bind_param("i", $id);
        
        if ($stmt->execute()) {
            $_SESSION['success'] = "Region deleted successfully.";
        } else {
            $_SESSION['error'] = "Error deleting region. It might be in use.";
        }
    } else {
        $_SESSION['error'] = "You don't have permission to delete regions.";
    }
    header("Location: index.php");
    exit();
}

$regions = $conn->query("SELECT * FROM regions ORDER BY name ASC");

include '../../includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0 text-gray-800">Regions Management</h1>
        <?php if (hasPermission('regions.create')): ?>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createRegionModal">
                <i class="fas fa-plus me-2"></i>Create New Region
            </button>
        <?php endif; ?>
    </div>

    <?php include '../../includes/messages.php'; ?>

    <div class="card shadow mb-4">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered" id="dataTable" width="100%" cellspacing="0">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Created At</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($region = $regions->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo $region['id']; ?></td>
                                <td><?php echo htmlspecialchars($region['name']); ?></td>
                                <td><?php echo $region['created_at']; ?></td>
                                <td>
                                    <?php if (hasPermission('regions.edit')): ?>
                                        <a href="edit.php?id=<?php echo $region['id']; ?>" class="btn btn-sm btn-info">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                    <?php endif; ?>
                                    <?php if (hasPermission('regions.delete')): ?>
                                        <a href="index.php?delete=<?php echo $region['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure you want to delete this region?')">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Create Region Modal -->
<div class="modal fade" id="createRegionModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="index.php" method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">Create New Region</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Region Name</label>
                        <input type="text" name="name" class="form-control" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="create_region" class="btn btn-primary">Create Region</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>

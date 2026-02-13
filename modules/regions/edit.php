<?php
require_once '../../includes/auth.php';
require_once '../../config/database.php';

if (!hasPermission('regions.edit')) {
    $_SESSION['error'] = "You don't have permission to edit regions.";
    header("Location: index.php");
    exit();
}

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$stmt = $conn->prepare("SELECT * FROM regions WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
$region = $result->fetch_assoc();

if (!$region) {
    $_SESSION['error'] = "Region not found.";
    header("Location: index.php");
    exit();
}

if (isset($_POST['update_region'])) {
    $name = sanitize($_POST['name']);
    
    $stmt = $conn->prepare("UPDATE regions SET name = ? WHERE id = ?");
    $stmt->bind_param("si", $name, $id);
    
    if ($stmt->execute()) {
        $_SESSION['success'] = "Region updated successfully.";
        header("Location: index.php");
        exit();
    } else {
        $error = "Error updating region: " . $conn->error;
    }
}

$page_title = 'Edit Region: ' . $region['name'];
include '../../includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="card shadow mb-4">
                <div class="card-header py-3 d-flex justify-content-between align-items-center">
                    <h6 class="m-0 font-weight-bold text-primary">Edit Region</h6>
                    <a href="index.php" class="btn btn-sm btn-secondary">Back to List</a>
                </div>
                <div class="card-body">
                    <?php if (isset($error)): ?>
                        <div class="alert alert-danger"><?php echo $error; ?></div>
                    <?php endif; ?>

                    <form action="edit.php?id=<?php echo $id; ?>" method="POST">
                        <div class="mb-3">
                            <label class="form-label">Region Name</label>
                            <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($region['name']); ?>" required>
                        </div>
                        <div class="d-grid">
                            <button type="submit" name="update_region" class="btn btn-primary">Update Region</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>

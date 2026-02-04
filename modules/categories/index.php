<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

if (!hasPermission('categories.manage')) {
    setAlert('danger', 'You do not have permission to access this page.');
    redirect('../../dashboard.php');
}

$page_title = 'Categories Management';
require_once '../../includes/header.php';

// Handle delete request
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $id = sanitize($_GET['delete']);
    
    // Check if category has products
    $check_sql = "SELECT COUNT(*) as count FROM products WHERE category_id = ? OR subcategory_id = ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("ii", $id, $id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    $has_products = $check_result->fetch_assoc()['count'] > 0;
    $check_stmt->close();
    
    if ($has_products) {
        setAlert('danger', 'Cannot delete category as it has associated products.');
    } else {
        // Check if category has subcategories
        $subcat_sql = "SELECT COUNT(*) as count FROM categories WHERE parent_id = ?";
        $subcat_stmt = $conn->prepare($subcat_sql);
        $subcat_stmt->bind_param("i", $id);
        $subcat_stmt->execute();
        $subcat_result = $subcat_stmt->get_result();
        $has_subcategories = $subcat_result->fetch_assoc()['count'] > 0;
        $subcat_stmt->close();
        
        if ($has_subcategories) {
            setAlert('danger', 'Cannot delete category as it has subcategories. Delete subcategories first.');
        } else {
            $delete_sql = "DELETE FROM categories WHERE id = ?";
            $delete_stmt = $conn->prepare($delete_sql);
            $delete_stmt->bind_param("i", $id);
            
            if ($delete_stmt->execute()) {
                setAlert('success', 'Category deleted successfully.');
                logActivity("Deleted category ID: $id");
            } else {
                setAlert('danger', 'Error deleting category: ' . $conn->error);
            }
            $delete_stmt->close();
        }
    }
    redirect('index.php');
}

// Fetch all categories
$sql = "SELECT c1.*, c2.name as parent_name 
        FROM categories c1 
        LEFT JOIN categories c2 ON c1.parent_id = c2.id 
        ORDER BY c1.parent_id, c1.name";
$result = $conn->query($sql);
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Categories</h2>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
        <i class="fas fa-plus"></i> Add Category
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
                        <th>Parent Category</th>
                        <th>Description</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($result->num_rows > 0): ?>
                        <?php while ($row = $result->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo $row['id']; ?></td>
                                <td><?php echo htmlspecialchars($row['name']); ?></td>
                                <td><?php echo $row['parent_name'] ? htmlspecialchars($row['parent_name']) : '-'; ?></td>
                                <td><?php echo $row['description'] ? htmlspecialchars($row['description']) : '-'; ?></td>
                                <td>
                                    <button class="btn btn-sm btn-outline-primary edit-category" 
                                            data-id="<?php echo $row['id']; ?>"
                                            data-name="<?php echo htmlspecialchars($row['name']); ?>"
                                            data-parent="<?php echo $row['parent_id']; ?>"
                                            data-description="<?php echo htmlspecialchars($row['description']); ?>">
                                        <i class="fas fa-edit"></i> Edit
                                    </button>
                                    <a href="index.php?delete=<?php echo $row['id']; ?>" class="btn btn-sm btn-outline-danger" 
                                       onclick="return confirm('Are you sure you want to delete this category?')">
                                        <i class="fas fa-trash"></i> Delete
                                    </a>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" class="text-center">No categories found</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add Category Modal -->
<div class="modal fade" id="addCategoryModal" tabindex="-1" aria-labelledby="addCategoryModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="create.php" method="POST">
                <div class="modal-header">
                    <h5 class="modal-title" id="addCategoryModalLabel">Add New Category</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="name" class="form-label">Category Name</label>
                        <input type="text" class="form-control" id="name" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label for="parent_id" class="form-label">Parent Category</label>
                        <select class="form-select" id="parent_id" name="parent_id">
                            <option value="">-- No Parent --</option>
                            <?php
                            $parent_cats = $conn->query("SELECT id, name FROM categories WHERE parent_id IS NULL");
                            while ($cat = $parent_cats->fetch_assoc()) {
                                echo '<option value="' . $cat['id'] . '">' . htmlspecialchars($cat['name']) . '</option>';
                            }
                            ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="description" class="form-label">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary">Save Category</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Category Modal -->
<div class="modal fade" id="editCategoryModal" tabindex="-1" aria-labelledby="editCategoryModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="edit.php" method="POST">
                <input type="hidden" id="edit_id" name="id">
                <div class="modal-header">
                    <h5 class="modal-title" id="editCategoryModalLabel">Edit Category</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="edit_name" class="form-label">Category Name</label>
                        <input type="text" class="form-control" id="edit_name" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label for="edit_parent_id" class="form-label">Parent Category</label>
                        <select class="form-select" id="edit_parent_id" name="parent_id">
                            <option value="">-- No Parent --</option>
                            <?php
                            $parent_cats = $conn->query("SELECT id, name FROM categories WHERE parent_id IS NULL");
                            while ($cat = $parent_cats->fetch_assoc()) {
                                echo '<option value="' . $cat['id'] . '">' . htmlspecialchars($cat['name']) . '</option>';
                            }
                            ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="edit_description" class="form-label">Description</label>
                        <textarea class="form-control" id="edit_description" name="description" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary">Update Category</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>

<script>
$(document).ready(function() {
    // Handle edit button click
    $('.edit-category').click(function() {
        var id = $(this).data('id');
        var name = $(this).data('name');
        var parent = $(this).data('parent');
        var description = $(this).data('description');
        
        $('#edit_id').val(id);
        $('#edit_name').val(name);
        $('#edit_parent_id').val(parent);
        $('#edit_description').val(description);
        
        $('#editCategoryModal').modal('show');
    });
});
</script>

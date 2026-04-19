<?php
require_once '../../../includes/auth.php';
require_once '../../../config/database.php';
require_once '../../../includes/functions.php';

if (!hasPermission('finance.expenses.categories')) {
    setAlert('danger', 'Access denied.');
    redirect('../../../dashboard.php');
}

$page_title = 'Expense Categories';
require_once '../../../includes/header.php';

// Handle Add Category
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_category'])) {
    $name = sanitize($_POST['name']);
    $description = sanitize($_POST['description']);
    
    try {
        $stmt = $pdo->prepare("INSERT INTO expense_categories (name, description) VALUES (?, ?)");
        $stmt->execute([$name, $description]);
        setAlert('success', 'Category added successfully.');
    } catch (PDOException $e) {
        setAlert('danger', 'Error adding category: ' . $e->getMessage());
    }
    redirect('categories.php');
}

// Handle Delete Category
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    try {
        // Check if category is in use
        $check = $pdo->prepare("SELECT COUNT(*) FROM expenses WHERE category_id = ?");
        $check->execute([$id]);
        if ($check->fetchColumn() > 0) {
            setAlert('danger', 'Cannot delete category that is in use by expenses.');
        } else {
            $stmt = $pdo->prepare("DELETE FROM expense_categories WHERE id = ?");
            $stmt->execute([$id]);
            setAlert('success', 'Category deleted successfully.');
        }
    } catch (PDOException $e) {
        setAlert('danger', 'Error deleting category: ' . $e->getMessage());
    }
    redirect('categories.php');
}

$stmt = $pdo->query("SELECT * FROM expense_categories ORDER BY name ASC");
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="container-fluid px-4">
    <div class="d-flex justify-content-between align-items-center mb-4 mt-4">
        <h1>Expense Categories</h1>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
            <i class="fas fa-plus me-1"></i> Add Category
        </button>
    </div>

    <?php include '../../../includes/messages.php'; ?>

    <div class="card shadow-sm">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover js-datatable">
                    <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Description</th>
                            <th>Created At</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($categories as $cat): ?>
                            <tr>
                                <td><?= $cat['id'] ?></td>
                                <td><strong><?= htmlspecialchars($cat['name']) ?></strong></td>
                                <td><?= htmlspecialchars($cat['description']) ?></td>
                                <td><?= date('M j, Y', strtotime($cat['created_at'])) ?></td>
                                <td class="text-end">
                                    <a href="categories.php?delete=<?= $cat['id'] ?>" 
                                       class="btn btn-sm btn-outline-danger" 
                                       onclick="return confirm('Are you sure you want to delete this category?')">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Add Category Modal -->
<div class="modal fade" id="addCategoryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <div class="modal-header">
                    <h5 class="modal-title">Add Expense Category</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Category Name</label>
                        <input type="text" class="form-control" name="name" required placeholder="e.g. Rent, Salaries, Utilities">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description (Optional)</label>
                        <textarea class="form-control" name="description" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_category" class="btn btn-primary">Save Category</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once '../../../includes/footer.php'; ?>

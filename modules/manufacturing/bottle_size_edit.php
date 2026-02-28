<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

if (!hasPermission('manufacturing.view')) {
    setAlert('danger', 'Access denied.');
    redirect('../../dashboard.php');
}

$id   = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$size = null;

if ($id > 0) {
    $stmt = $conn->prepare("SELECT * FROM bottle_sizes WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $size = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$size) {
        setAlert('danger', 'Bottle size not found.');
        redirect('bottle_sizes.php');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name     = sanitize($_POST['name'] ?? '');
    $sizeVal  = isset($_POST['size']) ? floatval($_POST['size']) : 0;
    $unit     = sanitize($_POST['unit'] ?? '');
    $type     = in_array($_POST['type'] ?? '', ['liquid', 'powder']) ? $_POST['type'] : null;
    $isActive = isset($_POST['is_active']) ? 1 : 0;

    if ($name === '') {
        setAlert('danger', 'Please enter a name.');
    } elseif ($sizeVal <= 0) {
        setAlert('danger', 'Size must be greater than zero.');
    } elseif ($unit === '') {
        setAlert('danger', 'Please enter a unit.');
    } elseif (!$type) {
        setAlert('danger', 'Please select a type.');
    } else {
        try {
            if ($id > 0) {
                $stmt = $pdo->prepare("
                    UPDATE bottle_sizes SET name = ?, size = ?, unit = ?, type = ?, is_active = ? WHERE id = ?
                ");
                $stmt->execute([$name, $sizeVal, $unit, $type, $isActive, $id]);
                setAlert('success', 'Bottle size updated successfully.');
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO bottle_sizes (name, size, unit, type, is_active) VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([$name, $sizeVal, $unit, $type, $isActive]);
                setAlert('success', 'Bottle size created successfully.');
            }
            redirect('bottle_sizes.php');
        } catch (Exception $e) {
            setAlert('danger', 'Error: ' . $e->getMessage());
        }
    }
}

$page_title = $id > 0 ? 'Edit Bottle Size' : 'New Bottle Size';
require_once '../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2><?= $page_title; ?></h2>
    <a href="bottle_sizes.php" class="btn btn-outline-secondary">
        <i class="fas fa-arrow-left me-1"></i> Back to List
    </a>
</div>

<div class="row">
    <div class="col-md-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-light">
                <h5 class="mb-0">Bottle Size Details</h5>
            </div>
            <div class="card-body">
                <form method="post">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="name"
                                   value="<?= htmlspecialchars($size['name'] ?? $_POST['name'] ?? ''); ?>"
                                   placeholder="e.g. 500ml Bottle, 1kg Sachet"
                                   required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Size <span class="text-danger">*</span></label>
                            <input type="number" step="0.001" min="0.001" class="form-control" name="size"
                                   value="<?= htmlspecialchars($size['size'] ?? $_POST['size'] ?? ''); ?>"
                                   placeholder="e.g. 500" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Unit <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="unit"
                                   value="<?= htmlspecialchars($size['unit'] ?? $_POST['unit'] ?? ''); ?>"
                                   placeholder="e.g. ml, L, g, kg" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Type <span class="text-danger">*</span></label>
                            <select class="form-select" name="type" required>
                                <option value="">— Select type —</option>
                                <?php
                                $selType = $size['type'] ?? $_POST['type'] ?? '';
                                foreach (['liquid' => 'Liquid', 'powder' => 'Powder'] as $val => $label): ?>
                                    <option value="<?= $val; ?>" <?= $selType === $val ? 'selected' : ''; ?>>
                                        <?= $label; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 d-flex align-items-end">
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="is_active" id="isActive"
                                       <?= (!isset($size) || !empty($size['is_active']) || isset($_POST['is_active'])) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="isActive">Active</label>
                            </div>
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary px-5">
                                <i class="fas fa-save me-2"></i> Save
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>

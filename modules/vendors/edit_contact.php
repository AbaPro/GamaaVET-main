<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

if (!hasPermission('vendors.contact')) {
    setAlert('danger', 'You do not have permission to access this page.');
    redirect('../../dashboard.php');
}

if (!isset($_GET['id']) || !is_numeric($_GET['id']) || 
    !isset($_GET['vendor_id']) || !is_numeric($_GET['vendor_id'])) {
    setAlert('danger', 'Invalid request parameters.');
    redirect('index.php');
}

$contact_id = sanitize($_GET['id']);
$vendor_id = sanitize($_GET['vendor_id']);
$page_title = 'Edit Contact';
require_once '../../includes/header.php';

// Get contact info
$contact_sql = "SELECT * FROM vendor_contacts WHERE id = ? AND vendor_id = ?";
$contact_stmt = $conn->prepare($contact_sql);
$contact_stmt->bind_param("ii", $contact_id, $vendor_id);
$contact_stmt->execute();
$contact_result = $contact_stmt->get_result();

if ($contact_result->num_rows === 0) {
    setAlert('danger', 'Contact not found.');
    redirect("contacts.php?id=$vendor_id");
}

$contact = $contact_result->fetch_assoc();
$contact_stmt->close();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = sanitize($_POST['name']);
    $email = !empty($_POST['email']) ? sanitize($_POST['email']) : NULL;
    $phone = sanitize($_POST['phone']);
    $position = !empty($_POST['position']) ? sanitize($_POST['position']) : NULL;
    $is_primary = isset($_POST['is_primary']) ? 1 : 0;
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // If setting as primary, first remove primary from current primary contact
        if ($is_primary && !$contact['is_primary']) {
            $remove_sql = "UPDATE vendor_contacts SET is_primary = 0 WHERE vendor_id = ? AND is_primary = 1";
            $remove_stmt = $conn->prepare($remove_sql);
            $remove_stmt->bind_param("i", $vendor_id);
            $remove_stmt->execute();
            $remove_stmt->close();
        }
        
        // Update contact
        $update_sql = "UPDATE vendor_contacts SET 
                       name = ?, email = ?, phone = ?, position = ?, is_primary = ? 
                       WHERE id = ?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param("ssssii", $name, $email, $phone, $position, $is_primary, $contact_id);
        $update_stmt->execute();
        
        // If removing primary status but no other primary exists, set this one back to primary
        if (!$is_primary && $contact['is_primary']) {
            $check_primary_sql = "SELECT COUNT(*) as count FROM vendor_contacts WHERE vendor_id = ? AND is_primary = 1";
            $check_primary_stmt = $conn->prepare($check_primary_sql);
            $check_primary_stmt->bind_param("i", $vendor_id);
            $check_primary_stmt->execute();
            $check_result = $check_primary_stmt->get_result();
            $primary_count = $check_result->fetch_assoc()['count'];
            $check_primary_stmt->close();
            
            if ($primary_count === 0) {
                $set_primary_sql = "UPDATE vendor_contacts SET is_primary = 1 WHERE id = ?";
                $set_primary_stmt = $conn->prepare($set_primary_sql);
                $set_primary_stmt->bind_param("i", $contact_id);
                $set_primary_stmt->execute();
                $set_primary_stmt->close();
                $is_primary = 1;
            }
        }
        
        // Commit transaction
        $conn->commit();
        
        setAlert('success', 'Contact updated successfully.');
        logActivity("Updated contact ID: $contact_id for vendor ID: $vendor_id");
    } catch (Exception $e) {
        $conn->rollback();
        setAlert('danger', 'Error updating contact: ' . $e->getMessage());
    }
    
    redirect("contacts.php?id=$vendor_id");
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Edit Contact</h2>
    <a href="contacts.php?id=<?php echo $vendor_id; ?>" class="btn btn-secondary">Back to Contacts</a>
</div>

<div class="card">
    <div class="card-body">
        <form action="edit_contact.php?id=<?php echo $contact_id; ?>&vendor_id=<?php echo $vendor_id; ?>" method="POST">
            <div class="mb-3">
                <label for="name" class="form-label">Name*</label>
                <input type="text" class="form-control" id="name" name="name" value="<?php echo htmlspecialchars($contact['name']); ?>" required>
            </div>
            <div class="mb-3">
                <label for="email" class="form-label">Email</label>
                <input type="email" class="form-control" id="email" name="email" value="<?php echo $contact['email'] ? htmlspecialchars($contact['email']) : ''; ?>">
            </div>
            <div class="mb-3">
                <label for="phone" class="form-label">Phone*</label>
                <input type="text" class="form-control" id="phone" name="phone" value="<?php echo htmlspecialchars($contact['phone']); ?>" required>
            </div>
            <div class="mb-3">
                <label for="position" class="form-label">Position</label>
                <input type="text" class="form-control" id="position" name="position" value="<?php echo $contact['position'] ? htmlspecialchars($contact['position']) : ''; ?>">
            </div>
            <div class="mb-3 form-check">
                <input type="checkbox" class="form-check-input" id="is_primary" name="is_primary" <?php echo $contact['is_primary'] ? 'checked' : ''; ?>>
                <label class="form-check-label" for="is_primary">Primary contact</label>
            </div>
            <button type="submit" class="btn btn-primary">Update Contact</button>
        </form>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>

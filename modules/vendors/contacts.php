<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

if (!hasPermission('vendors.contact')) {
    setAlert('danger', 'You do not have permission to access this page.');
    redirect('../../dashboard.php');
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    setAlert('danger', 'Invalid vendor ID.');
    redirect('index.php');
}

$vendor_id = sanitize($_GET['id']);
$page_title = 'Vendor Contacts';
require_once '../../includes/header.php';

// Get vendor info for header
$vendor_sql = "SELECT name FROM vendors WHERE id = ?";
$vendor_stmt = $conn->prepare($vendor_sql);
$vendor_stmt->bind_param("i", $vendor_id);
$vendor_stmt->execute();
$vendor_result = $vendor_stmt->get_result();

if ($vendor_result->num_rows === 0) {
    setAlert('danger', 'Vendor not found.');
    redirect('index.php');
}

$vendor = $vendor_result->fetch_assoc();
$vendor_stmt->close();

// Handle contact actions
if (isset($_GET['delete_contact']) && is_numeric($_GET['delete_contact'])) {
    $contact_id = sanitize($_GET['delete_contact']);
    
    // Check if contact is primary
    $check_sql = "SELECT is_primary FROM vendor_contacts WHERE id = ? AND vendor_id = ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("ii", $contact_id, $vendor_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows === 0) {
        setAlert('danger', 'Contact not found.');
    } else {
        $contact = $check_result->fetch_assoc();
        if ($contact['is_primary']) {
            setAlert('danger', 'Cannot delete primary contact. Change primary contact first.');
        } else {
            $delete_sql = "DELETE FROM vendor_contacts WHERE id = ?";
            $delete_stmt = $conn->prepare($delete_sql);
            $delete_stmt->bind_param("i", $contact_id);
            
            if ($delete_stmt->execute()) {
                setAlert('success', 'Contact deleted successfully.');
                logActivity("Deleted contact ID: $contact_id for vendor ID: $vendor_id");
            } else {
                setAlert('danger', 'Error deleting contact: ' . $conn->error);
            }
            $delete_stmt->close();
        }
    }
    $check_stmt->close();
    redirect("contacts.php?id=$vendor_id");
}

if (isset($_GET['set_primary']) && is_numeric($_GET['set_primary'])) {
    $contact_id = sanitize($_GET['set_primary']);
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Remove primary from current primary contact
        $remove_sql = "UPDATE vendor_contacts SET is_primary = 0 WHERE vendor_id = ? AND is_primary = 1";
        $remove_stmt = $conn->prepare($remove_sql);
        $remove_stmt->bind_param("i", $vendor_id);
        $remove_stmt->execute();
        $remove_stmt->close();
        
        // Set new primary contact
        $set_sql = "UPDATE vendor_contacts SET is_primary = 1 WHERE id = ? AND vendor_id = ?";
        $set_stmt = $conn->prepare($set_sql);
        $set_stmt->bind_param("ii", $contact_id, $vendor_id);
        $set_stmt->execute();
        
        if ($set_stmt->affected_rows > 0) {
            setAlert('success', 'Primary contact updated successfully.');
            logActivity("Set contact ID: $contact_id as primary for vendor ID: $vendor_id");
            $conn->commit();
        } else {
            setAlert('danger', 'Contact not found.');
            $conn->rollback();
        }
        $set_stmt->close();
    } catch (Exception $e) {
        $conn->rollback();
        setAlert('danger', 'Error updating primary contact: ' . $e->getMessage());
    }
    
    redirect("contacts.php?id=$vendor_id");
}

// Handle contact form submission
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
        if ($is_primary) {
            $remove_sql = "UPDATE vendor_contacts SET is_primary = 0 WHERE vendor_id = ? AND is_primary = 1";
            $remove_stmt = $conn->prepare($remove_sql);
            $remove_stmt->bind_param("i", $vendor_id);
            $remove_stmt->execute();
            $remove_stmt->close();
        }
        
        // Insert new contact
        $insert_sql = "INSERT INTO vendor_contacts 
                       (vendor_id, name, email, phone, position, is_primary) 
                       VALUES (?, ?, ?, ?, ?, ?)";
        $insert_stmt = $conn->prepare($insert_sql);
        $insert_stmt->bind_param("issssi", $vendor_id, $name, $email, $phone, $position, $is_primary);
        $insert_stmt->execute();
        $contact_id = $insert_stmt->insert_id;
        $insert_stmt->close();
        
        // Commit transaction
        $conn->commit();
        
        setAlert('success', 'Contact added successfully.');
        logActivity("Added contact ID: $contact_id for vendor ID: $vendor_id");
    } catch (Exception $e) {
        $conn->rollback();
        setAlert('danger', 'Error adding contact: ' . $e->getMessage());
    }
    
    redirect("contacts.php?id=$vendor_id");
}

// Get all contacts for this vendor
$contacts_sql = "SELECT * FROM vendor_contacts WHERE vendor_id = ? ORDER BY is_primary DESC, name";
$contacts_stmt = $conn->prepare($contacts_sql);
$contacts_stmt->bind_param("i", $vendor_id);
$contacts_stmt->execute();
$contacts_result = $contacts_stmt->get_result();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>
        Contacts for: <?php echo htmlspecialchars($vendor['name']); ?>
    </h2>
    <a href="view.php?id=<?php echo $vendor_id; ?>" class="btn btn-secondary">Back to Vendor</a>
</div>

<div class="row mb-4">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">Add New Contact</h5>
            </div>
            <div class="card-body">
                <form action="contacts.php?id=<?php echo $vendor_id; ?>" method="POST">
                    <div class="mb-3">
                        <label for="name" class="form-label">Name*</label>
                        <input type="text" class="form-control" id="name" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label for="email" class="form-label">Email</label>
                        <input type="email" class="form-control" id="email" name="email">
                    </div>
                    <div class="mb-3">
                        <label for="phone" class="form-label">Phone*</label>
                        <input type="text" class="form-control" id="phone" name="phone" required>
                    </div>
                    <div class="mb-3">
                        <label for="position" class="form-label">Position</label>
                        <input type="text" class="form-control" id="position" name="position">
                    </div>
                    <div class="mb-3 form-check">
                        <input type="checkbox" class="form-check-input" id="is_primary" name="is_primary">
                        <label class="form-check-label" for="is_primary">Set as primary contact</label>
                    </div>
                    <button type="submit" class="btn btn-primary">Add Contact</button>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h5 class="card-title mb-0">Vendor Contacts</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table js-datatable table-hover">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Position</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($contacts_result->num_rows > 0): ?>
                        <?php while ($contact = $contacts_result->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($contact['name']); ?></td>
                                <td><?php echo $contact['position'] ? htmlspecialchars($contact['position']) : '-'; ?></td>
                                <td><?php echo $contact['email'] ? htmlspecialchars($contact['email']) : '-'; ?></td>
                                <td><?php echo htmlspecialchars($contact['phone']); ?></td>
                                <td>
                                    <?php if ($contact['is_primary']): ?>
                                        <span class="badge bg-success">Primary</span>
                                    <?php else: ?>
                                        <a href="contacts.php?id=<?php echo $vendor_id; ?>&set_primary=<?php echo $contact['id']; ?>" 
                                           class="badge bg-warning text-dark" 
                                           onclick="return confirm('Set this contact as primary?')">
                                            Set Primary
                                        </a>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="edit_contact.php?id=<?php echo $contact['id']; ?>&vendor_id=<?php echo $vendor_id; ?>" 
                                       class="btn btn-sm btn-outline-primary">
                                        <i class="fas fa-edit"></i> Edit
                                    </a>
                                    <a href="contacts.php?id=<?php echo $vendor_id; ?>&delete_contact=<?php echo $contact['id']; ?>" 
                                       class="btn btn-sm btn-outline-danger" 
                                       onclick="return confirm('Are you sure you want to delete this contact?')">
                                        <i class="fas fa-trash"></i> Delete
                                    </a>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="text-center">No contacts found</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>

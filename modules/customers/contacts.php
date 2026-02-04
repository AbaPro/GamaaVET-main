<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

if (!hasPermission('customers.contacts.manage')) {
    setAlert('danger', 'You do not have permission to access this page.');
    redirect('../../dashboard.php');
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    setAlert('danger', 'Invalid customer ID.');
    redirect('index.php');
}

$customer_id = sanitize($_GET['id']);
$page_title = 'Customer Contacts';

// Get customer info for header
$customer_sql = "SELECT name FROM customers WHERE id = ?";
$customer_stmt = $conn->prepare($customer_sql);
$customer_stmt->bind_param("i", $customer_id);
$customer_stmt->execute();
$customer_result = $customer_stmt->get_result();

if ($customer_result->num_rows === 0) {
    setAlert('danger', 'Customer not found.');
    redirect('index.php');
}

$customer = $customer_result->fetch_assoc();
$customer_stmt->close();

// Handle contact actions
if (isset($_GET['delete_contact']) && is_numeric($_GET['delete_contact'])) {
    $contact_id = sanitize($_GET['delete_contact']);
    
    // Check if contact is primary
    $check_sql = "SELECT is_primary FROM customer_contacts WHERE id = ? AND customer_id = ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("ii", $contact_id, $customer_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows === 0) {
        setAlert('danger', 'Contact not found.');
    } else {
        $contact = $check_result->fetch_assoc();
        if ($contact['is_primary']) {
            setAlert('danger', 'Cannot delete primary contact. Change primary contact first.');
        } else {
            $delete_sql = "DELETE FROM customer_contacts WHERE id = ?";
            $delete_stmt = $conn->prepare($delete_sql);
            $delete_stmt->bind_param("i", $contact_id);
            
            if ($delete_stmt->execute()) {
                setAlert('success', 'Contact deleted successfully.');
                logActivity("Deleted contact ID: $contact_id for customer ID: $customer_id");
            } else {
                setAlert('danger', 'Error deleting contact: ' . $conn->error);
            }
            $delete_stmt->close();
        }
    }
    $check_stmt->close();
    redirect("contacts.php?id=$customer_id");
}

if (isset($_GET['set_primary']) && is_numeric($_GET['set_primary'])) {
    $contact_id = sanitize($_GET['set_primary']);
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Remove primary from current primary contact
        $remove_sql = "UPDATE customer_contacts SET is_primary = 0 WHERE customer_id = ? AND is_primary = 1";
        $remove_stmt = $conn->prepare($remove_sql);
        $remove_stmt->bind_param("i", $customer_id);
        $remove_stmt->execute();
        $remove_stmt->close();
        
        // Set new primary contact
        $set_sql = "UPDATE customer_contacts SET is_primary = 1 WHERE id = ? AND customer_id = ?";
        $set_stmt = $conn->prepare($set_sql);
        $set_stmt->bind_param("ii", $contact_id, $customer_id);
        $set_stmt->execute();
        
        if ($set_stmt->affected_rows > 0) {
            setAlert('success', 'Primary contact updated successfully.');
            logActivity("Set contact ID: $contact_id as primary for customer ID: $customer_id");
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
    
    redirect("contacts.php?id=$customer_id");
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
            $remove_sql = "UPDATE customer_contacts SET is_primary = 0 WHERE customer_id = ? AND is_primary = 1";
            $remove_stmt = $conn->prepare($remove_sql);
            $remove_stmt->bind_param("i", $customer_id);
            $remove_stmt->execute();
            $remove_stmt->close();
        }
        
        // Insert new contact
        $insert_sql = "INSERT INTO customer_contacts 
                       (customer_id, name, email, phone, position, is_primary) 
                       VALUES (?, ?, ?, ?, ?, ?)";
        $insert_stmt = $conn->prepare($insert_sql);
        $insert_stmt->bind_param("issssi", $customer_id, $name, $email, $phone, $position, $is_primary);
        $insert_stmt->execute();
        $contact_id = $insert_stmt->insert_id;
        $insert_stmt->close();
        
        // Commit transaction
        $conn->commit();
        
        setAlert('success', 'Contact added successfully.');
        logActivity("Added contact ID: $contact_id for customer ID: $customer_id");
    } catch (Exception $e) {
        $conn->rollback();
        setAlert('danger', 'Error adding contact: ' . $e->getMessage());
    }
    
    redirect("contacts.php?id=$customer_id");
}

// Get all contacts for this customer
$contacts_sql = "SELECT * FROM customer_contacts WHERE customer_id = ? ORDER BY is_primary DESC, name";
$contacts_stmt = $conn->prepare($contacts_sql);
$contacts_stmt->bind_param("i", $customer_id);
$contacts_stmt->execute();
$contacts_result = $contacts_stmt->get_result();

// Include header only after all possible redirects and processing
require_once '../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>
        Contacts for: <?php echo htmlspecialchars($customer['name']); ?>
    </h2>
    <a href="view.php?id=<?php echo $customer_id; ?>" class="btn btn-secondary">Back to Customer</a>
</div>

<div class="row mb-4">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">Add New Contact</h5>
            </div>
            <div class="card-body">
                <form action="contacts.php?id=<?php echo $customer_id; ?>" method="POST">
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
        <h5 class="card-title mb-0">Customer Contacts</h5>
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
                                        <a href="contacts.php?id=<?php echo $customer_id; ?>&set_primary=<?php echo $contact['id']; ?>" 
                                           class="badge bg-warning text-dark" 
                                           onclick="return confirm('Set this contact as primary?')">
                                            Set Primary
                                        </a>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="edit_contact.php?id=<?php echo $contact['id']; ?>&customer_id=<?php echo $customer_id; ?>" 
                                       class="btn btn-sm btn-outline-primary">
                                        <i class="fas fa-edit"></i> Edit
                                    </a>
                                    <a href="contacts.php?id=<?php echo $customer_id; ?>&delete_contact=<?php echo $contact['id']; ?>" 
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



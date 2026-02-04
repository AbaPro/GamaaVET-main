<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

if (!hasPermission('vendors.create')) {
    setAlert('danger', 'You do not have permission to access this page.');
    redirect('../../dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Vendor basic info
    $name = sanitize($_POST['name']);
    $type = sanitize($_POST['type']);
    $email = !empty($_POST['email']) ? sanitize($_POST['email']) : NULL;
    $phone = sanitize($_POST['phone']);
    $tax_number = !empty($_POST['tax_number']) ? sanitize($_POST['tax_number']) : NULL;
    $wallet_balance = !empty($_POST['wallet_balance']) ? sanitize($_POST['wallet_balance']) : 0;
    
    // Primary contact info
    $contact_name = sanitize($_POST['contact_name']);
    $contact_position = !empty($_POST['contact_position']) ? sanitize($_POST['contact_position']) : NULL;
    $contact_email = !empty($_POST['contact_email']) ? sanitize($_POST['contact_email']) : NULL;
    $contact_phone = sanitize($_POST['contact_phone']);
    
    // Address info
    $address_line1 = sanitize($_POST['address_line1']);
    $address_line2 = !empty($_POST['address_line2']) ? sanitize($_POST['address_line2']) : NULL;
    $city = !empty($_POST['city']) ? sanitize($_POST['city']) : NULL;
    $state = !empty($_POST['state']) ? sanitize($_POST['state']) : NULL;
    $postal_code = !empty($_POST['postal_code']) ? sanitize($_POST['postal_code']) : NULL;
    $country = sanitize($_POST['country']);
    $is_default_address = isset($_POST['is_default_address']) ? 1 : 0;
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Insert vendor
        $vendor_sql = "INSERT INTO vendors 
                       (name, type, email, phone, tax_number, wallet_balance) 
                       VALUES (?, ?, ?, ?, ?, ?)";
        $vendor_stmt = $conn->prepare($vendor_sql);
        $vendor_stmt->bind_param("sisssd", $name, $type, $email, $phone, $tax_number, $wallet_balance);
        $vendor_stmt->execute();
        $vendor_id = $vendor_stmt->insert_id;
        $vendor_stmt->close();
        
        // Insert primary contact
        $contact_sql = "INSERT INTO vendor_contacts 
                        (vendor_id, name, email, phone, position, is_primary) 
                        VALUES (?, ?, ?, ?, ?, 1)";
        $contact_stmt = $conn->prepare($contact_sql);
        $contact_stmt->bind_param("issss", $vendor_id, $contact_name, $contact_email, $contact_phone, $contact_position);
        $contact_stmt->execute();
        $contact_stmt->close();
        
        // Insert address
        $address_sql = "INSERT INTO vendor_addresses 
                        (vendor_id, address_type, address_line1, address_line2, city, state, postal_code, country, is_default) 
                        VALUES (?, 'primary', ?, ?, ?, ?, ?, ?, ?)";
        $address_stmt = $conn->prepare($address_sql);
        $address_stmt->bind_param("issssssi", $vendor_id, $address_line1, $address_line2, $city, $state, $postal_code, $country, $is_default_address);
        $address_stmt->execute();
        $address_stmt->close();
        
        // If wallet balance is positive, add initial deposit transaction
        if ($wallet_balance > 0) {
            $wallet_sql = "INSERT INTO vendor_wallet_transactions 
                           (vendor_id, amount, type, created_by) 
                           VALUES (?, ?, 'deposit', ?)";
            $wallet_stmt = $conn->prepare($wallet_sql);
            $wallet_stmt->bind_param("idi", $vendor_id, $wallet_balance, $_SESSION['user_id']);
            $wallet_stmt->execute();
            $wallet_stmt->close();
        }
        
        // Commit transaction
        $conn->commit();
        
        setAlert('success', 'Vendor added successfully.');
        logActivity("Added new vendor: $name (ID: $vendor_id)");
    } catch (Exception $e) {
        $conn->rollback();
        setAlert('danger', 'Error adding vendor: ' . $e->getMessage());
    }
}

redirect('index.php');
?>

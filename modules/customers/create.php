<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

if (!hasPermission('customers.create')) {
    setAlert('danger', 'You do not have permission to access this page.');
    redirect('../../dashboard.php');
}

$customerUploadsDir = __DIR__ . '/../../assets/uploads/customers';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Customer basic info
    $name = sanitize($_POST['name']);
    $type = sanitize($_POST['type']);
    $factory_id = !empty($_POST['factory_id']) ? intval($_POST['factory_id']) : NULL;
    $email = !empty($_POST['email']) ? sanitize($_POST['email']) : NULL;
    $phone = sanitize($_POST['phone']);
    $whatsapp_phone = !empty($_POST['whatsapp_phone']) ? sanitize($_POST['whatsapp_phone']) : NULL;
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
    $city = sanitize($_POST['city']);
    $state = sanitize($_POST['state']);
    $postal_code = sanitize($_POST['postal_code']);
    $country = sanitize($_POST['country']);
    $is_default_address = isset($_POST['is_default_address']) ? 1 : 0;
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Insert customer
        $customer_sql = "INSERT INTO customers 
                         (name, type, factory_id, email, phone, whatsapp_phone, tax_number, wallet_balance) 
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        $customer_stmt = $conn->prepare($customer_sql);
        $customer_stmt->bind_param(
            "siissssd",
            $name,
            $type,
            $factory_id,
            $email,
            $phone,
            $whatsapp_phone,
            $tax_number,
            $wallet_balance
        );
        $customer_stmt->execute();
        $customer_id = $customer_stmt->insert_id;
        $customer_stmt->close();
        
        // Insert primary contact
        $contact_sql = "INSERT INTO customer_contacts 
                        (customer_id, name, email, phone, position, is_primary) 
                        VALUES (?, ?, ?, ?, ?, 1)";
        $contact_stmt = $conn->prepare($contact_sql);
        $contact_stmt->bind_param("issss", $customer_id, $contact_name, $contact_email, $contact_phone, $contact_position);
        $contact_stmt->execute();
        $contact_stmt->close();
        
        // Insert address
        $address_sql = "INSERT INTO customer_addresses 
                        (customer_id, address_type, address_line1, address_line2, city, state, postal_code, country, is_default) 
                        VALUES (?, 'primary', ?, ?, ?, ?, ?, ?, ?)";
        $address_stmt = $conn->prepare($address_sql);
        $address_stmt->bind_param("issssssi", $customer_id, $address_line1, $address_line2, $city, $state, $postal_code, $country, $is_default_address);
        $address_stmt->execute();
        $address_stmt->close();
        
        // If wallet balance is positive, add initial deposit transaction
        if ($wallet_balance > 0) {
            $wallet_sql = "INSERT INTO customer_wallet_transactions 
                           (customer_id, amount, type, created_by) 
                           VALUES (?, ?, 'deposit', ?)";
            $wallet_stmt = $conn->prepare($wallet_sql);
            $wallet_stmt->bind_param("idi", $customer_id, $wallet_balance, $_SESSION['user_id']);
            $wallet_stmt->execute();
            $wallet_stmt->close();
        }

        // Handle optional document uploads
        $allowedDocExt = ['pdf', 'jpg', 'jpeg', 'png'];
        if (!is_dir($customerUploadsDir)) {
            mkdir($customerUploadsDir, 0775, true);
        }
        $taxDocumentPath = null;
        $commercialDocumentPath = null;

        $documentConfig = [
            'tax_document' => [
                'type' => 'tax_registration',
                'number' => $tax_number ?: 'N/A'
            ],
            'commercial_document' => [
                'type' => 'commercial_registration',
                'number' => 'N/A'
            ]
        ];
        foreach ($documentConfig as $field => $meta) {
            if (!isset($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if ($_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('Failed to upload document: ' . $field);
            }
            $ext = strtolower(pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowedDocExt, true)) {
                throw new Exception('Unsupported file type for ' . $field . '. Allowed: PDF/JPG/PNG.');
            }
            if ($_FILES[$field]['size'] > 5 * 1024 * 1024) {
                throw new Exception('Document ' . $field . ' exceeds the 5MB limit.');
            }
            $fileName = sprintf('customer_%d_%s_%s.%s', $customer_id, $meta['type'], uniqid(), $ext);
            $destination = $customerUploadsDir . DIRECTORY_SEPARATOR . $fileName;
            if (!move_uploaded_file($_FILES[$field]['tmp_name'], $destination)) {
                throw new Exception('Unable to save uploaded file for ' . $field . '.');
            }

            $filePathDb = 'assets/uploads/customers/' . $fileName;

            if ($field === 'tax_document') {
                $taxDocumentPath = $filePathDb;
            } elseif ($field === 'commercial_document') {
                $commercialDocumentPath = $filePathDb;
            }

            $docStmt = $conn->prepare("INSERT INTO customer_documents (customer_id, document_type, document_number, file_path) VALUES (?, ?, ?, ?)");
            $docNumber = $meta['number'] ?: 'N/A';
            $docStmt->bind_param("isss", $customer_id, $meta['type'], $docNumber, $filePathDb);
            $docStmt->execute();
            $docStmt->close();
        }

        if ($taxDocumentPath !== null || $commercialDocumentPath !== null) {
            $docUpdateStmt = $conn->prepare("UPDATE customers SET tax_document_path = ?, commercial_document_path = ? WHERE id = ?");
            $docUpdateStmt->bind_param("ssi", $taxDocumentPath, $commercialDocumentPath, $customer_id);
            $docUpdateStmt->execute();
            $docUpdateStmt->close();
        }
        
        // Commit transaction
        $conn->commit();
        
        setAlert('success', 'Customer added successfully.');
        logActivity("Added new customer: $name (ID: $customer_id)");
    } catch (Exception $e) {
        $conn->rollback();
        setAlert('danger', 'Error adding customer: ' . $e->getMessage());
    }
}

redirect('index.php');
?>

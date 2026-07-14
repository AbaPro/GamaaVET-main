<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

if (!hasPermission('customers.edit')) {
    setAlert('danger', 'You do not have permission to access this page.');
    redirect('../../dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)$_POST['id'];
    if (!canAccessCustomer($id)) {
        setAlert('danger', 'You do not have permission to edit this customer.');
        redirect('index.php');
    }
    $name = sanitize($_POST['name']);
    $type = sanitize($_POST['type']);
    $factory_id = !empty($_POST['factory_id']) ? intval($_POST['factory_id']) : NULL;
    $email = !empty($_POST['email']) ? sanitize($_POST['email']) : NULL;
    $phone = sanitize($_POST['phone']);
    $whatsapp_phone = !empty($_POST['whatsapp_phone']) ? sanitize($_POST['whatsapp_phone']) : NULL;
    $tax_number = !empty($_POST['tax_number']) ? sanitize($_POST['tax_number']) : NULL;
    $region = !empty($_POST['region']) ? sanitize($_POST['region']) : NULL;
    $direct_sale = !empty($_POST['direct_sale']) ? sanitize($_POST['direct_sale']) : NULL;

    $currentStmt = $conn->prepare("SELECT sales_person_id FROM customers WHERE id = ?");
    $currentStmt->bind_param('i', $id);
    $currentStmt->execute();
    $currentCustomer = $currentStmt->get_result()->fetch_assoc();
    $currentStmt->close();
    if (!$currentCustomer) {
        setAlert('danger', 'Customer not found.');
        redirect('index.php');
    }

    if (isSalesPersonUser()) {
        $direct_sale = ($_SESSION['login_region'] ?? 'factory') === 'factory'
            ? NULL
            : sanitize($_SESSION['login_region']);
        if ($factory_id !== NULL && !canAccessFactory($factory_id)) {
            setAlert('danger', 'You can only link customers to a factory assigned to you.');
            redirect('index.php');
        }
        $sales_person_id = $currentCustomer['sales_person_id'] !== null
            ? (int)$currentCustomer['sales_person_id']
            : NULL;
    } elseif (isAdminUser()) {
        $sales_person_id = array_key_exists('sales_person_id', $_POST)
            ? (!empty($_POST['sales_person_id']) ? (int)$_POST['sales_person_id'] : NULL)
            : ($currentCustomer['sales_person_id'] !== null ? (int)$currentCustomer['sales_person_id'] : NULL);
        if ($sales_person_id !== NULL && !isValidSalesPersonId($sales_person_id)) {
            setAlert('danger', 'Please select a valid active sales person.');
            redirect('index.php');
        }
    } else {
        $sales_person_id = $currentCustomer['sales_person_id'] !== null
            ? (int)$currentCustomer['sales_person_id']
            : NULL;
    }
    
    $update_sql = "UPDATE customers SET 
                   name = ?, type = ?, factory_id = ?, sales_person_id = ?, email = ?, phone = ?, whatsapp_phone = ?, tax_number = ?, region = ?, direct_sale = ?
                   WHERE id = ?";
    $update_stmt = $conn->prepare($update_sql);
    $update_stmt->bind_param(
        "siiissssssi",
        $name,
        $type,
        $factory_id,
        $sales_person_id,
        $email,
        $phone,
        $whatsapp_phone,
        $tax_number,
        $region,
        $direct_sale,
        $id
    );
    
    if ($update_stmt->execute()) {
        setAlert('success', 'Customer updated successfully.');
        logActivity("Updated customer ID: $id");
    } else {
        setAlert('danger', 'Error updating customer: ' . $conn->error);
    }
    $update_stmt->close();
}

redirect('index.php');
?>

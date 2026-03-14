<?php
require_once '../../includes/auth.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Permission check
if (!hasPermission('products.delete')) {
    setAlert('danger', "You don't have permission to delete products");
    header("Location: index.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['product_ids'])) {
    $product_ids = $_POST['product_ids'];
    $success_count = 0;
    $skipped_count = 0;
    $errors = [];

    foreach ($product_ids as $id) {
        $id = (int)$id;

        try {
            // Check if product exists in any inventory
            $check_sql = "SELECT COUNT(*) as count FROM inventory_products WHERE product_id = ?";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param("i", $id);
            $check_stmt->execute();
            $in_inventory = $check_stmt->get_result()->fetch_assoc()['count'] > 0;
            $check_stmt->close();

            if ($in_inventory) {
                $errors[] = "Product ID $id is currently in inventory.";
                $skipped_count++;
                continue;
            }

            // Check if product is used as a component
            $component_sql = "SELECT COUNT(*) as count FROM product_components WHERE component_id = ?";
            $component_stmt = $conn->prepare($component_sql);
            $component_stmt->bind_param("i", $id);
            $component_stmt->execute();
            $is_component = $component_stmt->get_result()->fetch_assoc()['count'] > 0;
            $component_stmt->close();

            if ($is_component) {
                $errors[] = "Product ID $id is used as a component in another product.";
                $skipped_count++;
                continue;
            }

            // Check if product is used in manufacturing formulas
            $formula_sql = "SELECT COUNT(*) as count FROM manufacturing_formulas WHERE product_id = ?";
            $formula_stmt = $conn->prepare($formula_sql);
            $formula_stmt->bind_param("i", $id);
            $formula_stmt->execute();
            $is_in_formula = $formula_stmt->get_result()->fetch_assoc()['count'] > 0;
            $formula_stmt->close();

            if ($is_in_formula) {
                $errors[] = "Product ID $id is referenced in a manufacturing formula.";
                $skipped_count++;
                continue;
            }

            // Check if product is used in sales orders
            $order_sql = "SELECT COUNT(*) as count FROM sales_order_items WHERE product_id = ?";
            $order_stmt = $conn->prepare($order_sql);
            $order_stmt->bind_param("i", $id);
            $order_stmt->execute();
            $has_orders = $order_stmt->get_result()->fetch_assoc()['count'] > 0;
            $order_stmt->close();

            if ($has_orders) {
                $errors[] = "Product ID $id has associated sales orders and cannot be deleted.";
                $skipped_count++;
                continue;
            }

            // Delete product
            $delete_sql = "DELETE FROM products WHERE id = ?";
            $delete_stmt = $conn->prepare($delete_sql);
            $delete_stmt->bind_param("i", $id);
            if ($delete_stmt->execute()) {
                $success_count++;
            } else {
                $errors[] = "Error deleting product ID $id: " . $delete_stmt->error;
                $skipped_count++;
            }
            $delete_stmt->close();

        } catch (Exception $e) {
            $errors[] = "System error for Product ID $id: " . $e->getMessage();
            $skipped_count++;
        }
    }

    if ($success_count > 0) {
        setAlert('success', "Successfully deleted $success_count products.");
        logActivity("Bulk deleted products IDs: " . implode(', ', $product_ids));
    }
    
    if ($skipped_count > 0) {
        $error_msg = "Skipped $skipped_count products due to constraints: <br>• " . implode("<br>• ", array_unique($errors));
        setAlert('warning', $error_msg);
    }
}

header("Location: index.php");
exit();

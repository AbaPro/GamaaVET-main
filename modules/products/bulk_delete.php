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
            // Fetch product info to show SKU/Name in errors
            $info_stmt = $conn->prepare("SELECT name, sku FROM products WHERE id = ?");
            if (!$info_stmt) throw new Exception("Error preparing info fetch: " . $conn->error);
            $info_stmt->bind_param("i", $id);
            $info_stmt->execute();
            $product_info = $info_stmt->get_result()->fetch_assoc();
            $info_stmt->close();

            $p_display = $product_info ? ($product_info['sku'] ? $product_info['sku'] . " (" . $product_info['name'] . ")" : $product_info['name']) : "ID $id";

            // Check if product exists in any inventory
            $check_sql = "SELECT COUNT(*) as count FROM inventory_products WHERE product_id = ?";
            $check_stmt = $conn->prepare($check_sql);
            if (!$check_stmt) throw new Exception("Error preparing inventory check: " . $conn->error);
            $check_stmt->bind_param("i", $id);
            $check_stmt->execute();
            $in_inventory = $check_stmt->get_result()->fetch_assoc()['count'] > 0;
            $check_stmt->close();

            if ($in_inventory) {
                $errors[] = "Product $p_display is currently in inventory.";
                $skipped_count++;
                continue;
            }

            // Check if product is used as a component (direct relation)
            $component_sql = "SELECT COUNT(*) as count FROM product_components WHERE component_id = ?";
            $component_stmt = $conn->prepare($component_sql);
            if (!$component_stmt) throw new Exception("Error preparing component check: " . $conn->error);
            $component_stmt->bind_param("i", $id);
            $component_stmt->execute();
            $is_component = $component_stmt->get_result()->fetch_assoc()['count'] > 0;
            $component_stmt->close();

            if ($is_component) {
                $errors[] = "Product $p_display is used as a component in another product.";
                $skipped_count++;
                continue;
            }

            // Check if product is used in manufacturing formulas (as result or in JSON components)
            $formula_sql = "SELECT COUNT(*) as count FROM manufacturing_formulas 
                           WHERE product_id = ? 
                           OR JSON_CONTAINS(components_json, JSON_OBJECT('product_id', ?))";
            $formula_stmt = $conn->prepare($formula_sql);
            if (!$formula_stmt) throw new Exception("Error preparing formula check: " . $conn->error);
            $formula_stmt->bind_param("ii", $id, $id);
            $formula_stmt->execute();
            $is_in_formula = $formula_stmt->get_result()->fetch_assoc()['count'] > 0;
            $formula_stmt->close();

            if ($is_in_formula) {
                $errors[] = "Product $p_display is referenced in a manufacturing formula.";
                $skipped_count++;
                continue;
            }

            // Check if product is used in sales orders
            $order_sql = "SELECT COUNT(*) as count FROM order_items WHERE product_id = ?";
            $order_stmt = $conn->prepare($order_sql);
            if (!$order_stmt) throw new Exception("Error preparing order check: " . $conn->error);
            $order_stmt->bind_param("i", $id);
            $order_stmt->execute();
            $has_orders = $order_stmt->get_result()->fetch_assoc()['count'] > 0;
            $order_stmt->close();

            if ($has_orders) {
                $errors[] = "Product $p_display has associated sales orders.";
                $skipped_count++;
                continue;
            }

            // Check if product is used in purchase orders
            $po_sql = "SELECT COUNT(*) as count FROM purchase_order_items WHERE product_id = ?";
            $po_stmt = $conn->prepare($po_sql);
            if (!$po_stmt) throw new Exception("Error preparing PO check: " . $conn->error);
            $po_stmt->bind_param("i", $id);
            $po_stmt->execute();
            $has_po = $po_stmt->get_result()->fetch_assoc()['count'] > 0;
            $po_stmt->close();

            if ($has_po) {
                $errors[] = "Product $p_display has associated purchase orders.";
                $skipped_count++;
                continue;
            }

            // Check if product is used in quotations
            $quot_sql = "SELECT COUNT(*) as count FROM quotation_items WHERE product_id = ?";
            $quot_stmt = $conn->prepare($quot_sql);
            if (!$quot_stmt) throw new Exception("Error preparing quotation check: " . $conn->error);
            $quot_stmt->bind_param("i", $id);
            $quot_stmt->execute();
            $has_quot = $quot_stmt->get_result()->fetch_assoc()['count'] > 0;
            $quot_stmt->close();

            if ($has_quot) {
                $errors[] = "Product $p_display is referenced in quotations.";
                $skipped_count++;
                continue;
            }

            // Check if product is used in inventory transfers
            $trans_sql = "SELECT COUNT(*) as count FROM transfer_items WHERE product_id = ?";
            $trans_stmt = $conn->prepare($trans_sql);
            if (!$trans_stmt) throw new Exception("Error preparing transfer check: " . $conn->error);
            $trans_stmt->bind_param("i", $id);
            $trans_stmt->execute();
            $has_trans = $trans_stmt->get_result()->fetch_assoc()['count'] > 0;
            $trans_stmt->close();

            if ($has_trans) {
                $errors[] = "Product $p_display is referenced in inventory transfers.";
                $skipped_count++;
                continue;
            }

            // Check if product is used in returns
            $ret_sql = "SELECT COUNT(*) as count FROM order_returns WHERE product_id = ?";
            $ret_stmt = $conn->prepare($ret_sql);
            if (!$ret_stmt) throw new Exception("Error preparing returns check: " . $conn->error);
            $ret_stmt->bind_param("i", $id);
            $ret_stmt->execute();
            $has_ret = $ret_stmt->get_result()->fetch_assoc()['count'] > 0;
            $ret_stmt->close();

            if ($has_ret) {
                $errors[] = "Product $p_display has associated returns.";
                $skipped_count++;
                continue;
            }

            // Delete product
            $delete_sql = "DELETE FROM products WHERE id = ?";
            $delete_stmt = $conn->prepare($delete_sql);
            if (!$delete_stmt) throw new Exception("Error preparing product deletion: " . $conn->error);
            $delete_stmt->bind_param("i", $id);
            if ($delete_stmt->execute()) {
                $success_count++;
            } else {
                $errors[] = "Error deleting product $p_display: " . $delete_stmt->error;
                $skipped_count++;
            }
            $delete_stmt->close();

        } catch (Throwable $e) {
            $errors[] = "System error for Product $p_display: " . $e->getMessage();
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

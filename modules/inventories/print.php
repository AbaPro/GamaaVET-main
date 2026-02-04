<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

if (!hasPermission('inventories.print')) {
    setAlert('danger', 'You do not have permission to access this page.');
    redirect('../../dashboard.php');
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    setAlert('danger', 'Invalid inventory ID.');
    redirect('index.php');
}

$inventory_id = sanitize($_GET['id']);

// Get inventory info
$inventory_sql = "SELECT * FROM inventories WHERE id = ?";
$inventory_stmt = $conn->prepare($inventory_sql);
$inventory_stmt->bind_param("i", $inventory_id);
$inventory_stmt->execute();
$inventory_result = $inventory_stmt->get_result();

if ($inventory_result->num_rows === 0) {
    setAlert('danger', 'Inventory not found.');
    redirect('index.php');
}

$inventory = $inventory_result->fetch_assoc();
$inventory_stmt->close();

// Get inventory products
$products_sql = "SELECT p.id, p.name, p.sku, p.barcode, ip.quantity, p.min_stock_level 
                 FROM inventory_products ip 
                 JOIN products p ON ip.product_id = p.id 
                 WHERE ip.inventory_id = ? 
                 ORDER BY p.name";
$products_stmt = $conn->prepare($products_sql);
$products_stmt->bind_param("i", $inventory_id);
$products_stmt->execute();
$products_result = $products_stmt->get_result();

// Set header for PDF or print
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Report - <?php echo htmlspecialchars($inventory['name']); ?></title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 20px; }
        .header { text-align: center; margin-bottom: 20px; }
        .header h1 { margin: 0; }
        .header p { margin: 5px 0; }
        .info { margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
        .footer { margin-top: 30px; text-align: right; font-size: 0.8em; }
        @media print {
            .no-print { display: none; }
            body { padding: 0; }
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Inventory Report</h1>
        <h2><?php echo htmlspecialchars($inventory['name']); ?></h2>
        <p>Location: <?php echo htmlspecialchars($inventory['location']); ?></p>
        <p>Printed on: <?php echo date('M d, Y H:i'); ?></p>
    </div>
    
    <div class="info">
        <p><strong>Description:</strong> <?php echo htmlspecialchars($inventory['description']); ?></p>
    </div>
    
    <table>
        <thead>
            <tr>
                <th>SKU</th>
                <th>Product Name</th>
                <th>Barcode</th>
                <th>Quantity</th>
                <th>Min Stock</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($products_result->num_rows > 0): ?>
                <?php while ($product = $products_result->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($product['sku']); ?></td>
                        <td><?php echo htmlspecialchars($product['name']); ?></td>
                        <td><?php echo htmlspecialchars($product['barcode']); ?></td>
                        <td><?php echo $product['quantity']; ?></td>
                        <td><?php echo $product['min_stock_level']; ?></td>
                        <td>
                            <?php if ($product['quantity'] <= $product['min_stock_level']): ?>
                                <span style="color: red;">Low Stock</span>
                            <?php else: ?>
                                <span style="color: green;">In Stock</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr>
                    <td colspan="6" class="text-center">No products found in this inventory</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
    
    <div class="footer">
        <p>Printed by: <?php echo $_SESSION['user_name']; ?></p>
    </div>
    
    <div class="no-print" style="text-align: center; margin-top: 20px;">
        <button onclick="window.print()" class="btn btn-primary">Print Report</button>
        <button onclick="window.close()" class="btn btn-secondary">Close</button>
    </div>
    
    <script>
        window.onload = function() {
            // Auto-print if desired
            // window.print();
        };
    </script>
</body>
</html>
<?php
$products_stmt->close();
?>

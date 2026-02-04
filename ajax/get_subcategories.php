<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

if (!hasPermission('categories.manage')) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!isset($_GET['category_id']) || !is_numeric($_GET['category_id'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid category ID']);
    exit;
}

$category_id = sanitize($_GET['category_id']);

// Get subcategories
$sql = "SELECT id, name FROM categories WHERE parent_id = ? ORDER BY name";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $category_id);
$stmt->execute();
$result = $stmt->get_result();

$subcategories = [];
while ($row = $result->fetch_assoc()) {
    $subcategories[] = $row;
}
$stmt->close();

echo json_encode(['success' => true, 'subcategories' => $subcategories]);
?>

<?php
require_once '../../includes/auth.php';
require_once '../../includes/formula_access.php';

header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
if (($_SESSION['login_region'] ?? 'factory') !== 'factory'
    || !hasPermission('manufacturing.view') || !canViewManufacturingFormula()) {
    http_response_code(403);
    exit('Formula access required.');
}
$stmt = $pdo->prepare('SELECT customer_id, sample_images_json FROM manufacturing_formulas WHERE id = ?');
$stmt->execute([(int)($_GET['id'] ?? 0)]);
$formula = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$formula || !canAccessCustomer((int)$formula['customer_id'])) {
    http_response_code(404);
    exit;
}
$imageKey = $_GET['image'] ?? '';
foreach (json_decode($formula['sample_images_json'] ?? '[]', true) ?: [] as $image) {
    $path = $image['path'] ?? '';
    if (!is_string($imageKey) || !hash_equals(hash('sha256', $path), $imageKey)) continue;
    $root = realpath(__DIR__ . '/../../assets/uploads/manufacturing/formula_samples');
    $file = realpath(__DIR__ . '/../../' . $path);
    if (!$root || !$file || strpos($file, $root . DIRECTORY_SEPARATOR) !== 0 || !is_file($file)) break;
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) break;
    header('Content-Type: ' . $mime);
    readfile($file);
    exit;
}
http_response_code(404);

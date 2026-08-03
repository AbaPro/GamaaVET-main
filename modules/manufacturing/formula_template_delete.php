<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once 'lib.php';

if (!hasPermission('manufacturing.view') || !hasPermission('manufacturing.formula.view_all') || !hasPermission('manufacturing.delete')) {
    setAlert('danger', 'Access denied.');
    redirect('../../dashboard.php');
}
if (empty($_SESSION['formula_unlocked'])) {
    setAlert('info', 'Please unlock formulas first.');
    redirect('formulas.php');
}
if (!manufacturing_table_exists($conn, 'manufacturing_formula_templates')) {
    setAlert('warning', 'Formula templates are not installed yet. Apply the formula templates migration.');
    redirect('formulas.php');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('formula_templates.php');
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if ($id <= 0) {
    setAlert('danger', 'Invalid formula template.');
    redirect('formula_templates.php');
}

$stmt = $pdo->prepare('DELETE FROM manufacturing_formula_templates WHERE id = ?');
$stmt->execute([$id]);
setAlert($stmt->rowCount() ? 'success' : 'warning', $stmt->rowCount() ? 'Formula template deleted. Existing formulas were not changed.' : 'Formula template not found.');
redirect('formula_templates.php');

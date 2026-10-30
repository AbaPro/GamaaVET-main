<?php
// Lightweight heartbeat: never redirect JSON callers to a login HTML page.
session_start();
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
$active = isLoggedIn();
if (!$active) http_response_code(401);
session_write_close();
echo json_encode(['authenticated' => $active]);

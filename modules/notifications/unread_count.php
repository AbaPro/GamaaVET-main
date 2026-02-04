<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
header('Content-Type: application/json');
if (!isLoggedIn() || !hasPermission('notifications.view')) { echo json_encode(['count'=>0]); exit; }
echo json_encode(['count' => getUnreadNotificationsCount()]);


<?php
function inventoryRequestToken() {
    if (empty($_SESSION['inventory_request_token'])) $_SESSION['inventory_request_token'] = bin2hex(random_bytes(32));
    return $_SESSION['inventory_request_token'];
}
function inventoryValidateRequest() {
    $token = $_POST['inventory_request_token'] ?? '';
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !is_string($token)
        || !hash_equals(inventoryRequestToken(), $token)) {
        http_response_code(403);
        exit('Invalid request. Refresh the page and try again.');
    }
}

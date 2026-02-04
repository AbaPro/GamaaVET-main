<?php
// Allow overriding the defaults using config/local.php (ignored by git)
$localConfigPath = __DIR__ . '/local.php';
if (file_exists($localConfigPath)) {
    require_once $localConfigPath;
}

// Database configuration
$defaults = [
    'DB_HOST' => 'localhost',
    'DB_USER' => 'root',
    'DB_PASS' => '',
    'DB_NAME' => 'gammavet_stg3',
    'BASE_URL' => 'http://localhost/GammaVET/'
];

foreach ($defaults as $const => $value) {
    if (!defined($const)) {
        define($const, $value);
    }
}

// Create connection
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

// africa/cairo timezone
date_default_timezone_set('Africa/Cairo');

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// BASE_URL is defined above so it can be overridden by config/local.php.

// Set charset
$conn->set_charset("utf8mb4");

// make a PDO connection
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}


?>

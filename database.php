<?php
// config/database.php - PDO connection + error handler settings
define('DEV_MODE', false); // change to false for production

if (DEV_MODE) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(E_ALL);
}

// Now include error handler, which will conditionally register based on DEV_MODE
require_once __DIR__ . '/../includes/error_handler.php';

define('DB_HOST', '');
define('DB_NAME', '');
define('DB_USER', 'root');
define('DB_PASS', '');
define('BASE_URL', 'http://localhost/digital/');
define('BACKUP_KEY', 'eea524d4ad7e03e891993b808c465cc9ddd0c47a78c756c1aa91d97ef0');

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            error_log('Database connection failed: ' . $e->getMessage());
            die('Database connection failed. Please check configuration.');
        }
    }
    return $pdo;
}
<?php
$host = 'localhost';
$db   = 'archiflow_db'; // updated from architecture_db
$user = 'root';
$pass = '';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $conn = new mysqli($host, $user, $pass, $db);
    $conn->set_charset('utf8mb4');
} catch (mysqli_sql_exception $e) {
    if (stripos($e->getMessage(), 'Unknown database') !== false) {
        die("Database '$db' not found. Create it first: CREATE DATABASE $db CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci; then import your schema.");
    }
    die('DB connection error: ' . $e->getMessage());
}
?>

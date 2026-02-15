<?php
declare(strict_types=1);

require_once __DIR__ . '/constants.php';

$DB_HOST = 'localhost';
$DB_NAME = 'fixmyarea';
$DB_USER = 'root';
$DB_PASS = ''; // XAMPP default is empty

try {

    $pdo = new PDO(
        "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, // throw errors
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );

} catch (PDOException $e) {

    die("Database connection failed: " . $e->getMessage());
}
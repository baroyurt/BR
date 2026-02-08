<?php
// Europe/Nicosia timezone - tüm sistem için ortak
date_default_timezone_set('Europe/Nicosia');

define('DB_HOST', 'localhost');
define('DB_NAME', 'breaklistslot');
define('DB_USER', 'root');
define('DB_PASS', '');

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch(PDOException $e) {
    die("Bağlantı hatası: " . $e->getMessage());
}
?>
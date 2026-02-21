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
    // MySQL timezone'u PHP timezone ile senkronize et
    // Bu sayede FROM_UNIXTIME() ve UNIX_TIMESTAMP() doğru çalışır
    // Europe/Nicosia timezone offset'ini dinamik olarak al (yaz saati için de çalışır)
    $now = new DateTime('now', new DateTimeZone('Europe/Nicosia'));
    $offset = $now->format('P'); // +02:00 veya +03:00 formatında
    $pdo->exec("SET time_zone = '$offset'");
} catch(PDOException $e) {
    die("Bağlantı hatası: " . $e->getMessage());
}
?>
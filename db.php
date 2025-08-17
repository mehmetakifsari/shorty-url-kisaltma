<?php
// /v2/db.php
declare(strict_types=1);

$DB_HOST = 'localhost';
$DB_NAME = 'sirtkoyu_db'; // cPanel'deki veritabanı adı
$DB_USER = 'sirtkoyu_db'; // cPanel'deki kullanıcı adı
$DB_PASS = '^OymGEsf@mvp{9Xk'; // şifreniz

$dsn = "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $DB_USER, $DB_PASS, $options);
} catch (Throwable $e) {
    http_response_code(500);
    exit('Veritabanı bağlantı hatası: ' . $e->getMessage());
}

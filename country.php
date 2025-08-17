<?php
declare(strict_types=1);
ini_set('default_charset','UTF-8');
header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php'; // oturum kontrolü için

// Eğer dosya direkt tarayıcıdan çağrıldıysa:
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    if (empty($_SESSION['uid'])) {
        // giriş yapılmamış → login.php
        header('Location: login.php');
        exit;
    } else {
        // giriş yapılmış → admin.php
        header('Location: admin.php');
        exit;
    }
}

// Buradan sonrası sadece include edildiğinde çalışır
$country = $_GET['name'] ?? '';
if ($country === '') {
    echo "<p>Ülke seçilmedi.</p>";
    return;
}

$stmt = $pdo->prepare("SELECT * FROM clicks WHERE country = :country ORDER BY id DESC LIMIT 50");
$stmt->execute(['country' => $country]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<h3>" . htmlspecialchars($country) . " - Son 50 Tıklama</h3>";
if (!$rows) {
    echo "<p>Kayıt bulunamadı.</p>";
    return;
}

echo "<table>";
echo "<tr><th>ID</th><th>IP</th><th>URL ID</th><th>Referrer</th><th>Tarih</th></tr>";
foreach ($rows as $r) {
    echo "<tr>";
    echo "<td>{$r['id']}</td>";
    echo "<td>{$r['ip']}</td>";
    echo "<td>{$r['url_id']}</td>";
    echo "<td>" . htmlspecialchars($r['referrer'] ?? '-') . "</td>";
    echo "<td>{$r['created_at']}</td>";
    echo "</tr>";
}
echo "</table>";

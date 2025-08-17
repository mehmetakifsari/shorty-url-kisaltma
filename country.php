<?php
declare(strict_types=1);
ini_set('default_charset','UTF-8');
header('Content-Type: text/html; charset=UTF-8');
require_once __DIR__ . '/db.php';

$country = $_GET['name'] ?? '';
if ($country === '') {
    echo "<p>Ülke seçilmedi.</p>";
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM clicks WHERE country = :country ORDER BY id DESC LIMIT 50");
$stmt->execute(['country' => $country]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<h3>" . htmlspecialchars($country) . " - Son 50 Tıklama</h3>";
if (!$rows) {
    echo "<p>Kayıt bulunamadı.</p>";
    exit;
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

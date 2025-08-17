<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

$id = (int)($_GET['id'] ?? 0);
if ($id > 0) {
    $stmt = $pdo->prepare("DELETE FROM urls WHERE id=:id");
    $stmt->execute(['id'=>$id]);

    // clicks tablosunu da temizleyelim
    $stmt2 = $pdo->prepare("DELETE FROM clicks WHERE url_id=:id");
    $stmt2->execute(['id'=>$id]);
}
header("Location: admin.php");
exit;

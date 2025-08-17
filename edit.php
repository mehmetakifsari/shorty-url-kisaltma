<?php
// /v2/edit.php
declare(strict_types=1);

require __DIR__.'/db.php';
require __DIR__.'/helpers.php';
require __DIR__.'/auth.php';   // oturum kontrolü
require_login();               // sadece giriş yapanlar erişir

/** /v2 alt dizinini de dikkate alarak temel URL üretir */
function shorty_base_url(): string {
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
  $base   = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
  if ($base === '/' || $base === '\\') $base = '';
  return $scheme.'://'.$host.$base;
}

/** QR URL helper (admin.php ile aynı) */
if (!function_exists('qr_url')) {
  function qr_url(string $slug, int $size=6, string $fmt='png'): string {
    return 'qr.php?slug='.urlencode($slug).'&size='.$size.'&fmt='.$fmt;
  }
}

/* CSRF token */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['csrf'])) {
  $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf'];

/* Kaydı slug veya id ile getir */
$id   = isset($_GET['id'])   ? (int)$_GET['id'] : null;
$slug = isset($_GET['slug']) ? trim((string)$_GET['slug']) : null;

if ($id) {
  $st = $pdo->prepare('SELECT * FROM urls WHERE id=? LIMIT 1');
  $st->execute([$id]);
} elseif ($slug) {
  $st = $pdo->prepare('SELECT * FROM urls WHERE slug=? LIMIT 1');
  $st->execute([$slug]);
} else {
  // Hızlı seçim ekranı
  $rows = $pdo->query("SELECT id, slug, title, destination_url, is_active, click_count FROM urls ORDER BY id DESC LIMIT 200")->fetchAll();
  header('Content-Type: text/html; charset=UTF-8'); ?>
  <!doctype html>
  <meta charset="utf-8">
  <title>Kısa Link Seç</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    body{font-family:system-ui,Arial;max-width:1000px;margin:30px auto;padding:0 16px}
    table{border-collapse:collapse;width:100%}
    th,td{border:1px solid #ddd;padding:8px;font-size:14px}
    th{background:#f8f8f8}
    .badge{display:inline-block;background:#eef;padding:2px 6px;border-radius:6px}
    input[type=search]{padding:8px;border:1px solid #ccc;border-radius:8px;width:100%;max-width:320px}
  </style>
  <h1>🔧 Düzenlenecek Linki Seç</h1>
  <p><a href="admin.php">← Admin’e dön</a></p>

  <input type="search" id="q" placeholder="Slug veya başlık ara…" oninput="filterTable(this.value)">
  <table id="tbl">
    <tr><th>ID</th><th>Slug</th><th>Başlık</th><th>Hedef</th><th>Aktif</th><th>Tık</th><th>İşlem</th></tr>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?=$r['id']?></td>
        <td><?=htmlspecialchars($r['slug'])?></td>
        <td><?=htmlspecialchars($r['title'] ?? '')?></td>
        <td><?=htmlspecialchars(mb_strimwidth($r['destination_url'],0,60,'…'))?></td>
        <td><?=$r['is_active'] ? 'Evet' : 'Hayır'?></td>
        <td><span class="badge"><?=$r['click_count']?></span></td>
        <td><a href="?id=<?=$r['id']?>">Düzenle</a></td>
      </tr>
    <?php endforeach; ?>
  </table>
  <script>
    function filterTable(q){
      q = (q||'').toLowerCase().trim();
      const rows = document.querySelectorAll('#tbl tr');
      for(let i=1;i<rows.length;i++){
        const cells = rows[i].children;
        const slug  = (cells[1].innerText||'').toLowerCase();
        const title = (cells[2].innerText||'').toLowerCase();
        rows[i].style.display = (slug.includes(q)||title.includes(q)) ? '' : 'none';
      }
    }
  </script>
  <?php exit;
}

$row = $st->fetch();
if (!$row) {
  http_response_code(404);
  exit('Kayıt bulunamadı.');
}

/* POST işlemleri: güncelle / pasifleştir / aktif et */
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
    http_response_code(400);
    exit('Geçersiz CSRF.');
  }

  $action = $_POST['action'] ?? 'save';

  if ($action === 'toggle') {
    $newActive = (int)!((int)$row['is_active']);
    $u = $pdo->prepare('UPDATE urls SET is_active=? WHERE id=?');
    $u->execute([$newActive, (int)$row['id']]);
    $row['is_active'] = $newActive;
    $msg = $newActive ? 'Link AKTİF edildi.' : 'Link PASİF edildi.';
  } elseif ($action === 'save') {
    $newDest  = trim($_POST['destination_url'] ?? '');
    $newTitle = trim($_POST['title'] ?? '');
    $newSlug  = trim($_POST['slug'] ?? '');

    // Validasyonlar
    if (!validate_url($newDest)) {
      $msg = 'Hedef URL geçersiz.';
    } elseif ($newTitle !== '' && mb_strlen($newTitle) > 191) {
      $msg = 'Başlık en fazla 191 karakter olmalı.';
    } elseif ($newSlug === '') {
      $msg = 'Slug boş olamaz.';
    } elseif (!preg_match('/^[A-Za-z0-9_-]{3,32}$/', $newSlug)) {
      $msg = 'Slug 3-32, A-Z a-z 0-9 _ - olmalı.';
    } else {
      // Slug çakışma kontrolü (kendisi hariç)
      $q = $pdo->prepare('SELECT id FROM urls WHERE slug=? AND id<>? LIMIT 1');
      $q->execute([$newSlug, (int)$row['id']]);
      if ($q->fetch()) {
        $msg = 'Bu slug kullanımda.';
      } else {
        // Güncelle
        $u = $pdo->prepare('UPDATE urls SET destination_url=?, title=?, slug=? WHERE id=?');
        $u->execute([$newDest, ($newTitle===''? null : $newTitle), $newSlug, (int)$row['id']]);
        // Görünen satırı güncelle
        $row['destination_url'] = $newDest;
        $row['title']           = ($newTitle===''? null : $newTitle);
        $row['slug']            = $newSlug;
        $msg = 'Kayıt güncellendi.';
      }
    }
  }
}

/* Form */
header('Content-Type: text/html; charset=UTF-8'); ?>
<!doctype html>
<meta charset="utf-8">
<title>Kısa Link Düzenle</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
body{font-family:system-ui,Arial;max-width:720px;margin:30px auto;padding:0 16px}
label{display:block;margin:8px 0 4px}
input,button{font:inherit;padding:10px;border-radius:8px;border:1px solid #ccc;width:100%}
button{cursor:pointer}
.row{display:flex;gap:8px;align-items:center;margin:10px 0;flex-wrap:wrap}
.badge{display:inline-block;background:#eef;padding:2px 6px;border-radius:6px}
.msg{margin:12px 0;padding:10px;border-radius:8px;border:1px solid #cfe;background:#f6fffb}
.warn{margin:12px 0;padding:10px;border-radius:8px;border:1px solid #fed;background:#fffaf6}
small{color:#666}
</style>

<h1>🔧 Kısa Link Düzenle</h1>
<p><a href="admin.php">← Admin’e dön</a></p>

<?php if ($msg): ?>
  <div class="msg"><?=htmlspecialchars($msg)?></div>
<?php endif; ?>

<p>
  <strong>ID:</strong> <?=$row['id']?> |
  <strong>Kısa URL:</strong>
  <a target="_blank" href="<?=shorty_base_url().'/'.htmlspecialchars($row['slug'])?>">
    <?=shorty_base_url().'/'.htmlspecialchars($row['slug'])?>
  </a> |
  <strong>Durum:</strong> <?=$row['is_active'] ? 'Aktif' : 'Pasif'?> |
  <strong>Tık:</strong> <span class="badge"><?=$row['click_count']?></span>
</p>

<?php
  // ---- QR ÖNİZLEME BLOĞU ----
  $qrPrev = qr_url($row['slug'], 6, 'png');
  $qrBig  = qr_url($row['slug'], 8, 'png');
  $qrJ    = qr_url($row['slug'], 6, 'jpg');
?>
<div style="border:1px solid #e5e7f0;border-radius:10px;padding:12px;background:#fff;margin:10px 0;display:flex;gap:16px;align-items:center;flex-wrap:wrap">
  <img src="<?=htmlspecialchars($qrPrev)?>" alt="QR" style="width:150px;height:150px;border:1px solid #e5e7f0;border-radius:8px;background:#fff">
  <div>
    <div style="margin-bottom:6px"><a href="<?=$qrPrev?>" target="_blank" rel="noopener">PNG aç</a></div>
    <div style="margin-bottom:6px"><a href="<?=$qrBig?>" download="<?=htmlspecialchars($row['slug'])?>-qr.png">PNG indir (büyük)</a></div>
    <div style="margin-bottom:6px"><a href="<?=$qrJ?>" download="<?=htmlspecialchars($row['slug'])?>-qr.jpg">JPG indir</a></div>
  </div>
</div>

<form method="post">
  <input type="hidden" name="csrf" value="<?=$csrf?>">
  <input type="hidden" name="action" value="save">

  <label>Başlık (opsiyonel)</label>
  <input type="text" name="title" value="<?=htmlspecialchars($row['title'] ?? '')?>" maxlength="191" placeholder="Örn: Ağustos kampanyası">

  <label>Hedef URL</label>
  <input type="url" name="destination_url" required value="<?=htmlspecialchars($row['destination_url'])?>">

  <label>Slug (kısa url son kısmı)</label>
  <input type="text" name="slug" required value="<?=htmlspecialchars($row['slug'])?>" pattern="[A-Za-z0-9_-]{3,32}" title="3-32 karakter; harf, rakam, _ veya -">

  <div class="row">
    <button type="submit">Kaydet</button>
    <button type="submit" name="action" value="toggle" formaction="?id=<?=$row['id']?>">Aktif/Pasif Değiştir</button>
  </div>
</form>

<p class="warn">
  <strong>Not:</strong> Slug’ı değiştirirseniz eski kısa URL çalışmaz; yerine yeni kısa URL aktif olur.
  Tıklama geçmişi (clicks) bu linkin <em>id</em>’si ile bağlı olduğundan kaybolmaz.
</p>
<p><small>IP/bot tespiti ve referrer analitiği admin panelinde detaylı görünür.</small></p>

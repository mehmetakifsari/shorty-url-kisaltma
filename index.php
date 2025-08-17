<?php
// /v2/index.php
declare(strict_types=1);

// Çıktı tamponu: header() uyarılarını önler
ob_start();

require __DIR__.'/db.php';
require __DIR__.'/helpers.php';
require __DIR__.'/auth.php';
require_login();

/** UTF-8 güvenli uzunluk ölçer (mbstring yoksa strlen'e düşer) */
if (!function_exists('ulen')) {
  function ulen(string $s): int {
    return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
  }
}

/** /v2 alt dizinini de dikkate alarak temel URL üretir */
function shorty_base_url(): string {
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
  $base   = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
  if ($base === '/' || $base === '\\') $base = '';
  return $scheme.'://'.$host.$base;
}

/* ---------------- JSON API (POST /v2/index.php?api=shorten) ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_GET['api'] ?? '') === 'shorten')) {
  header('Content-Type: application/json; charset=UTF-8');

  $input = json_decode(file_get_contents('php://input'), true) ?? [];
  $url   = trim($input['url'] ?? '');
  $title = trim($input['title'] ?? '');
  $slug  = trim($input['slug'] ?? '');

  if (!validate_url($url)) {
    http_response_code(400);
    echo json_encode(['ok'=>false, 'error'=>'Geçersiz URL']); 
    ob_end_flush(); exit;
  }

  if ($title !== '') {
    if (ulen($title) > 191) { http_response_code(400); echo json_encode(['ok'=>false, 'error'=>'Başlık en fazla 191 karakter']); ob_end_flush(); exit; }
  } else { $title = null; }

  if ($slug !== '') {
    if (!preg_match('/^[A-Za-z0-9_-]{3,32}$/', $slug)) { http_response_code(400); echo json_encode(['ok'=>false, 'error'=>'Slug 3-32, A-Z a-z 0-9 _ -']); ob_end_flush(); exit; }
    $q = $pdo->prepare('SELECT id FROM urls WHERE slug=? LIMIT 1'); $q->execute([$slug]);
    if ($q->fetch()) { http_response_code(409); echo json_encode(['ok'=>false, 'error'=>'Slug kullanımda']); ob_end_flush(); exit; }
  } else {
    do {
      $slug = generate_slug(6);
      $q = $pdo->prepare('SELECT id FROM urls WHERE slug=? LIMIT 1'); $q->execute([$slug]);
      $exists = (bool)$q->fetch();
    } while ($exists);
  }

  $ip = client_ip();
  $ins = $pdo->prepare('INSERT INTO urls (slug, destination_url, title, created_ip) VALUES (?,?,?,?)');
  $ins->execute([$slug, $url, $title, $ip ?: null]);

  $short = shorty_base_url().'/'.$slug;
  echo json_encode(['ok'=>true, 'slug'=>$slug, 'short_url'=>$short]);
  ob_end_flush(); exit;
}

/* ---------------- Form POST (UI) ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_GET['api'])) {
  // DİKKAT: Burada daha önce ECHO/HTML yok!
  $url   = trim($_POST['url'] ?? '');
  $title = trim($_POST['title'] ?? '');
  $slug  = trim($_POST['slug'] ?? '');

  if (!validate_url($url)) { header('Location: ?error=invalid'); ob_end_flush(); exit; }

  if ($title !== '') {
    if (ulen($title) > 191) { header('Location: ?error=title'); ob_end_flush(); exit; }
  }
  $title = ($title === '') ? null : $title;

  if ($slug !== '') {
    if (!preg_match('/^[A-Za-z0-9_-]{3,32}$/', $slug)) { header('Location: ?error=slug'); ob_end_flush(); exit; }
    $q = $pdo->prepare('SELECT id FROM urls WHERE slug=? LIMIT 1'); $q->execute([$slug]);
    if ($q->fetch()) { header('Location: ?error=taken'); ob_end_flush(); exit; }
  } else {
    do {
      $slug = generate_slug(6);
      $q = $pdo->prepare('SELECT id FROM urls WHERE slug=? LIMIT 1'); $q->execute([$slug]);
      $exists = (bool)$q->fetch();
    } while ($exists);
  }

  $ip = client_ip();
  $ins = $pdo->prepare('INSERT INTO urls (slug, destination_url, title, created_ip) VALUES (?,?,?,?)');
  $ins->execute([$slug, $url, $title, $ip ?: null]);

  header('Location: ?ok=1&slug='.urlencode($slug));
  ob_end_flush(); exit;
}

/* ---------------- UI (GET) ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && empty($_GET['api'])) {
  $user = htmlspecialchars($_SESSION['name'] ?? 'Kullanıcı', ENT_QUOTES, 'UTF-8');
  $ok   = !empty($_GET['ok']) && !empty($_GET['slug']);
  $slug = $ok ? htmlspecialchars($_GET['slug'], ENT_QUOTES, 'UTF-8') : '';
  $short = $ok ? shorty_base_url().'/'.$slug : '';
  $err  = $_GET['error'] ?? '';
  $map = [
    'invalid' => 'Geçersiz URL.',
    'title'   => 'Başlık en fazla 191 karakter olmalı.',
    'slug'    => 'Slug 3-32, sadece A-Z a-z 0-9 _ - izinli.',
    'taken'   => 'Bu slug zaten kullanımda.'
  ];
  $msg = $err ? ($map[$err] ?? 'Bilinmeyen hata.') : '';

  echo <<<HTML
<!doctype html>
<meta charset="utf-8">
<title>Shorty – Link Kısalt</title>
<link rel="icon" type="image/svg+xml" href="/v2/images/favicon.svg">
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
body{font-family:system-ui,Arial;max-width:820px;margin:40px auto;padding:0 16px;background:#f7f8fb}
header{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px}
header .user{color:#333}
header a.btn{padding:8px 12px;border-radius:8px;background:#4a67e8;color:#fff;text-decoration:none}
.card{padding:20px;border:1px solid #e5e7f0;border-radius:12px;background:#fff}
label{display:block;margin:8px 0 4px}
input,button{font:inherit;padding:10px;border-radius:8px;border:1px solid #cfd3e1;width:100%}
button{cursor:pointer;background:#4a67e8;color:#fff;border-color:#4a67e8}
small{color:#666}
.success{background:#f3f7ff;padding:12px;border-radius:8px;border:1px solid #dbe7ff;margin-top:12px}
.error{background:#fff2f2;padding:12px;border-radius:8px;border:1px solid #ffd6d6;margin-top:12px;color:#a40000}
.links{margin-top:12px}
</style>

<header>
  <div class="user">👋 Merhaba, <strong>{$user}</strong></div>
  <nav>
    <a class="btn ghost" href="admin.php">Admin Panel</a>
    <a class="btn ghost" href="users.php">Kullanıcı Ekle/Sil</a>
    <a class="btn" href="logout.php">Çıkış Yap</a>
  </nav>
</header>

<h1>🔗 Shorty</h1>
<div class="card">
HTML;

  if ($msg !== '') {
    $safe = htmlspecialchars($msg, ENT_QUOTES, 'UTF-8');
    echo '<div class="error">'.$safe.'</div>';
  }

  echo <<<HTML
  <form method="post" action="">
    <label>Uzun URL</label>
    <input type="url" name="url" placeholder="https://..." required>

    <label>Başlık (opsiyonel) — raporlamada görünür</label>
    <input type="text" name="title" placeholder="Örn: Ağustos kampanyası">

    <label>Özel slug (opsiyonel) — kısa linkin son kısmı</label>
    <input type="text" name="slug" placeholder="ör: kampanya-2025">

    <br>
    <button type="submit">Kısalt</button>
  </form>
HTML;

  if ($ok) {
    $shortA = htmlspecialchars($short, ENT_QUOTES, 'UTF-8');
    echo '<p class="success">Kısa link hazır: <a href="'.$shortA.'" target="_blank" rel="noopener">'.$shortA.'</a></p>';
  }

  echo <<<HTML
  <div class="links">
    <small>İstatistik ve detaylar için <a href="admin.php">admin paneli</a>.</small>
  </div>
</div>
HTML;

  ob_end_flush(); exit;
}

/* ---------------- Fallback ---------------- */
http_response_code(404);
echo 'Not found';
ob_end_flush();

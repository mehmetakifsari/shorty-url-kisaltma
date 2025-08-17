<?php
// /v2/redirect.php
declare(strict_types=1);
require __DIR__.'/db.php';
require __DIR__.'/helpers.php';

/**
 * Doğrudan erişim koruması:
 * - Bu dosya direkt çağrılmışsa VE geçerli bir slug YOKSA
 *   oturum yoksa login.php'ye, varsa admin.php'ye yönlendir.
 * - Geçerli slug varsa normal redirect akışı çalışır (public davranış korunur).
 */
if (
  PHP_SAPI !== 'cli' &&
  isset($_SERVER['SCRIPT_FILENAME']) &&
  basename(__FILE__) === basename((string)$_SERVER['SCRIPT_FILENAME'])
) {
  $slugCheck = trim($_GET['slug'] ?? '');
  $isValidSlug = ($slugCheck !== '' && preg_match('/^[A-Za-z0-9_-]{3,32}$/', $slugCheck));

  if (!$isValidSlug) {
    if (session_status() !== PHP_SESSION_ACTIVE) {
      session_start();
    }
    if (empty($_SESSION['uid'])) {
      header('Location: login.php');
      exit;
    } else {
      header('Location: admin.php');
      exit;
    }
  }
}

// === Buradan sonrası: GEÇERLİ slug ile normal redirect akışı ===

$slug = trim($_GET['slug'] ?? '');
if ($slug === '' || !preg_match('/^[A-Za-z0-9_-]{3,32}$/', $slug)) {
  http_response_code(404);
  exit('Geçersiz kısa link.');
}

$q = $pdo->prepare('SELECT id, destination_url, is_active FROM urls WHERE slug = ? LIMIT 1');
$q->execute([$slug]);
$row = $q->fetch();
if (!$row || (int)$row['is_active'] !== 1) {
  http_response_code(404);
  exit('Kısa link bulunamadı/kapalı.');
}

$urlId = (int)$row['id'];
$dest  = (string)$row['destination_url'];

/** 1. parti ziyaretçi çerezi (yaklaşık tekil) */
$cookieName = 'shorty_uid';
$visitorId = $_COOKIE[$cookieName] ?? '';
if ($visitorId === '') {
  $visitorId = bin2hex(random_bytes(8)); // 16 hex
  @setcookie($cookieName, $visitorId, [
    'expires'  => time() + 60*60*24*365, // 1 yıl
    'path'     => '/',
    'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'httponly' => true,
    'samesite' => 'Lax',
  ]);
}

$ip        = client_ip() ?: null;
$ua        = $_SERVER['HTTP_USER_AGENT'] ?? '';
$refRaw    = $_SERVER['HTTP_REFERER'] ?? '';
$secFetch  = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '';   // bazı tarayıcılar gönderir
$referrerP = $_SERVER['HTTP_REFERRER_POLICY'] ?? '';  // nadiren gelir

// referrer host
$refHost = '';
if ($refRaw) { $p = @parse_url($refRaw); $refHost = $p['host'] ?? ''; }

// HTTPS -> HTTP düşüş kontrolü (gizlilik nedeniyle referrer düşebilir)
$fromHttps = (stripos($refRaw, 'https://') === 0);
$toHttp    = (stripos($dest,   'http://')  === 0);
$httpsToHttpDrop = ($fromHttps && $toHttp) ? 'YES' : 'NO';

// Çok uzun referrer’ı DB için kısalt (ör. 1024)
$REF_MAX = 1024;
$refForDb = (mb_strlen($refRaw, 'UTF-8') > $REF_MAX)
  ? mb_substr($refRaw, 0, $REF_MAX, 'UTF-8')
  : $refRaw;

// ---- DEBUG LOG ----
try {
  $logFile = __DIR__ . '/ref_debug.log';
  $line = sprintf(
    "[%s] slug=%s | ip=%s | https->http=%s | ref=%s | ua=%s | sec-fetch-site=%s | refpol=%s\n",
    date('Y-m-d H:i:s'),
    $slug,
    $ip ?? '(null)',
    $httpsToHttpDrop,
    $refRaw !== '' ? $refRaw : '(boş)',
    $ua !== '' ? $ua : '(boş)',
    $secFetch !== '' ? $secFetch : '(yok)',
    $referrerP !== '' ? $referrerP : '(yok)'
  );
  @file_put_contents($logFile, $line, FILE_APPEND);
} catch (Throwable $e) {
  // log başarısızsa sessiz geç
}

// Basit tarayıcı/cihaz tespiti
$browser = detect_browser($ua);
$device  = detect_device($ua);

// Geo
$country = null; $city = null;
if ($ip) {
  $geo = lookup_geo($ip);
  $country = $geo['country'];
  $city    = $geo['city'];
}

// Kaydet
$pdo->beginTransaction();
try {
  $ins = $pdo->prepare('INSERT INTO clicks (url_id, ip, visitor_id, country, city, user_agent, browser, device, referer, referer_host) 
                        VALUES (?,?,?,?,?,?,?,?,?,?)');
  $ins->execute([$urlId, $ip, $visitorId, $country, $city, $ua, $browser, $device, $refForDb, $refHost]);

  $upd = $pdo->prepare('UPDATE urls SET click_count = click_count + 1, last_click_at = NOW() WHERE id = ?');
  $upd->execute([$urlId]);

  $pdo->commit();
} catch (Throwable $e) {
  $pdo->rollBack();
  // Sessiz geç
}

// 302 yönlendirme (burada hiçbir echo/çıktı OLMAMALI)
header('Location: '.$dest, true, 302);
exit;

<?php
// /v2/qr.php?slug=ABC123&size=6&fmt=png|jpg
declare(strict_types=1);
require __DIR__.'/db.php';

function shorty_base(): string {
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
  $base   = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
  if ($base === '/' || $base === '\\') $base = '';
  return $scheme.'://'.$host.$base;
}

// ---- parametreler
$slug = preg_replace('/[^A-Za-z0-9_-]/','', $_GET['slug'] ?? '');
$size = max(2, min(10, (int)($_GET['size'] ?? 6)));       // 2..10
$fmt  = strtolower($_GET['fmt'] ?? 'png');                // png|jpg
if (!in_array($fmt, ['png','jpg'], true)) $fmt = 'png';

if ($slug === '') { http_response_code(400); exit('slug?'); }

// aktif mi?
$st = $pdo->prepare('SELECT slug, is_active FROM urls WHERE slug=? LIMIT 1');
$st->execute([$slug]);
$u = $st->fetch();
if (!$u || !$u['is_active']) { http_response_code(404); exit('not found'); }

// ---- klasör ve dosya isimleri
$target   = shorty_base().'/'.$slug;                      // QR verisi (kısa URL)
$dir      = __DIR__.'/images/qr';                         // kalıcı klasör
if (!is_dir($dir)) @mkdir($dir, 0775, true);
$pngFile  = $dir.'/qr_'.$slug.'_s'.$size.'.png';
$finalExt = ($fmt === 'jpg') ? 'jpg' : 'png';
$file     = $dir.'/qr_'.$slug.'_s'.$size.'.'.$finalExt;

// ---- dosya zaten var mı?
if (!file_exists($file)) {
  // Her durumda önce kaynak PNG üretelim / indirelim
  if (!file_exists($pngFile)) {
    $api = 'https://api.qrserver.com/v1/create-qr-code/?size='.($size*50).'x'.($size*50).'&data='.rawurlencode($target);
    $png = null;

    // allow_url_fopen kapalı olabilir; önce file_get_contents, sonra cURL deneriz
    $png = @file_get_contents($api);
    if ($png === false) {
      if (function_exists('curl_init')) {
        $ch = curl_init($api);
        curl_setopt_array($ch, [
          CURLOPT_RETURNTRANSFER => true,
          CURLOPT_TIMEOUT => 5,
          CURLOPT_FOLLOWLOCATION => true,
          CURLOPT_SSL_VERIFYPEER => true,
          CURLOPT_SSL_VERIFYHOST => 2,
          CURLOPT_USERAGENT => 'ShortyQR/1.0',
        ]);
        $png = curl_exec($ch);
        curl_close($ch);
      }
    }
    if ($png === false || $png === null) { http_response_code(502); exit('qr api error'); }
    file_put_contents($pngFile, $png);
  }

  // İstenen format JPG ise dönüştür
  if ($fmt === 'jpg') {
    // GD mevcutsa JPG çıkaralım; yoksa PNG’yi servis etmeye devam ederiz
    if (function_exists('imagecreatefrompng') && function_exists('imagejpeg')) {
      $im = @imagecreatefrompng($pngFile);
      if ($im !== false) {
        // beyaz zemin
        $w = imagesx($im); $h = imagesy($im);
        $bg = imagecreatetruecolor($w, $h);
        $white = imagecolorallocate($bg, 255, 255, 255);
        imagefill($bg, 0, 0, $white);
        imagecopy($bg, $im, 0, 0, 0, 0, $w, $h);
        @imagejpeg($bg, $file, 92);
        imagedestroy($bg);
        imagedestroy($im);
      } else {
        // dönüştürülemediyse PNG üzerinden servis verelim
        $file = $pngFile;
        $finalExt = 'png';
      }
    } else {
      // GD yok; PNG üzerinden servis
      $file = $pngFile;
      $finalExt = 'png';
    }
  } else {
    // fmt=png
    $file = $pngFile;
  }
}

// ---- çıktı
if (!file_exists($file)) { http_response_code(500); exit('qr file missing'); }

$mtime = filemtime($file) ?: time();
$etag  = md5($file.$mtime);

// Basit cache header’ları
header('ETag: "'.$etag.'"');
header('Last-Modified: '.gmdate('D, d M Y H:i:s', $mtime).' GMT');
header('Cache-Control: public, max-age=31536000, immutable');

if ($finalExt === 'jpg')  header('Content-Type: image/jpeg');
else                      header('Content-Type: image/png');

// İndirilebilir yapmak isterseniz (yorum kaldırın):
// header('Content-Disposition: inline; filename="'.basename($file).'"');

readfile($file);

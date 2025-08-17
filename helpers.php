<?php
// shorty/helpers.php
declare(strict_types=1);

function client_ip(): ?string {
  // 1) Cloudflare gerçek ziyaretçi IP'si
  if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
    $ip = trim($_SERVER['HTTP_CF_CONNECTING_IP']);
    if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
  }

  // 2) X-Forwarded-For: soldan ilk PUBLIC IP'yi al; yoksa listedeki ilk geçerli IP'yi al
  if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    $parts = explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR']);
    foreach ($parts as $part) {
      $ip = trim($part);
      if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return $ip;
      }
    }
    foreach ($parts as $part) {
      $ip = trim($part);
      if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
    }
  }

  // 3) X-Real-IP
  if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
    $ip = trim($_SERVER['HTTP_X_REAL_IP']);
    if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
  }

  // 4) Son çare: REMOTE_ADDR
  if (!empty($_SERVER['REMOTE_ADDR'])) {
    $ip = trim($_SERVER['REMOTE_ADDR']);
    if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
  }

  return null;
}

function detect_browser(string $ua): string {
  $ua = strtolower($ua);
  if (strpos($ua,'edg/')!==false) return 'Edge';
  if (strpos($ua,'opr/')!==false || strpos($ua,'opera')!==false) return 'Opera';
  if (strpos($ua,'chrome')!==false && strpos($ua,'chromium')===false) return 'Chrome';
  if (strpos($ua,'firefox')!==false) return 'Firefox';
  if (strpos($ua,'safari')!==false && strpos($ua,'chrome')===false) return 'Safari';
  if (strpos($ua,'trident')!==false || strpos($ua,'msie')!==false) return 'IE';
  if (preg_match('/bot|crawler|spider|slurp|curl|wget/i',$ua)) return 'Bot';
  return 'Other';
}

function detect_device(string $ua): string {
  if (preg_match('/tablet|ipad/i',$ua)) return 'tablet';
  if (preg_match('/mobi|android/i',$ua)) return 'mobile';
  if (preg_match('/bot|crawler|spider|slurp|curl|wget/i',$ua)) return 'bot';
  return 'desktop';
}

/** cURL ile GeoIP (paylaşımlı hostingte daha sorunsuz) */
function lookup_geo(string $ip): array {
  if (!filter_var($ip, FILTER_VALIDATE_IP)) return ['country'=>null,'city'=>null];

  $ch = curl_init("https://ipwho.is/".urlencode($ip)."?fields=success,country,city");
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 2,
    CURLOPT_CONNECTTIMEOUT => 2,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_USERAGENT => 'Shorty/1.0',
  ]);
  $resp = curl_exec($ch);
  $err  = curl_errno($ch);
  curl_close($ch);

  if ($err || !$resp) return ['country'=>null,'city'=>null];
  $j = json_decode($resp, true);
  if (!is_array($j) || empty($j['success'])) return ['country'=>null,'city'=>null];
  return ['country'=>$j['country'] ?? null, 'city'=>$j['city'] ?? null];
}

function validate_url(string $url): bool {
  return filter_var($url, FILTER_VALIDATE_URL) && preg_match('#^https?://#i',$url);
}

function generate_slug(int $length=6): string {
  $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
  $out='';
  for($i=0;$i<$length;$i++) $out .= $alphabet[random_int(0,strlen($alphabet)-1)];
  return $out;
}

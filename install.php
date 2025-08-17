<?php
/**
 * install.php — Tek dosyalık kurulum sihirbazı (CSS gap + db.php yazımı)
 * - images/qr klasörünü 0755 oluşturur
 * - GitHub ZIP (main.zip) indirir, ilk klasörü soyup köke açar
 * - config/.db_setup.ini (geçici) ve db.php (kalıcı) yazar
 * - index.php'ye tek seferlik DB kurulum bloğu enjekte eder (önce db.php ile bağlanır)
 * - install.lock ile tekrar kurulumu engeller (?reset=1 ile kaldır)
 */
declare(strict_types=1);
session_start();
ini_set('default_charset','UTF-8');

const MIN_PHP      = '8.0.0';
$BASE              = realpath(__DIR__) ?: __DIR__;
$LOCK              = $BASE.'/install.lock';
$IMAGES_QR_DIR     = $BASE.'/images/qr';
$REMOTE_URL        = 'https://github.com/mehmetakifsari/shorty-url-kisaltma/archive/refs/heads/main.zip';
$TMP_ZIP           = $BASE.'/package_remote.zip';
$INDEX_PATH        = $BASE.'/index.php';
$DB_SETUP_INI      = $BASE.'/config/.db_setup.ini';   // geçici
$DB_FILE           = $BASE.'/db.php';                 // kalıcı
$DB_INSTALLED_FLAG = $BASE.'/.db_installed';

// ZIP açılırken üzerine yazılmaması gereken dosyalar:
$EXCLUDES = [
  'install.php', 'install.lock', '.db_installed', 'db.php' // db.php'yi biz yazacağız, paketten gelirse ezmesin
];

// ============ Yardımcılar ============
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
function tok(): string { if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16)); return $_SESSION['csrf']; }
function checktok(string $t): bool { return isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $t); }
function ok(bool $b): string { return $b ? '<span style="color:#16a34a">✔</span>' : '<span style="color:#dc2626">✘</span>'; }
function ver_ok(): bool { return version_compare(PHP_VERSION, MIN_PHP, '>='); }
function exts(array $xs): array { $r=[]; foreach($xs as $x){ $r[$x]=extension_loaded($x);} return $r; }
function php_sq(string $s): string { return str_replace(["\\","'"], ["\\\\","\\'"], $s); } // PHP tek tırnak kaçış

function render_head(string $ttl='Kurulum'): void {
  echo '<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
  echo '<title>'.h($ttl).'</title><style>
  :root{--bg:#0b1020;--card:#111827;--mut:#9ca3af;--txt:#e5e7eb;--btn:#2563eb;--b:#1f2937}
  body{background:var(--bg);color:var(--txt);font-family:system-ui,Segoe UI,Roboto,Arial;margin:0;padding:24px}
  .wrap{max-width:980px;margin:0 auto}
  .card{background:#111827;border:1px solid var(--b);border-radius:14px;padding:20px;margin-bottom:16px;box-shadow:0 10px 30px rgba(0,0,0,.25)}
  h1{margin:0 0 10px;font-size:24px} h2{margin:18px 0 8px;font-size:18px}
  table{width:100%;border-collapse:collapse} th,td{border-bottom:1px solid var(--b);padding:10px;text-align:left;font-size:14px}
  .mut{color:var(--mut)}
  input[type=text],input[type=password]{width:100%;background:#0f172a;color:#e5e7eb;border:1px solid var(--b);border-radius:10px;padding:10px;margin-bottom:8px}
  .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:20px 24px}
  .btn{background:var(--btn);color:#fff;border:0;border-radius:10px;padding:12px 16px;cursor:pointer;font-weight:600}
  .btn:disabled{opacity:.5;cursor:not-allowed}
  .note{background:#0f172a;border:1px solid var(--b);border-radius:10px;padding:10px}
  details summary{cursor:pointer}
  .footer{margin-top:12px;color:#9ca3af;font-size:12px}
  </style></head><body><div class="wrap">';
}
function render_foot(): void { echo '<div class="footer">Powered by Revenge ❤️</div></div></body></html>'; }

function http_get(string $url): string|false {
  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>true,
      CURLOPT_TIMEOUT=>120, CURLOPT_CONNECTTIMEOUT=>15,
      CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2,
      CURLOPT_USERAGENT=>'shorty-installer/1.0'
    ]);
    $data = curl_exec($ch);
    curl_close($ch);
    return $data;
  }
  if (ini_get('allow_url_fopen')) return @file_get_contents($url);
  return false;
}

// ZIP’i köke aç: GitHub’ın üst klasörünü (repo-main/) soyar ve EXCLUDES’teki adları atlar
function extract_zip_flat(string $zipFile, string $dest, array $excludes): array {
  $log = [];
  if (!extension_loaded('zip')) return [false, ['ZipArchive eklentisi yüklü değil.']];
  $z = new ZipArchive();
  if ($z->open($zipFile)!==true) return [false, ["Zip açılamadı: $zipFile"]];
  $prefix = '';
  if ($z->numFiles>0) {
    $first = $z->getNameIndex(0);
    if (preg_match('~^([^/]+/)~', $first, $m)) $prefix = $m[1];
  }
  for ($i=0; $i<$z->numFiles; $i++) {
    $name = $z->getNameIndex($i);
    if ($prefix && str_starts_with($name, $prefix)) $name = substr($name, strlen($prefix));
    $base = trim($name, '/');
    if ($base==='') continue; // üst klasör
    $bn = basename($base);
    if (in_array($bn, $excludes, true)) { $log[]="(atl.) $base"; continue; }

    $target = $dest . DIRECTORY_SEPARATOR . $base;
    if (str_ends_with($name,'/')) {
      @mkdir($target, 0755, true);
      $log[] = "dir  -> $base";
    } else {
      @mkdir(dirname($target), 0755, true);
      $bytes = $z->getFromIndex($i);
      if ($bytes===false) { $log[]="ERR -> $base (okunamadı)"; continue; }
      file_put_contents($target, $bytes);
      $log[] = "file-> $base";
    }
  }
  $z->close();
  return [true, $log];
}

// index.php'ye tek seferlik kurulum bloğu enjekte et (zaten varsa ekleme)
function inject_once_to_index(string $indexFile, string $injectCode, string $markerBegin, string $markerEnd): bool {
  if (!is_file($indexFile)) return false;
  $src = file_get_contents($indexFile);
  if ($src===false) return false;
  if (str_contains($src, $markerBegin) && str_contains($src, $markerEnd)) {
    // daha önce enjekte edilmiş
    return true;
  }
  // PHP kapanış etiketi varsa öncesine, yoksa en sona ekle
  if (preg_match('~\?>\s*$~', $src)) {
    $src = preg_replace('~\?>\s*$~', "\n{$injectCode}\n?>", $src);
  } else {
    $src .= "\n{$injectCode}\n";
  }
  return file_put_contents($indexFile, $src)!==false;
}

// ============ Reset/Kilit ============
if (isset($_GET['reset']) && $_GET['reset']==='1') { @unlink($LOCK); @unlink($DB_INSTALLED_FLAG); @unlink($DB_SETUP_INI); header('Location: '.$_SERVER['PHP_SELF']); exit; }
if (is_file($LOCK)) { render_head('Kurulum Tamamlandı'); echo '<div class="card"><h1>✅ Kurulum kilitli</h1><p>Gerekirse <code>?reset=1</code> ile kilidi kaldırın.</p></div>'; render_foot(); exit; }

// ============ Gereksinimler ============
$PHP_OK  = ver_ok();
$EXT_REQ = exts(['pdo','pdo_mysql','mbstring','json','curl','openssl','fileinfo','zip']);
$CAN_INSTALL = $PHP_OK && !in_array(false, $EXT_REQ, true);

// ============ POST ============
$isPost = ($_SERVER['REQUEST_METHOD']==='POST') && (($_POST['act']??'')==='install');
if ($isPost && !checktok($_POST['csrf'] ?? '')) { http_response_code(400); die('CSRF doğrulaması başarısız'); }

if ($isPost) {
  $db_host = trim((string)($_POST['db_host'] ?? 'localhost'));
  $db_port = trim((string)($_POST['db_port'] ?? '3306'));
  $db_name = trim((string)($_POST['db_name'] ?? ''));
  $db_user = trim((string)($_POST['db_user'] ?? ''));
  $db_pass = (string)($_POST['db_pass'] ?? '');

  $errs = [];
  if ($db_name==='') $errs[] = 'Veritabanı adı boş olamaz';
  if ($db_user==='') $errs[] = 'Veritabanı kullanıcı adı boş olamaz';
  if (!$CAN_INSTALL) $errs[] = 'Zorunlu gereksinimler karşılanmıyor';

  render_head('Kurulum Başlatılıyor');
  echo '<div class="card"><h1>🚀 Kurulum</h1>';

  if ($errs) {
    echo '<p style="color:#dc2626"><strong>Hata:</strong> '.h(implode(' • ',$errs)).'</p><p><a class="btn" href="'.h($_SERVER['PHP_SELF']).'">Geri Dön</a></p></div>';
    render_foot(); exit;
  }

  // 1) images/qr (0755)
  if (!is_dir($IMAGES_QR_DIR)) { @mkdir($IMAGES_QR_DIR, 0755, true); }
  echo '<p>'.ok(is_dir($IMAGES_QR_DIR)).' images/qr oluşturma</p>';

  // 2) ZIP indir & çıkar
  $deployOk=false; $deployLog=[];
  $bytes = http_get($REMOTE_URL);
  if ($bytes!==false && @file_put_contents($TMP_ZIP,$bytes)!==false) {
    [$deployOk, $deployLog] = extract_zip_flat($TMP_ZIP, $BASE, $EXCLUDES);
    @unlink($TMP_ZIP);
  } else {
    $deployLog[] = 'ZIP indirilemedi ya da yazılamadı.';
  }
  echo '<p>'.ok($deployOk).' GitHub ZIP açma</p>';
  echo '<details class="note"><summary>Dosya kurulum kaydı</summary><pre style="white-space:pre-wrap;word-break:break-word;color:#e5e7eb;">'.h(implode("\n",$deployLog)).'</pre></details>';

  // 3) Geçici INI + kalıcı db.php yaz
  @is_dir(dirname($DB_SETUP_INI)) || @mkdir(dirname($DB_SETUP_INI), 0755, true);
  $ini = "DB_HOST={$db_host}\nDB_PORT={$db_port}\nDB_NAME={$db_name}\nDB_USER={$db_user}\nDB_PASS=".str_replace(["\r","\n"],['',''],$db_pass)."\n";
  $iniOk = file_put_contents($DB_SETUP_INI, $ini)!==false;

  $dsn = "mysql:host={$db_host};port={$db_port};dbname={$db_name};charset=utf8mb4";
  $dbPhp = <<<PHP
<?php
// AUTO-GENERATED by install.php
declare(strict_types=1);
return [
  'dsn'  => '{$dsn}',
  'user' => '".php_sq($db_user)."',
  'pass' => '".php_sq($db_pass)."',
];
PHP;
  $dbOk = (file_put_contents($DB_FILE, $dbPhp)!==false);
  echo '<p>'.ok($iniOk).' Geçici DB yapılandırması (config/.db_setup.ini)</p>';
  echo '<p>'.ok($dbOk).' db.php oluşturuldu</p>';

  // 4) index.php'ye kurulum bloğu enjekte et
  $markerBegin = "/*==INSTALLER_DB_SETUP_BEGIN==*/";
  $markerEnd   = "/*==INSTALLER_DB_SETUP_END==*/";
  $injectCode  = <<<PHP
{$markerBegin}
if (!is_file(__DIR__.'/.db_installed')) {
    try {
        // 1) Öncelik: db.php
        \$pdo = null;
        \$cfgFile = __DIR__ . '/db.php';
        if (is_file(\$cfgFile)) {
            \$cfg = require \$cfgFile;
            if (is_array(\$cfg) && !empty(\$cfg['dsn'])) {
                \$pdo = new PDO(\$cfg['dsn'], \$cfg['user'] ?? null, \$cfg['pass'] ?? null, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]);
            }
        }
        // 2) Olmadıysa geçici ini (installer)
        if (!\$pdo) {
            \$ini = @parse_ini_file(__DIR__.'/config/.db_setup.ini', false, INI_SCANNER_RAW) ?: [];
            \$h = \$ini['DB_HOST'] ?? 'localhost';
            \$p = \$ini['DB_PORT'] ?? '3306';
            \$n = \$ini['DB_NAME'] ?? '';
            \$u = \$ini['DB_USER'] ?? '';
            \$w = \$ini['DB_PASS'] ?? '';
            if (\$n === '' || \$u === '') { throw new RuntimeException('Eksik DB bilgisi'); }
            \$dsn = "mysql:host={\$h};port={\$p};dbname={\$n};charset=utf8mb4";
            \$pdo = new PDO(\$dsn, \$u, \$w, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        }

        // === ÖRNEK ŞEMA — ihtiyacına göre düzenle ===
        \$pdo->exec("
        CREATE TABLE IF NOT EXISTS urls (
          id INT AUTO_INCREMENT PRIMARY KEY,
          slug VARCHAR(64) UNIQUE NOT NULL,
          destination_url TEXT NOT NULL,
          title VARCHAR(255) NULL,
          is_active TINYINT(1) NOT NULL DEFAULT 1,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
        \$pdo->exec("CREATE INDEX IF NOT EXISTS idx_urls_active ON urls(is_active)");

        \$pdo->exec("
        CREATE TABLE IF NOT EXISTS settings (
          id INT AUTO_INCREMENT PRIMARY KEY,
          name VARCHAR(100) UNIQUE NOT NULL,
          value TEXT,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
        \$stmt = \$pdo->prepare("INSERT IGNORE INTO settings(name,value) VALUES(?,?)");
        \$stmt->execute(['site_title','Shorty Link Kısaltma']);

        // Kurulum tamam — bayrak bırak ve geçici ini'yi sil
        @file_put_contents(__DIR__.'/.db_installed', date('c'));
        @unlink(__DIR__.'/config/.db_setup.ini');
    } catch (Throwable \$e) {
        echo "<div style=\\"background:#fee2e2;color:#7f1d1d;padding:10px;border-radius:8px;margin:10px 0\\">Kurulum hatası: ".htmlspecialchars(\$e->getMessage(), ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')."</div>";
    }
}
{$markerEnd}
PHP;

  $injOk = inject_once_to_index($INDEX_PATH, $injectCode, $markerBegin, $markerEnd);
  echo '<p>'.ok($injOk).' index.php içine tek-seferlik kurulum bloğu enjekte edildi</p>';

  // 5) install.lock
  $locked = file_put_contents($LOCK, "installed_at=".date('c')."\n")!==false;
  echo '<p>'.ok($locked).' Kurulum kilidi oluşturuldu (install.lock)</p>';

  echo '<p class="mut">İlk açılışta <code>index.php</code> tabloları kurar, sonra <code>.db_installed</code> yazar ve <code>config/.db_setup.ini</code> silinir.</p>';
  echo '<p><a class="btn" href="./">Siteye Git</a> <a class="btn" href="'.h($_SERVER['PHP_SELF']).'">Kurulum Sayfasına Dön</a></p>';
  echo '</div>'; render_foot(); exit;
}

// ============ Ekran ============
render_head('Kurulum');
?>
<div class="card">
  <h1>Kurulum Sihirbazı</h1>
  <p class="mut">Bu sihirbaz, GitHub paketini indirir, <code>images/qr</code> klasörünü oluşturur, <code>db.php</code> dosyasını yazar ve <code>index.php</code>'ye tek seferlik DB kurulum kodu ekler.</p>

  <h2>Gereksinimler</h2>
  <table>
    <tr><th>Koşul</th><th>Durum</th><th>Bilgi</th></tr>
    <tr><td>PHP ≥ <?=h(MIN_PHP)?></td><td><?=ok(ver_ok())?></td><td><?=h(PHP_VERSION)?></td></tr>
  </table>
  <h2>Zorunlu Uzantılar</h2>
  <table>
    <tr><th>Uzantı</th><th>Durum</th></tr>
    <?php foreach (exts(['pdo','pdo_mysql','mbstring','json','curl','openssl','fileinfo','zip']) as $e=>$v): ?>
      <tr><td><?=h($e)?></td><td><?=ok($v)?></td></tr>
    <?php endforeach; ?>
  </table>
</div>

<div class="card">
  <h2>Veritabanı Bilgileri</h2>
  <form method="post" class="grid" onsubmit="return confirm('Kurulumu başlatmak istiyor musunuz?');">
    <input type="hidden" name="csrf" value="<?=h(tok())?>">
    <input type="hidden" name="act" value="install">
    <div><label class="mut">Sunucu (host)</label><input type="text" name="db_host" value="localhost"></div>
    <div><label class="mut">Port</label><input type="text" name="db_port" value="3306"></div>
    <div><label class="mut">Veritabanı</label><input type="text" name="db_name" placeholder="veritabani_adi"></div>
    <div><label class="mut">Kullanıcı</label><input type="text" name="db_user" placeholder="kullanici_adi"></div>
    <div><label class="mut">Şifre</label><input type="password" name="db_pass" placeholder="••••••••"></div>
    <div style="align-self:end"><button class="btn" <?=$CAN_INSTALL?'':'disabled'?>>Kur</button></div>
  </form>
  <p class="mut">ZIP kaynağı: <code><?=h($REMOTE_URL)?></code></p>
</div>
<?php render_foot();

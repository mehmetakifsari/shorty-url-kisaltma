<?php
// /v2/admin.php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors','1');

require __DIR__.'/db.php';          // $pdo
require __DIR__.'/auth.php';        // oturum kontrolü
require_login();                    // gerekirse sadece admin: require_admin();

/** /v2 alt dizinini de dikkate alarak temel URL üretir */
function shorty_base_url(): string {
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
  $base   = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
  if ($base === '/' || $base === '\\') $base = '';
  return $scheme.'://'.$host.$base;
}

/** QR dosya URL’i */
function qr_url(string $slug, int $size=6, string $fmt='png'): string {
  return 'qr.php?slug='.urlencode($slug).'&size='.$size.'&fmt='.$fmt;
}

/** IP maskele (görsel amaçlı) */
function mask_ip(?string $ip): string {
  if (!$ip) return '(null)';
  if (strpos($ip, ':') !== false) { $parts = explode(':', $ip); if (count($parts)>=4) { $parts = array_slice($parts,0,4);} return implode(':',$parts).'::'; }
  if (preg_match('/^\d+\.\d+\.\d+\.\d+$/',$ip)) { $p=explode('.',$ip); $p[3]='0'; return implode('.',$p); }
  return $ip;
}

$slug    = trim($_GET['slug']  ?? '');     // belirli link detayı
$q       = trim($_GET['q']     ?? '');     // arama
$range   = trim($_GET['range'] ?? '7d');   // 7d, 30d, all
$onlyQr  = isset($_GET['only_qr']) ? (int)$_GET['only_qr'] : 0;

$rangeSql = '';
if ($range === '7d')  $rangeSql = "AND c.clicked_at >= (NOW() - INTERVAL 7 DAY)";
if ($range === '30d') $rangeSql = "AND c.clicked_at >= (NOW() - INTERVAL 30 DAY)";
$qrSql = $onlyQr ? "AND c.is_qr = 1" : "";

header('Content-Type: text/html; charset=UTF-8');
?>
<!doctype html>
<meta charset="utf-8">
<title>Shorty Admin</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
:root{--b:#4a67e8;--bg:#fff;--mut:#666}
body{font-family:system-ui,Arial;max-width:1100px;margin:30px auto;padding:0 16px;background:#f8f9ff}
h1{margin:0 0 12px}
header{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px}
header .user{color:#333}
.btn{display:inline-block;padding:8px 12px;border-radius:8px;background:#4a67e8;color:#fff;text-decoration:none}
.btn.ghost{background:#e9ecff;color:#223}
table{border-collapse:collapse;width:100%;background:#fff}
th,td{border:1px solid #e5e7f0;padding:8px 10px;font-size:14px;vertical-align:top}
th{background:#f6f7fb;text-align:left}
code{background:#f3f4f8;padding:2px 4px;border-radius:4px}
.filters{display:flex;gap:8px;align-items:center;margin-bottom:12px;flex-wrap:wrap}
input,select,button{font:inherit;padding:6px 10px}
.badge{display:inline-block;background:#eef;padding:2px 6px;border-radius:6px}
small{color:#666}
.actions a{margin-right:8px}
.nowrap{white-space:nowrap}
.trunc{max-width:520px;display:inline-block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;vertical-align:bottom}
.kpi{display:flex;gap:16px;flex-wrap:wrap;margin:8px 0}
.kpi .card{border:1px solid #e5e7f0;border-radius:10px;padding:8px 12px;background:#fff}
#clock_wrap{display:flex;gap:16px;flex-wrap:wrap;align-items:flex-start}
#clock{position:relative;width:360px;max-width:100%;aspect-ratio:1;border:1px solid #e5e7f0;border-radius:12px;padding:12px;background:#fff}
#clock_tooltip{position:absolute;padding:6px 8px;border:1px solid #ccc;background:#fff;border-radius:6px;box-shadow:0 2px 8px rgba(0,0,0,.08);font-size:12px;pointer-events:none;display:none}
.card{border:1px solid #e5e7f0;border-radius:10px;padding:12px;background:#fff}
.qr-cell img{display:block;margin:auto;border:1px solid #e5e7f0;border-radius:6px;background:#fff}
.notice{padding:10px 12px;border:1px solid #cfd8ff;background:#eef3ff;border-radius:8px;margin-bottom:12px}
.notice.err{border-color:#ffd0d0;background:#fff1f1}
header nav a{margin-left:8px}

/* Oto yenileme */
.refresh-wrap{display:flex;align-items:center;gap:8px;margin-left:8px}
.refresh-badge{font-size:12px;color:#555;background:#eef;border:1px solid #dde;padding:2px 8px;border-radius:999px}
</style>

<header>
  <div class="user">📊 Shorty Analitik</div>
  <nav style="display:flex;align-items:center">
    <!-- Oto yenileme seçici -->
    <div class="refresh-wrap">
      <label for="autoRefreshSel" style="font-size:14px;color:#333">Oto yenileme:</label>
      <select id="autoRefreshSel">
        <option value="0">Kapalı</option>
        <option value="10">10 sn</option>
        <option value="20">20 sn</option>
        <option value="30">30 sn</option>
        <option value="60">60 sn</option>
      </select>
      <span id="refreshCountdown" class="refresh-badge" style="display:none">—</span>
    </div>

    <!-- Sadece index yönlendirmesi (kısaltma ekranı) -->
    <a class="btn ghost" href="index.php">URL Kısalt</a>
    <a class="btn" href="logout.php">Çıkış Yap</a>
  </nav>
</header>

<?php if (isset($_GET['err']) && $_GET['err']!==''): ?>
  <div class="notice err">❗ Hata: <?= htmlspecialchars($_GET['err']) ?></div>
<?php endif; ?>

<script>
/* ======== OTO YENİLEME (tüm sayfa yenileme) ======== */
(function(){
  const KEY = 'shorty_admin_refresh_secs';
  const sel = document.getElementById('autoRefreshSel');
  const badge = document.getElementById('refreshCountdown');
  let timer = null, left = 0, period = 0;

  function save(v){ try{ localStorage.setItem(KEY, String(v)); }catch(e){} }
  function load(){ try{ return parseInt(localStorage.getItem(KEY)||'0',10)||0; }catch(e){ return 0; } }

  function stopTimer(){
    if (timer) { clearInterval(timer); timer=null; }
    badge.style.display = 'none';
  }
  function startTimer(sec){
    stopTimer();
    if (!sec) return;
    period = sec; left = sec;
    badge.style.display = 'inline-block';
    badge.textContent = left + ' sn';
    timer = setInterval(()=>{
      left--;
      if (left<=0) {
        // Aynı URL'yi, mevcut tüm parametrelerle yenile
        location.replace(location.href);
        return;
      }
      badge.textContent = left + ' sn';
    }, 1000);
  }

  // ilk yüklemede seçimi uygula
  const init = load();
  if (init) sel.value = String(init);
  startTimer(init);

  sel.addEventListener('change', function(){
    const val = parseInt(this.value,10)||0;
    save(val);
    if (val) startTimer(val); else stopTimer();
  });

  // görünürlük değişirse; arka plandan dönünce yeniden başlat
  document.addEventListener('visibilitychange', ()=>{
    if (document.hidden) return;
    const cur = load();
    if (cur) startTimer(cur);
  });
})();
</script>

<div class="filters">
  <form method="get">
    <label>Ara (slug veya başlık):
      <input name="q" value="<?=htmlspecialchars($q)?>" placeholder="örn: kampanya">
    </label>
    <label>Aralık:
      <select name="range">
        <option value="7d"  <?= $range==='7d'?'selected':'' ?>>Son 7 gün</option>
        <option value="30d" <?= $range==='30d'?'selected':'' ?>>Son 30 gün</option>
        <option value="all" <?= $range==='all'?'selected':'' ?>>Tümü</option>
      </select>
    </label>
    <button>Uygula</button>
  </form>
</div>

<?php
/* ------------------------- DETAY SAYFASI ------------------------- */
if ($slug !== '') {
  $stmt = $pdo->prepare('SELECT id, slug, destination_url, title, is_active, click_count, created_at, last_click_at FROM urls WHERE slug=? LIMIT 1');
  $stmt->execute([$slug]);
  $url = $stmt->fetch();
  if (!$url) { echo "<p>Bulunamadı.</p>"; exit; }

  echo "<h2>Link: <code>".htmlspecialchars($url['slug'])."</code></h2>";
  // filtre çubuğu (QR filtresi dahil)
  echo "<div class='filters' style='margin-top:-6px'>
          <form method='get'>
            <input type='hidden' name='slug' value='".htmlspecialchars($slug,ENT_QUOTES)."'>
            <label>Aralık:
              <select name='range'>
                <option value='7d'  ".($range==='7d'?'selected':'').">Son 7 gün</option>
                <option value='30d' ".($range==='30d'?'selected':'').">Son 30 gün</option>
                <option value='all' ".($range==='all'?'selected':'').">Tümü</option>
              </select>
            </label>
            <label><input type='checkbox' name='only_qr' value='1' ".($onlyQr?'checked':'')."> Sadece QR</label>
            <button>Uygula</button>
            <a href='edit.php?id=".$url['id']."' class='btn' style='margin-left:8px'>🔧 Düzenle</a>
            <a href='admin.php' style='margin-left:6px'>← Listeye dön</a>
          </form>
        </div>";

  if (!empty($url['title'])) echo "<p><strong>Başlık:</strong> ".htmlspecialchars($url['title'])."</p>";
  echo "<p><strong>Hedef:</strong> <span class='trunc'>".htmlspecialchars($url['destination_url'])."</span></p>";
  echo "<p><strong>Durum:</strong> ".($url['is_active'] ? 'Aktif' : 'Pasif')."</p>";
  echo "<p>Toplam tık: <strong>{$url['click_count']}</strong> | Son tık: ".($url['last_click_at'] ?? '-').($onlyQr?' <small>(yalnızca QR filtreli)</small>':'')."</p>";

  // -------- QR BLOĞU --------
  $qr6  = qr_url($url['slug'], 6, 'png');
  $qr8  = qr_url($url['slug'], 8, 'png');
  $qrJ6 = qr_url($url['slug'], 6, 'jpg');

  echo '<div class="card" style="margin:12px 0;display:flex;gap:16px;align-items:center;flex-wrap:wrap">';
  echo '  <div>';
  echo '    <div><strong>QR Kod</strong></div>';
  echo '    <img src="'.htmlspecialchars($qr6).'" alt="QR" style="width:180px;height:180px;border:1px solid #e5e7f0;border-radius:8px;background:#fff">';
  echo '  </div>';
  echo '  <div>';
  echo '    <div style="margin-bottom:6px"><a class="btn" href="'.$qr6.'" target="_blank" rel="noopener">PNG aç</a></div>';
  echo '    <div style="margin-bottom:6px"><a class="btn" href="'.$qr8.'" download="'.htmlspecialchars($url['slug']).'-qr.png">PNG indir (büyük)</a></div>';
  echo '    <div style="margin-bottom:6px"><a class="btn" href="'.$qrJ6.'" download="'.htmlspecialchars($url['slug']).'-qr.jpg">JPG indir</a></div>';
  echo '    <small>Boyutlar: <a href="'.qr_url($url['slug'],4).'" target="_blank">4</a> · <a href="'.$qr6.'" target="_blank">6</a> · <a href="'.qr_url($url['slug'],8).'" target="_blank">8</a> · <a href="'.qr_url($url['slug'],10).'" target="_blank">10</a></small>';
  echo '  </div>';
  echo '</div>';

  /* ----- KPI'lar: İnsan/Bot ve Yaklaşık Tekil ----- */
  $sql = "SELECT 
            COUNT(*) AS total,
            SUM(CASE WHEN (browser='Bot' OR device='bot') THEN 1 ELSE 0 END) AS bots,
            SUM(CASE WHEN (browser='Bot' OR device!='bot') THEN 1 ELSE 0 END) AS humans
          FROM clicks c WHERE c.url_id = ? $rangeSql $qrSql";
  $st = $pdo->prepare($sql); $st->execute([(int)$url['id']]); $tot = $st->fetch();

  $sql = "SELECT 
            COUNT(DISTINCT visitor_id) AS uniq_cookie,
            COUNT(DISTINCT COALESCE(visitor_id, CONCAT(ip,'|', SUBSTRING(user_agent,1,64)))) AS uniq_fallback
          FROM clicks c WHERE c.url_id = ? $rangeSql $qrSql";
  $st = $pdo->prepare($sql); $st->execute([(int)$url['id']]); $uniq = $st->fetch();

  echo "<div class='kpi'>
          <div class='card'><div><strong>Yaklaşık Tekil (cookie)</strong></div><div>".(int)$uniq['uniq_cookie']."</div></div>
          <div class='card'><div><strong>Yaklaşık Tekil (cookie+IP/UA)</strong></div><div>".(int)$uniq['uniq_fallback']."</div></div>
          <div class='card'><div><strong>İnsan</strong></div><div>".(int)($tot['humans'] ?? 0)."</div></div>
          <div class='card'><div><strong>Bot</strong></div><div>".(int)($tot['bots'] ?? 0)."</div></div>
        </div>";

  // Ülke kırılımı
  $sql = "SELECT COALESCE(country,'(Unknown)') AS country, COUNT(*) AS n 
          FROM clicks c WHERE c.url_id = ? $rangeSql $qrSql GROUP BY country ORDER BY n DESC LIMIT 50";
  $st = $pdo->prepare($sql); $st->execute([(int)$url['id']]); $countries = $st->fetchAll();
  echo "<h3>Ülkelere göre</h3><table><tr><th>Ülke</th><th>Tık</th></tr>";
  foreach ($countries as $c) echo "<tr><td>".htmlspecialchars($c['country'])."</td><td>{$c['n']}</td></tr>";
  echo "</table>";

  // Tarayıcı kırılımı
  $sql = "SELECT COALESCE(browser,'(Unknown)') AS browser, COUNT(*) AS n 
          FROM clicks c WHERE c.url_id = ? $rangeSql $qrSql GROUP BY browser ORDER BY n DESC";
  $st = $pdo->prepare($sql); $st->execute([(int)$url['id']]); $browsers = $st->fetchAll();
  echo "<h3>Tarayıcılar</h3><table><tr><th>Tarayıcı</th><th>Tık</th></tr>";
  foreach ($browsers as $b) echo "<tr><td>".htmlspecialchars($b['browser'])."</td><td>{$b['n']}</td></tr>";
  echo "</table>";

  // İnsan/Bot
  $sql = "SELECT CASE WHEN (browser='Bot' OR device='bot') THEN 'Bot' ELSE 'İnsan' END AS kind, COUNT(*) AS n
          FROM clicks c WHERE c.url_id = ? $rangeSql $qrSql GROUP BY kind ORDER BY kind";
  $st = $pdo->prepare($sql); $st->execute([(int)$url['id']]); $hb = $st->fetchAll();
  echo "<h3>İnsan / Bot</h3><table><tr><th>Tip</th><th>Tık</th></tr>";
  foreach ($hb as $r) echo "<tr><td>{$r['kind']}</td><td>{$r['n']}</td></tr>";
  echo "</table>";

  /* -------------------- SAATLİK DAĞILIM: SAAT KADRANI -------------------- */
  $sql = "SELECT HOUR(clicked_at) AS h, COUNT(*) AS n
          FROM clicks c WHERE c.url_id = ? $rangeSql $qrSql
          GROUP BY h ORDER BY h";
  $st = $pdo->prepare($sql); $st->execute([(int)$url['id']]); $byHour = $st->fetchAll();
  $map = array_fill(0, 24, 0);
  $max = 0;
  foreach ($byHour as $r) { $map[(int)$r['h']] = (int)$r['n']; if ((int)$r['n'] > $max) $max = (int)$r['n']; }
  $rank = []; for ($h=0;$h<24;$h++) $rank[] = ['h'=>$h,'n'=>$map[$h]];
  usort($rank, function($a,$b){ return $b['n'] <=> $a['n']; });
  $hourJson = json_encode($map, JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP);
  $maxVal   = (int)$max;
  ?>

  <h3>Saatlik Dağılım</h3>
  <div id="clock_wrap">
    <div id="clock">
      <div id="clock_tooltip"></div>
    </div>
    <div style="flex:1;min-width:260px">
      <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
          <strong>En Çok Tıklanan Saatler</strong>
          <small style="color:#666">(ilk 8)</small>
        </div>
        <ol style="margin:0;padding-left:18px">
          <?php
            $limit = 8;
            for ($i=0; $i<count($rank) && $i<$limit; $i++) {
              $h = $rank[$i]['h']; $n = $rank[$i]['n'];
              $pct = ($maxVal>0)? round($n*100/$maxVal) : 0;
              echo "<li style='margin:6px 0'><span style=\"display:inline-block;width:64px\">".sprintf('%02d:00', $h)."</span> ".
                   "<span style='display:inline-block;width:48px;text-align:right'>{$n}</span> ".
                   "<span style='display:inline-block;width:120px;margin-left:6px;height:8px;background:#eef;border-radius:6px;overflow:hidden'><i style='display:block;height:8px;background:var(--b);width:{$pct}%'></i></span></li>";
            }
          ?>
        </ol>
        <div style="margin-top:8px;color:#666;font-size:12px">Toplam saat dilimi: 24 · Maks: <?=$maxVal?></div>
      </div>
    </div>
  </div>

  <script>
  (function(){
    const data = <?php echo $hourJson ?>;   // [n0..n23]
    const MAX  = <?php echo $maxVal ?>;
    const box = document.getElementById('clock');
    const tip = document.getElementById('clock_tooltip');

    function showTip(x,y, html){
      tip.style.left = (x+12)+'px';
      tip.style.top  = (y+12)+'px';
      tip.innerHTML = html;
      tip.style.display = 'block';
    }
    function hideTip(){ tip.style.display = 'none'; }

    const W = box.clientWidth - 24; // padding hariç
    const H = W, cx = W/2, cy = H/2, R = Math.min(W,H)/2 - 10, innerR = R*0.45;
    const svgNS = 'http://www.w3.org/2000/svg';
    const svg = document.createElementNS(svgNS, 'svg');
    svg.setAttribute('viewBox', `0 0 ${W} ${H}`); svg.setAttribute('width', W); svg.setAttribute('height', H); svg.style.display='block';

    const bg = document.createElementNS(svgNS, 'circle');
    bg.setAttribute('cx', cx); bg.setAttribute('cy', cy); bg.setAttribute('r', R);
    bg.setAttribute('fill', '#fafbff'); bg.setAttribute('stroke', '#e5e9f7'); bg.setAttribute('stroke-width', '1');
    svg.appendChild(bg);

    for (let h=0; h<24; h+=3) {
      const ang = (h/24)*2*Math.PI - Math.PI/2;
      const tx = cx + Math.cos(ang)*(innerR - 8);
      const ty = cy + Math.sin(ang)*(innerR - 8) + 4;
      const t = document.createElementNS(svgNS, 'text');
      t.setAttribute('x', tx); t.setAttribute('y', ty); t.setAttribute('text-anchor','middle');
      t.setAttribute('font-size','10'); t.setAttribute('fill','#667'); t.textContent = String(h).padStart(2,'0');
      svg.appendChild(t);
    }

    function arcPath(cx,cy,r0,r1,startAng,endAng) {
      const x0 = cx + Math.cos(startAng)*r1, y0 = cy + Math.sin(startAng)*r1;
      const x1 = cx + Math.cos(endAng)*r1,   y1 = cy + Math.sin(endAng)*r1;
      const x2 = cx + Math.cos(endAng)*r0,   y2 = cy + Math.sin(endAng)*r0;
      const x3 = cx + Math.cos(startAng)*r0, y3 = cy + Math.sin(startAng)*r0;
      const largeArc = (endAng - startAng) > Math.PI ? 1 : 0;
      return ['M',x0,y0,'A',r1,r1,0,largeArc,1,x1,y1,'L',x2,y2,'A',r0,r0,0,largeArc,0,x3,y3,'Z'].join(' ');
    }

    const step = 2*Math.PI/24;
    for (let h=0; h<24; h++) {
      const val = data[h] || 0;
      const t   = (MAX>0) ? (val/MAX) : 0;
      const c   = Math.round(240 - 140*t);
      const fill= `rgb(${c}, ${c+12}, 255)`;
      const start = h*step - Math.PI/2, end = (h+1)*step - Math.PI/2;

      const path = document.createElementNS(svgNS, 'path');
      path.setAttribute('d', arcPath(cx,cy,innerR,R,start,end));
      path.setAttribute('fill', fill);
      path.setAttribute('stroke', '#dde3f6');
      path.setAttribute('stroke-width', '0.5');
      path.addEventListener('mousemove', (ev)=>{
        const pct = (MAX>0)? Math.round(val*100/MAX) : 0;
        const next = (h+1)%24;
        showTip(ev.offsetX, ev.offsetY,
          `<strong>${String(h).padStart(2,'0')}:00–${String(next).padStart(2,'0')}:00</strong><br>`+
          `Tık: <strong>${val}</strong> (${pct}%)`);
      });
      path.addEventListener('mouseleave', hideTip);
      svg.appendChild(path);

      const tickAng = end;
      const xA = cx + Math.cos(tickAng)*R, yA = cy + Math.sin(tickAng)*R;
      const xB = cx + Math.cos(tickAng)*(R-6), yB = cy + Math.sin(tickAng)*(R-6);
      const line = document.createElementNS(svgNS, 'line');
      line.setAttribute('x1',xA); line.setAttribute('y1',yA); line.setAttribute('x2',xB); line.setAttribute('y2',yB);
      line.setAttribute('stroke','#cfd7ee'); line.setAttribute('stroke-width','0.8');
      svg.appendChild(line);
    }

    box.prepend(svg);
  })();
  </script>

  <?php
  // Referrer host (özet)
  $sql = "SELECT COALESCE(referer_host,'(Direct)') AS host, COUNT(*) AS n 
          FROM clicks c WHERE c.url_id = ? $rangeSql $qrSql GROUP BY host ORDER BY n DESC LIMIT 50";
  $st = $pdo->prepare($sql); $st->execute([(int)$url['id']]); $refs = $st->fetchAll();
  echo "<h3>Kaynak (Referrer) — Host</h3><table><tr><th>Host</th><th>Tık</th></tr>";
  foreach ($refs as $r) echo "<tr><td>".htmlspecialchars($r['host'])."</td><td>{$r['n']}</td></tr>";
  echo "</table>";

  // Tam URL (query dahil)
  $sql = "SELECT CASE WHEN (referer IS NULL OR referer='') THEN '(Direct)' ELSE referer END AS ref, COUNT(*) AS n
          FROM clicks c WHERE c.url_id = ? $rangeSql $qrSql 
          GROUP BY ref ORDER BY n DESC, ref ASC LIMIT 100";
  $st = $pdo->prepare($sql); $st->execute([(int)$url['id']]); $refurls = $st->fetchAll();
  echo "<h3>Kaynak (Referrer) — Tam URL</h3><table><tr><th>Referrer</th><th>Tık</th></tr>";
  foreach ($refurls as $r) {
    $label = $r['ref'];
    if ($label !== '(Direct)') {
      $safe = htmlspecialchars($label);
      echo "<tr><td class='trunc'><a href='{$safe}' target='_blank' rel='noopener'>{$safe}</a></td><td>{$r['n']}</td></tr>";
    } else {
      echo "<tr><td>(Direct)</td><td>{$r['n']}</td></tr>";
    }
  }
  echo "</table>";

  // Path bazlı (querysiz)
  $sql = "SELECT CASE 
                  WHEN (referer IS NULL OR referer='') THEN '(Direct)' 
                  ELSE SUBSTRING_INDEX(referer,'?',1) 
                END AS refpath,
                COUNT(*) AS n
          FROM clicks c WHERE c.url_id = ? $rangeSql $qrSql 
          GROUP BY refpath ORDER BY n DESC, refpath ASC LIMIT 100";
  $st = $pdo->prepare($sql); $st->execute([(int)$url['id']]); $refpaths = $st->fetchAll();
  echo "<h3>Kaynak (Referrer) — Path (querysiz)</h3><table><tr><th>Referrer (path)</th><th>Tık</th></tr>";
  foreach ($refpaths as $r) {
    $label = $r['refpath'];
    if ($label !== '(Direct)') {
      $safe = htmlspecialchars($label);
      echo "<tr><td class='trunc'><a href='{$safe}' target='_blank' rel='noopener'>{$safe}</a></td><td>{$r['n']}</td></tr>";
    } else {
      echo "<tr><td>(Direct)</td><td>{$r['n']}</td></tr>";
    }
  }
  echo "</table>";

  // Günlük seri
  $sql = "SELECT DATE(clicked_at) AS d, COUNT(*) AS n, COUNT(DISTINCT NULLIF(ip,'')) AS uniq_ips
          FROM clicks c WHERE c.url_id = ? $rangeSql $qrSql GROUP BY d ORDER BY d DESC LIMIT 60";
  $st = $pdo->prepare($sql); $st->execute([(int)$url['id']]); $days = $st->fetchAll();
  echo "<h3>Günlük Trend</h3><table><tr><th>Tarih</th><th>Tık</th><th>Tekil IP</th></tr>";
  foreach ($days as $d) echo "<tr><td>{$d['d']}</td><td>{$d['n']}</td><td>{$d['uniq_ips']}</td></tr>";
  echo "</table>";

  /* -------------------- DÜNYA HARİTASI + FİLTRELİ LİSTE -------------------- */
  $sql = "SELECT id, clicked_at, ip, country, browser, device, visitor_id,
                 CASE WHEN (referer IS NULL OR referer='') THEN '(Direct)' ELSE referer END AS ref
          FROM clicks c
          WHERE c.url_id = ? $rangeSql $qrSql
          ORDER BY clicked_at DESC
          LIMIT 100";
  $st = $pdo->prepare($sql);
  $st->execute([(int)$url['id']]);
  $last = $st->fetchAll();

  $countryCount = [];
  foreach ($last as $row) {
    $cn = $row['country'] ?: '(Unknown)';
    if (!isset($countryCount[$cn])) $countryCount[$cn] = 0;
    $countryCount[$cn]++;
  }

  $lastJson = json_encode($last, JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP);
  $ccJson   = json_encode($countryCount, JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP);
  ?>

  <h3>Son 100 Tıklama — Dünya Haritası <?= $onlyQr ? '<small>(yalnızca QR)</small>' : '' ?></h3>
  <div class="card">
    <div id="geo_map" style="width:100%;min-height:420px"></div>
    <div id="geo_info" style="margin-top:10px;color:#666">
      Haritadan bir ülkeye tıklayın; altta sadece o ülkenin tıklamaları listelenecek.
    </div>
    <div id="geo_list" style="margin-top:12px"></div>
  </div>

  <script src="https://www.gstatic.com/charts/loader.js"></script>
  <script>
  (function(){
    const LAST = <?php echo $lastJson ?>;
    const CC   = <?php echo $ccJson ?>;

    function escHtml(s){return String(s).replace(/[<>&]/g, m=>({'<':'&lt;','>':'&gt;','&':'&amp;'}[m]));}
    function maskIp(ip){
      if(!ip) return '(null)';
      if(ip.indexOf(':')>=0){ const p=ip.split(':').slice(0,4); return p.join(':')+'::'; }
      const m=ip.match(/^(\d+)\.(\d+)\.(\d+)\.(\d+)$/); if(m) return m[1]+'.'+m[2]+'.'+m[3]+'.0';
      return ip;
    }
    function normCountry(s){ return s ? String(s).trim().toLowerCase() : '(unknown)'; }

    function renderList(countryFilterRaw) {
      const box = document.getElementById('geo_list');
      const filter = (countryFilterRaw===null || countryFilterRaw===undefined) ? null : normCountry(countryFilterRaw);
      let rows = LAST.slice();
      if (filter !== null) {
        if (filter === '(unknown)') {
          rows = rows.filter(r => !r.country || normCountry(r.country) === '(unknown)');
        } else {
          rows = rows.filter(r => normCountry(r.country) === filter);
        }
      }
      if (!rows.length) { box.innerHTML = '<p><em>Seçilen ülke için kayıt yok.</em></p>'; return; }
      let html = '<table style="border-collapse:collapse;width:100%">'+
                 '<tr><th style="text-align:left;border:1px solid #e5e7f0;padding:6px">Zaman</th>'+
                 '<th style="text-align:left;border:1px solid #e5e7f0;padding:6px">IP</th>'+
                 '<th style="text-align:left;border:1px solid #e5e7f0;padding:6px">Ülke</th>'+
                 '<th style="text-align:left;border:1px solid #e5e7f0;padding:6px">Tarayıcı</th>'+
                 '<th style="text-align:left;border:1px solid #e5e7f0;padding:6px">Cihaz</th>'+
                 '<th style="text-align:left;border:1px solid #e5e7f0;padding:6px">Visitor</th>'+
                 '<th style="text-align:left;border:1px solid #e5e7f0;padding:6px">Referrer</th></tr>';
      rows.forEach(r=>{
        const refCell = (!r.ref || r.ref==='(Direct)') ? '(Direct)' :
          '<a href="'+escHtml(String(r.ref))+'" target="_blank" rel="noopener">'+escHtml(String(r.ref).length>120?String(r.ref).slice(0,120)+'…':String(r.ref))+'</a>';
        html += '<tr>'+
          '<td style="border:1px solid #e5e7f0;padding:6px;white-space:nowrap">'+escHtml(r.clicked_at)+'</td>'+
          '<td style="border:1px solid #e5e7f0;padding:6px">'+escHtml(maskIp(r.ip))+'</td>'+
          '<td style="border:1px solid #e5e7f0;padding:6px">'+escHtml(r.country||'(Unknown)')+'</td>'+
          '<td style="border:1px solid #e5e7f0;padding:6px">'+escHtml(r.browser||'-')+'</td>'+
          '<td style="border:1px solid #e5e7f0;padding:6px">'+escHtml(r.device||'-')+'</td>'+
          '<td style="border:1px solid #e5e7f0;padding:6px">'+escHtml(r.visitor_id||'-')+'</td>'+
          '<td style="border:1px solid #e5e7f0;padding:6px)">'+refCell+'</td>'+
        '</tr>';
      });
      html += '</table>';
      box.innerHTML = html;
    }

    google.charts.load('current', {'packages':['geochart']});
    google.charts.setOnLoadCallback(drawGeo);

    function drawGeo() {
      const data = new google.visualization.DataTable();
      data.addColumn('string', 'Country');
      data.addColumn('number', 'Clicks');
      Object.keys(CC).forEach(country=>{
        if(!country || country==='(Unknown)') return;
        data.addRow([country, Number(CC[country]||0)]);
      });

      const options = {
        legend:'none',
        colorAxis:{colors:['#e0ecf4','#4a67e8']},
        backgroundColor:'transparent',
        keepAspectRatio:true,
        datalessRegionColor:'#f0f0f0',
        tooltip:{textStyle:{fontSize:12}}
      };

      const chart = new google.visualization.GeoChart(document.getElementById('geo_map'));
      let currentCountry = null;

      google.visualization.events.addListener(chart,'select',function(){
        const sel = chart.getSelection();
        if (sel && sel.length) {
          const row = sel[0].row;
          const country = data.getValue(row, 0);
          currentCountry = country;
          renderList(country);
          document.getElementById('geo_info').innerHTML = 'Ülke: <strong>'+country+'</strong> için son tıklamalar:';
        } else {
          if (currentCountry) {
            renderList(currentCountry);
            document.getElementById('geo_info').innerHTML = 'Ülke: <strong>'+currentCountry+'</strong> için son tıklamalar:';
          } else {
            renderList(null);
            document.getElementById('geo_info').innerHTML = 'Tüm ülkeler gösteriliyor.';
          }
        }
      });

      chart.draw(data, options);
      renderList(null);
    }
  })();
  </script>

  <?php
  exit;
}

/* ------------------------- LİSTE SAYFASI ------------------------- */
$params = [];
$where  = '';
if ($q !== '') {
  $where = "WHERE slug LIKE ? OR title LIKE ?";
  $like  = '%'.$q.'%';
  $params = [$like, $like];
}

$sql = "SELECT id, slug, destination_url, title, is_active, click_count, created_at, last_click_at 
        FROM urls $where ORDER BY id DESC LIMIT 200";
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();

echo "<h2>Son Linkler</h2>";
echo "<table><tr><th>Slug</th><th>Başlık</th><th>Hedef</th><th>Durum</th><th>Tık</th><th>Oluşturma</th><th>Son Tık</th><th>İşlem</th><th>QR</th></tr>";
foreach ($rows as $r) {
  $short   = shorty_base_url().'/'.htmlspecialchars($r['slug']);
  $qrThumb = qr_url($r['slug'], 4, 'png'); // küçük önizleme

  echo "<tr>";
  echo "<td><a href=\"?slug=".urlencode($r['slug'])."\">".htmlspecialchars($r['slug'])."</a><br><small><a href=\"".$short."\" target=\"_blank\" rel=\"noopener\">".$short."</a></small></td>";
  echo "<td>".($r['title'] ? htmlspecialchars(function_exists('mb_strimwidth') ? mb_strimwidth($r['title'],0,60,'…','UTF-8') : (strlen($r['title'])>60?substr($r['title'],0,57).'…':$r['title'])) : '<small>(yok)</small>')."</td>";
  echo "<td><span class='trunc'>".htmlspecialchars($r['destination_url'])."</span></td>";
  echo "<td>".($r['is_active'] ? 'Aktif' : 'Pasif')."</td>";
  echo "<td><span class='badge'>".(int)$r['click_count']."</span></td>";
  echo "<td>".htmlspecialchars((string)$r['created_at'])."</td>";
  echo "<td>".htmlspecialchars((string)($r['last_click_at'] ?? '-'))."</td>";
  echo "<td class='actions'>
          <a href=\"edit.php?id=".$r['id']."\">Düzenle</a>
          <a href=\"?slug=".urlencode($r['slug'])."\">Detay</a>
        </td>";
  echo "<td class='qr-cell'><img src=\"".$qrThumb."\" alt=\"QR\" width=\"50\" height=\"50\"></td>";
  echo "</tr>";
}
echo "</table>";

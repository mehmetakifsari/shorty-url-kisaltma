<?php
// /v2/login.php
declare(strict_types=1);
require __DIR__.'/db.php';
require __DIR__.'/auth.php'; // session başlatır

$error = '';
$info  = '';

$hasUsers = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn() > 0;

if ($_SERVER['REQUEST_METHOD']==='POST') {
  $mode = $_POST['mode'] ?? 'login';
  $email = trim($_POST['email'] ?? '');
  $pass  = (string)($_POST['pass'] ?? '');
  $name  = trim($_POST['name'] ?? '');

  if ($mode==='setup' && !$hasUsers) {
    if ($email==='' || $pass==='' || strlen($pass)<8) {
      $error = 'E-posta ve en az 8 karakterli şifre zorunlu.';
    } else {
      $ins = $pdo->prepare("INSERT INTO users (email, pass_hash, name, role) VALUES (?,?,?, 'admin')");
      try {
        $ins->execute([$email, password_hash($pass, PASSWORD_DEFAULT), ($name?:null)]);
        $info = 'Admin hesabı oluşturuldu. Şimdi giriş yapın.';
        $hasUsers = true;
      } catch (PDOException $e) {
        $error = 'Hata: '.$e->getMessage();
      }
    }
  } else { // normal login
    $st = $pdo->prepare("SELECT id, pass_hash, role, is_active, name, email FROM users WHERE email=? LIMIT 1");
    $st->execute([$email]);
    $u = $st->fetch();
    if (!$u || !$u['is_active'] || !password_verify($pass, $u['pass_hash'])) {
      $error = 'E-posta veya şifre hatalı.';
    } else {
      session_regenerate_id(true);
      $_SESSION['uid']  = (int)$u['id'];
      $_SESSION['role'] = $u['role'];
      $_SESSION['name'] = $u['name'] ?: $u['email'];
      $next = $_GET['next'] ?? 'index.php';
      header('Location: '.$next);
      exit;
    }
  }
}

header('Content-Type: text/html; charset=UTF-8');
?>
<!doctype html>
<meta charset="utf-8">
<title><?= $hasUsers ? 'Giriş Yap' : 'İlk Kurulum' ?></title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
body{font-family:system-ui,Arial;margin:40px auto;max-width:420px;padding:0 16px;background:#f7f8fb}
.card{background:#fff;border:1px solid #e5e7f0;border-radius:12px;padding:18px}
h1{margin:0 0 16px}
label{display:block;margin:10px 0 6px}
input,button{font:inherit;padding:10px;border:1px solid #cfd3e1;border-radius:10px;width:100%}
button{cursor:pointer;background:#4a67e8;color:#fff;border-color:#4a67e8}
.err{margin:10px 0;padding:10px;border-radius:10px;background:#fff2f2;border:1px solid #f6caca}
.ok{margin:10px 0;padding:10px;border-radius:10px;background:#f2fff7;border:1px solid #cdeed6}
small{color:#666}
</style>

<div class="card">
  <h1><?= $hasUsers ? 'Giriş Yap' : 'İlk Kurulum' ?></h1>

  <?php if ($error): ?><div class="err"><?=htmlspecialchars($error)?></div><?php endif; ?>
  <?php if ($info):  ?><div class="ok"><?=htmlspecialchars($info)?></div><?php endif; ?>

  <?php if ($hasUsers): ?>
    <form method="post" autocomplete="off">
      <input type="hidden" name="mode" value="login">
      <label>E-posta</label>
      <input type="email" name="email" required>
      <label>Şifre</label>
      <input type="password" name="pass" required>
      <button type="submit">Giriş</button>
    </form>
  <?php else: ?>
    <form method="post" autocomplete="off">
      <input type="hidden" name="mode" value="setup">
      <label>Ad (opsiyonel)</label>
      <input type="text" name="name">
      <label>Admin e-posta</label>
      <input type="email" name="email" required>
      <label>Admin şifre (min 8)</label>
      <input type="password" name="pass" required minlength="8">
      <button type="submit">Admin oluştur</button>
    </form>
  <?php endif; ?>
</div>

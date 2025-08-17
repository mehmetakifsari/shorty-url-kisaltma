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
  $username = trim($_POST['username'] ?? '');
  $pass     = (string)($_POST['pass'] ?? '');
  $name     = trim($_POST['name'] ?? '');

  if ($mode==='setup' && !$hasUsers) {
    if ($username==='' || $pass==='' || strlen($pass)<8) {
      $error = 'Kullanıcı adı ve en az 8 karakterli şifre zorunlu.';
    } else {
      $ins = $pdo->prepare("INSERT INTO users (username, pass_hash, name, role, is_active) VALUES (?,?,?, 'admin', 1)");
      try {
        $ins->execute([$username, password_hash($pass, PASSWORD_DEFAULT), ($name?:null)]);
        $info = 'Admin hesabı oluşturuldu. Şimdi giriş yapın.';
        $hasUsers = true;
      } catch (PDOException $e) {
        $error = 'Hata: '.$e->getMessage();
      }
    }
  } else { // normal login
    $st = $pdo->prepare("SELECT id, pass_hash, role, is_active, name, username FROM users WHERE username=? LIMIT 1");
    $st->execute([$username]);
    $u = $st->fetch();
    if (!$u || !$u['is_active'] || !password_verify($pass, $u['pass_hash'])) {
      $error = 'Kullanıcı adı veya şifre hatalı.';
    } else {
      session_regenerate_id(true);
      $_SESSION['uid']  = (int)$u['id'];
      $_SESSION['role'] = $u['role'];
      $_SESSION['name'] = $u['name'] ?: $u['username'];
      $next = $_GET['next'] ?? 'index.php';
      header('Location: '.$next);
      exit;
    }
  }
}

header('Content-Type: text/html; charset=UTF-8');
?>
<!doctype html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <title><?= $hasUsers ? 'Giriş Yap' : 'İlk Kurulum' ?></title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="css/login.css">
</head>
<body>
  <div class="card">
    <h1><?= $hasUsers ? 'Giriş Yap' : 'İlk Kurulum' ?></h1>

    <?php if ($error): ?><div class="err"><?=htmlspecialchars($error)?></div><?php endif; ?>
    <?php if ($info):  ?><div class="ok"><?=htmlspecialchars($info)?></div><?php endif; ?>

    <?php if ($hasUsers): ?>
      <form method="post" autocomplete="off">
        <input type="hidden" name="mode" value="login">
        <label>Kullanıcı Adı</label>
        <input type="text" name="username" required>
        <label>Şifre</label>
        <input type="password" name="pass" required>
        <button type="submit">Giriş</button>
      </form>
    <?php else: ?>
      <form method="post" autocomplete="off">
        <input type="hidden" name="mode" value="setup">
        <label>Ad (opsiyonel)</label>
        <input type="text" name="name">
        <label>Admin kullanıcı adı</label>
        <input type="text" name="username" required>
        <label>Admin şifre (min 8)</label>
        <input type="password" name="pass" required minlength="8">
        <button type="submit">Admin oluştur</button>
      </form>
    <?php endif; ?>
  </div>
</body>
</html>

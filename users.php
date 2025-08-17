<?php
// /v2/users.php
declare(strict_types=1);
require __DIR__.'/db.php';
require __DIR__.'/auth.php';
require_admin();

$msg = '';

if ($_SERVER['REQUEST_METHOD']==='POST') {
  $action = $_POST['action'] ?? '';
  if ($action === 'create') {
    $email = trim($_POST['email'] ?? '');
    $name  = trim($_POST['name'] ?? '');
    $role  = ($_POST['role'] ?? 'member') === 'admin' ? 'admin' : 'member';
    $pass  = (string)($_POST['pass'] ?? '');
    if ($email==='' || $pass==='' || strlen($pass)<8) { $msg='Eksik alanlar.'; }
    else {
      $ins = $pdo->prepare("INSERT INTO users (email, pass_hash, name, role) VALUES (?,?,?,?)");
      try {
        $ins->execute([$email, password_hash($pass, PASSWORD_DEFAULT), ($name?:null), $role]);
        $msg='Kullanıcı eklendi.';
      } catch (PDOException $e) {
        $msg='Hata: '.$e->getMessage();
      }
    }
  } elseif ($action === 'toggle') {
    $id = (int)($_POST['id'] ?? 0);
    $pdo->prepare("UPDATE users SET is_active=1-is_active WHERE id=?")->execute([$id]);
    $msg='Durum güncellendi.';
  } elseif ($action === 'resetpass') {
    $id  = (int)($_POST['id'] ?? 0);
    $new = (string)($_POST['newpass'] ?? '');
    if (strlen($new) < 8) $msg = 'Şifre en az 8 karakter.';
    else {
      $pdo->prepare("UPDATE users SET pass_hash=? WHERE id=?")->execute([password_hash($new, PASSWORD_DEFAULT), $id]);
      $msg='Şifre güncellendi.';
    }
  }
}

$rows = $pdo->query("SELECT id, email, name, role, is_active, created_at FROM users ORDER BY id DESC")->fetchAll();

header('Content-Type: text/html; charset=UTF-8');
?>
<!doctype html>
<meta charset="utf-8">
<title>Kullanıcılar</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
body{font-family:system-ui,Arial;max-width:1000px;margin:30px auto;padding:0 16px}
table{border-collapse:collapse;width:100%}
th,td{border:1px solid #ddd;padding:8px}
th{background:#f7f7f7}
input,button,select{font:inherit;padding:8px}
.row{display:flex;gap:8px;flex-wrap:wrap;margin:10px 0}
.badge{display:inline-block;background:#eef;padding:2px 6px;border-radius:6px}
</style>

<h1>👤 Kullanıcılar</h1>
<p><a href="admin.php">← Admin</a> | <a href="logout.php">Çıkış</a></p>

<?php if ($msg): ?><p><strong><?=htmlspecialchars($msg)?></strong></p><?php endif; ?>

<h2>Yeni kullanıcı ekle</h2>
<form method="post" class="row">
  <input type="hidden" name="action" value="create">
  <input type="email" name="email" placeholder="email" required>
  <input type="text"  name="name"  placeholder="ad (opsiyonel)">
  <select name="role"><option value="member">member</option><option value="admin">admin</option></select>
  <input type="password" name="pass" placeholder="şifre (min 8)" required minlength="8">
  <button type="submit">Ekle</button>
</form>

<h2>Liste</h2>
<table>
  <tr><th>ID</th><th>E-posta</th><th>Ad</th><th>Rol</th><th>Aktif</th><th>Oluşturma</th><th>İşlem</th></tr>
  <?php foreach($rows as $r): ?>
  <tr>
    <td><?=$r['id']?></td>
    <td><?=htmlspecialchars($r['email'])?></td>
    <td><?=htmlspecialchars($r['name'] ?? '')?></td>
    <td><?=$r['role']?></td>
    <td><?=$r['is_active'] ? 'Evet' : 'Hayır'?></td>
    <td><?=$r['created_at']?></td>
    <td>
      <form method="post" style="display:inline">
        <input type="hidden" name="action" value="toggle">
        <input type="hidden" name="id" value="<?=$r['id']?>">
        <button>Aktif/Pasif</button>
      </form>
      <form method="post" style="display:inline" onsubmit="return confirm('Yeni şifre verilsin mi?')">
        <input type="hidden" name="action" value="resetpass">
        <input type="hidden" name="id" value="<?=$r['id']?>">
        <input type="password" name="newpass" placeholder="yeni şifre" required minlength="8">
        <button>Şifre Sıfırla</button>
      </form>
    </td>
  </tr>
  <?php endforeach; ?>
</table>

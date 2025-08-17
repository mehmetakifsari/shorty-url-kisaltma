<?php
// /v2/auth.php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

function require_login(): void {
  if (empty($_SESSION['uid'])) {
    $next = urlencode($_SERVER['REQUEST_URI'] ?? '/v2/');
    header("Location: login.php?next={$next}");
    exit;
  }
}
function require_admin(): void {
  require_login();
  if (($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('Bu sayfa için yetkiniz yok.');
  }
}

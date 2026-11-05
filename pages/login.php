<?php
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $s = db()->prepare("SELECT * FROM users WHERE username=? AND active=1");
    $s->execute([trim($_POST['username'] ?? '')]);
    $u = $s->fetch();
    if ($u && verify_password($u, (string)($_POST['password'] ?? ''))) {
        session_regenerate_id(true);
        $_SESSION['uid'] = (int)$u['id'];
        redirect('index.php');
    }
    $err = 'Username atau password salah';
}
?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login &middot; <?= e(setting('store_name', 'Kasir POS')) ?></title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body class="login-bg">
<div class="loginbox">
  <div class="login-logo"><?= e(mb_strtoupper(mb_substr(setting('store_name', 'K'), 0, 1))) ?></div>
  <h1><?= e(setting('store_name', 'Kasir POS')) ?></h1>
  <p class="muted" style="margin:4px 0 22px">Masuk untuk melanjutkan</p>
  <form method="post" action="index.php?page=login">
    <?= csrf_field() ?>
    <div class="fg"><label class="lbl">Username</label><input name="username" required autocomplete="username" autofocus></div>
    <div class="fg"><label class="lbl">Password</label><input type="password" name="password" required autocomplete="current-password"></div>
    <?php if ($err): ?><p class="err"><?= e($err) ?></p><?php endif; ?>
    <button class="btn btn-primary btn-block">Masuk</button>
  </form>
</div>
</body>
</html>

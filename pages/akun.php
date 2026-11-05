<?php
$pageTitle = 'Akun';

$kasir = db()->query("SELECT * FROM users WHERE role='kasir' ORDER BY id LIMIT 1")->fetch() ?: null;
$admin = me();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $act = $_POST['action'] ?? '';
    $username = trim($_POST['username'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $targetId = $act === 'kasir' ? (int)($kasir['id'] ?? 0) : (int)$admin['id'];

    if ($username === '' || !preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $username)) {
        flash_set('Username minimal 3 karakter (huruf, angka, titik, garis bawah, strip)', 'err');
        redirect('index.php?page=akun');
    }
    $dup = db()->prepare("SELECT id FROM users WHERE username=? AND id<>?");
    $dup->execute([$username, $targetId]);
    if ($dup->fetch()) {
        flash_set('Username sudah dipakai', 'err');
        redirect('index.php?page=akun');
    }
    if ($password !== '' && strlen($password) < 4) {
        flash_set('Password minimal 4 karakter', 'err');
        redirect('index.php?page=akun');
    }

    if ($act === 'kasir') {
        $active = isset($_POST['active']) ? 1 : 0;
        if ($kasir) {
            db()->prepare("UPDATE users SET username=?, active=? WHERE id=?")->execute([$username, $active, $kasir['id']]);
        } else {
            if ($password === '') { flash_set('Password wajib diisi', 'err'); redirect('index.php?page=akun'); }
            db()->prepare("INSERT INTO users (name, username, password, role, active) VALUES ('Kasir', ?, ?, 'kasir', ?)")
                ->execute([$username, password_hash($password, PASSWORD_DEFAULT), $active]);
            $targetId = (int)db()->lastInsertId();
            $password = '';
        }
    } else {
        db()->prepare("UPDATE users SET username=? WHERE id=?")->execute([$username, $admin['id']]);
    }
    if ($password !== '') {
        db()->prepare("UPDATE users SET password=? WHERE id=?")->execute([password_hash($password, PASSWORD_DEFAULT), $targetId]);
    }
    flash_set('Akun disimpan');
    redirect('index.php?page=akun');
}
?>
<h2>Akun</h2>
<p class="sub">Kasir memakai satu akun bersama. Nama kasir di struk diambil dari <a href="index.php?page=jadwal">Jadwal Shift</a>.</p>

<div class="g2">
  <div class="card pad">
    <h3 class="cardh2">Akun Kasir</h3>
    <form method="post" action="index.php?page=akun">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="kasir">
      <div class="fg"><label class="lbl">Username</label>
        <input name="username" value="<?= e($kasir['username'] ?? 'kasir') ?>" required></div>
      <div class="fg"><label class="lbl">Password <?= $kasir ? 'baru' : '' ?></label>
        <input name="password" type="password" autocomplete="new-password"
               placeholder="<?= $kasir ? 'Kosongkan jika tidak diubah' : 'Wajib diisi' ?>"></div>
      <div class="fg"><label class="check"><input type="checkbox" name="active" value="1" <?= !$kasir || $kasir['active'] ? 'checked' : '' ?>> Akun aktif (bisa login)</label></div>
      <button class="btn btn-primary">Simpan Akun Kasir</button>
    </form>
  </div>

  <div class="card pad">
    <h3 class="cardh2">Akun Admin</h3>
    <form method="post" action="index.php?page=akun">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="admin">
      <div class="fg"><label class="lbl">Username</label>
        <input name="username" value="<?= e($admin['username']) ?>" required></div>
      <div class="fg"><label class="lbl">Password baru</label>
        <input name="password" type="password" autocomplete="new-password" placeholder="Kosongkan jika tidak diubah"></div>
      <button class="btn btn-primary">Simpan Akun Admin</button>
    </form>
  </div>
</div>

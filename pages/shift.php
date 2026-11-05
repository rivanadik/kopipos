<?php
$pageTitle = is_admin() ? 'Rekap Shift' : 'Shift';
$me = me();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !is_admin()) {
    check_csrf();
    $act = $_POST['action'] ?? '';

    if ($act === 'open') {
        $s = db()->prepare("SELECT * FROM shift_configs WHERE id=? AND active=1");
        $s->execute([(int)($_POST['config_id'] ?? 0)]);
        $c = $s->fetch();
        if (active_shift()) {
            flash_set('Masih ada shift yang berjalan', 'err');
        } elseif (!$c) {
            flash_set('Pilih jadwal shift yang valid', 'err');
        } else {
            db()->prepare("INSERT INTO shifts (user_id, shift_config_id, shift_name, cashier_name, opened_at, start_cash)
                VALUES (?,?,?,?,?,?)")
                ->execute([(int)$me['id'], $c['id'], $c['name'], $c['cashier_name'], now(),
                           max(0, (int)round((float)($_POST['start_cash'] ?? 0)))]);
            flash_set($c['name'] . ' dibuka. Selamat bertugas, ' . $c['cashier_name']);
            redirect('index.php?page=pos');
        }
        redirect('index.php?page=shift');
    }

    if ($act === 'close') {
        $sh = active_shift();
        if (!$sh) {
            flash_set('Tidak ada shift yang berjalan', 'err');
        } elseif (($_POST['counted'] ?? '') === '') {
            flash_set('Isi jumlah kas yang dihitung', 'err');
        } else {
            $counted = max(0, (int)round((float)$_POST['counted']));
            $st = shift_stats((int)$sh['id'], (float)$sh['start_cash']);
            db()->prepare("UPDATE shifts SET closed_at=?, end_cash=?, expected_cash=? WHERE id=?")
                ->execute([now(), $counted, $st['expected'], $sh['id']]);
            $diff = $counted - $st['expected'];
            flash_set($sh['shift_name'] . ' ditutup. Selisih kas ' . rp($diff), $diff < 0 ? 'err' : 'ok');
        }
        redirect('index.php?page=shift');
    }
}

/* ---------- ADMIN: rekap ---------- */
if (is_admin()) {
    $rows = db()->query("SELECT * FROM shifts ORDER BY id DESC LIMIT 200")->fetchAll();
    ?>
    <h2>Rekap Shift</h2>
    <p class="sub">Riwayat buka-tutup shift kasir beserta selisih kas. Tombol Cetak Rekap mencetak ringkasan shift ke printer thermal.</p>
    <div class="card"><?= shift_table($rows, true) ?></div>
    <?php
    return;
}

/* ---------- KASIR ---------- */
$sh = active_shift();
$configs = shift_configs();
$detected = current_shift_config();
$hist = db()->prepare("SELECT * FROM shifts WHERE user_id=? ORDER BY id DESC LIMIT 30");
$hist->execute([(int)$me['id']]);
$hist = $hist->fetchAll();
?>
<h2>Shift</h2>

<?php if (!$sh): ?>
  <p class="sub">Buka shift sebelum melayani transaksi.</p>
  <?php if (!$configs): ?>
    <div class="alert err">Admin belum membuat jadwal shift. Hubungi admin untuk mengatur Jadwal Shift.</div>
  <?php else: ?>
    <div class="card pad" style="max-width:480px;margin-bottom:18px">
      <?php if ($detected): ?>
        <div class="shift-now">
          <div class="muted" style="font-size:12px">Terdeteksi dari jam sekarang (<?= date('H:i') ?>)</div>
          <div class="shift-big"><?= e($detected['name']) ?> &middot; <?= e($detected['cashier_name']) ?></div>
          <div class="muted" style="font-size:12px"><?= hm($detected['start_time']) ?> &ndash; <?= hm($detected['end_time']) ?></div>
        </div>
      <?php else: ?>
        <div class="alert warn">Jam sekarang tidak masuk jadwal shift mana pun. Pilih shift secara manual.</div>
      <?php endif; ?>
      <form method="post" action="index.php?page=shift">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="open">
        <div class="fg"><label class="lbl">Shift</label>
          <select name="config_id">
            <?php foreach ($configs as $c): ?>
              <option value="<?= (int)$c['id'] ?>" <?= $detected && (int)$detected['id'] === (int)$c['id'] ? 'selected' : '' ?>>
                <?= e($c['name']) ?> &middot; <?= e($c['cashier_name']) ?> (<?= hm($c['start_time']) ?>&ndash;<?= hm($c['end_time']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="fg"><label class="lbl">Kas awal di laci (Rp)</label><input name="start_cash" type="number" min="0" value="0"></div>
        <button class="btn btn-primary btn-block">Buka Shift</button>
      </form>
    </div>
  <?php endif; ?>

<?php else:
  $st = shift_stats((int)$sh['id'], (float)$sh['start_cash']); ?>
  <p class="sub"><?= e($sh['shift_name']) ?> &middot; <?= e($sh['cashier_name']) ?> &middot; berjalan sejak <?= fmt_dt($sh['opened_at']) ?></p>
  <div class="stats">
    <div class="card stat"><div class="l">Kas awal</div><div class="v"><?= rp($sh['start_cash']) ?></div></div>
    <div class="card stat"><div class="l">Omset shift</div><div class="v"><?= rp($st['total']) ?></div></div>
    <div class="card stat"><div class="l">Transaksi</div><div class="v"><?= (int)$st['cnt'] ?></div></div>
    <div class="card stat"><div class="l">Kas seharusnya di laci</div><div class="v"><?= rp($st['expected']) ?></div></div>
  </div>
  <div class="card pad" style="max-width:480px;margin-bottom:18px">
    <h3 class="cardh2">Tutup Shift</h3>
    <form method="post" action="index.php?page=shift" onsubmit="return confirm('Tutup shift sekarang?')">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="close">
      <div class="fg"><label class="lbl">Jumlah kas yang dihitung di laci (Rp)</label><input name="counted" type="number" min="0" required></div>
      <button class="btn btn-danger btn-block">Tutup Shift</button>
    </form>
  </div>
<?php endif; ?>

<div class="card"><div class="cardh">Riwayat shift</div><?= shift_table($hist, true) ?></div>

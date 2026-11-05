<?php
// Dipanggil dari index.php. Variabel: $content, $pageTitle
$nav = is_admin()
    ? [
        ['Laporan', [['dashboard', 'Dashboard'], ['laporan', 'Laporan Nota'], ['labarugi', 'Laba Rugi'],
                     ['cetak', 'Log Cetak Struk'], ['shift', 'Rekap Shift']]],
        ['Produk & Stok', [['produk', 'Produk'], ['kategori', 'Kategori'], ['bahan', 'Bahan Baku'], ['resep', 'Resep & HPP'],
                           ['stok', 'Stok Opname'], ['diskon', 'Diskon Otomatis'], ['kupon', 'Kupon']]],
        ['Keuangan', [['beban', 'Beban Operasional']]],
        ['Pengaturan', [['jadwal', 'Jadwal Shift'], ['struk', 'Customize Struk'], ['printer', 'Printer Thermal'], ['akun', 'Akun']]],
      ]
    : [
        ['Kasir', [['pos', 'Kasir (POS)'], ['shift', 'Shift'], ['riwayat', 'Riwayat Nota'], ['stok', 'Cek Stok'], ['printer', 'Printer Thermal']]],
      ];
$lowCount = low_stock()['count'];
$cur = $_GET['page'] ?? '';
$sh = is_admin() ? null : active_shift();
?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle ?? 'Kasir') ?> &middot; <?= e(setting('store_name', 'Kasir')) ?></title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div id="app">
  <aside id="side">
    <div>
      <div class="brand"><?= e(setting('store_name', 'Toko Saya')) ?><small>Sistem Kasir</small></div>
      <?php foreach ($nav as [$group, $items]): ?>
        <div class="nvg"><?= e($group) ?></div>
        <?php foreach ($items as [$id, $label]): ?>
          <a class="nv<?= $cur === $id ? ' on' : '' ?>" href="index.php?page=<?= $id ?>"><?= e($label) ?><?php if ($id === 'stok' && $lowCount): ?><span class="nv-badge" title="Stok menipis"><?= $lowCount ?></span><?php endif; ?></a>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </div>
    <div class="who">
      <b><?= e(me()['name']) ?></b><br>
      <?php if (is_admin()): ?>Administrator
      <?php elseif ($sh): ?><?= e($sh['shift_name']) ?> &middot; <?= e($sh['cashier_name']) ?>
      <?php else: ?>Shift belum dibuka<?php endif; ?>
      <a class="btn btn-sm" href="index.php?page=logout">Keluar</a>
    </div>
  </aside>

  <div id="main">
    <header id="topbar">
      <div class="tb-title"><?= e($pageTitle ?? '') ?></div>
      <div class="tb-right">
        <span class="clock" id="clock"></span>
        <a href="index.php?page=printer" id="printer-pill" class="pill pill-off" title="Status printer thermal">
          <span class="dot"></span><span class="pill-text">Printer belum terhubung</span>
        </a>
      </div>
    </header>
    <main>
      <?= flash_show() ?>
      <?= $content ?>
    </main>
  </div>
</div>

<!-- Modal preview struk (dipakai di semua halaman) -->
<div id="rcpt-modal" class="overlay hidden">
  <div class="modal">
    <div class="mh"><h3>Preview Struk</h3><button class="x" type="button" data-close>&times;</button></div>
    <div class="mb rcpt-body">
      <div id="rcpt-status" class="rcpt-status"></div>
      <div id="rcpt-paper"></div>
      <div id="rcpt-history" class="print-history"></div>
    </div>
    <div class="mf">
      <button class="btn" type="button" data-close>Tutup</button>
      <button class="btn btn-primary" type="button" id="rcpt-print">Cetak Struk</button>
    </div>
  </div>
</div>
<div id="toast" class="toast"></div>
<div id="print-area"></div>
<style id="print-page"></style>

<script>
window.CSRF = '<?= csrf() ?>';
function printReport() {
  document.getElementById('print-page').textContent = '@page { size: A4; margin: 12mm; }';
  document.body.classList.add('print-report');
  window.print();
  setTimeout(() => document.body.classList.remove('print-report'), 500);
}
</script>
<script src="assets/printer.js"></script>
</body>
</html>

<?php
$pageTitle = 'Laba Rugi';
$from = valid_date($_GET['from'] ?? null, date('Y-m-01'));
$to = valid_date($_GET['to'] ?? null, today());

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'expense') {
    check_csrf();
    $name = trim((string)($_POST['name'] ?? ''));
    $amount = (int)round((float)($_POST['amount'] ?? 0));
    if ($name === '' || $amount <= 0) flash_set('Isi nama dan jumlah beban', 'err');
    else {
        q("INSERT INTO expenses (exp_date, name, amount) VALUES (?,?,?)", [valid_date($_POST['exp_date'] ?? null, today()), $name, $amount]);
        flash_set('Beban ' . $name . ' dicatat');
    }
    redirect("index.php?page=labarugi&from=$from&to=$to");
}

$s = q("SELECT COUNT(*) n, COALESCE(SUM(gross),0) gross, COALESCE(SUM(auto_disc),0) ad, COALESCE(SUM(coupon_disc),0) cd,
    COALESCE(SUM(total),0) net, COALESCE(SUM(hpp_total),0) hpp FROM transactions WHERE DATE(created_at) BETWEEN ? AND ?", [$from, $to])->fetch();
$exp = q("SELECT name, SUM(amount) amount, COUNT(*) n FROM expenses WHERE exp_date BETWEEN ? AND ? GROUP BY name ORDER BY amount DESC", [$from, $to])->fetchAll();
$byCat = q("SELECT COALESCE(NULLIF(ti.category,''),'Tanpa kategori') cat, SUM(ti.total) omset, SUM(ti.hpp*ti.qty) hpp, SUM(ti.qty) qty
    FROM transaction_items ti JOIN transactions t ON t.id=ti.trx_id WHERE DATE(t.created_at) BETWEEN ? AND ?
    GROUP BY cat ORDER BY omset DESC", [$from, $to])->fetchAll();

$disc = $s['ad'] + $s['cd'];
$gp = $s['net'] - $s['hpp'];
$totExp = array_sum(array_column($exp, 'amount'));
$np = $gp - $totExp;
$gpm = $s['net'] > 0 ? $gp / $s['net'] * 100 : 0;
$npm = $s['net'] > 0 ? $np / $s['net'] * 100 : 0;

if (isset($_GET['export'])) {
    $rows = [
        ['Periode', fmt_d($from) . ' s/d ' . fmt_d($to)], [],
        ['PENDAPATAN'], ['Penjualan kotor', $s['gross']], ['Diskon & kupon', -$disc], ['Pendapatan bersih', $s['net']], [],
        ['HARGA POKOK PENJUALAN'], ['Modal bahan (HPP)', -$s['hpp']], ['LABA KOTOR', $gp], [],
        ['BEBAN OPERASIONAL'],
    ];
    foreach ($exp as $x) $rows[] = [$x['name'], -$x['amount']];
    $rows[] = ['Total beban operasional', -$totExp];
    $rows[] = [];
    $rows[] = ['LABA BERSIH', $np];
    csv_out("laba_rugi_{$from}_{$to}.csv", [setting('store_name') . ' - Laporan Laba Rugi', ''], $rows);
}
?>
<div class="toolbar no-print">
  <div><h2>Laporan Laba Rugi</h2><p class="sub" style="margin:0">Pendapatan bersih dikurangi modal bahan (HPP) dan beban operasional.</p></div>
  <div class="btn-row">
    <a class="btn" href="index.php?page=labarugi&from=<?= e($from) ?>&to=<?= e($to) ?>&export=1">Export CSV</a>
    <button class="btn" type="button" onclick="printReport()">Cetak / PDF</button>
  </div>
</div>
<form method="get" class="filter card pad-sm no-print">
  <input type="hidden" name="page" value="labarugi">
  <label class="lbl">Periode</label>
  <input type="date" name="from" value="<?= e($from) ?>"><span class="muted">s/d</span><input type="date" name="to" value="<?= e($to) ?>">
  <button class="btn">Tampilkan</button>
  <span class="quick-range">
    <a href="index.php?page=labarugi&from=<?= today() ?>&to=<?= today() ?>">Hari ini</a>
    <a href="index.php?page=labarugi&from=<?= date('Y-m-d', strtotime('-6 days')) ?>&to=<?= today() ?>">7 hari</a>
    <a href="index.php?page=labarugi&from=<?= date('Y-m-01') ?>&to=<?= today() ?>">Bulan ini</a>
    <a href="index.php?page=labarugi&from=<?= date('Y-m-01', strtotime('first day of last month')) ?>&to=<?= date('Y-m-t', strtotime('last day of last month')) ?>">Bulan lalu</a>
  </span>
</form>

<div class="stats">
  <div class="card stat"><div class="l">Pendapatan bersih</div><div class="v"><?= rp($s['net']) ?></div><div class="s"><?= (int)$s['n'] ?> nota</div></div>
  <div class="card stat"><div class="l">Laba kotor</div><div class="v"><?= rp($gp) ?></div><div class="s">Margin <?= pct($gpm) ?></div></div>
  <div class="card stat"><div class="l">Beban operasional</div><div class="v neg"><?= rp($totExp) ?></div><div class="s"><?= count($exp) ?> pos beban</div></div>
  <div class="card stat <?= $np < 0 ? 'stat-bad' : 'stat-good' ?>"><div class="l">Laba bersih</div><div class="v"><?= rp($np) ?></div><div class="s">Margin <?= pct($npm) ?></div></div>
</div>

<div class="lr-layout">
  <div class="card pad statement">
    <div class="st-head">
      <div class="st-store"><?= e(setting('store_name')) ?></div>
      <div>LAPORAN LABA RUGI</div>
      <div class="muted sm">Periode <?= fmt_d($from) ?> s/d <?= fmt_d($to) ?></div>
    </div>
    <div class="st-sec">1. Pendapatan</div>
    <div class="st-row"><span>Penjualan kotor</span><span><?= rp($s['gross']) ?></span></div>
    <div class="st-row"><span>Diskon otomatis</span><span class="neg">(<?= rp($s['ad']) ?>)</span></div>
    <div class="st-row"><span>Potongan kupon</span><span class="neg">(<?= rp($s['cd']) ?>)</span></div>
    <div class="st-total"><span>Pendapatan bersih</span><span><?= rp($s['net']) ?></span></div>

    <div class="st-sec">2. Harga pokok penjualan</div>
    <div class="st-row"><span>Modal bahan baku (HPP)</span><span class="neg">(<?= rp($s['hpp']) ?>)</span></div>
    <div class="st-total st-hl"><span>Laba kotor</span><span><?= rp($gp) ?></span></div>

    <div class="st-sec">3. Beban operasional</div>
    <?php if (!$exp): ?><div class="st-row muted"><span>Belum ada beban pada periode ini</span><span>-</span></div><?php endif; ?>
    <?php foreach ($exp as $x): ?>
      <div class="st-row st-sub"><span><?= e($x['name']) ?><?= $x['n'] > 1 ? ' <span class="muted sm">(' . $x['n'] . 'x)</span>' : '' ?></span><span><?= rp($x['amount']) ?></span></div>
    <?php endforeach; ?>
    <div class="st-total"><span>Total beban operasional</span><span class="neg">(<?= rp($totExp) ?>)</span></div>

    <div class="st-final <?= $np < 0 ? 'is-loss' : 'is-profit' ?>"><span><?= $np < 0 ? 'RUGI BERSIH' : 'LABA BERSIH' ?></span><span><?= rp($np) ?></span></div>
  </div>

  <div>
    <div class="card pad no-print" style="margin-bottom:16px">
      <h3 class="cardh2">Catat beban baru</h3>
      <form method="post" action="index.php?page=labarugi&from=<?= e($from) ?>&to=<?= e($to) ?>">
        <?= csrf_field() ?><input type="hidden" name="action" value="expense">
        <div class="fg"><label class="lbl">Tanggal</label><input type="date" name="exp_date" value="<?= today() ?>"></div>
        <div class="fg"><label class="lbl">Nama beban</label><input name="name" placeholder="Misal: Gas LPG" required></div>
        <div class="fg"><label class="lbl">Jumlah (Rp)</label><input name="amount" type="number" min="1" required></div>
        <button class="btn btn-primary btn-block">Simpan Beban</button>
        <a class="link-sm" href="index.php?page=beban">Kelola semua beban &rarr;</a>
      </form>
    </div>
    <div class="card">
      <div class="cardh">Laba kotor per kategori</div>
      <?php if (!$byCat): ?><div class="empty">Belum ada penjualan</div><?php else: ?>
      <table><thead><tr><th>Kategori</th><th class="right">Omset</th><th class="right">Laba</th></tr></thead><tbody>
        <?php foreach ($byCat as $c): ?>
          <tr><td><?= e($c['cat']) ?><div class="muted sm"><?= (int)$c['qty'] ?> porsi</div></td><td class="right"><?= rp($c['omset']) ?></td><td class="right plus"><?= rp($c['omset'] - $c['hpp']) ?></td></tr>
        <?php endforeach; ?>
      </tbody></table><?php endif; ?>
    </div>
  </div>
</div>

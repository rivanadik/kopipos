<?php
$pageTitle = 'Dashboard';
$t = today();
$y = date('Y-m-d', strtotime('-1 day'));
$since = date('Y-m-d', strtotime('-6 days'));

$day = fn(string $d) => q("SELECT COUNT(*) n, COALESCE(SUM(total),0) omset, COALESCE(SUM(hpp_total),0) hpp
    FROM transactions WHERE DATE(created_at)=?", [$d])->fetch();
$td = $day($t);
$yd = $day($y);
$trend = fn($a, $b) => $b > 0 ? ($a - $b) / $b * 100 : null;
$avg = $td['n'] ? $td['omset'] / $td['n'] : 0;
$open = (int)db()->query("SELECT COUNT(*) FROM shifts WHERE closed_at IS NULL")->fetchColumn();

// 14 hari terakhir
$daily = [];
foreach (q("SELECT DATE(created_at) d, SUM(total) omset, SUM(total - hpp_total) laba FROM transactions
    WHERE created_at >= ? GROUP BY DATE(created_at)", [date('Y-m-d', strtotime('-13 days'))]) as $r) $daily[$r['d']] = $r;
$c14 = ['labels' => [], 'omset' => [], 'laba' => []];
for ($i = 13; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $c14['labels'][] = date('d/m', strtotime($d));
    $c14['omset'][] = (float)($daily[$d]['omset'] ?? 0);
    $c14['laba'][] = (float)($daily[$d]['laba'] ?? 0);
}
// Per jam hari ini
$hours = array_fill(0, 24, 0);
foreach (q("SELECT HOUR(created_at) h, COUNT(*) n FROM transactions WHERE DATE(created_at)=? GROUP BY h", [$t]) as $r) $hours[(int)$r['h']] = (int)$r['n'];
$hFrom = 6; $hTo = 23;
foreach ($hours as $h => $n) if ($n) { $hFrom = min($hFrom, $h); $hTo = max($hTo, $h); }
$cHour = ['labels' => [], 'values' => []];
for ($h = $hFrom; $h <= $hTo; $h++) { $cHour['labels'][] = str_pad((string)$h, 2, '0', STR_PAD_LEFT); $cHour['values'][] = $hours[$h]; }
// 7 hari: kategori, tipe pesanan, metode bayar
$pairs = fn(string $sql) => array_map(fn($r) => [$r['k'], (float)$r['v']], q($sql, [$since])->fetchAll());
$cCat = $pairs("SELECT COALESCE(NULLIF(ti.category,''),'Tanpa kategori') k, SUM(ti.total) v FROM transaction_items ti
    JOIN transactions t ON t.id=ti.trx_id WHERE DATE(t.created_at) >= ? GROUP BY k ORDER BY v DESC");
$cType = $pairs("SELECT order_type k, COUNT(*) v FROM transactions WHERE DATE(created_at) >= ? GROUP BY k ORDER BY v DESC");
$cMethod = $pairs("SELECT method k, SUM(total) v FROM transactions WHERE DATE(created_at) >= ? GROUP BY k ORDER BY v DESC");
$tops = q("SELECT ti.name, SUM(ti.qty) q, SUM(ti.total) omset FROM transaction_items ti JOIN transactions t ON t.id = ti.trx_id
    WHERE DATE(t.created_at) >= ? GROUP BY ti.name ORDER BY q DESC LIMIT 6", [$since])->fetchAll();
$maxTop = $tops ? max(array_column($tops, 'q')) : 1;
$last = db()->query("SELECT * FROM transactions ORDER BY id DESC LIMIT 6")->fetchAll();
$low = low_stock();
$unprinted = (int)q("SELECT COUNT(*) FROM transactions WHERE DATE(created_at)=? AND print_count=0", [$t])->fetchColumn();

function trend_html(?float $p): string
{
    if ($p === null) return '<span class="muted">belum ada data kemarin</span>';
    return '<span class="' . ($p >= 0 ? 'plus' : 'neg') . '">' . ($p >= 0 ? '▲ ' : '▼ ') . pct(abs($p)) . '</span> <span class="muted">vs kemarin</span>';
}
?>
<h2>Dashboard</h2>
<p class="sub">Ringkasan hari ini, <?= date('d/m/Y') ?></p>

<div class="stats">
  <div class="card stat"><div class="l">Omset hari ini</div><div class="v"><?= rp($td['omset']) ?></div><div class="s"><?= trend_html($trend((float)$td['omset'], (float)$yd['omset'])) ?></div></div>
  <div class="card stat"><div class="l">Laba kotor hari ini</div><div class="v"><?= rp($td['omset'] - $td['hpp']) ?></div><div class="s">HPP <?= rp($td['hpp']) ?></div></div>
  <div class="card stat"><div class="l">Jumlah nota</div><div class="v"><?= (int)$td['n'] ?></div><div class="s">Rata-rata <?= rp($avg) ?> / nota</div></div>
  <div class="card stat <?= $low['count'] ? 'stat-warn' : '' ?>"><div class="l">Stok menipis</div><div class="v"><?= $low['count'] ?> item</div>
    <div class="s"><a href="index.php?page=stok">Lihat stok</a> &middot; <?= $open ?> shift aktif</div></div>
</div>

<?php if ($low['count'] || $unprinted): ?>
<div class="alert-row">
  <?php if ($low['count']): ?>
  <div class="alert warn alert-list">
    <b>⚠ Peringatan stok</b>
    <ul>
      <?php foreach (array_slice($low['products'], 0, 4) as $p): ?><li><?= e($p['name']) ?> tinggal <b><?= num($p['stock']) ?> porsi</b></li><?php endforeach; ?>
      <?php foreach (array_slice($low['recipe'], 0, 3) as $p): ?><li><?= e($p['name']) ?>: bahan cukup untuk <b>± <?= num($p['stock']) ?> porsi</b></li><?php endforeach; ?>
      <?php foreach (array_slice($low['materials'], 0, 4) as $m): ?><li><?= e($m['name']) ?> tinggal <b><?= num($m['stock']) ?> <?= e($m['unit']) ?></b></li><?php endforeach; ?>
    </ul>
    <a href="index.php?page=stok">Buka Stok Opname &rarr;</a>
  </div>
  <?php endif; ?>
  <?php if ($unprinted): ?>
  <div class="alert err"><b><?= $unprinted ?> nota hari ini belum pernah dicetak.</b>
    <a href="index.php?page=laporan&print=belum">Periksa di Laporan Nota &rarr;</a></div>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="dash-grid">
  <div class="card span-2"><div class="cardh">Omset &amp; laba kotor 14 hari terakhir</div><div class="chart" id="ch-14" style="height:260px"></div></div>
  <div class="card"><div class="cardh">Penjualan per kategori <span class="muted sm">7 hari</span></div><div class="chart" id="ch-cat"></div></div>
  <div class="card"><div class="cardh">Jumlah nota per jam <span class="muted sm">hari ini</span></div><div class="chart" id="ch-hour" style="height:220px"></div></div>
  <div class="card"><div class="cardh">Dine In vs Take Away <span class="muted sm">7 hari</span></div><div class="chart" id="ch-type"></div></div>
  <div class="card"><div class="cardh">Metode bayar <span class="muted sm">7 hari</span></div><div class="chart" id="ch-method"></div></div>
  <div class="card">
    <div class="cardh">Produk terlaris <span class="muted sm">7 hari</span></div>
    <?php if (!$tops): ?><div class="empty">Belum ada penjualan</div><?php endif; ?>
    <div class="toplist">
      <?php foreach ($tops as $i => $r): ?>
        <div class="top-item">
          <span class="top-rank"><?= $i + 1 ?></span>
          <div class="top-main"><div class="top-name"><?= e($r['name']) ?></div>
            <div class="top-bar"><span style="width:<?= round($r['q'] / $maxTop * 100) ?>%"></span></div></div>
          <div class="right"><b><?= (int)$r['q'] ?></b><div class="muted sm"><?= rp($r['omset']) ?></div></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="card span-2"><div class="cardh">Nota terakhir <a class="link-sm" style="float:right" href="index.php?page=laporan">Semua nota &rarr;</a></div><?= render_trx_table($last) ?></div>
</div>

<script src="assets/charts.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const c14 = <?= json_encode($c14) ?>;
  Charts.line(document.getElementById('ch-14'), {labels: c14.labels, series: [
    {name: 'Omset', values: c14.omset}, {name: 'Laba kotor', values: c14.laba}], money: true});
  const h = <?= json_encode($cHour) ?>;
  Charts.bar(document.getElementById('ch-hour'), {labels: h.labels, values: h.values, unit: 'nota'});
  Charts.donut(document.getElementById('ch-cat'), {data: <?= json_encode($cCat, JSON_UNESCAPED_UNICODE) ?>, money: true});
  Charts.donut(document.getElementById('ch-type'), {data: <?= json_encode($cType, JSON_UNESCAPED_UNICODE) ?>, unit: 'nota'});
  Charts.donut(document.getElementById('ch-method'), {data: <?= json_encode($cMethod, JSON_UNESCAPED_UNICODE) ?>, money: true});
});
</script>

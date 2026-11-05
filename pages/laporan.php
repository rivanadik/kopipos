<?php
$pageTitle = 'Laporan Nota';
$from = valid_date($_GET['from'] ?? null, today());
$to = valid_date($_GET['to'] ?? null, $from);
$f = [
    'shift' => trim((string)($_GET['shift'] ?? '')),
    'method' => trim((string)($_GET['method'] ?? '')),
    'type' => trim((string)($_GET['type'] ?? '')),
    'print' => trim((string)($_GET['print'] ?? '')),
    'q' => trim((string)($_GET['q'] ?? '')),
];

$where = ['DATE(t.created_at) BETWEEN ? AND ?'];
$params = [$from, $to];
if ($f['shift'] !== '') { $where[] = 't.shift_name = ?'; $params[] = $f['shift']; }
if ($f['method'] !== '') { $where[] = 't.method = ?'; $params[] = $f['method']; }
if ($f['type'] !== '') { $where[] = 't.order_type = ?'; $params[] = $f['type']; }
if ($f['print'] === 'belum') $where[] = 't.print_count = 0';
if ($f['print'] === 'ulang') $where[] = 't.print_count > 1';
if ($f['q'] !== '') { $where[] = '(t.no LIKE ? OR t.customer_name LIKE ?)'; $params[] = '%' . $f['q'] . '%'; $params[] = '%' . $f['q'] . '%'; }
$w = implode(' AND ', $where);

$rows = q("SELECT t.* FROM transactions t WHERE $w ORDER BY t.id DESC LIMIT 2000", $params)->fetchAll();
$items = [];
if ($rows) {
    $ids = implode(',', array_map(fn($r) => (int)$r['id'], $rows));
    foreach (db()->query("SELECT trx_id, name, qty FROM transaction_items WHERE trx_id IN ($ids) ORDER BY id") as $i) $items[(int)$i['trx_id']][] = $i;
}

if (isset($_GET['export'])) {
    $out = [];
    foreach ($rows as $r) {
        $it = implode(', ', array_map(fn($i) => $i['name'] . ' x' . $i['qty'], $items[(int)$r['id']] ?? []));
        $out[] = [$r['no'], $r['queue_no'], $r['created_at'], $r['customer_name'], order_label($r), $r['shift_name'], $r['cashier_name'],
                  $it, $r['method'], $r['gross'], $r['auto_disc'] + $r['coupon_disc'], $r['coupon_code'], $r['total'], $r['hpp_total'],
                  $r['total'] - $r['hpp_total'], $r['print_count'], $r['last_printed_at']];
    }
    csv_out("nota_{$from}_{$to}.csv", ['No Nota', 'Antrian', 'Waktu', 'Pelanggan', 'Tipe', 'Shift', 'Kasir', 'Item', 'Metode',
        'Subtotal', 'Diskon', 'Kupon', 'Total', 'HPP', 'Laba Kotor', 'Jumlah Cetak', 'Terakhir Dicetak'], $out);
}

$sum = ['n' => count($rows), 'omset' => 0, 'hpp' => 0, 'disc' => 0, 'prints' => 0, 'unprinted' => 0, 'reprints' => 0];
$byMethod = []; $byType = []; $byShift = [];
foreach ($rows as $r) {
    $sum['omset'] += $r['total']; $sum['hpp'] += $r['hpp_total']; $sum['disc'] += $r['auto_disc'] + $r['coupon_disc'];
    $sum['prints'] += $r['print_count'];
    if ((int)$r['print_count'] === 0) $sum['unprinted']++;
    $sum['reprints'] += max(0, (int)$r['print_count'] - 1);
    $byMethod[$r['method']] = ($byMethod[$r['method']] ?? 0) + $r['total'];
    $byType[$r['order_type']] = ($byType[$r['order_type']] ?? 0) + 1;
    $k = $r['shift_name'] . ' · ' . $r['cashier_name'];
    $byShift[$k] = $byShift[$k] ?? ['n' => 0, 'omset' => 0, 'laba' => 0];
    $byShift[$k]['n']++; $byShift[$k]['omset'] += $r['total']; $byShift[$k]['laba'] += $r['total'] - $r['hpp_total'];
}
$profit = $sum['omset'] - $sum['hpp'];
$shiftNames = array_column(shift_configs(false), 'name');
$qs = http_build_query(['page' => 'laporan', 'from' => $from, 'to' => $to] + array_filter($f, fn($v) => $v !== ''));
?>
<div class="toolbar no-print">
  <div><h2>Laporan Nota</h2><p class="sub" style="margin:0">Semua nota tersimpan. Klik baris untuk melihat struk dan riwayat cetaknya.</p></div>
  <div class="btn-row">
    <a class="btn" href="index.php?<?= e($qs) ?>&export=1">Export CSV</a>
    <button class="btn" type="button" onclick="printReport()">Cetak / PDF</button>
    <?php if ($from === $to): ?><button class="btn btn-primary" type="button" onclick="Receipt.printSummary('day','<?= e($from) ?>')">Cetak Rekap Harian (Thermal)</button><?php endif; ?>
  </div>
</div>

<form method="get" class="filter card pad-sm no-print">
  <input type="hidden" name="page" value="laporan">
  <input type="date" name="from" value="<?= e($from) ?>" title="Dari tanggal"><span class="muted">s/d</span>
  <input type="date" name="to" value="<?= e($to) ?>" title="Sampai tanggal">
  <select name="shift"><option value="">Semua shift</option><?php foreach ($shiftNames as $n): ?><option <?= $f['shift'] === $n ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></select>
  <select name="type"><option value="">Semua tipe</option><?php foreach (['Dine In', 'Take Away'] as $n): ?><option <?= $f['type'] === $n ? 'selected' : '' ?>><?= $n ?></option><?php endforeach; ?></select>
  <select name="method"><option value="">Semua metode</option><?php foreach (['Tunai', 'QRIS', 'Transfer', 'Debit'] as $n): ?><option <?= $f['method'] === $n ? 'selected' : '' ?>><?= $n ?></option><?php endforeach; ?></select>
  <select name="print"><option value="">Semua status cetak</option><option value="belum" <?= $f['print'] === 'belum' ? 'selected' : '' ?>>Belum pernah dicetak</option><option value="ulang" <?= $f['print'] === 'ulang' ? 'selected' : '' ?>>Pernah dicetak ulang</option></select>
  <input name="q" value="<?= e($f['q']) ?>" placeholder="No. nota / pelanggan" style="max-width:190px">
  <button class="btn btn-primary">Terapkan</button>
  <?php if ($qs !== http_build_query(['page' => 'laporan', 'from' => $from, 'to' => $to])): ?><a href="index.php?page=laporan&from=<?= e($from) ?>&to=<?= e($to) ?>" class="link-sm">Reset filter</a><?php endif; ?>
</form>

<div class="print-only report-title"><?= e(setting('store_name')) ?> &mdash; Laporan Nota <?= fmt_d($from) ?><?= $from !== $to ? ' s/d ' . fmt_d($to) : '' ?></div>

<div class="stats stats-6">
  <div class="card stat"><div class="l">Jumlah nota</div><div class="v"><?= $sum['n'] ?></div></div>
  <div class="card stat"><div class="l">Total penjualan</div><div class="v"><?= rp($sum['omset']) ?></div><div class="s">Diskon <?= rp($sum['disc']) ?></div></div>
  <div class="card stat"><div class="l">Total HPP (modal)</div><div class="v"><?= rp($sum['hpp']) ?></div></div>
  <div class="card stat stat-good"><div class="l">Laba kotor</div><div class="v"><?= rp($profit) ?></div><div class="s">Margin <?= pct($sum['omset'] > 0 ? $profit / $sum['omset'] * 100 : 0) ?></div></div>
  <div class="card stat"><div class="l">Struk dicetak</div><div class="v"><?= $sum['prints'] ?>x</div><div class="s"><?= $sum['reprints'] ?> cetak ulang</div></div>
  <div class="card stat <?= $sum['unprinted'] ? 'stat-bad' : '' ?>"><div class="l">Nota belum dicetak</div><div class="v"><?= $sum['unprinted'] ?></div>
    <?php if ($sum['unprinted']): ?><div class="s"><a href="index.php?page=laporan&from=<?= e($from) ?>&to=<?= e($to) ?>&print=belum">Lihat</a></div><?php endif; ?></div>
</div>

<?php if ($rows): ?>
<div class="g3" style="margin-bottom:16px">
  <div class="card"><div class="cardh">Per shift &amp; kasir</div><table><tbody>
    <?php foreach ($byShift as $k => $v): ?><tr><td><?= e($k) ?><div class="muted sm"><?= $v['n'] ?> nota &middot; laba <?= rp($v['laba']) ?></div></td><td class="right"><b><?= rp($v['omset']) ?></b></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <div class="card"><div class="cardh">Per metode bayar</div><table><tbody>
    <?php foreach ($byMethod as $k => $v): ?><tr><td><?= e($k) ?></td><td class="right"><b><?= rp($v) ?></b></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <div class="card"><div class="cardh">Tipe pesanan</div><table><tbody>
    <?php foreach ($byType as $k => $v): ?><tr><td><?= e($k) ?></td><td class="right"><b><?= $v ?></b> nota</td></tr><?php endforeach; ?>
  </tbody></table></div>
</div>
<?php endif; ?>

<div class="card">
  <div class="cardh">Detail nota penjualan <span class="badge b-info" style="float:right"><?= $sum['n'] ?> nota</span></div>
  <?php if (!$rows): ?><div class="empty">Tidak ada nota pada filter ini</div><?php else: ?>
  <div class="tscroll"><table class="nota-table">
    <thead><tr><th>No. Nota</th><th class="right">Antrian</th><th>Waktu</th><th>Pelanggan</th><th>Tipe</th><th>Item dipesan</th>
      <th>Shift &middot; Kasir</th><th>Metode</th><th class="right">HPP</th><th class="right">Total</th><th class="right">Laba</th><th class="center">Cetak</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr class="click" onclick="Receipt.openById(<?= (int)$r['id'] ?>)">
        <td class="mono"><b><?= e($r['no']) ?></b></td>
        <td class="right"><b><?= (int)$r['queue_no'] ?></b></td>
        <td class="nowrap"><?= date('d/m H:i', strtotime($r['created_at'])) ?></td>
        <td><?= e($r['customer_name'] ?: '-') ?></td>
        <td class="nowrap"><span class="badge <?= $r['order_type'] === 'Dine In' ? 'b-info' : 'b-cat' ?>"><?= e(order_label($r)) ?></span></td>
        <td class="sm items-cell"><?php foreach ($items[(int)$r['id']] ?? [] as $i): ?><div><?= e($i['name']) ?> <b>×<?= (int)$i['qty'] ?></b></div><?php endforeach; ?></td>
        <td class="sm"><?= e($r['shift_name']) ?><div class="muted"><?= e($r['cashier_name']) ?></div></td>
        <td><?= e($r['method']) ?></td>
        <td class="right muted"><?= rp($r['hpp_total']) ?></td>
        <td class="right"><b><?= rp($r['total']) ?></b></td>
        <td class="right plus"><?= rp($r['total'] - $r['hpp_total']) ?></td>
        <td class="center"><?= print_badge((int)$r['print_count']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr><td colspan="8" class="right"><b>TOTAL</b></td><td class="right"><b><?= rp($sum['hpp']) ?></b></td>
      <td class="right"><b><?= rp($sum['omset']) ?></b></td><td class="right plus"><b><?= rp($profit) ?></b></td><td class="center"><b><?= $sum['prints'] ?>x</b></td></tr></tfoot>
  </table></div>
  <?php endif; ?>
</div>

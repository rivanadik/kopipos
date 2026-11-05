<?php
$pageTitle = 'Log Cetak Struk';
$from = valid_date($_GET['from'] ?? null, today());
$to = valid_date($_GET['to'] ?? null, $from);

$logs = q("SELECT pl.*, t.no, t.queue_no, t.customer_name, t.total, t.shift_name, t.cashier_name, t.created_at trx_at, u.username
    FROM print_logs pl JOIN transactions t ON t.id=pl.trx_id LEFT JOIN users u ON u.id=pl.user_id
    WHERE DATE(pl.printed_at) BETWEEN ? AND ? ORDER BY pl.id DESC LIMIT 1000", [$from, $to])->fetchAll();
$s = q("SELECT COUNT(*) n, COALESCE(SUM(print_count=0),0) unprinted, COALESCE(SUM(print_count>1),0) reprinted
    FROM transactions WHERE DATE(created_at) BETWEEN ? AND ?", [$from, $to])->fetch();
$first = count(array_filter($logs, fn($l) => (int)$l['copy_no'] === 1));
$reprint = count($logs) - $first;
?>
<div class="toolbar">
  <div><h2>Log Cetak Struk</h2><p class="sub" style="margin:0">Setiap struk yang dicetak, termasuk cetak ulang, tercatat di sini beserta waktu, cara cetak, dan akun yang mencetak.</p></div>
</div>
<form method="get" class="filter card pad-sm">
  <input type="hidden" name="page" value="cetak">
  <label class="lbl">Tanggal cetak</label>
  <input type="date" name="from" value="<?= e($from) ?>"><span class="muted">s/d</span><input type="date" name="to" value="<?= e($to) ?>">
  <button class="btn">Tampilkan</button>
</form>

<div class="stats">
  <div class="card stat"><div class="l">Total cetakan</div><div class="v"><?= count($logs) ?>x</div></div>
  <div class="card stat"><div class="l">Cetak pertama</div><div class="v"><?= $first ?></div></div>
  <div class="card stat <?= $reprint ? 'stat-warn' : '' ?>"><div class="l">Cetak ulang</div><div class="v"><?= $reprint ?></div><div class="s"><?= (int)$s['reprinted'] ?> nota dicetak lebih dari sekali</div></div>
  <div class="card stat <?= $s['unprinted'] ? 'stat-bad' : '' ?>"><div class="l">Nota belum dicetak</div><div class="v"><?= (int)$s['unprinted'] ?></div><div class="s">dari <?= (int)$s['n'] ?> nota pada periode ini</div></div>
</div>

<div class="card">
  <div class="tscroll"><table>
    <thead><tr><th>Waktu cetak</th><th>No. Nota</th><th class="right">Antrian</th><th>Pelanggan</th><th>Cetakan</th><th>Cara cetak</th><th>Perangkat</th><th>Dicetak oleh</th><th>Shift &middot; Kasir</th><th class="right">Total nota</th></tr></thead>
    <tbody>
    <?php if (!$logs): ?><tr><td colspan="10" class="empty">Belum ada struk dicetak pada periode ini</td></tr><?php endif; ?>
    <?php foreach ($logs as $l): ?>
      <tr class="click" onclick="Receipt.openById(<?= (int)$l['trx_id'] ?>)">
        <td class="nowrap"><?= fmt_dt($l['printed_at']) ?></td>
        <td class="mono"><?= e($l['no']) ?><div class="muted sm">dibuat <?= date('H:i', strtotime($l['trx_at'])) ?></div></td>
        <td class="right"><b><?= (int)$l['queue_no'] ?></b></td>
        <td><?= e($l['customer_name'] ?: '-') ?></td>
        <td><?= (int)$l['copy_no'] === 1 ? '<span class="badge b-on">Pertama</span>' : '<span class="badge b-warn">Ulang ke-' . ((int)$l['copy_no'] - 1) . '</span>' ?></td>
        <td><?= $l['method'] === 'thermal' ? 'Printer thermal' : 'Dialog print browser' ?></td>
        <td class="sm muted"><?= e($l['device'] ?: '-') ?></td>
        <td><?= e($l['username'] ?? '-') ?></td>
        <td class="sm"><?= e($l['shift_name'] . ' · ' . $l['cashier_name']) ?></td>
        <td class="right"><?= rp($l['total']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

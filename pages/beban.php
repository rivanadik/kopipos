<?php
$from = valid_date($_GET['from'] ?? null, date('Y-m-01'));
$to = valid_date($_GET['to'] ?? null, date('Y-m-t'));
$total = (float)q("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE exp_date BETWEEN ? AND ?", [$from, $to])->fetchColumn();
$cfg = [
    'title' => 'Beban Operasional',
    'table' => 'expenses',
    'where' => "exp_date BETWEEN " . db()->quote($from) . " AND " . db()->quote($to),
    'order' => 'exp_date DESC, id DESC',
    'intro' => 'Catat pengeluaran toko: sewa, listrik, air, gaji, gas, dan lainnya. Dipakai di Laporan Laba Rugi.',
    'top_html' => '<form method="get" class="filter card pad-sm"><input type="hidden" name="page" value="beban">'
        . '<label class="lbl">Periode</label><input type="date" name="from" value="' . e($from) . '">'
        . '<span class="muted">s/d</span><input type="date" name="to" value="' . e($to) . '">'
        . '<button class="btn">Tampilkan</button><span class="filter-total">Total beban: <b class="neg">' . rp($total) . '</b></span></form>',
    'fields' => [
        ['exp_date', 'Tanggal', 'date', null, today(), true],
        ['name', 'Nama beban', 'text', null, '', true],
        ['amount', 'Jumlah (Rp)', 'int', null, '', true],
        ['note', 'Catatan (opsional)', 'text'],
    ],
    'before_save' => fn(array $d, int $id) => $d['amount'] <= 0 ? 'Jumlah harus lebih dari 0' : $d,
    'cols' => [
        ['Tanggal', fn($r) => fmt_d($r['exp_date'])],
        ['Nama beban', fn($r) => '<b>' . e($r['name']) . '</b>'],
        ['Catatan', fn($r) => e($r['note'] ?: '-')],
        ['Jumlah', fn($r) => '<span class="neg">' . rp($r['amount']) . '</span>', true],
    ],
];
require __DIR__ . '/_crud.php';

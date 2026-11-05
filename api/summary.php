<?php
// Data rekap shift / harian untuk dicetak di printer thermal
require __DIR__ . '/../app/init.php';
api_guard(false);
$type = $_GET['type'] ?? '';
$rows = [];

if ($type === 'shift') {
    $s = q("SELECT * FROM shifts WHERE id=?", [(int)($_GET['id'] ?? 0)])->fetch();
    if (!$s) json_out(['error' => 'Shift tidak ditemukan'], 404);
    $where = 'shift_id = ?'; $p = [$s['id']];
    $title = 'REKAP ' . strtoupper($s['shift_name']);
    $info = [['Kasir', $s['cashier_name']], ['Dibuka', fmt_dt($s['opened_at'])], ['Ditutup', $s['closed_at'] ? fmt_dt($s['closed_at']) : 'masih berjalan']];
} elseif ($type === 'day') {
    $d = valid_date($_GET['date'] ?? null, today());
    $where = 'DATE(created_at) = ?'; $p = [$d];
    $title = 'REKAP HARIAN';
    $info = [['Tanggal', fmt_d($d)]];
} else {
    json_out(['error' => 'Jenis rekap tidak valid'], 400);
}

$sum = q("SELECT COUNT(*) n, COALESCE(SUM(gross),0) gross, COALESCE(SUM(auto_disc+coupon_disc),0) disc, COALESCE(SUM(total),0) total,
    COALESCE(SUM(hpp_total),0) hpp, COALESCE(SUM(print_count),0) prints, COALESCE(SUM(print_count=0),0) unprinted,
    COALESCE(SUM(GREATEST(print_count-1,0)),0) reprints,
    COALESCE(SUM(order_type='Dine In'),0) dine, COALESCE(SUM(order_type='Take Away'),0) take
    FROM transactions WHERE $where", $p)->fetch();
$methods = q("SELECT method, COUNT(*) n, SUM(total) v FROM transactions WHERE $where GROUP BY method ORDER BY v DESC", $p)->fetchAll();
$items = q("SELECT ti.name, SUM(ti.qty) qty FROM transaction_items ti JOIN transactions t ON t.id=ti.trx_id
    WHERE " . str_replace(['shift_id', 'created_at'], ['t.shift_id', 't.created_at'], $where) . " GROUP BY ti.name ORDER BY qty DESC LIMIT 15", $p)->fetchAll();

$sections = [];
$sections[] = ['Penjualan', [
    ['Jumlah nota', (string)$sum['n']], ['Dine In / Take Away', $sum['dine'] . ' / ' . $sum['take']],
    ['Penjualan kotor', rp($sum['gross'])], ['Diskon & kupon', '-' . rp($sum['disc'])],
    ['Total penjualan', rp($sum['total'])],
]];
$sections[] = ['Per metode bayar', array_map(fn($m) => [$m['method'] . ' (' . $m['n'] . ')', rp($m['v'])], $methods)];
if ($type === 'shift') {
    $st = shift_stats((int)$s['id'], (float)$s['start_cash']);
    $exp = $s['closed_at'] ? (float)$s['expected_cash'] : $st['expected'];
    $kas = [['Kas awal', rp($s['start_cash'])], ['Penjualan tunai', rp($st['cash'])], ['Kas seharusnya', rp($exp)]];
    if ($s['closed_at']) { $kas[] = ['Kas dihitung', rp($s['end_cash'])]; $kas[] = ['Selisih', rp((float)$s['end_cash'] - $exp)]; }
    $sections[] = ['Kas laci', $kas];
}
$sections[] = ['Cetak struk', [['Total cetakan', $sum['prints'] . 'x'], ['Cetak ulang', $sum['reprints'] . 'x'], ['Nota belum dicetak', (string)$sum['unprinted']]]];
$sections[] = ['Item terjual', array_map(fn($i) => [$i['name'], 'x' . $i['qty']], $items)];

json_out(['title' => $title, 'info' => $info, 'sections' => $sections, 'printedAt' => date('d/m/Y H:i'),
          'by' => me()['username'], 'settings' => receipt_settings()]);

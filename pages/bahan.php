<?php
$pageTitle = 'Bahan Baku';

// ---------- Stok masuk (pembelian bahan) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'masuk') {
    check_csrf();
    $m = q("SELECT * FROM materials WHERE id=?", [(int)$_POST['material_id']])->fetch();
    $packs = (float)str_replace(',', '.', $_POST['packs'] ?? '0');
    $extra = (float)str_replace(',', '.', $_POST['extra'] ?? '0');
    $price = (int)round((float)($_POST['price'] ?? 0));
    if (!$m) { flash_set('Bahan tidak ditemukan', 'err'); redirect('index.php?page=bahan'); }
    $qty = $packs * (float)$m['pack_size'] + $extra;
    if ($qty <= 0) { flash_set('Isi jumlah kemasan atau jumlah satuan', 'err'); redirect('index.php?page=bahan&masuk=' . $m['id']); }
    $pdo = db();
    $pdo->beginTransaction();
    $cur = (float)q("SELECT stock FROM materials WHERE id=? FOR UPDATE", [$m['id']])->fetchColumn();
    $after = $cur + $qty;
    if ($price > 0 && $price !== (int)$m['price']) {
        q("UPDATE materials SET stock=?, price_old=price, price=? WHERE id=?", [$after, $price, $m['id']]);
    } else {
        q("UPDATE materials SET stock=? WHERE id=?", [$after, $m['id']]);
    }
    stock_log('material', (int)$m['id'], $qty, $after, 'masuk', trim((string)($_POST['note'] ?? '')), null, $price > 0 ? $price : (int)$m['price']);
    $pdo->commit();
    flash_set('Stok masuk ' . num($qty) . ' ' . $m['unit'] . ' ' . $m['name'] . ' tercatat. Sisa stok ' . num($after) . ' ' . $m['unit']);
    redirect('index.php?page=bahan');
}

// ---------- Rekap stok masuk & terpakai ----------
$mv = [];
foreach (db()->query("SELECT item_id,
        SUM(CASE WHEN reason='masuk' THEN change_qty ELSE 0 END) masuk,
        -SUM(CASE WHEN reason='penjualan' THEN change_qty ELSE 0 END) pakai
        FROM stock_logs WHERE item_type='material' GROUP BY item_id") as $r) {
    $mv[(int)$r['item_id']] = $r;
}

$top = '';
$masukId = (int)($_GET['masuk'] ?? 0);
if ($masukId && ($m = q("SELECT * FROM materials WHERE id=?", [$masukId])->fetch())) {
    $top = '<div class="card pad form-card"><h3 class="cardh2">Stok Masuk: ' . e($m['name']) . '</h3>'
        . '<p class="muted sm" style="margin-top:-8px">1 kemasan = ' . num($m['pack_size']) . ' ' . e($m['unit']) . ' &middot; stok sekarang ' . num($m['stock']) . ' ' . e($m['unit']) . '</p>'
        . '<form method="post" action="index.php?page=bahan">' . csrf_field()
        . '<input type="hidden" name="action" value="masuk"><input type="hidden" name="material_id" value="' . (int)$m['id'] . '">'
        . '<div class="form-grid">'
        . '<div class="fg"><label class="lbl">Jumlah kemasan dibeli</label><input name="packs" type="number" step="any" min="0" value="1" autofocus></div>'
        . '<div class="fg"><label class="lbl">Tambahan satuan (' . e($m['unit']) . ', opsional)</label><input name="extra" type="number" step="any" min="0" value="0"></div>'
        . '<div class="fg"><label class="lbl">Harga beli per kemasan (Rp)</label><input name="price" type="number" min="0" value="' . (int)$m['price'] . '">'
        . '<span class="hint">Jika berbeda dari harga sekarang, HPP semua produk ikut diperbarui.</span></div>'
        . '<div class="fg"><label class="lbl">Catatan (supplier / no. faktur)</label><input name="note" maxlength="200"></div>'
        . '</div><div class="form-actions"><button class="btn btn-primary">Simpan Stok Masuk</button><a class="btn" href="index.php?page=bahan">Batal</a></div></form></div>';
}

$units = ['gram' => 'gram', 'ml' => 'ml', 'pcs' => 'pcs'];
$cfg = [
    'title' => 'Bahan Baku',
    'table' => 'materials',
    'order' => 'active DESC, name',
    'intro' => 'Bahan mentah, kemasan, dan es. Harga beli per kemasan dibagi isi kemasan = harga pokok per satuan.',
    'top_html' => $top,
    'fields' => [
        ['name', 'Nama bahan', 'text', null, '', true],
        ['unit', 'Satuan', 'select', $units, 'gram'],
        ['pack_size', 'Isi per kemasan beli (dalam satuan)', 'dec', null, 1000, true],
        ['price', 'Harga beli per kemasan (Rp)', 'int', null, '', true],
        ['min_stock', 'Batas stok menipis (dalam satuan)', 'dec', null, 0],
        ['active', 'Aktif', 'check'],
    ],
    'before_save' => function (array $d, int $id) {
        if ($d['pack_size'] <= 0) return 'Isi per kemasan harus lebih dari 0';
        if ($id) {
            $old = q("SELECT price FROM materials WHERE id=?", [$id])->fetchColumn();
            if ((int)$old !== (int)$d['price']) $d['price_old'] = (int)$old;
        } else {
            $d['price_old'] = $d['price'];
        }
        return $d;
    },
    'row_actions' => fn($r) => '<a class="btn btn-sm btn-ok" href="index.php?page=bahan&masuk=' . (int)$r['id'] . '">+ Stok Masuk</a> ',
    'cols' => [
        ['Nama bahan', fn($r) => '<b>' . e($r['name']) . '</b>' . ($r['active'] ? '' : ' <span class="badge b-off">Nonaktif</span>')],
        ['Ukuran beli', fn($r) => num($r['pack_size']) . ' ' . e($r['unit']) . ' / kemasan'],
        ['Harga lama', fn($r) => '<span class="muted">' . rp($r['price_old']) . '</span>', true],
        ['Harga baru', fn($r) => '<b>' . rp($r['price']) . '</b>', true],
        ['Selisih', function ($r) {
            $d = (float)$r['price'] - (float)$r['price_old'];
            if (abs($d) < 1) return '<span class="muted sm">Tetap</span>';
            $p = $r['price_old'] > 0 ? $d / $r['price_old'] * 100 : 0;
            return '<span class="badge ' . ($d > 0 ? 'b-bad' : 'b-on') . '">' . ($d > 0 ? '▲ +' : '▼ ') . rp($d) . ' (' . ($d > 0 ? '+' : '') . pct($p) . ')</span>';
        }],
        ['Harga / satuan', fn($r) => '<span class="mono">' . rp_unit(unit_price($r)) . ' / ' . e($r['unit']) . '</span>', true],
        ['Stok masuk', fn($r) => '<span class="info">' . num($mv[(int)$r['id']]['masuk'] ?? 0) . ' ' . e($r['unit']) . '</span>', true],
        ['Terpakai', fn($r) => '<span class="neg">' . num($mv[(int)$r['id']]['pakai'] ?? 0) . ' ' . e($r['unit']) . '</span>', true],
        ['Sisa stok', fn($r) => '<b class="' . ((float)$r['stock'] <= 0 ? 'neg' : '') . '">' . num($r['stock']) . ' ' . e($r['unit']) . '</b><br>' . stock_badge((float)$r['stock'], (float)$r['min_stock']), true],
    ],
];
require __DIR__ . '/_crud.php';

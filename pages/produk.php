<?php
$cats = categories_map();
$hpp = hpp_map();
$avail = recipe_avail_map();
$cfg = [
    'title' => 'Produk',
    'table' => 'products',
    'order' => 'category_id IS NULL, category_id, name',
    'intro' => 'HPP dihitung dari resep. Stok porsi diatur di menu Stok Opname.',
    'fields' => [
        ['name', 'Nama produk', 'text', null, '', true],
        ['code', 'Kode produk (opsional, misal KP-01)', 'text'],
        ['category_id', 'Kategori', 'fk', ['' => '— Tanpa kategori —'] + $cats],
        ['price', 'Harga jual (Rp)', 'int', null, '', true],
        ['image', 'URL gambar (opsional)', 'text'],
        ['min_stock', 'Batas stok menipis (porsi)', 'int', null, 5],
        ['track_stock', 'Hitung stok porsi manual (untuk produk siap jual tanpa resep, misal kue titipan)', 'check', null, 0],
        ['active', 'Tampilkan di kasir', 'check'],
    ],
    'before_save' => function (array $d, int $id) {
        if ($d['price'] < 0) return 'Harga tidak valid';
        if ($d['code'] === '') $d['code'] = null;
        return $d;
    },
    'cols' => [
        ['Gambar', fn($r) => thumb_html($r['image'], $r['name'])],
        ['Kode', fn($r) => '<span class="mono">' . e($r['code'] ?: '-') . '</span>'],
        ['Nama', fn($r) => '<b>' . e($r['name']) . '</b>'],
        ['Kategori', fn($r) => e($cats[(int)$r['category_id']] ?? '-')],
        ['Harga jual', fn($r) => rp($r['price']), true],
        ['HPP', fn($r) => isset($hpp[(int)$r['id']]) ? rp($hpp[(int)$r['id']]) : '<span class="muted">-</span>', true],
        ['Margin', function ($r) use ($hpp) {
            $h = $hpp[(int)$r['id']] ?? null;
            if ($h === null || $r['price'] <= 0) return '<span class="muted">-</span>';
            $m = ($r['price'] - $h) / $r['price'] * 100;
            return '<span class="badge ' . ($m >= 50 ? 'b-on' : ($m >= 30 ? 'b-warn' : 'b-bad')) . '">' . pct($m) . '</span>';
        }, true],
        ['Stok', function ($r) use ($avail) {
            if ($r['track_stock']) return '<b>' . num($r['stock']) . '</b> porsi ' . stock_badge((float)$r['stock'], (float)$r['min_stock']);
            if (isset($avail[(int)$r['id']])) return '± ' . num($avail[(int)$r['id']]) . ' porsi <span class="muted sm">(dari bahan)</span>';
            return '<span class="muted">Tidak dihitung</span>';
        }],
        ['Status', fn($r) => badge_html($r['active'])],
    ],
];
require __DIR__ . '/_crud.php';

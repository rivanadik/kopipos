<?php
$cats = categories_map();
$cfg = [
    'title' => 'Diskon Otomatis',
    'table' => 'discounts',
    'intro' => 'Diskon langsung memotong harga di kasir tanpa kode. Jika ada beberapa diskon, dipakai yang paling besar.',
    'fields' => [
        ['name', 'Nama diskon (tercetak di struk)', 'text', null, '', true],
        ['category_id', 'Berlaku untuk', 'fk', ['' => 'Semua produk'] + $cats],
        ['type', 'Tipe', 'select', ['percent' => 'Persen (%)', 'nominal' => 'Nominal (Rp)'], 'percent'],
        ['value', 'Nilai', 'dec', null, '', true],
        ['active', 'Aktif', 'check'],
    ],
    'cols' => [
        ['Nama', 'name'],
        ['Berlaku untuk', fn($r) => $r['category_id'] === null ? 'Semua produk' : e($cats[(int)$r['category_id']] ?? '-')],
        ['Nilai', fn($r) => $r['type'] === 'percent' ? num($r['value']) . '%' : rp($r['value']), true],
        ['Status', fn($r) => badge_html($r['active'])],
    ],
];
require __DIR__ . '/_crud.php';

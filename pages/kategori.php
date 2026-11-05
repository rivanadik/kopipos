<?php
$counts = [];
foreach (db()->query("SELECT category_id, COUNT(*) n FROM products GROUP BY category_id") as $r) $counts[(int)$r['category_id']] = (int)$r['n'];
$cfg = [
    'title' => 'Kategori',
    'table' => 'categories',
    'order' => 'sort_order, name',
    'intro' => 'Kelompokkan produk, misalnya Kopi, Non Kopi, Makanan Berat, Makanan Ringan. Urutan menentukan posisi tab di halaman kasir.',
    'fields' => [
        ['name', 'Nama kategori', 'text', null, '', true],
        ['sort_order', 'Urutan tampil (angka kecil tampil lebih dulu)', 'int', null, count(categories_map()) + 1],
        ['active', 'Tampilkan di kasir', 'check'],
    ],
    'cols' => [
        ['Urutan', fn($r) => (int)$r['sort_order']],
        ['Nama', fn($r) => '<b>' . e($r['name']) . '</b>'],
        ['Jumlah produk', fn($r) => ($counts[(int)$r['id']] ?? 0) . ' produk'],
        ['Status', fn($r) => badge_html($r['active'])],
    ],
];
require __DIR__ . '/_crud.php';

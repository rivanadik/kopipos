<?php
$cfg = [
    'title' => 'Kupon',
    'table' => 'coupons',
    'fields' => [
        ['code', 'Kode kupon', 'text'],
        ['type', 'Tipe potongan', 'select', ['percent' => 'Persen (%)', 'nominal' => 'Nominal (Rp)'], 'percent'],
        ['value', 'Nilai potongan', 'dec'],
        ['min_belanja', 'Minimal belanja (Rp)', 'int', null, 0],
        ['expiry', 'Berlaku sampai (kosongkan jika tanpa batas)', 'date'],
        ['quota', 'Kuota pemakaian (0 = tanpa batas)', 'int', null, 0],
        ['active', 'Aktif', 'check'],
    ],
    'cols' => [
        ['Kode', 'code'],
        ['Potongan', fn($r) => $r['type'] === 'percent' ? (float)$r['value'] . '%' : rp($r['value']), true],
        ['Min. belanja', fn($r) => rp($r['min_belanja']), true],
        ['Berlaku s/d', fn($r) => e($r['expiry'] ?: '-')],
        ['Terpakai', fn($r) => (int)$r['used'] . ' / ' . ($r['quota'] > 0 ? (int)$r['quota'] : '∞')],
        ['Status', fn($r) => badge_html($r['active'])],
    ],
];
require __DIR__ . '/_crud.php';

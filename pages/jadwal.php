<?php
$now = current_shift_config();
$cfg = [
    'title' => 'Jadwal Shift',
    'table' => 'shift_configs',
    'intro' => 'Atur Shift 1, 2, 3 beserta jam dan nama kasir yang bertugas. Nama ini otomatis tercetak di struk.',
    'fields' => [
        ['name', 'Nama shift', 'text', null, 'Shift ' . (count(shift_configs(false)) + 1), true],
        ['start_time', 'Jam mulai', 'time', null, '07:00', true],
        ['end_time', 'Jam selesai (boleh melewati tengah malam, misal 23:00 - 07:00)', 'time', null, '15:00', true],
        ['cashier_name', 'Nama kasir yang bertugas', 'text', null, '', true],
        ['active', 'Aktif', 'check'],
    ],
    'cols' => [
        ['Shift', fn($r) => '<b>' . e($r['name']) . '</b>'
            . ($now && (int)$now['id'] === (int)$r['id'] ? ' <span class="badge b-info">Sedang berlaku</span>' : '')],
        ['Jam', fn($r) => hm($r['start_time']) . ' &ndash; ' . hm($r['end_time'])],
        ['Nama kasir', 'cashier_name'],
        ['Status', fn($r) => badge_html($r['active'])],
    ],
];
require __DIR__ . '/_crud.php';

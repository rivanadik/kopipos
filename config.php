<?php
/*
 | Koneksi database
 | ----------------------------------------------------------------
 | LOKAL (XAMPP / Laragon):
 |   host 127.0.0.1, name kasir_pos, user root, pass '' (kosong)
 |
 | SHARED HOSTING (cPanel): nama database & user diberi awalan akun, contoh:
 |   'host' => 'localhost',
 |   'name' => 'tokosaya_kasir',
 |   'user' => 'tokosaya_kasir',
 |   'pass' => 'password-yang-dibuat-di-cpanel',
 */
return [
    'db' => [
        'host'    => '127.0.0.1',
        'name'    => 'kasir_pos',
        'user'    => 'root',
        'pass'    => '',
        'charset' => 'utf8mb4',
    ],
];

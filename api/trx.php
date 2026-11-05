<?php
require __DIR__ . '/../app/init.php';
api_guard(false);
$p = trx_payload((int)($_GET['id'] ?? 0));
if (!$p) json_out(['error' => 'Transaksi tidak ditemukan'], 404);
json_out($p);

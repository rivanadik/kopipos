<?php
// Pengaturan struk terbaru, dipakai untuk Test Print dari halaman Printer
require __DIR__ . '/../app/init.php';
api_guard(false);
json_out(['settings' => receipt_settings()]);

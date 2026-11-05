<?php
require __DIR__ . '/../app/init.php';
api_guard();
$in = api_input();
$r = calc_cart(is_array($in['cart'] ?? null) ? $in['cart'] : [], trim((string)($in['coupon'] ?? '')));
// HPP tidak perlu dikirim ke layar kasir
foreach ($r['lines'] as &$l) unset($l['hpp']);
json_out($r);

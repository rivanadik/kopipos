<?php
require __DIR__ . '/../app/init.php';
api_guard();
if (is_admin()) json_out(['error' => 'Transaksi hanya bisa dilakukan akun kasir'], 403);

$sh = active_shift();
if (!$sh) json_out(['error' => 'Shift belum dibuka'], 400);

$in = api_input();
$cart = is_array($in['cart'] ?? null) ? $in['cart'] : [];
$method = in_array($in['method'] ?? '', ['Tunai', 'QRIS', 'Transfer', 'Debit'], true) ? $in['method'] : 'Tunai';
$orderType = ($in['orderType'] ?? '') === 'Take Away' ? 'Take Away' : 'Dine In';
$customer = mb_substr(trim((string)($in['customer'] ?? '')), 0, 60);
$tableNo = $orderType === 'Dine In' ? mb_substr(trim((string)($in['tableNo'] ?? '')), 0, 10) : '';
if ($customer === '' && setting('require_customer', '1') === '1') json_out(['error' => 'Nama pelanggan wajib diisi'], 400);
// Nomor WhatsApp pelanggan (opsional) untuk kirim struk digital
$phoneRaw = trim((string)($in['phone'] ?? ''));
$phone = wa_normalize($phoneRaw);
if ($phoneRaw !== '' && $phone === '') json_out(['error' => 'Nomor WhatsApp tidak valid (contoh: 0812xxxxxxx)'], 400);
if (num_too_big((float)($in['paid'] ?? 0))) json_out(['error' => num_limit_msg('Uang diterima')], 400);

$pdo = db();
try {
    $pdo->beginTransaction();

    // Hitung ulang di server sambil mengunci baris produk (stok aman dari transaksi bersamaan)
    $r = calc_cart($cart, trim((string)($in['coupon'] ?? '')), true);
    if (!$r['lines']) throw new RuntimeException('Keranjang kosong');
    if ($r['err']) throw new RuntimeException($r['err']);
    if ($r['stockErr']) throw new RuntimeException($r['stockErr']);

    $paid = $method === 'Tunai' ? (float)($in['paid'] ?? 0) : $r['total'];
    if ($paid < $r['total']) throw new RuntimeException('Uang diterima kurang dari total');

    if ($r['couponId']) {
        $u = $pdo->prepare("UPDATE coupons SET used = used + 1 WHERE id=? AND (quota = 0 OR used < quota)");
        $u->execute([$r['couponId']]);
        if ($u->rowCount() === 0) throw new RuntimeException('Kuota kupon sudah habis');
    }

    $queue = (int)q("SELECT COALESCE(MAX(queue_no),0)+1 FROM transactions WHERE created_at >= ? FOR UPDATE", [today() . ' 00:00:00'])->fetchColumn();
    $hppTotal = 0;
    foreach ($r['lines'] as $l) $hppTotal += $l['hpp'] * $l['qty'];

    q("INSERT INTO transactions
        (no, queue_no, customer_name, order_type, table_no, user_id, shift_id, shift_name, cashier_name, created_at,
         gross, auto_disc, coupon_code, coupon_disc, total, hpp_total, method, paid, change_amt)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)", [
        'TMP-' . bin2hex(random_bytes(6)), $queue, $customer ?: null, $orderType, $tableNo ?: null,
        (int)me()['id'], $sh['id'], $sh['shift_name'], $sh['cashier_name'], now(),
        $r['gross'], $r['autoD'], $r['coupon'] ?: null, $r['cd'], $r['total'], $hppTotal, $method, $paid, max(0, $paid - $r['total']),
    ]);
    $id = (int)$pdo->lastInsertId();
    $no = date('ymd') . '-' . str_pad((string)$id, 5, '0', STR_PAD_LEFT);
    q("UPDATE transactions SET no=? WHERE id=?", [$no, $id]);
    if ($phone !== '' && trx_has_phone()) q("UPDATE transactions SET customer_phone=? WHERE id=?", [$phone, $id]);

    $stocks = [];
    $matUse = [];
    foreach ($r['lines'] as $l) {
        q("INSERT INTO transaction_items (trx_id, product_id, name, category, qty, price, disc, dname, hpp, total)
           VALUES (?,?,?,?,?,?,?,?,?,?)",
          [$id, $l['product_id'], $l['name'], $l['category'] ?: null, $l['qty'], $l['price'], $l['disc'], $l['dname'] ?: null, $l['hpp'], $l['net']]);

        // Stok porsi manual
        if ($l['track']) {
            q("UPDATE products SET stock = stock - ? WHERE id=?", [$l['qty'], $l['product_id']]);
            $after = (int)q("SELECT stock FROM products WHERE id=?", [$l['product_id']])->fetchColumn();
            stock_log('product', $l['product_id'], -$l['qty'], $after, 'penjualan', '', $id);
            $stocks[$l['product_id']] = $after;
        }
        // Bahan baku sesuai resep
        foreach (q("SELECT material_id, qty FROM recipes WHERE product_id=?", [$l['product_id']]) as $rc) {
            $matUse[(int)$rc['material_id']] = ($matUse[(int)$rc['material_id']] ?? 0) + (float)$rc['qty'] * $l['qty'];
        }
    }
    foreach ($matUse as $mid => $use) {
        q("UPDATE materials SET stock = stock - ? WHERE id=?", [$use, $mid]);
        $after = (float)q("SELECT stock FROM materials WHERE id=?", [$mid])->fetchColumn();
        stock_log('material', $mid, -$use, $after, 'penjualan', '', $id);
    }

    $pdo->commit();
    $payload = trx_payload($id);
    $payload['stocks'] = $stocks;
    json_out($payload);
} catch (RuntimeException $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_out(['error' => $ex->getMessage()], 400);
} catch (Throwable $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('checkout: ' . $ex->getMessage());
    json_out(['error' => 'Gagal menyimpan transaksi'], 500);
}

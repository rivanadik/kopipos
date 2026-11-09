<?php
date_default_timezone_set('Asia/Jakarta');
if (session_status() === PHP_SESSION_NONE) session_start();

$config = require __DIR__ . '/../config.php';

/* ================= DATABASE ================= */
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        global $config;
        $c = $config['db'];
        $pdo = new PDO(
            "mysql:host={$c['host']};dbname={$c['name']};charset={$c['charset']}",
            $c['user'], $c['pass'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
        $pdo->exec("SET time_zone = '+07:00'");
    }
    return $pdo;
}
function q(string $sql, array $p = []): PDOStatement
{
    $s = db()->prepare($sql);
    $s->execute($p);
    return $s;
}

/* ================= BATAS ANGKA & SKEMA ================= */
// Batas wajar untuk stok / harga / jumlah yang diinput (1 triliun). Di atas ini ditolak dengan pesan,
// bukan error database. Kolom stok sudah dilebarkan (BIGINT / DECIMAL(20,2)) agar jutaan-miliaran aman.
const MAX_NUM = 1000000000000.0;
function num_too_big(float $v): bool { return !is_finite($v) || abs($v) > MAX_NUM; }
function num_limit_msg(string $label): string
{
    return 'Angka "' . $label . '" terlalu besar (maksimal ' . number_format(MAX_NUM, 0, ',', '.') . ')';
}

// Nomor WhatsApp -> format internasional tanpa +/spasi (08xx -> 628xx). '' jika tidak valid.
function wa_normalize(string $raw): string
{
    $d = preg_replace('/\D+/', '', $raw);
    if ($d === '') return '';
    if (strncmp($d, '00', 2) === 0) $d = substr($d, 2);
    elseif ($d[0] === '0') $d = '62' . substr($d, 1);
    elseif ($d[0] === '8') $d = '62' . $d;
    return strlen($d) >= 9 && strlen($d) <= 15 ? $d : '';
}

// Migrasi skema otomatis & aman diulang (dijalankan sekali; ditandai di tabel settings).
function ensure_schema(): void
{
    try {
        if ((int)setting('schema_v', '1') >= 2) return;
        if (!empty($_SESSION['schema_tried'])) return;   // gagal sebelumnya (mis. user DB tanpa hak ALTER): jangan ulang tiap request
        $_SESSION['schema_tried'] = 1;
        $pdo = db();
        $hasCol = function (string $t, string $c) use ($pdo): bool {
            $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
            $s->execute([$t, $c]);
            return (int)$s->fetchColumn() > 0;
        };
        $sql = [];
        if (!$hasCol('transactions', 'customer_phone')) {
            $sql[] = "ALTER TABLE transactions ADD COLUMN customer_phone VARCHAR(20) NULL AFTER customer_name";
        }
        // Stok boleh sampai miliaran (sebelumnya INT = maks ±2,1 miliar dan DECIMAL(14,2))
        $sql[] = "ALTER TABLE products MODIFY stock BIGINT NOT NULL DEFAULT 0, MODIFY min_stock BIGINT NOT NULL DEFAULT 5";
        $sql[] = "ALTER TABLE materials MODIFY pack_size DECIMAL(20,2) NOT NULL DEFAULT 1 COMMENT 'isi per kemasan beli',
            MODIFY stock DECIMAL(20,2) NOT NULL DEFAULT 0 COMMENT 'dalam satuan unit',
            MODIFY min_stock DECIMAL(20,2) NOT NULL DEFAULT 0";
        $sql[] = "ALTER TABLE recipes MODIFY qty DECIMAL(20,2) NOT NULL";
        $sql[] = "ALTER TABLE stock_logs MODIFY change_qty DECIMAL(20,2) NOT NULL, MODIFY stock_after DECIMAL(20,2) NOT NULL";
        $sql[] = "ALTER TABLE transaction_items MODIFY qty BIGINT UNSIGNED NOT NULL";
        $ok = true;
        foreach ($sql as $s) {
            try { $pdo->exec($s); }
            catch (Throwable $e) { $ok = false; error_log('ensure_schema: ' . $e->getMessage()); }
        }
        if ($ok) setting_save(['schema_v' => '2']);
    } catch (Throwable $e) {
        error_log('ensure_schema: ' . $e->getMessage());
    }
}
// Apakah kolom nomor WhatsApp sudah ada? (dicek sekali per request)
function trx_has_phone(): bool
{
    static $v = null;
    if ($v === null) {
        try { $v = (bool)db()->query("SHOW COLUMNS FROM transactions LIKE 'customer_phone'")->fetch(); }
        catch (Throwable $e) { $v = false; }
    }
    return $v;
}

/* ================= FORMAT ================= */
function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function rp($n): string { return ($n < 0 ? '-' : '') . 'Rp ' . number_format(abs((float)$n), 0, ',', '.'); }
function num($n): string
{
    $n = (float)$n;
    if (abs($n - round($n)) < 0.001) return number_format($n, 0, ',', '.');
    return rtrim(rtrim(number_format($n, 2, ',', '.'), '0'), ',');
}
// Harga per satuan kecil (misal Rp 18,5/ml) tampil dengan desimal
function rp_unit($n): string { return 'Rp ' . num(round((float)$n, (float)$n < 100 ? 2 : 0)); }
function pct($n): string { return number_format((float)$n, 1, ',', '.') . '%'; }
function today(): string { return date('Y-m-d'); }
function now(): string { return date('Y-m-d H:i:s'); }
function fmt_dt(?string $s): string { return $s ? date('d/m/Y H:i', strtotime($s)) : '-'; }
function fmt_d(?string $s): string { return $s ? date('d/m/Y', strtotime($s)) : '-'; }
function hm($t): string { return substr((string)$t, 0, 5); }
function redirect(string $url): void { header('Location: ' . $url); exit; }
function valid_date($d, string $def): string { return is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : $def; }

/* ================= PENGATURAN ================= */
function settings_all(bool $reload = false): array
{
    static $s = null;
    if ($s === null || $reload) {
        $s = [];
        foreach (db()->query("SELECT k, v FROM settings") as $r) $s[$r['k']] = (string)$r['v'];
    }
    return $s;
}
function setting(string $k, string $d = ''): string
{
    $s = settings_all();
    return array_key_exists($k, $s) ? $s[$k] : $d;
}
function setting_save(array $kv): void
{
    $st = db()->prepare("INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)");
    foreach ($kv as $k => $v) $st->execute([$k, (string)$v]);
    settings_all(true);
}
function receipt_defaults(): array
{
    return [
        'store_name' => 'Toko Saya', 'store_address' => '', 'store_phone' => '',
        'header_note' => '', 'footer' => 'Terima kasih atas kunjungan Anda', 'footer2' => '',
        'paper' => '58',
        'show_address' => '1', 'show_phone' => '1', 'show_no' => '1', 'show_date' => '1',
        'show_shift' => '1', 'show_cashier' => '1', 'show_queue' => '1', 'show_customer' => '1',
        'show_order_type' => '1', 'show_item_disc' => '1', 'show_payment' => '1',
        'feed_lines' => '3', 'auto_cut' => '1', 'auto_print' => '1',
    ];
}
function receipt_settings(): array
{
    $out = [];
    foreach (receipt_defaults() as $k => $v) $out[$k] = setting($k, $v);
    return $out;
}

/* ================= UI KECIL ================= */
function thumb_html(?string $img, string $name): string
{
    return '<div class="thumb">' . ($img ? '<img src="' . e($img) . '" alt="" onerror="this.remove()">' : '')
        . e(mb_strtoupper(mb_substr($name ?: '?', 0, 1))) . '</div>';
}
function badge_html($on): string
{
    return '<span class="badge ' . ($on ? 'b-on' : 'b-off') . '">' . ($on ? 'Aktif' : 'Nonaktif') . '</span>';
}
function stock_badge(float $stock, float $min): string
{
    if ($stock <= 0) return '<span class="badge b-bad">Habis</span>';
    if ($stock <= $min) return '<span class="badge b-warn">Menipis</span>';
    return '<span class="badge b-on">Aman</span>';
}
function print_badge(int $n): string
{
    if ($n === 0) return '<span class="badge b-bad" title="Belum pernah dicetak">Belum</span>';
    if ($n === 1) return '<span class="badge b-on" title="Dicetak 1 kali">1x</span>';
    return '<span class="badge b-warn" title="Dicetak ' . $n . ' kali (' . ($n - 1) . ' cetak ulang)">' . $n . 'x</span>';
}

/* ================= CSRF & FLASH ================= */
function csrf(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="_csrf" value="' . csrf() . '">'; }
function check_csrf(): void
{
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['_csrf'] ?? '')) {
        http_response_code(403);
        die('Sesi tidak valid. Muat ulang halaman lalu coba lagi.');
    }
}
function flash_set(string $msg, string $type = 'ok'): void { $_SESSION['flash'] = [$msg, $type]; }
function flash_show(): string
{
    if (empty($_SESSION['flash'])) return '';
    [$m, $t] = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return '<div class="alert ' . e($t) . '">' . e($m) . '</div>';
}

/* ================= AUTH ================= */
function verify_password(array $user, string $input): bool
{
    $stored = (string)$user['password'];
    if (strncmp($stored, 'plain:', 6) === 0) {
        if (!hash_equals(substr($stored, 6), $input)) return false;
        q("UPDATE users SET password=? WHERE id=?", [password_hash($input, PASSWORD_DEFAULT), $user['id']]);
        return true;
    }
    return password_verify($input, $stored);
}
function me(): ?array
{
    static $cache = false;
    if ($cache === false) {
        $cache = null;
        if (!empty($_SESSION['uid'])) $cache = q("SELECT * FROM users WHERE id=? AND active=1", [(int)$_SESSION['uid']])->fetch() ?: null;
    }
    return $cache;
}
function is_admin(): bool { return me() !== null && me()['role'] === 'admin'; }

/* ================= KATEGORI ================= */
function categories_map(bool $activeOnly = false): array
{
    $out = [];
    foreach (db()->query("SELECT id, name FROM categories" . ($activeOnly ? " WHERE active=1" : "") . " ORDER BY sort_order, name") as $r) {
        $out[(int)$r['id']] = $r['name'];
    }
    return $out;
}

/* ================= HPP & STOK ================= */
function unit_price(array $m): float { return (float)$m['pack_size'] > 0 ? (float)$m['price'] / (float)$m['pack_size'] : 0.0; }

// HPP per porsi tiap produk dari resep
function hpp_map(bool $reload = false): array
{
    static $map = null;
    if ($map === null || $reload) {
        $map = [];
        $rows = db()->query("SELECT r.product_id, SUM(r.qty * m.price / NULLIF(m.pack_size,0)) hpp
            FROM recipes r JOIN materials m ON m.id = r.material_id GROUP BY r.product_id");
        foreach ($rows as $r) $map[(int)$r['product_id']] = (float)$r['hpp'];
    }
    return $map;
}
// Perkiraan porsi yang masih bisa dibuat dari stok bahan
function recipe_avail_map(): array
{
    $map = [];
    $rows = db()->query("SELECT r.product_id, MIN(FLOOR(GREATEST(m.stock,0) / r.qty)) avail
        FROM recipes r JOIN materials m ON m.id = r.material_id WHERE r.qty > 0 GROUP BY r.product_id");
    foreach ($rows as $r) $map[(int)$r['product_id']] = (int)$r['avail'];
    return $map;
}
// Daftar stok menipis / habis
function low_stock(): array
{
    $prod = db()->query("SELECT id, code, name, stock, min_stock FROM products
        WHERE active=1 AND track_stock=1 AND stock <= min_stock ORDER BY stock")->fetchAll();
    $avail = recipe_avail_map();
    $rec = [];
    foreach (db()->query("SELECT id, code, name, min_stock FROM products WHERE active=1 AND track_stock=0") as $p) {
        $a = $avail[(int)$p['id']] ?? null;
        if ($a !== null && $a <= (int)$p['min_stock']) { $p['stock'] = $a; $rec[] = $p; }
    }
    $mat = db()->query("SELECT id, name, unit, stock, min_stock FROM materials
        WHERE active=1 AND stock <= min_stock ORDER BY stock / NULLIF(min_stock,0)")->fetchAll();
    return ['products' => $prod, 'recipe' => $rec, 'materials' => $mat,
            'count' => count($prod) + count($rec) + count($mat)];
}
function stock_log(string $type, int $id, float $change, float $after, string $reason, string $note = '',
                   ?int $trxId = null, ?int $unitPrice = null): void
{
    q("INSERT INTO stock_logs (item_type, item_id, change_qty, stock_after, reason, note, unit_price, trx_id, user_id, created_at)
       VALUES (?,?,?,?,?,?,?,?,?,?)",
      [$type, $id, $change, $after, $reason, $note !== '' ? mb_substr($note, 0, 200) : null, $unitPrice, $trxId,
       me()['id'] ?? null, now()]);
}

/* ================= JADWAL & SESI SHIFT ================= */
function shift_configs(bool $onlyActive = true): array
{
    return db()->query("SELECT * FROM shift_configs" . ($onlyActive ? " WHERE active=1" : "") . " ORDER BY start_time")->fetchAll();
}
function time_in_range(string $t, string $start, string $end): bool
{
    if ($start === $end) return true;
    return $start < $end ? ($t >= $start && $t < $end) : ($t >= $start || $t < $end);
}
function current_shift_config(): ?array
{
    $t = date('H:i:s');
    foreach (shift_configs() as $c) if (time_in_range($t, $c['start_time'], $c['end_time'])) return $c;
    return null;
}
function active_shift(): ?array
{
    if (!me()) return null;
    return q("SELECT * FROM shifts WHERE user_id=? AND closed_at IS NULL ORDER BY id DESC LIMIT 1", [(int)me()['id']])->fetch() ?: null;
}
function shift_stats(int $sid, float $start): array
{
    $r = q("SELECT COALESCE(SUM(total),0) total, COUNT(*) cnt, COALESCE(SUM(hpp_total),0) hpp,
        COALESCE(SUM(CASE WHEN method='Tunai' THEN total ELSE 0 END),0) cash,
        COALESCE(SUM(print_count),0) prints, COALESCE(SUM(print_count=0),0) unprinted
        FROM transactions WHERE shift_id=?", [$sid])->fetch();
    $r['expected'] = $start + (float)$r['cash'];
    return $r;
}
function shift_table(array $rows, bool $actions = false): string
{
    if (!$rows) return '<div class="empty">Belum ada data shift</div>';
    $h = '<div class="tscroll"><table><thead><tr><th>Shift</th><th>Kasir</th><th>Dibuka</th><th>Ditutup</th>'
        . '<th class="right">Nota</th><th class="right">Omset</th><th class="right">Kas seharusnya</th>'
        . '<th class="right">Kas dihitung</th><th class="right">Selisih</th>' . ($actions ? '<th></th>' : '') . '</tr></thead><tbody>';
    foreach ($rows as $s) {
        $st = shift_stats((int)$s['id'], (float)$s['start_cash']);
        $closed = $s['closed_at'];
        $sel = $closed ? (float)$s['end_cash'] - (float)$s['expected_cash'] : null;
        $selCls = $sel === null ? '' : ($sel < 0 ? 'neg' : ($sel > 0 ? 'plus' : ''));
        $h .= '<tr><td><b>' . e($s['shift_name']) . '</b></td><td>' . e($s['cashier_name']) . '</td>'
            . '<td>' . fmt_dt($s['opened_at']) . '</td>'
            . '<td>' . ($closed ? fmt_dt($closed) : '<span class="badge b-on">Berjalan</span>') . '</td>'
            . '<td class="right">' . (int)$st['cnt'] . '</td>'
            . '<td class="right">' . rp($st['total']) . '</td>'
            . '<td class="right">' . rp($closed ? $s['expected_cash'] : $st['expected']) . '</td>'
            . '<td class="right">' . ($closed ? rp($s['end_cash']) : '-') . '</td>'
            . '<td class="right ' . $selCls . '">' . ($sel === null ? '-' : rp($sel)) . '</td>'
            . ($actions ? '<td class="right"><button class="btn btn-sm" type="button" onclick="Receipt.printSummary(\'shift\',' . (int)$s['id'] . ')">Cetak Rekap</button></td>' : '')
            . '</tr>';
    }
    return $h . '</tbody></table></div>';
}

/* ================= DISKON & KUPON ================= */
function best_disc(array $p): array
{
    static $discs = null;
    if ($discs === null) $discs = db()->query("SELECT * FROM discounts WHERE active=1")->fetchAll();
    $best = ['amt' => 0.0, 'name' => ''];
    foreach ($discs as $d) {
        if ($d['category_id'] !== null && (int)$d['category_id'] !== (int)$p['category_id']) continue;
        $amt = $d['type'] === 'percent' ? round((float)$p['price'] * (float)$d['value'] / 100) : (float)$d['value'];
        $amt = min($amt, (float)$p['price']);
        if ($amt > $best['amt']) $best = ['amt' => $amt, 'name' => $d['name']];
    }
    return $best;
}
function coupon_check(?array $c, float $sub): array
{
    if (!$c) return ['err' => 'Kupon tidak ditemukan'];
    if (!$c['active']) return ['err' => 'Kupon tidak aktif'];
    if ($c['expiry'] && $c['expiry'] < today()) return ['err' => 'Kupon sudah kedaluwarsa'];
    if ((int)$c['quota'] > 0 && (int)$c['used'] >= (int)$c['quota']) return ['err' => 'Kuota kupon sudah habis'];
    if ($sub < (float)$c['min_belanja']) return ['err' => 'Minimal belanja ' . rp($c['min_belanja'])];
    $amt = $c['type'] === 'percent' ? round($sub * (float)$c['value'] / 100) : (float)$c['value'];
    return ['amt' => min($amt, $sub)];
}
// Hitung keranjang di server (sumber kebenaran harga, diskon, kupon, HPP, dan stok)
function calc_cart(array $cart, string $code, bool $lock = false): array
{
    $lines = [];
    $hpp = hpp_map();
    $cats = categories_map();
    $st = db()->prepare("SELECT * FROM products WHERE id=? AND active=1" . ($lock ? " FOR UPDATE" : ""));
    $stockErr = '';
    $seen = [];
    foreach ($cart as $it) {
        $id = (int)($it['id'] ?? 0);
        if (isset($seen[$id])) continue;
        $seen[$id] = true;
        $qty = max(1, min(999, (int)($it['qty'] ?? 1)));
        $st->execute([$id]);
        $p = $st->fetch();
        if (!$p) continue;
        if ($p['track_stock'] && $qty > (int)$p['stock'] && $stockErr === '') {
            $stockErr = (int)$p['stock'] <= 0 ? "Stok {$p['name']} habis" : "Stok {$p['name']} tinggal " . num($p['stock']);
        }
        $price = (float)$p['price'];
        $d = best_disc($p);
        $lines[] = [
            'product_id' => (int)$p['id'], 'name' => $p['name'], 'category' => $cats[(int)$p['category_id']] ?? '',
            'price' => $price, 'qty' => $qty, 'disc' => $d['amt'], 'dname' => $d['name'],
            'hpp' => round($hpp[(int)$p['id']] ?? 0), 'track' => (int)$p['track_stock'],
            'gross' => $price * $qty, 'net' => ($price - $d['amt']) * $qty,
        ];
    }
    $gross = array_sum(array_column($lines, 'gross'));
    $sub = array_sum(array_column($lines, 'net'));

    $cpn = null; $cd = 0.0; $err = '';
    if ($code !== '') {
        $c = q("SELECT * FROM coupons WHERE UPPER(code)=?", [strtoupper($code)])->fetch() ?: null;
        $v = coupon_check($c, $sub);
        if (isset($v['err'])) $err = $v['err'];
        else { $cpn = $c; $cd = $v['amt']; }
    }
    return [
        'lines' => $lines, 'gross' => $gross, 'autoD' => $gross - $sub, 'sub' => $sub,
        'cd' => $cd, 'total' => max(0, $sub - $cd),
        'coupon' => $cpn['code'] ?? '', 'couponId' => $cpn ? (int)$cpn['id'] : null,
        'err' => $err, 'stockErr' => $stockErr,
    ];
}

/* ================= TRANSAKSI / NOTA ================= */
function order_label(array $r): string
{
    $t = (string)$r['order_type'];
    return $t . ($t === 'Dine In' && !empty($r['table_no']) ? ' · Meja ' . $r['table_no'] : '');
}
function render_trx_table(array $rows): string
{
    if (!$rows) return '<div class="empty">Belum ada transaksi</div>';
    $h = '<div class="tscroll"><table><thead><tr><th>No. Nota</th><th class="right">Antrian</th><th>Waktu</th><th>Pelanggan</th>'
        . '<th>Tipe</th><th>Shift &middot; Kasir</th><th class="right">Total</th><th class="center">Cetak</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $h .= '<tr class="click" onclick="Receipt.openById(' . (int)$r['id'] . ')">'
            . '<td class="mono">' . e($r['no']) . '</td><td class="right"><b>' . (int)$r['queue_no'] . '</b></td>'
            . '<td>' . fmt_dt($r['created_at']) . '</td><td>' . e($r['customer_name'] ?: '-') . '</td>'
            . '<td>' . e(order_label($r)) . '</td><td>' . e($r['shift_name'] . ' · ' . $r['cashier_name']) . '</td>'
            . '<td class="right"><b>' . rp($r['total']) . '</b></td><td class="center">' . print_badge((int)$r['print_count']) . '</td></tr>';
    }
    return $h . '</tbody></table></div>';
}
function print_logs_for(int $trxId): array
{
    return array_map(fn($r) => [
        'copy' => (int)$r['copy_no'], 'at' => fmt_dt($r['printed_at']), 'method' => $r['method'],
        'device' => (string)$r['device'], 'by' => (string)$r['username'],
    ], q("SELECT pl.*, u.username FROM print_logs pl LEFT JOIN users u ON u.id = pl.user_id
          WHERE pl.trx_id=? ORDER BY pl.id", [$trxId])->fetchAll());
}
function trx_payload(int $id): ?array
{
    $t = q("SELECT * FROM transactions WHERE id=?", [$id])->fetch();
    if (!$t) return null;
    $items = array_map(fn($r) => [
        'name' => $r['name'], 'qty' => (int)$r['qty'], 'price' => (float)$r['price'],
        'disc' => (float)$r['disc'], 'dname' => (string)$r['dname'], 'total' => (float)$r['total'],
    ], q("SELECT * FROM transaction_items WHERE trx_id=? ORDER BY id", [$id])->fetchAll());
    return [
        'trx' => [
            'id' => (int)$t['id'], 'no' => $t['no'], 'date' => fmt_dt($t['created_at']),
            'queue' => (int)$t['queue_no'], 'customer' => (string)$t['customer_name'],
            'phone' => (string)($t['customer_phone'] ?? ''),
            'orderType' => $t['order_type'], 'tableNo' => (string)$t['table_no'],
            'shift' => $t['shift_name'], 'cashier' => $t['cashier_name'],
            'gross' => (float)$t['gross'], 'autoDisc' => (float)$t['auto_disc'],
            'coupon' => (string)$t['coupon_code'], 'couponDisc' => (float)$t['coupon_disc'],
            'total' => (float)$t['total'], 'method' => $t['method'],
            'paid' => (float)$t['paid'], 'change' => (float)$t['change_amt'],
            'printCount' => (int)$t['print_count'],
        ],
        'items' => $items,
        'prints' => print_logs_for($id),
        'settings' => receipt_settings(),
    ];
}

/* ================= API ================= */
function json_out($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
function api_input(): array
{
    $d = json_decode((string)file_get_contents('php://input'), true);
    return is_array($d) ? $d : [];
}
function api_guard(bool $post = true): void
{
    if (!me()) json_out(['error' => 'Sesi berakhir, silakan login ulang'], 401);
    if ($post) {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'Metode tidak valid'], 405);
        if (!hash_equals($_SESSION['csrf'] ?? '', $_SERVER['HTTP_X_CSRF'] ?? '')) {
            json_out(['error' => 'Sesi tidak valid, muat ulang halaman'], 403);
        }
    }
}
// Kirim CSV dari array baris
function csv_out(string $filename, array $head, array $rows): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, $head, ';');
    foreach ($rows as $r) fputcsv($out, $r, ';');
    fclose($out);
    exit;
}

ensure_schema();

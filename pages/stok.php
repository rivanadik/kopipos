<?php
$pageTitle = 'Stok';
$admin = is_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $admin) {
    check_csrf();
    $type = ($_POST['type'] ?? '') === 'material' ? 'material' : 'product';
    $id = (int)($_POST['id'] ?? 0);
    $tbl = $type === 'material' ? 'materials' : 'products';
    $pdo = db();
    try {
        $pdo->beginTransaction();
        $row = q("SELECT * FROM `$tbl` WHERE id=? FOR UPDATE", [$id])->fetch();
        if (!$row || ($type === 'product' && !$row['track_stock'])) {
            $pdo->rollBack();
            flash_set('Item tidak ditemukan atau stoknya tidak dihitung manual', 'err');
            redirect('index.php?page=stok');
        }
        $cur = (float)$row['stock'];
        $note = trim((string)($_POST['note'] ?? ''));
        if (($_POST['action'] ?? '') === 'opname') {
            $actual = (float)str_replace(',', '.', (string)($_POST['actual'] ?? ''));
            if (($_POST['actual'] ?? '') === '' || $actual < 0) { $pdo->rollBack(); flash_set('Isi jumlah stok fisik', 'err'); redirect('index.php?page=stok'); }
            if (num_too_big($actual)) { $pdo->rollBack(); flash_set(num_limit_msg('Stok fisik'), 'err'); redirect('index.php?page=stok'); }
            $change = $actual - $cur;
            $reason = 'opname';
            $note = $note !== '' ? $note : 'Stok opname: sistem ' . num($cur) . ', fisik ' . num($actual);
        } else {
            $change = (float)($_POST['delta'] ?? 0);
            if (num_too_big($change)) { $pdo->rollBack(); flash_set(num_limit_msg('Penyesuaian stok'), 'err'); redirect('index.php?page=stok'); }
            $reason = $change > 0 ? 'masuk' : 'koreksi';
        }
        $after = $type === 'product' ? (int)round($cur + $change) : $cur + $change;
        if (num_too_big((float)$after)) { $pdo->rollBack(); flash_set(num_limit_msg('Stok akhir'), 'err'); redirect('index.php?page=stok'); }
        if ($type === 'product' && $after < 0) { $pdo->rollBack(); flash_set('Stok tidak boleh minus', 'err'); redirect('index.php?page=stok'); }
        q("UPDATE `$tbl` SET stock=? WHERE id=?", [$after, $id]);
        if (abs($change) > 0.0001) stock_log($type, $id, $change, $after, $reason, $note);
        $pdo->commit();
    } catch (PDOException $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('stok: ' . $ex->getMessage());
        flash_set('Stok gagal disimpan. Pastikan angka tidak terlalu besar dan database sudah dimigrasi.', 'err');
        redirect('index.php?page=stok');
    }
    flash_set($row['name'] . ': stok ' . num($cur) . ' → ' . num($after));
    redirect('index.php?page=stok' . ($type === 'material' ? '#bahan' : ''));
}

$cats = categories_map();
$avail = recipe_avail_map();
$prods = db()->query("SELECT * FROM products WHERE active=1 ORDER BY category_id IS NULL, category_id, name")->fetchAll();
$mats = db()->query("SELECT * FROM materials WHERE active=1 ORDER BY name")->fetchAll();
$low = low_stock();
$logs = db()->query("SELECT l.*, COALESCE(p.name, m.name) item_name, m.unit, u.username, t.no trx_no
    FROM stock_logs l
    LEFT JOIN products p ON l.item_type='product' AND p.id=l.item_id
    LEFT JOIN materials m ON l.item_type='material' AND m.id=l.item_id
    LEFT JOIN users u ON u.id=l.user_id
    LEFT JOIN transactions t ON t.id=l.trx_id
    ORDER BY l.id DESC LIMIT 60")->fetchAll();
$reasonLabel = ['masuk' => 'Stok masuk', 'penjualan' => 'Terjual', 'opname' => 'Opname', 'koreksi' => 'Koreksi'];

function opname_form(string $type, array $r, string $unit): string
{
    return '<form method="post" action="index.php?page=stok" class="opname">' . csrf_field()
        . '<input type="hidden" name="type" value="' . $type . '"><input type="hidden" name="id" value="' . (int)$r['id'] . '">'
        . '<input name="actual" type="number" step="any" min="0" placeholder="Stok fisik" title="Jumlah hasil hitung fisik (' . e($unit) . ')">'
        . '<button name="action" value="opname" class="btn btn-xs">Opname</button>'
        . ($type === 'product'
            ? '<span class="opname-sep"></span><button name="delta" value="-1" class="btn btn-xs" title="Kurangi 1">−1</button>'
              . '<button name="delta" value="5" class="btn btn-xs btn-ok" title="Tambah 5">+5</button>'
              . '<button name="delta" value="10" class="btn btn-xs btn-ok" title="Tambah 10">+10</button>'
            : '')
        . '</form>';
}
?>
<h2>Stok <?= $admin ? 'Opname' : '' ?> Produk &amp; Bahan</h2>
<p class="sub"><?= $admin ? 'Pantau ketersediaan, sesuaikan dengan hitungan fisik, dan lihat riwayat keluar-masuk stok.' : 'Cek ketersediaan produk dan bahan.' ?></p>

<?php if ($low['count']): ?>
<div class="alert warn alert-list">
  <b>⚠ <?= $low['count'] ?> item stok menipis / habis</b>
  <ul>
    <?php foreach ($low['products'] as $p): ?><li><?= e($p['name']) ?>: <b><?= num($p['stock']) ?> porsi</b> (batas <?= num($p['min_stock']) ?>)</li><?php endforeach; ?>
    <?php foreach ($low['recipe'] as $p): ?><li><?= e($p['name']) ?>: bahan cukup untuk <b>± <?= num($p['stock']) ?> porsi</b></li><?php endforeach; ?>
    <?php foreach ($low['materials'] as $m): ?><li><?= e($m['name']) ?>: <b><?= num($m['stock']) ?> <?= e($m['unit']) ?></b> (batas <?= num($m['min_stock']) ?>)</li><?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<div class="card" style="margin-bottom:18px">
  <div class="cardh">Stok produk</div>
  <div class="tscroll"><table>
    <thead><tr><th>Kode</th><th>Produk</th><th>Kategori</th><th>Cara hitung</th><th class="right">Stok</th><th>Status</th><?= $admin ? '<th>Penyesuaian</th>' : '' ?></tr></thead>
    <tbody>
    <?php foreach ($prods as $p):
      $isTrack = (bool)$p['track_stock'];
      $a = $avail[(int)$p['id']] ?? null;
      $val = $isTrack ? (int)$p['stock'] : $a;
    ?>
      <tr>
        <td class="mono muted"><?= e($p['code'] ?: '-') ?></td>
        <td><b><?= e($p['name']) ?></b></td>
        <td><?= e($cats[(int)$p['category_id']] ?? '-') ?></td>
        <td class="muted sm"><?= $isTrack ? 'Stok porsi manual' : ($a !== null ? 'Dari stok bahan (resep)' : 'Tidak dihitung') ?></td>
        <td class="right"><b class="big-num"><?= $val === null ? '-' : ($isTrack ? '' : '± ') . num($val) ?></b> <?= $val === null ? '' : 'porsi' ?></td>
        <td><?= $val === null ? '<span class="badge b-off">-</span>' : stock_badge((float)$val, (float)$p['min_stock']) ?></td>
        <?php if ($admin): ?><td><?= $isTrack ? opname_form('product', $p, 'porsi') : '<span class="muted sm">Atur lewat stok bahan</span>' ?></td><?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<div class="card" id="bahan" style="margin-bottom:18px">
  <div class="cardh">Stok bahan baku <?php if ($admin): ?><a class="btn btn-sm" style="float:right;margin-top:-4px" href="index.php?page=bahan">Kelola bahan &amp; stok masuk</a><?php endif; ?></div>
  <div class="tscroll"><table>
    <thead><tr><th>Bahan</th><th class="right">Sisa stok</th><th class="right">Batas menipis</th><th>Status</th><?= $admin ? '<th>Penyesuaian</th>' : '' ?></tr></thead>
    <tbody>
    <?php foreach ($mats as $m): ?>
      <tr>
        <td><b><?= e($m['name']) ?></b></td>
        <td class="right"><b class="big-num <?= $m['stock'] <= 0 ? 'neg' : '' ?>"><?= num($m['stock']) ?></b> <?= e($m['unit']) ?></td>
        <td class="right muted"><?= num($m['min_stock']) ?> <?= e($m['unit']) ?></td>
        <td><?= stock_badge((float)$m['stock'], (float)$m['min_stock']) ?></td>
        <?php if ($admin): ?><td><?= opname_form('material', $m, $m['unit']) ?></td><?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<?php if ($admin): ?>
<div class="card">
  <div class="cardh">Riwayat mutasi stok (60 terakhir)</div>
  <div class="tscroll"><table>
    <thead><tr><th>Waktu</th><th>Item</th><th>Jenis</th><th class="right">Perubahan</th><th class="right">Stok akhir</th><th>Keterangan</th><th>Oleh</th></tr></thead>
    <tbody>
    <?php if (!$logs): ?><tr><td colspan="7" class="empty">Belum ada mutasi stok</td></tr><?php endif; ?>
    <?php foreach ($logs as $l): $u = $l['item_type'] === 'material' ? $l['unit'] : 'porsi'; ?>
      <tr>
        <td class="nowrap"><?= fmt_dt($l['created_at']) ?></td>
        <td><?= e($l['item_name'] ?? '(terhapus)') ?> <span class="muted sm"><?= $l['item_type'] === 'material' ? 'bahan' : 'produk' ?></span></td>
        <td><span class="badge <?= ['masuk' => 'b-on', 'penjualan' => 'b-info', 'opname' => 'b-warn', 'koreksi' => 'b-off'][$l['reason']] ?>"><?= $reasonLabel[$l['reason']] ?></span></td>
        <td class="right <?= $l['change_qty'] < 0 ? 'neg' : 'plus' ?>"><?= $l['change_qty'] > 0 ? '+' : '' ?><?= num($l['change_qty']) ?> <?= e($u) ?></td>
        <td class="right"><?= num($l['stock_after']) ?> <?= e($u) ?></td>
        <td class="sm"><?= $l['trx_no'] ? 'Nota <span class="mono">' . e($l['trx_no']) . '</span>' : e($l['note'] ?: '-') ?>
          <?= $l['unit_price'] ? ' · ' . rp($l['unit_price']) . '/kemasan' : '' ?></td>
        <td class="muted sm"><?= e($l['username'] ?? '-') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>

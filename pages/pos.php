<?php
$pageTitle = 'Kasir (POS)';
$sh = active_shift();

if (!$sh): ?>
  <div class="card pad center-card">
    <h2>Shift belum dibuka</h2>
    <p class="muted">Buka shift terlebih dahulu sebelum melakukan transaksi.</p>
    <a class="btn btn-primary" href="index.php?page=shift">Buka Shift</a>
  </div>
<?php return; endif;

$catRows = db()->query("SELECT id, name FROM categories WHERE active=1 ORDER BY sort_order, name")->fetchAll();
$catName = array_column($catRows, 'name', 'id');
$prods = db()->query("SELECT p.* FROM products p LEFT JOIN categories c ON c.id = p.category_id
    WHERE p.active=1 AND (p.category_id IS NULL OR c.active=1)
    ORDER BY c.sort_order IS NULL, c.sort_order, p.name")->fetchAll();
$avail = recipe_avail_map();
$detected = current_shift_config();
$mismatch = $detected && (int)$detected['id'] !== (int)$sh['shift_config_id'];
$nextQueue = (int)q("SELECT COALESCE(MAX(queue_no),0)+1 FROM transactions WHERE DATE(created_at)=?", [today()])->fetchColumn();
$hasUncat = (bool)array_filter($prods, fn($p) => !isset($catName[(int)$p['category_id']]));
?>
<?php if ($mismatch): ?>
  <div class="alert warn">Jam sekarang sudah masuk <b><?= e($detected['name']) ?> (<?= e($detected['cashier_name']) ?>)</b>.
    Struk masih tercetak atas nama <?= e($sh['shift_name']) ?> &middot; <?= e($sh['cashier_name']) ?>.
    <a href="index.php?page=shift">Tutup shift ini</a> lalu buka shift baru.</div>
<?php endif; ?>

<div class="pos">
  <section class="pos-left">
    <div class="pos-top">
      <input id="q" class="search" placeholder="Cari produk... (tekan /)" autocomplete="off">
      <div class="shift-chip"><?= e($sh['shift_name']) ?> &middot; <?= e($sh['cashier_name']) ?></div>
    </div>
    <div class="chips" id="chips">
      <button class="chip on" data-cat="">Semua</button>
      <?php foreach ($catRows as $c): ?><button class="chip" data-cat="<?= e($c['name']) ?>"><?= e($c['name']) ?></button><?php endforeach; ?>
      <?php if ($hasUncat): ?><button class="chip" data-cat="Lainnya">Lainnya</button><?php endif; ?>
    </div>
    <div class="pgrid" id="pgrid">
      <?php if (!$prods): ?><div class="empty" style="grid-column:1/-1">Belum ada produk. Minta admin menambahkan produk.</div><?php endif; ?>
      <?php foreach ($prods as $p):
        $d = best_disc($p);
        $track = (int)$p['track_stock'];
        $stock = $track ? (int)$p['stock'] : ($avail[(int)$p['id']] ?? null);
        $min = (int)$p['min_stock'];
        $out = $track && $stock <= 0;
        $stockChip = '';
        if ($stock !== null) {
            if ($stock <= 0) $stockChip = '<em class="pstock out">' . ($track ? 'Habis' : 'Bahan habis') . '</em>';
            elseif ($stock <= $min) $stockChip = '<em class="pstock low">' . ($track ? 'Sisa ' . $stock : 'Bahan menipis') . '</em>';
            elseif ($track) $stockChip = '<em class="pstock">Stok ' . $stock . '</em>';
        }
      ?>
        <button type="button" class="pc<?= $out ? ' sold-out' : '' ?>" data-id="<?= (int)$p['id'] ?>"
                data-cat="<?= e($catName[(int)$p['category_id']] ?? 'Lainnya') ?>" data-name="<?= e(mb_strtolower($p['name'] . ' ' . $p['code'])) ?>"
                data-track="<?= $track ?>" data-stock="<?= $track ? (int)$p['stock'] : '' ?>" data-min="<?= $min ?>">
          <div class="pimg">
            <?php if ($p['image']): ?><img src="<?= e($p['image']) ?>" alt="" loading="lazy" onerror="this.remove()"><?php endif; ?>
            <span><?= e(mb_strtoupper(mb_substr($p['name'], 0, 1))) ?></span>
            <?php if ($d['amt'] > 0): ?><em class="ptag">Promo</em><?php endif; ?>
            <?= $stockChip ?>
            <b class="pqty" hidden></b>
          </div>
          <div class="pbody">
            <div class="pname"><?= e($p['name']) ?></div>
            <div class="pprice">
              <?php if ($d['amt'] > 0): ?><s><?= rp($p['price']) ?></s> <?= rp($p['price'] - $d['amt']) ?>
              <?php else: ?><?= rp($p['price']) ?><?php endif; ?>
            </div>
          </div>
        </button>
      <?php endforeach; ?>
    </div>
  </section>

  <aside class="card cart">
    <div class="cart-h">
      <span>Pesanan <span class="queue-chip" title="Nomor antrian berikutnya">Antrian #<b id="queueNo"><?= $nextQueue ?></b></span></span>
      <button class="btn btn-sm btn-ghost" id="btnClear" type="button">Kosongkan</button>
    </div>
    <div class="cust">
      <div class="otype" id="otype">
        <button type="button" class="ot on" data-t="Dine In">Dine In</button>
        <button type="button" class="ot" data-t="Take Away">Take Away</button>
      </div>
      <div class="cust-row">
        <input id="custName" maxlength="60" placeholder="Nama pelanggan<?= setting('require_customer', '1') === '1' ? ' *' : '' ?>" autocomplete="off">
        <input id="tableNo" maxlength="10" placeholder="No. meja" autocomplete="off">
      </div>
    </div>
    <div class="cart-items" id="items"></div>
    <div class="cart-f">
      <div class="cpn">
        <input id="cpn" placeholder="Kode kupon" autocomplete="off">
        <button class="btn" id="btnCpn" type="button">Pakai</button>
      </div>
      <div id="msg"></div>
      <div id="sumbox"></div>
      <div class="methods" id="methods">
        <button type="button" class="mt on" data-m="Tunai">Tunai</button>
        <button type="button" class="mt" data-m="QRIS">QRIS</button>
        <button type="button" class="mt" data-m="Transfer">Transfer</button>
        <button type="button" class="mt" data-m="Debit">Debit</button>
      </div>
      <div id="cashbox">
        <input id="paid" type="number" min="0" inputmode="numeric" placeholder="Uang diterima (Rp)">
        <div class="quick" id="quick"></div>
        <div class="row"><span>Kembali</span><b id="change">Rp 0</b></div>
      </div>
      <button class="btn btn-primary btn-pay" id="btnPay" type="button" disabled>Bayar</button>
    </div>
  </aside>
</div>
<script>window.POS_CFG = {requireCustomer: <?= setting('require_customer', '1') === '1' ? 'true' : 'false' ?>};</script>
<script src="assets/pos.js"></script>

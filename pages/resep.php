<?php
$pageTitle = 'Resep & HPP';
$target = max(1, min(95, (float)setting('target_margin', '60')));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $act = $_POST['action'] ?? '';
    if ($act === 'target') {
        setting_save(['target_margin' => (string)max(1, min(95, (float)($_POST['target_margin'] ?? 60)))]);
        flash_set('Target margin disimpan');
        redirect('index.php?page=resep');
    }
    if ($act === 'save') {
        $pid = (int)($_POST['product_id'] ?? 0);
        $p = q("SELECT * FROM products WHERE id=?", [$pid])->fetch();
        if (!$p) { flash_set('Produk tidak ditemukan', 'err'); redirect('index.php?page=resep'); }
        $mats = (array)($_POST['mat'] ?? []);
        $qtys = (array)($_POST['qty'] ?? []);
        $rows = [];
        foreach ($mats as $i => $mid) {
            $mid = (int)$mid;
            $qty = (float)str_replace(',', '.', (string)($qtys[$i] ?? 0));
            if ($mid > 0 && $qty > 0) $rows[$mid] = ($rows[$mid] ?? 0) + $qty;
        }
        $pdo = db();
        $pdo->beginTransaction();
        q("DELETE FROM recipes WHERE product_id=?", [$pid]);
        foreach ($rows as $mid => $qty) q("INSERT INTO recipes (product_id, material_id, qty) VALUES (?,?,?)", [$pid, $mid, $qty]);
        $price = (int)round((float)($_POST['price'] ?? $p['price']));
        if ($price > 0) q("UPDATE products SET price=? WHERE id=?", [$price, $pid]);
        $pdo->commit();
        flash_set('Resep ' . $p['name'] . ' disimpan (' . count($rows) . ' bahan)');
        redirect('index.php?page=resep');
    }
}

$cats = categories_map();
$hpp = hpp_map();
$materials = db()->query("SELECT * FROM materials ORDER BY active DESC, name")->fetchAll();
$recipes = [];
foreach (db()->query("SELECT r.*, m.name, m.unit FROM recipes r JOIN materials m ON m.id=r.material_id ORDER BY r.id") as $r) {
    $recipes[(int)$r['product_id']][] = $r;
}
$recommend = fn(float $h) => $h > 0 ? (int)(ceil($h / (1 - $target / 100) / 1000) * 1000) : 0;

$editId = (int)($_GET['edit'] ?? 0);
$edit = $editId ? q("SELECT * FROM products WHERE id=?", [$editId])->fetch() : null;

if ($edit):
    $rows = $recipes[$editId] ?? [];
    $matJs = [];
    foreach ($materials as $m) $matJs[(int)$m['id']] = ['name' => $m['name'], 'unit' => $m['unit'], 'up' => unit_price($m)];
?>
<div class="toolbar">
  <div><h2>Resep: <?= e($edit['name']) ?></h2>
    <p class="sub" style="margin:0">Isi takaran bahan untuk <b>1 porsi</b>. HPP dan margin dihitung langsung.</p></div>
  <a class="btn" href="index.php?page=resep">&larr; Kembali</a>
</div>
<form method="post" action="index.php?page=resep" class="recipe-layout" id="rform">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save">
  <input type="hidden" name="product_id" value="<?= (int)$edit['id'] ?>">
  <div class="card">
    <div class="cardh">Racikan bahan</div>
    <div class="tscroll">
    <table class="recipe-table">
      <thead><tr><th>Bahan</th><th style="width:150px">Takaran</th><th class="right">Harga / satuan</th><th class="right">Biaya</th><th></th></tr></thead>
      <tbody id="rrows">
      <?php foreach (array_merge($rows, [['material_id' => 0, 'qty' => '']]) as $r): ?>
        <tr>
          <td><select name="mat[]" class="rmat">
            <option value="0">— Pilih bahan —</option>
            <?php foreach ($materials as $m): ?>
              <option value="<?= (int)$m['id'] ?>" <?= (int)$r['material_id'] === (int)$m['id'] ? 'selected' : '' ?>><?= e($m['name']) ?> (<?= e($m['unit']) ?>)</option>
            <?php endforeach; ?>
          </select></td>
          <td><div class="unit-input"><input name="qty[]" class="rqty" type="number" step="any" min="0" value="<?= e($r['qty'] === '' ? '' : (float)$r['qty']) ?>"><span class="runit"></span></div></td>
          <td class="right rup mono"></td>
          <td class="right rcost"><b></b></td>
          <td class="right"><button type="button" class="btn btn-sm btn-ghost rdel" title="Hapus baris">&times;</button></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <div class="pad-sm"><button type="button" class="btn btn-sm" id="radd">+ Tambah bahan</button></div>
  </div>

  <div class="card pad recipe-sum">
    <div class="row"><span class="muted">Total HPP / porsi</span><b id="rhpp">Rp 0</b></div>
    <div class="fg" style="margin-top:14px"><label class="lbl">Harga jual (Rp)</label>
      <input name="price" id="rprice" type="number" min="0" value="<?= (int)$edit['price'] ?>"></div>
    <div class="row"><span class="muted">Laba kotor / porsi</span><b id="rprofit">Rp 0</b></div>
    <div class="row"><span class="muted">Margin</span><b id="rmargin">0%</b></div>
    <div class="reco">
      <div class="muted sm">Rekomendasi harga (target margin <?= num($target) ?>%)</div>
      <div class="reco-val" id="rreco">Rp 0</div>
      <button type="button" class="btn btn-sm" id="ruse">Pakai harga ini</button>
    </div>
    <button class="btn btn-primary btn-block">Simpan Resep</button>
  </div>
</form>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const M = <?= json_encode($matJs, JSON_UNESCAPED_UNICODE) ?>;
  const target = <?= json_encode($target) ?>;
  const rp = n => (n < 0 ? '-' : '') + 'Rp ' + Math.round(Math.abs(n)).toLocaleString('id-ID');
  const tbody = document.getElementById('rrows');
  const tpl = tbody.lastElementChild.cloneNode(true);
  let reco = 0;
  function calc() {
    let hpp = 0;
    tbody.querySelectorAll('tr').forEach(tr => {
      const m = M[tr.querySelector('.rmat').value];
      const q = parseFloat(tr.querySelector('.rqty').value) || 0;
      tr.querySelector('.runit').textContent = m ? m.unit : '';
      tr.querySelector('.rup').textContent = m ? 'Rp ' + m.up.toLocaleString('id-ID', {maximumFractionDigits: m.up < 100 ? 2 : 0}) + ' / ' + m.unit : '';
      const cost = m ? m.up * q : 0;
      tr.querySelector('.rcost b').textContent = m && q ? rp(cost) : '';
      hpp += cost;
    });
    const price = parseFloat(document.getElementById('rprice').value) || 0;
    const margin = price > 0 ? (price - hpp) / price * 100 : 0;
    reco = hpp > 0 ? Math.ceil(hpp / (1 - target / 100) / 1000) * 1000 : 0;
    document.getElementById('rhpp').textContent = rp(hpp);
    document.getElementById('rprofit').textContent = rp(price - hpp);
    const mEl = document.getElementById('rmargin');
    mEl.textContent = margin.toLocaleString('id-ID', {maximumFractionDigits: 1}) + '%';
    mEl.className = margin >= target ? 'plus' : (margin >= 30 ? 'warn-t' : 'neg');
    document.getElementById('rreco').textContent = rp(reco);
  }
  document.getElementById('radd').onclick = () => { const r = tpl.cloneNode(true); r.querySelector('.rqty').value = ''; tbody.appendChild(r); calc(); };
  tbody.addEventListener('click', e => { if (e.target.closest('.rdel')) { if (tbody.children.length > 1) e.target.closest('tr').remove(); else { tbody.querySelector('.rmat').value = 0; tbody.querySelector('.rqty').value = ''; } calc(); } });
  document.getElementById('rform').addEventListener('input', calc);
  document.getElementById('rform').addEventListener('change', calc);
  document.getElementById('ruse').onclick = () => { if (reco) { document.getElementById('rprice').value = reco; calc(); } };
  calc();
});
</script>
<?php return; endif; ?>

<div class="toolbar">
  <div><h2>Resep &amp; HPP</h2>
    <p class="sub" style="margin:0">Hitung modal (HPP) per porsi dari takaran bahan, lalu bandingkan dengan harga jual.</p></div>
  <form method="post" action="index.php?page=resep" class="inline-form">
    <?= csrf_field() ?><input type="hidden" name="action" value="target">
    <label class="lbl" style="margin:0">Target margin</label>
    <input name="target_margin" type="number" min="1" max="95" value="<?= e(num($target)) ?>" style="width:80px"> %
    <button class="btn btn-sm">Simpan</button>
  </form>
</div>

<div class="card">
<div class="tscroll">
<table>
  <thead><tr><th>Produk</th><th>Kategori</th><th>Rincian racikan (per porsi)</th><th class="right">HPP</th>
    <th class="right">Harga jual</th><th class="right">Margin</th><th class="right">Rekomendasi</th><th></th></tr></thead>
  <tbody>
  <?php foreach (db()->query("SELECT * FROM products ORDER BY category_id IS NULL, category_id, name") as $p):
    $h = $hpp[(int)$p['id']] ?? 0;
    $m = $p['price'] > 0 && $h > 0 ? ($p['price'] - $h) / $p['price'] * 100 : null;
    $rc = $recommend($h);
  ?>
    <tr>
      <td><b><?= e($p['name']) ?></b><div class="muted sm mono"><?= e($p['code'] ?: '') ?></div></td>
      <td><span class="badge b-cat"><?= e($cats[(int)$p['category_id']] ?? '-') ?></span></td>
      <td><div class="chips-sm">
        <?php if (empty($recipes[(int)$p['id']])): ?><span class="muted sm">Belum ada resep</span><?php endif; ?>
        <?php foreach ($recipes[(int)$p['id']] ?? [] as $r): ?>
          <span class="chip-sm"><?= e($r['name']) ?>: <b><?= num($r['qty']) ?> <?= e($r['unit']) ?></b></span>
        <?php endforeach; ?>
      </div></td>
      <td class="right"><b><?= $h > 0 ? rp($h) : '-' ?></b></td>
      <td class="right"><?= rp($p['price']) ?></td>
      <td class="right"><?php if ($m === null): ?>-<?php else: ?><span class="badge <?= $m >= $target ? 'b-on' : ($m >= 30 ? 'b-warn' : 'b-bad') ?>"><?= pct($m) ?></span><?php endif; ?></td>
      <td class="right"><?= $rc ? rp($rc) : '-' ?></td>
      <td class="right"><a class="btn btn-sm" href="index.php?page=resep&edit=<?= (int)$p['id'] ?>"><?= empty($recipes[(int)$p['id']]) ? '+ Buat resep' : 'Ubah resep' ?></a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
</div>

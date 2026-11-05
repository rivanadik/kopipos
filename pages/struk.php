<?php
$pageTitle = 'Customize Struk';
$def = receipt_defaults();
$toggles = [
    'show_address' => 'Tampilkan alamat', 'show_phone' => 'Tampilkan telepon',
    'show_no' => 'Nomor transaksi', 'show_date' => 'Tanggal & jam',
    'show_shift' => 'Nama shift (Shift 1/2/3)', 'show_cashier' => 'Nama kasir',
    'show_queue' => 'Nomor antrian (huruf besar)', 'show_customer' => 'Nama pelanggan',
    'show_order_type' => 'Dine In / Take Away + meja',
    'show_item_disc' => 'Rincian diskon per item', 'show_payment' => 'Metode bayar & kembalian',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $save = [];
    foreach (['store_name', 'store_address', 'store_phone', 'header_note', 'footer', 'footer2'] as $k) {
        $save[$k] = mb_substr(trim((string)($_POST[$k] ?? '')), 0, 120);
    }
    if ($save['store_name'] === '') $save['store_name'] = 'Toko Saya';
    $save['paper'] = ($_POST['paper'] ?? '58') === '80' ? '80' : '58';
    foreach (array_merge(array_keys($toggles), ['auto_cut', 'auto_print', 'require_customer']) as $k) $save[$k] = isset($_POST[$k]) ? '1' : '0';
    $save['feed_lines'] = (string)max(0, min(8, (int)($_POST['feed_lines'] ?? 3)));
    setting_save($save);
    flash_set('Pengaturan struk disimpan');
    redirect('index.php?page=struk');
}

$s = receipt_settings();
$s['require_customer'] = setting('require_customer', '1');
$sample = current_shift_config() ?: (shift_configs()[0] ?? ['name' => 'Shift 1', 'cashier_name' => 'Nama Kasir']);
?>
<h2>Customize Struk</h2>
<p class="sub">Atur isi struk. Preview di kanan berubah langsung dan sama persis dengan hasil cetak printer thermal.</p>

<div class="struk-layout">
  <form method="post" action="index.php?page=struk" id="struk-form" class="card pad">
    <?= csrf_field() ?>
    <h3 class="cardh2">Kepala struk</h3>
    <div class="fg"><label class="lbl">Nama toko</label><input name="store_name" value="<?= e($s['store_name']) ?>" maxlength="120"></div>
    <div class="fg"><label class="lbl">Alamat</label><input name="store_address" value="<?= e($s['store_address']) ?>" maxlength="120"></div>
    <div class="fg"><label class="lbl">Telepon / WhatsApp</label><input name="store_phone" value="<?= e($s['store_phone']) ?>" maxlength="120"></div>
    <div class="fg"><label class="lbl">Catatan atas (opsional, misal: IG @tokosaya)</label><input name="header_note" value="<?= e($s['header_note']) ?>" maxlength="120"></div>

    <h3 class="cardh2" style="margin-top:22px">Kaki struk</h3>
    <div class="fg"><label class="lbl">Pesan penutup baris 1</label><input name="footer" value="<?= e($s['footer']) ?>" maxlength="120"></div>
    <div class="fg"><label class="lbl">Pesan penutup baris 2 (opsional)</label><input name="footer2" value="<?= e($s['footer2']) ?>" maxlength="120"></div>

    <h3 class="cardh2" style="margin-top:22px">Isi yang ditampilkan</h3>
    <div class="toggles">
      <?php foreach ($toggles as $k => $label): ?>
        <label class="check"><input type="checkbox" name="<?= $k ?>" value="1" <?= $s[$k] === '1' ? 'checked' : '' ?>> <?= e($label) ?></label>
      <?php endforeach; ?>
    </div>

    <h3 class="cardh2" style="margin-top:22px">Kertas & printer</h3>
    <div class="g2s">
      <div class="fg"><label class="lbl">Lebar kertas</label>
        <select name="paper">
          <option value="58" <?= $s['paper'] === '58' ? 'selected' : '' ?>>58 mm (32 karakter)</option>
          <option value="80" <?= $s['paper'] === '80' ? 'selected' : '' ?>>80 mm (48 karakter)</option>
        </select></div>
      <div class="fg"><label class="lbl">Baris kosong sebelum potong</label>
        <input name="feed_lines" type="number" min="0" max="8" value="<?= e($s['feed_lines']) ?>"></div>
    </div>
    <label class="check"><input type="checkbox" name="auto_cut" value="1" <?= $s['auto_cut'] === '1' ? 'checked' : '' ?>> Potong kertas otomatis (jika printer punya auto cutter)</label>
    <label class="check"><input type="checkbox" name="auto_print" value="1" <?= $s['auto_print'] === '1' ? 'checked' : '' ?>> Cetak otomatis setelah pembayaran</label>

    <h3 class="cardh2" style="margin-top:22px">Kasir</h3>
    <label class="check"><input type="checkbox" name="require_customer" value="1" <?= $s['require_customer'] === '1' ? 'checked' : '' ?>> Wajib isi nama pelanggan sebelum bayar (untuk memanggil pesanan)</label>

    <div class="form-actions">
      <button class="btn btn-primary">Simpan Pengaturan</button>
      <button class="btn" type="button" id="btnTest">Test Print</button>
    </div>
  </form>

  <div class="preview-col">
    <div class="preview-head">
      <span>Preview</span>
      <span class="muted" id="pv-width"></span>
    </div>
    <div class="preview-stage"><div id="pv"></div></div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const form = document.getElementById('struk-form');
  const sample = {
    trx: {no: '<?= date('ymd') ?>-00012', date: '<?= date('d/m/Y H:i') ?>', queue: 12, customer: 'Andi', orderType: 'Dine In', tableNo: '5',
          shift: <?= json_encode($sample['name']) ?>, cashier: <?= json_encode($sample['cashier_name']) ?>,
          gross: 47000, autoDisc: 2400, coupon: 'HEMAT10', couponDisc: 4460, total: 40140,
          method: 'Tunai', paid: 50000, change: 9860},
    items: [
      {name: 'Nasi Goreng Spesial', qty: 2, price: 15000, disc: 0, dname: '', total: 30000},
      {name: 'Kopi Susu', qty: 1, price: 12000, disc: 2400, dname: 'Promo Minuman', total: 9600},
      {name: 'Es Teh Manis', qty: 1, price: 5000, disc: 0, dname: '', total: 5000}
    ]
  };
  // Total contoh dihitung ulang agar konsisten
  sample.trx.gross = sample.items.reduce((a, i) => a + i.price * i.qty, 0);
  sample.trx.autoDisc = sample.items.reduce((a, i) => a + i.disc * i.qty, 0);
  const sub = sample.trx.gross - sample.trx.autoDisc;
  sample.trx.couponDisc = Math.round(sub * 0.1);
  sample.trx.total = sub - sample.trx.couponDisc;
  sample.trx.change = sample.trx.paid - sample.trx.total;

  function readSettings() {
    const s = {};
    form.querySelectorAll('input[name], select[name]').forEach(el => {
      if (el.name === '_csrf') return;
      s[el.name] = el.type === 'checkbox' ? (el.checked ? '1' : '0') : el.value;
    });
    return s;
  }
  function render() {
    const s = readSettings();
    Receipt.renderInto(document.getElementById('pv'), sample, s);
    document.getElementById('pv-width').textContent = (s.paper === '80' ? '80 mm' : '58 mm');
  }
  form.addEventListener('input', render);
  form.addEventListener('change', render);
  document.getElementById('btnTest').addEventListener('click', () => {
    Receipt.print(sample, readSettings(), {test: true});
  });
  render();
});
</script>

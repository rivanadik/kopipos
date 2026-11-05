<?php
$pageTitle = 'Printer Thermal';
?>
<h2>Printer Thermal</h2>
<p class="sub">Sambungkan printer thermal di perangkat ini. Pengaturan koneksi disimpan per perangkat/browser.</p>

<div id="pr-env" class="alert warn hidden"></div>

<div class="printer-layout">
  <div class="card pad">
    <div class="pr-status" id="pr-status">
      <div class="pr-dot"></div>
      <div>
        <div class="pr-title" id="pr-title">Printer belum terhubung</div>
        <div class="muted" id="pr-sub">Pilih jenis koneksi lalu klik Hubungkan</div>
      </div>
    </div>

    <h3 class="cardh2" style="margin-top:22px">Jenis koneksi</h3>
    <div class="conn-grid" id="conn-grid">
      <label class="conn" data-type="usb">
        <input type="radio" name="ptype" value="usb">
        <b>USB</b><span>Printer USB langsung (WebUSB)</span><em class="conn-na"></em>
      </label>
      <label class="conn" data-type="serial">
        <input type="radio" name="ptype" value="serial">
        <b>Serial / COM</b><span>USB-Serial, RS232, atau Bluetooth yang terpasang sebagai port COM</span><em class="conn-na"></em>
      </label>
      <label class="conn" data-type="bluetooth">
        <input type="radio" name="ptype" value="bluetooth">
        <b>Bluetooth</b><span>Printer portable Bluetooth Low Energy</span><em class="conn-na"></em>
      </label>
      <label class="conn" data-type="browser">
        <input type="radio" name="ptype" value="browser">
        <b>Cetak browser</b><span>Lewat dialog print dan driver printer Windows</span><em class="conn-na"></em>
      </label>
    </div>

    <div class="fg hidden" id="baud-wrap" style="max-width:240px;margin-top:14px">
      <label class="lbl">Baud rate</label>
      <select id="baud">
        <option>9600</option><option>19200</option><option>38400</option><option>57600</option><option>115200</option>
      </select>
    </div>

    <div class="form-actions">
      <button class="btn btn-primary" id="btnConnect" type="button">Hubungkan</button>
      <button class="btn" id="btnDisconnect" type="button">Putuskan</button>
      <button class="btn" id="btnTest" type="button">Test Print</button>
    </div>
    <div id="pr-log" class="pr-log"></div>
  </div>

  <div class="card pad tips">
    <h3 class="cardh2">Panduan singkat</h3>
    <p><b>Browser:</b> gunakan Google Chrome atau Microsoft Edge versi terbaru. Koneksi langsung hanya berjalan di <code>localhost</code> atau HTTPS.</p>
    <p><b>USB di Windows:</b> jika printer tidak muncul atau gagal tersambung, driver Windows sedang memakai printer itu. Pilih <i>Serial / COM</i> bila printer punya port COM virtual, atau gunakan <i>Cetak browser</i> dengan driver printer bawaan.</p>
    <p><b>Bluetooth klasik:</b> pasangkan (pair) printer di pengaturan Bluetooth Windows. Printer akan muncul sebagai port COM, lalu pilih <i>Serial / COM</i>.</p>
    <p><b>Bluetooth portable (BLE):</b> pilih <i>Bluetooth</i>. Koneksi Bluetooth perlu dihubungkan ulang setelah halaman dimuat ulang, jadi hubungkan dari halaman Kasir.</p>
    <p><b>Cetak browser:</b> set printer thermal sebagai printer default, ukuran kertas 58/80 mm, margin <i>None</i>, dan matikan header/footer di dialog print.</p>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const $ = id => document.getElementById(id);
  const radios = document.querySelectorAll('input[name=ptype]');
  const support = Printer.support();

  // Tandai koneksi yang tidak didukung browser ini
  document.querySelectorAll('.conn').forEach(el => {
    const t = el.dataset.type;
    if (!support[t]) {
      el.classList.add('disabled');
      el.querySelector('input').disabled = true;
      el.querySelector('.conn-na').textContent = 'Tidak didukung browser ini';
    }
  });
  if (!window.isSecureContext) {
    $('pr-env').textContent = 'Halaman ini tidak dibuka lewat localhost/HTTPS, jadi koneksi USB/Serial/Bluetooth tidak tersedia. Buka lewat http://localhost atau aktifkan HTTPS.';
    $('pr-env').classList.remove('hidden');
  } else if (!support.usb && !support.serial && !support.bluetooth) {
    $('pr-env').textContent = 'Browser ini tidak mendukung koneksi printer langsung. Gunakan Google Chrome atau Microsoft Edge, atau pilih Cetak browser.';
    $('pr-env').classList.remove('hidden');
  }

  const sel = () => (document.querySelector('input[name=ptype]:checked') || {}).value;
  function syncType() {
    const t = sel();
    document.querySelectorAll('.conn').forEach(el => el.classList.toggle('on', el.dataset.type === t));
    $('baud-wrap').classList.toggle('hidden', t !== 'serial');
  }
  radios.forEach(r => r.addEventListener('change', syncType));

  const st = Printer.state();
  const initial = document.querySelector(`input[name=ptype][value="${st.type}"]`);
  if (initial && !initial.disabled) initial.checked = true;
  else { const first = document.querySelector('input[name=ptype]:not(:disabled)'); if (first) first.checked = true; }
  $('baud').value = String(st.baud);
  syncType();

  function log(msg, isErr) {
    const d = document.createElement('div');
    d.className = isErr ? 'neg' : '';
    d.textContent = new Date().toLocaleTimeString('id-ID') + '  ' + msg;
    $('pr-log').prepend(d);
  }

  function update(s) {
    const box = $('pr-status');
    box.className = 'pr-status ' + (s.status === 'connected' ? 'is-on' : s.status === 'connecting' ? 'is-wait' : s.type === 'browser' ? 'is-browser' : 'is-off');
    if (s.status === 'connected') {
      $('pr-title').textContent = 'Printer terhubung';
      $('pr-sub').textContent = s.deviceName + ' · ' + Printer.typeLabel(s.type);
    } else if (s.status === 'connecting') {
      $('pr-title').textContent = 'Menghubungkan...';
      $('pr-sub').textContent = Printer.typeLabel(s.type);
    } else if (s.type === 'browser') {
      $('pr-title').textContent = 'Mode cetak browser';
      $('pr-sub').textContent = 'Struk dicetak lewat dialog print browser';
    } else {
      $('pr-title').textContent = 'Printer belum terhubung';
      $('pr-sub').textContent = s.error || 'Pilih jenis koneksi lalu klik Hubungkan';
    }
    $('btnDisconnect').disabled = s.status !== 'connected' && s.type !== 'browser';
  }
  Printer.onChange(update);
  update(Printer.state());

  $('btnConnect').onclick = async () => {
    const t = sel();
    if (!t) return;
    try {
      await Printer.connect(t, {baud: Number($('baud').value)});
      log(t === 'browser' ? 'Mode cetak browser dipilih' : 'Terhubung ke ' + Printer.state().deviceName);
    } catch (e) {
      log(e.message || String(e), true);
    }
  };
  $('btnDisconnect').onclick = async () => { await Printer.disconnect(); log('Koneksi diputus'); };
  $('btnTest').onclick = async () => {
    try { await Receipt.testPrint(); log('Test print dikirim'); }
    catch (e) { log(e.message || String(e), true); }
  };
});
</script>

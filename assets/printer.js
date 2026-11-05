/* =====================================================================
   Kasir POS - Printer Thermal & Struk
   - Printer : koneksi USB (WebUSB), Serial/COM (Web Serial), Bluetooth BLE,
               atau cetak lewat dialog browser. Status disebar ke seluruh UI.
   - Receipt : menyusun isi struk sekali, lalu dipakai untuk preview,
               perintah ESC/POS, dan cetak browser (hasilnya identik).
   ===================================================================== */
(function () {
  'use strict';

  const LS_KEY = 'kasirpos_printer';
  const BLE_SERVICES = [
    '000018f0-0000-1000-8000-00805f9b34fb',
    '0000ff00-0000-1000-8000-00805f9b34fb',
    '0000ffe0-0000-1000-8000-00805f9b34fb',
    '0000fee7-0000-1000-8000-00805f9b34fb',
    'e7810a71-73ae-499d-8c15-faa9aef0c3f2',
    '49535343-fe7d-4ae5-8fa9-9fafd205e455'
  ];
  const TYPE_LABEL = {usb: 'USB', serial: 'Serial / COM', bluetooth: 'Bluetooth', browser: 'Cetak browser'};
  const sleep = ms => new Promise(r => setTimeout(r, ms));
  const hex4 = n => (n || 0).toString(16).padStart(4, '0');

  /* ------------------------- util UI ------------------------- */
  function toast(msg, isErr) {
    const t = document.getElementById('toast');
    if (!t) return;
    t.textContent = msg;
    t.className = 'toast show' + (isErr ? ' err' : '');
    clearTimeout(toast._t);
    toast._t = setTimeout(() => { t.className = 'toast'; }, 2800);
  }
  window.toast = toast;

  function loadPrefs() {
    try { return Object.assign({type: 'usb', baud: 9600}, JSON.parse(localStorage.getItem(LS_KEY)) || {}); }
    catch (e) { return {type: 'usb', baud: 9600}; }
  }
  function savePrefs(p) {
    try { localStorage.setItem(LS_KEY, JSON.stringify(p)); } catch (e) { /* abaikan */ }
  }

  /* ------------------------- driver koneksi ------------------------- */
  async function openSerial(port, baud) {
    if (!port.writable) await port.open({baudRate: baud});
    const writer = port.writable.getWriter();
    const info = port.getInfo ? port.getInfo() : {};
    return {
      name: info.usbVendorId ? `Serial USB ${hex4(info.usbVendorId)}:${hex4(info.usbProductId)}` : 'Port Serial / COM',
      ids: {vid: info.usbVendorId || 0, pid: info.usbProductId || 0},
      raw: port,
      async write(bytes) { await writer.write(bytes); },
      async close() {
        try { writer.releaseLock(); } catch (e) { /* abaikan */ }
        try { await port.close(); } catch (e) { /* abaikan */ }
      }
    };
  }

  async function openUsb(dev) {
    if (!dev.opened) await dev.open();
    if (!dev.configuration) await dev.selectConfiguration(1);
    let found = null;
    for (const itf of dev.configuration.interfaces) {
      for (const alt of itf.alternates) {
        const out = alt.endpoints.find(ep => ep.direction === 'out' && ep.type === 'bulk');
        if (!out) continue;
        if (!found || alt.interfaceClass === 7) found = {itf, alt, out};
        if (alt.interfaceClass === 7) break;
      }
      if (found && found.alt.interfaceClass === 7) break;
    }
    if (!found) throw new Error('Perangkat USB ini tidak memiliki jalur cetak (bukan printer?)');
    try {
      await dev.claimInterface(found.itf.interfaceNumber);
      if (found.itf.alternates.length > 1) {
        await dev.selectAlternateInterface(found.itf.interfaceNumber, found.alt.alternateSetting);
      }
    } catch (e) {
      throw new Error('Printer USB sedang dipakai driver Windows atau tab lain. Coba mode Serial/COM atau Cetak browser.');
    }
    const ep = found.out.endpointNumber;
    return {
      name: dev.productName || `Printer USB ${hex4(dev.vendorId)}:${hex4(dev.productId)}`,
      ids: {vid: dev.vendorId, pid: dev.productId},
      raw: dev,
      async write(bytes) {
        for (let i = 0; i < bytes.length; i += 1024) await dev.transferOut(ep, bytes.slice(i, i + 1024));
      },
      async close() {
        try { await dev.releaseInterface(found.itf.interfaceNumber); } catch (e) { /* abaikan */ }
        try { await dev.close(); } catch (e) { /* abaikan */ }
      }
    };
  }

  async function openBle(dev) {
    const server = await dev.gatt.connect();
    let ch = null;
    const services = await server.getPrimaryServices();
    for (const s of services) {
      try {
        const chars = await s.getCharacteristics();
        ch = chars.find(c => c.properties.writeWithoutResponse || c.properties.write);
        if (ch) break;
      } catch (e) { /* lanjut ke service berikutnya */ }
    }
    if (!ch) { dev.gatt.disconnect(); throw new Error('Printer Bluetooth ini tidak menyediakan jalur cetak yang dikenali'); }
    const noResp = ch.properties.writeWithoutResponse && ch.writeValueWithoutResponse;
    return {
      name: dev.name || 'Printer Bluetooth',
      ids: {id: dev.id},
      raw: dev,
      async write(bytes) {
        for (let i = 0; i < bytes.length; i += 100) {
          const chunk = bytes.slice(i, i + 100);
          if (noResp) { await ch.writeValueWithoutResponse(chunk); await sleep(15); }
          else await ch.writeValue(chunk);
        }
      },
      async close() { try { dev.gatt.disconnect(); } catch (e) { /* abaikan */ } }
    };
  }

  /* ------------------------- Printer (state) ------------------------- */
  const prefs = loadPrefs();
  const Printer = {
    _s: {type: prefs.type, baud: Number(prefs.baud) || 9600, status: 'disconnected', deviceName: '', error: ''},
    _h: null,
    _fns: [],
    _queue: Promise.resolve(),

    support() {
      const sec = window.isSecureContext;
      return {usb: sec && !!navigator.usb, serial: sec && !!navigator.serial, bluetooth: sec && !!navigator.bluetooth, browser: true};
    },
    typeLabel(t) { return TYPE_LABEL[t] || t; },
    state() { return Object.assign({}, this._s); },
    isReady() { return this._s.status === 'connected' && !!this._h; },
    onChange(fn) { this._fns.push(fn); },
    _set(patch) {
      Object.assign(this._s, patch);
      const p = loadPrefs();
      p.type = this._s.type; p.baud = this._s.baud;
      if (patch.ids) p.ids = patch.ids;
      savePrefs(p);
      const snap = this.state();
      this._fns.forEach(fn => { try { fn(snap); } catch (e) { console.error(e); } });
    },

    async connect(type, opt = {}) {
      if (opt.baud) this._s.baud = opt.baud;
      await this.disconnect(true);
      if (type === 'browser') { this._set({type, status: 'disconnected', deviceName: '', error: ''}); return; }
      if (!this.support()[type]) throw new Error(`Koneksi ${TYPE_LABEL[type]} tidak didukung browser ini`);
      this._set({type, status: 'connecting', error: ''});
      try {
        let h;
        if (type === 'serial') {
          const port = await navigator.serial.requestPort();
          h = await openSerial(port, this._s.baud);
        } else if (type === 'usb') {
          const dev = await navigator.usb.requestDevice({filters: []});
          h = await openUsb(dev);
        } else {
          const dev = await navigator.bluetooth.requestDevice({acceptAllDevices: true, optionalServices: BLE_SERVICES});
          h = await openBle(dev);
        }
        this._attach(type, h);
      } catch (e) {
        const cancelled = e && (e.name === 'NotFoundError' || /No device selected|cancel/i.test(e.message || ''));
        const msg = cancelled ? 'Pemilihan perangkat dibatalkan'
          : (e.name === 'InvalidStateError' || /already open/i.test(e.message || '')) ? 'Port sedang dipakai tab atau aplikasi lain'
          : (e.message || String(e));
        this._set({status: 'disconnected', deviceName: '', error: msg});
        throw new Error(msg);
      }
    },

    _attach(type, h) {
      this._h = h;
      this._set({type, status: 'connected', deviceName: h.name, error: '', ids: h.ids});
      if (type === 'bluetooth') {
        h.raw.addEventListener('gattserverdisconnected', () => this._lost('Koneksi Bluetooth terputus'), {once: true});
      }
    },

    _lost(msg) {
      if (!this._h) return;
      this._h = null;
      this._set({status: 'disconnected', deviceName: '', error: msg});
      toast(msg, true);
    },

    async disconnect(silent) {
      const h = this._h;
      this._h = null;
      if (h) await h.close();
      if (!silent) this._set({status: 'disconnected', deviceName: '', error: ''});
    },

    // Sambung ulang otomatis ke perangkat yang sudah pernah diizinkan
    async autoConnect() {
      const p = loadPrefs();
      const t = this._s.type;
      if (this.isReady() || t === 'browser' || !this.support()[t]) return;
      try {
        if (t === 'serial') {
          const ports = await navigator.serial.getPorts();
          const ids = p.ids || {};
          const port = ports.find(x => { const i = x.getInfo(); return i.usbVendorId === ids.vid && i.usbProductId === ids.pid; }) || ports[0];
          if (!port) return;
          this._set({status: 'connecting'});
          this._attach('serial', await openSerial(port, this._s.baud));
        } else if (t === 'usb') {
          const devs = await navigator.usb.getDevices();
          const ids = p.ids || {};
          const dev = devs.find(d => d.vendorId === ids.vid && d.productId === ids.pid) || devs[0];
          if (!dev) return;
          this._set({status: 'connecting'});
          this._attach('usb', await openUsb(dev));
        } else if (t === 'bluetooth' && navigator.bluetooth.getDevices) {
          const devs = await navigator.bluetooth.getDevices();
          const dev = devs.find(d => d.id === (p.ids || {}).id);
          if (!dev) return;
          this._set({status: 'connecting'});
          const h = await Promise.race([openBle(dev), sleep(5000).then(() => { throw new Error('timeout'); })]);
          this._attach('bluetooth', h);
        }
      } catch (e) {
        const busy = e && (e.name === 'InvalidStateError' || /already open|claim/i.test(e.message || ''));
        this._set({status: 'disconnected', deviceName: '',
          error: busy ? 'Printer sedang dipakai tab lain. Tutup tab tersebut lalu klik Hubungkan.' : 'Klik Hubungkan untuk menyambungkan printer'});
      }
    },

    // Kirim data berurutan agar beberapa cetakan tidak bercampur
    write(bytes) {
      const job = this._queue.then(async () => {
        if (!this.isReady()) throw new Error('Printer belum terhubung');
        try { await this._h.write(bytes); }
        catch (e) { this._lost('Printer terputus saat mencetak'); throw new Error('Printer terputus saat mencetak'); }
      });
      this._queue = job.catch(() => {});
      return job;
    }
  };

  // Lepas / colok ulang perangkat
  if (navigator.serial) {
    navigator.serial.addEventListener('disconnect', e => { if (Printer._h && Printer._h.raw === e.target) Printer._lost('Printer serial dicabut'); });
    navigator.serial.addEventListener('connect', () => { if (Printer._s.type === 'serial') Printer.autoConnect(); });
  }
  if (navigator.usb) {
    navigator.usb.addEventListener('disconnect', e => { if (Printer._h && Printer._h.raw === e.device) Printer._lost('Printer USB dicabut'); });
    navigator.usb.addEventListener('connect', () => { if (Printer._s.type === 'usb') Printer.autoConnect(); });
  }
  window.addEventListener('beforeunload', () => { if (Printer._h) Printer._h.close(); });

  /* ------------------------- Receipt (isi struk) ------------------------- */
  const clean = s => String(s == null ? '' : s).normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[^\x20-\x7E]/g, '?');
  const num = n => Math.round(Number(n) || 0).toLocaleString('id-ID');

  function wrap(text, w) {
    const words = clean(text).split(/\s+/).filter(Boolean);
    const out = [];
    let line = '';
    for (let word of words) {
      while (word.length > w) { if (line) { out.push(line); line = ''; } out.push(word.slice(0, w)); word = word.slice(w); }
      if (!word) continue;
      if (!line) line = word;
      else if (line.length + 1 + word.length <= w) line += ' ' + word;
      else { out.push(line); line = word; }
    }
    if (line) out.push(line);
    return out.length ? out : [''];
  }

  const Receipt = {
    width(s) { return s && s.paper === '80' ? 48 : 32; },

    build(data, s, opt = {}) {
      const W = this.width(s);
      const t = data.trx || {};
      const items = data.items || [];
      const L = [];
      const on = k => String(s[k]) === '1';
      const add = (text, o = {}) => L.push({text: clean(text), align: o.align || 'left', bold: !!o.bold, tall: !!o.tall, big: !!o.big});
      const center = (text, o = {}) => { if (String(text || '').trim()) wrap(text, o.big ? Math.floor(W / 2) : W).forEach(x => add(x, Object.assign({align: 'center'}, o))); };
      const rule = ch => add(ch.repeat(W));
      const lr = (l, r, o) => {
        l = clean(l); r = clean(r);
        if (l.length + r.length + 1 <= W) { add(l + ' '.repeat(W - l.length - r.length) + r, o); return; }
        const parts = wrap(l, W);
        const last = parts.pop();
        parts.forEach(x => add(x, o));
        if (last.length + r.length + 1 <= W) add(last + ' '.repeat(W - last.length - r.length) + r, o);
        else { add(last, o); add(' '.repeat(Math.max(0, W - r.length)) + r, o); }
      };
      const info = (label, val) => {
        const head = clean(label).padEnd(8) + ': ';
        const lines = wrap(val, W - head.length);
        lines.forEach((x, i) => add((i ? ' '.repeat(head.length) : head) + x));
      };

      const copyNo = opt.copyNo || 1;
      if (opt.test) { center('*** CONTOH STRUK ***'); add(''); }
      if (copyNo > 1) { center('*** CETAK ULANG KE-' + (copyNo - 1) + ' ***', {bold: true}); add(''); }
      center(s.store_name || 'Toko', {bold: true, tall: true});
      if (on('show_address')) center(s.store_address);
      if (on('show_phone') && String(s.store_phone || '').trim()) center('Telp ' + s.store_phone);
      center(s.header_note);
      rule('=');
      if (on('show_queue') && t.queue) {
        center('NOMOR ANTRIAN');
        center(String(t.queue), {bold: true, big: true});
      }
      if (on('show_order_type') && t.orderType) {
        center(t.orderType.toUpperCase() + (t.orderType === 'Dine In' && t.tableNo ? ' - MEJA ' + t.tableNo : ''), {bold: true});
      }
      if ((on('show_queue') && t.queue) || (on('show_order_type') && t.orderType)) rule('-');
      if (on('show_customer') && t.customer) info('Nama', t.customer);
      if (on('show_no')) info('No', t.no);
      if (on('show_date')) info('Tanggal', t.date);
      if (on('show_shift')) info('Shift', t.shift);
      if (on('show_cashier')) info('Kasir', t.cashier);
      if ((on('show_customer') && t.customer) || on('show_no') || on('show_date') || on('show_shift') || on('show_cashier')) rule('-');

      items.forEach(i => {
        wrap(i.name, W).forEach(x => add(x));
        lr(`  ${i.qty} x ${num(i.price)}`, num(i.price * i.qty));
        if (on('show_item_disc') && Number(i.disc) > 0) lr(`  Disc ${i.dname || ''}`.trimEnd(), '-' + num(i.disc * i.qty));
      });
      rule('-');
      lr('Subtotal', num(t.gross));
      if (Number(t.autoDisc) > 0) lr('Diskon', '-' + num(t.autoDisc));
      if (Number(t.couponDisc) > 0) lr('Kupon ' + (t.coupon || ''), '-' + num(t.couponDisc));
      rule('=');
      lr('TOTAL', 'Rp ' + num(t.total), {bold: true, tall: true});
      rule('=');
      if (on('show_payment')) {
        lr(t.method || 'Tunai', num(t.paid));
        if ((t.method || 'Tunai') === 'Tunai') lr('Kembali', num(t.change));
        rule('-');
      }
      center(s.footer);
      center(s.footer2);
      return L;
    },

    html(lines, s) {
      const esc = x => x.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
      return `<div class="paper" style="--w:${this.width(s)}">` + lines.map(l =>
        `<div class="ln a-${l.align}${l.bold ? ' b' : ''}${l.tall ? ' t' : ''}${l.big ? ' g' : ''}"><span>${esc(l.text) || '&nbsp;'}</span></div>`
      ).join('') + `<div class="paper-cut"></div></div>`;
    },

    renderInto(el, data, s, opt) { el.innerHTML = this.html(this.build(data, s, opt), s); },

    escpos(lines, s) {
      const out = [0x1B, 0x40, 0x1B, 0x74, 0x00];          // reset printer, code page PC437
      for (const l of lines) {
        out.push(0x1B, 0x61, l.align === 'center' ? 1 : l.align === 'right' ? 2 : 0);
        out.push(0x1B, 0x45, l.bold ? 1 : 0);
        out.push(0x1D, 0x21, l.big ? 0x11 : l.tall ? 0x01 : 0x00);
        for (let i = 0; i < l.text.length; i++) out.push(l.text.charCodeAt(i) & 0x7F);
        out.push(0x0A);
      }
      out.push(0x1B, 0x61, 0, 0x1B, 0x45, 0, 0x1D, 0x21, 0);
      const feed = Math.max(0, Math.min(8, parseInt(s.feed_lines, 10) || 0));
      if (feed) out.push(0x1B, 0x64, feed);
      if (String(s.auto_cut) === '1') out.push(0x1D, 0x56, 0x42, 0x00);
      return new Uint8Array(out);
    },

    browserPrint(lines, s) {
      const area = document.getElementById('print-area');
      const page = document.getElementById('print-page');
      area.innerHTML = this.html(lines, s);
      page.textContent = `@page { size: ${s.paper === '80' ? '80mm' : '58mm'} auto; margin: 0; }`;
      document.body.classList.add('printing');
      setTimeout(() => {
        window.print();
        document.body.classList.remove('printing');
      }, 50);
    },

    async printLines(lines, s) {
      if (Printer.isReady()) {
        await Printer.write(this.escpos(lines, s));
        toast('Struk dikirim ke printer');
        return 'thermal';
      }
      if (Printer.state().type !== 'browser') toast('Printer belum terhubung, memakai cetak browser', true);
      this.browserPrint(lines, s);
      return 'browser';
    },

    async print(data, s, opt = {}) {
      const t = data.trx || {};
      const logged = !opt.test && t.id;
      const o = Object.assign({}, opt, logged ? {copyNo: (t.printCount || 0) + 1} : {});
      let method;
      try { method = await this.printLines(this.build(data, s, o), s); }
      catch (e) { toast('Gagal mencetak: ' + e.message, true); throw e; }
      if (logged) await this.logPrint(data, method);
      return method;
    },

    // Catat cetakan ke server agar jumlah cetak tiap nota transparan
    async logPrint(data, method) {
      try {
        const r = await fetch('api/print_log.php', {
          method: 'POST',
          headers: {'Content-Type': 'application/json', 'X-CSRF': window.CSRF || ''},
          body: JSON.stringify({id: data.trx.id, method, device: method === 'thermal' ? Printer.state().deviceName : 'Dialog print browser'})
        });
        const d = await r.json();
        if (!r.ok) throw new Error(d.error || 'Gagal mencatat cetak');
        data.trx.printCount = d.printCount;
        data.prints = d.prints;
        if (this._cur === data) this._renderModal();
      } catch (e) { toast('Struk tercetak, tetapi gagal mencatat log cetak: ' + e.message, true); }
    },

    // Rekap shift / harian ke printer thermal
    async printSummary(type, ref) {
      try {
        const url = 'api/summary.php?type=' + encodeURIComponent(type) + (type === 'shift' ? '&id=' : '&date=') + encodeURIComponent(ref);
        const r = await fetch(url, {headers: {'Accept': 'application/json'}});
        const d = await r.json();
        if (!r.ok) throw new Error(d.error || 'Gagal memuat rekap');
        const s = d.settings, W = this.width(s), L = [];
        const add = (text, o = {}) => L.push({text: clean(text), align: o.align || 'left', bold: !!o.bold, tall: !!o.tall});
        const lr = (l, rr, o) => {
          l = clean(l); rr = clean(rr);
          if (l.length + rr.length + 1 > W) l = l.slice(0, W - rr.length - 1);
          add(l + ' '.repeat(Math.max(1, W - l.length - rr.length)) + rr, o);
        };
        wrap(s.store_name, W).forEach(x => add(x, {align: 'center', bold: true, tall: true}));
        add(d.title, {align: 'center', bold: true});
        add('='.repeat(W));
        d.info.forEach(([k, v]) => lr(k, v));
        d.sections.forEach(([title, rows]) => {
          if (!rows.length) return;
          add('-'.repeat(W)); add(title.toUpperCase(), {bold: true});
          rows.forEach(([k, v]) => lr(k, v));
        });
        add('='.repeat(W));
        add('Dicetak ' + d.printedAt + ' oleh ' + d.by, {align: 'center'});
        await this.printLines(L, s);
      } catch (e) { toast(e.message, true); }
    },

    async testPrint() {
      const r = await fetch('api/test.php', {headers: {'Accept': 'application/json'}});
      const d = await r.json();
      if (!r.ok) throw new Error(d.error || 'Gagal memuat pengaturan');
      const s = d.settings, W = this.width(s), st = Printer.state();
      const L = [];
      const add = (text, o = {}) => L.push({text: clean(text), align: o.align || 'center', bold: !!o.bold, tall: !!o.tall});
      add(s.store_name, {bold: true, tall: true});
      add('TEST PRINT', {bold: true});
      add('-'.repeat(W));
      add('Printer siap digunakan');
      add('Koneksi: ' + Printer.typeLabel(st.type));
      if (st.deviceName) add(st.deviceName);
      add(new Date().toLocaleString('id-ID'));
      add('-'.repeat(W));
      add('1234567890'.repeat(5).slice(0, W), {align: 'left'});
      add(`Kertas ${s.paper} mm / ${W} karakter`);
      return this.printLines(L, s);
    },

    /* ---------- modal preview ---------- */
    _cur: null,
    show(payload, opt = {}) {
      this._cur = payload;
      document.getElementById('rcpt-modal').classList.remove('hidden');
      this._renderModal();
      const s = payload.settings;
      if (opt.autoPrint && String(s.auto_print) === '1') {
        if (Printer.isReady()) this.print(payload, s).then(() => this._modalStatus('Struk sudah dicetak')).catch(() => this._modalStatus());
        else if (Printer.state().type === 'browser') this.print(payload, s).catch(() => {});
      }
    },
    _renderModal() {
      const p = this._cur;
      if (!p) return;
      const t = p.trx || {};
      this.renderInto(document.getElementById('rcpt-paper'), p, p.settings, {copyNo: (t.printCount || 0) + 1});
      const hist = document.getElementById('rcpt-history');
      if (hist) {
        const list = p.prints || [];
        hist.innerHTML = !t.id ? '' : list.length
          ? `<div class="ph-title">Riwayat cetak nota ini: <b>${list.length}x</b>${list.length > 1 ? ` <span class="badge b-warn">${list.length - 1} cetak ulang</span>` : ''}` +
            `<div class="muted">Preview di atas menampilkan tanda cetak ulang yang akan muncul jika dicetak lagi.</div></div>` +
            list.map(x => `<div class="ph-row"><span>${x.copy === 1 ? 'Cetak pertama' : 'Cetak ulang ke-' + (x.copy - 1)}</span>` +
              `<span>${x.at}</span><span>${x.method === 'thermal' ? 'Thermal' : 'Browser'}</span><span>${(x.by || '-').replace(/[<>&]/g, '')}</span></div>`).join('')
          : '<div class="ph-title">Nota ini <b>belum pernah dicetak</b>.</div>';
      }
      const btn = document.getElementById('rcpt-print');
      if (btn) btn.textContent = (t.printCount || 0) > 0 ? 'Cetak Ulang' : 'Cetak Struk';
      this._modalStatus();
    },
    _modalStatus(done) {
      const el = document.getElementById('rcpt-status');
      if (!el || !this._cur) return;
      const st = Printer.state();
      if (done) { el.className = 'rcpt-status ok'; el.textContent = done + ' ke ' + st.deviceName; return; }
      if (Printer.isReady()) { el.className = 'rcpt-status ok'; el.textContent = 'Printer terhubung: ' + st.deviceName; }
      else if (st.type === 'browser') { el.className = 'rcpt-status info'; el.textContent = 'Mode cetak browser'; }
      else { el.className = 'rcpt-status bad'; el.innerHTML = 'Printer belum terhubung. <a href="index.php?page=printer">Hubungkan printer</a> atau cetak lewat browser.'; }
    },
    hide() { document.getElementById('rcpt-modal').classList.add('hidden'); this._cur = null; },
    async openById(id) {
      try {
        const r = await fetch('api/trx.php?id=' + encodeURIComponent(id), {headers: {'Accept': 'application/json'}});
        const d = await r.json();
        if (!r.ok) throw new Error(d.error || 'Gagal memuat struk');
        this.show(d);
      } catch (e) { toast(e.message, true); }
    }
  };

  window.Printer = Printer;
  window.Receipt = Receipt;

  /* ------------------------- pengikatan UI global ------------------------- */
  function paintPill(s) {
    const pill = document.getElementById('printer-pill');
    if (!pill) return;
    const txt = pill.querySelector('.pill-text');
    let cls = 'pill-off', label = 'Printer belum terhubung';
    if (s.status === 'connected') { cls = 'pill-on'; label = 'Printer terhubung · ' + s.deviceName; }
    else if (s.status === 'connecting') { cls = 'pill-wait'; label = 'Menghubungkan printer...'; }
    else if (s.type === 'browser') { cls = 'pill-browser'; label = 'Mode cetak browser'; }
    pill.className = 'pill ' + cls;
    txt.textContent = label;
    pill.title = s.error || label;
  }
  Printer.onChange(s => { paintPill(s); Receipt._modalStatus(); });

  document.addEventListener('DOMContentLoaded', () => {
    paintPill(Printer.state());
    Printer.autoConnect();

    const modal = document.getElementById('rcpt-modal');
    if (modal) {
      modal.addEventListener('click', e => { if (e.target === modal || e.target.hasAttribute('data-close')) Receipt.hide(); });
      document.getElementById('rcpt-print').addEventListener('click', () => {
        if (Receipt._cur) Receipt.print(Receipt._cur, Receipt._cur.settings).then(m => { if (m === 'thermal') Receipt._modalStatus('Struk sudah dicetak'); }).catch(() => {});
      });
      document.addEventListener('keydown', e => { if (e.key === 'Escape' && !modal.classList.contains('hidden')) Receipt.hide(); });
    }

    const clock = document.getElementById('clock');
    if (clock) {
      const tick = () => { clock.textContent = new Date().toLocaleString('id-ID', {weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit'}); };
      tick(); setInterval(tick, 15000);
    }
  });
})();

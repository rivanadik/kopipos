/* Kasir POS - halaman kasir. Total selalu dihitung server (api/calc.php). */
(function () {
  'use strict';
  const $ = id => document.getElementById(id);
  const rp = n => 'Rp ' + Math.round(Number(n) || 0).toLocaleString('id-ID');
  const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
  const CFG = window.POS_CFG || {};
  const st = {cart: [], coupon: '', method: 'Tunai', orderType: 'Dine In', last: null, busy: false, cat: '', q: ''};
  let seq = 0;

  async function post(url, body) {
    const r = await fetch(url, {
      method: 'POST',
      headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF': window.CSRF},
      body: JSON.stringify(body)
    });
    let d;
    try { d = await r.json(); } catch (e) { throw new Error('Respon server tidak valid'); }
    if (r.status === 401) { location.href = 'index.php?page=login'; throw new Error(d.error); }
    if (!r.ok) throw new Error(d.error || 'Terjadi kesalahan');
    return d;
  }

  function msg(text, isErr) {
    $('msg').innerHTML = text ? `<div class="${isErr ? 'err' : 'ok'}">${esc(text)}</div>` : '';
  }

  async function recalc() {
    const my = ++seq;
    if (!st.cart.length) { st.last = null; render(); return; }
    try {
      const d = await post('api/calc.php', {cart: st.cart, coupon: st.coupon});
      if (my !== seq) return;
      if (d.err && st.coupon) { msg(d.err, true); st.coupon = ''; return recalc(); }
      // Buang item yang produknya sudah dinonaktifkan admin
      const ids = new Set(d.lines.map(l => l.product_id));
      st.cart = st.cart.filter(c => ids.has(c.id));
      st.last = d;
      render();
    } catch (e) { msg(e.message, true); }
  }

  function qtyOf(id) { const c = st.cart.find(x => x.id === id); return c ? c.qty : 0; }
  function cardOf(id) { return document.querySelector(`.pc[data-id="${id}"]`); }
  function stockLeft(id) {
    const el = cardOf(id);
    return el && el.dataset.track === '1' ? Number(el.dataset.stock) : Infinity;
  }
  function add(id) {
    const c = st.cart.find(x => x.id === id);
    const want = (c ? c.qty : 0) + 1;
    const left = stockLeft(id);
    if (want > left) { toast(left <= 0 ? 'Stok produk ini habis' : 'Stok tinggal ' + left.toLocaleString('id-ID'), true); return; }
    if (c) c.qty++; else st.cart.push({id, qty: 1});
    paintBadges(); recalc();
  }
  function change(id, d) {
    const c = st.cart.find(x => x.id === id);
    if (!c) return;
    if (d > 0 && c.qty + d > stockLeft(id)) { toast('Stok tinggal ' + stockLeft(id).toLocaleString('id-ID'), true); return; }
    c.qty += d;
    if (c.qty <= 0) st.cart = st.cart.filter(x => x.id !== id);
    paintBadges(); recalc();
  }
  function reset() {
    st.cart = []; st.coupon = ''; st.last = null;
    $('paid').value = ''; $('cpn').value = ''; $('custName').value = ''; $('tableNo').value = ''; $('custPhone').value = ''; msg('');
    paintBadges(); render();
  }

  function paintBadges() {
    document.querySelectorAll('.pc').forEach(el => {
      const q = qtyOf(Number(el.dataset.id));
      const b = el.querySelector('.pqty');
      b.hidden = !q; b.textContent = q;
      el.classList.toggle('in-cart', q > 0);
    });
  }

  function suggestions(total) {
    const out = [total];
    [5000, 10000, 20000, 50000, 100000].forEach(step => {
      const v = Math.ceil(total / step) * step;
      if (v > total && !out.includes(v)) out.push(v);
    });
    return out.slice(0, 4);
  }

  function render() {
    const d = st.last;
    const lines = d ? d.lines : [];
    $('items').innerHTML = lines.length ? lines.map(l => `
      <div class="ci">
        <div class="ci-main">
          <div class="n">${esc(l.name)}</div>
          <div class="muted sm">${rp(l.price)}${l.disc > 0 ? ` <span class="dsc">&minus;${rp(l.disc)}</span>` : ''}</div>
        </div>
        <div class="qty">
          <button type="button" data-q="${l.product_id}" data-d="-1" aria-label="Kurangi">&minus;</button>
          <b>${l.qty}</b>
          <button type="button" data-q="${l.product_id}" data-d="1" aria-label="Tambah">+</button>
        </div>
        <div class="ci-total">${rp(l.net)}</div>
      </div>`).join('')
      : `<div class="empty cart-empty"><b>Belum ada pesanan</b><span>Klik produk untuk menambahkan</span></div>`;

    const total = d ? d.total : 0;
    $('sumbox').innerHTML = d ? `
      <div class="row muted"><span>Subtotal</span><span>${rp(d.gross)}</span></div>
      ${d.autoD > 0 ? `<div class="row dsc"><span>Diskon</span><span>&minus;${rp(d.autoD)}</span></div>` : ''}
      ${d.cd > 0 ? `<div class="row dsc"><span>Kupon ${esc(d.coupon)} <a href="#" id="rmCpn">hapus</a></span><span>&minus;${rp(d.cd)}</span></div>` : ''}
      <div class="row total"><span>Total</span><span>${rp(total)}</span></div>` : '';
    const rm = $('rmCpn');
    if (rm) rm.onclick = e => { e.preventDefault(); st.coupon = ''; msg(''); recalc(); };

    const cash = st.method === 'Tunai';
    $('cashbox').style.display = cash ? '' : 'none';
    $('quick').innerHTML = total > 0 ? suggestions(total).map((v, i) =>
      `<button type="button" class="btn btn-sm" data-paid="${v}">${i === 0 ? 'Uang pas' : v.toLocaleString('id-ID')}</button>`).join('') : '';
    const paid = Number($('paid').value) || 0;
    $('change').textContent = rp(Math.max(0, paid - total));
    $('change').className = cash && paid > 0 && paid < total ? 'neg' : '';
    const needName = CFG.requireCustomer && !$('custName').value.trim();
    const stockErr = d && d.stockErr;
    const ok = lines.length > 0 && !st.busy && !needName && !stockErr && (!cash || paid >= total);
    $('btnPay').disabled = !ok;
    $('btnPay').textContent = st.busy ? 'Memproses...'
      : stockErr ? stockErr
      : (lines.length && needName ? 'Isi nama pelanggan' : (lines.length ? 'Bayar ' + rp(total) : 'Bayar'));
    $('custName').classList.toggle('need', lines.length > 0 && needName);
    $('tableNo').style.display = st.orderType === 'Dine In' ? '' : 'none';
  }

  async function pay() {
    if ($('btnPay').disabled || st.busy) return;
    st.busy = true; render();
    try {
      const p = await post('api/checkout.php', {
        cart: st.cart, coupon: st.coupon, method: st.method, paid: Number($('paid').value) || 0,
        customer: $('custName').value.trim(), phone: $('custPhone').value.trim(), orderType: st.orderType,
        tableNo: st.orderType === 'Dine In' ? $('tableNo').value.trim() : ''
      });
      applyStocks(p.stocks || {});
      $('queueNo').textContent = (p.trx.queue || 0) + 1;
      reset();
      toast('Nota ' + p.trx.no + ' · Antrian ' + p.trx.queue + (p.trx.customer ? ' · ' + p.trx.customer : '') + ' tersimpan');
      Receipt.show(p, {autoPrint: true});
    } catch (e) {
      toast(e.message, true);
      recalc();
    } finally {
      st.busy = false; render();
    }
  }

  // Perbarui label stok di kartu produk setelah transaksi
  function applyStocks(map) {
    Object.keys(map).forEach(id => {
      const el = cardOf(id);
      if (!el || el.dataset.track !== '1') return;
      const v = Number(map[id]), min = Number(el.dataset.min);
      el.dataset.stock = v;
      let chip = el.querySelector('.pstock');
      if (!chip) { chip = document.createElement('em'); el.querySelector('.pimg').appendChild(chip); }
      chip.className = 'pstock' + (v <= 0 ? ' out' : v <= min ? ' low' : '');
      chip.textContent = v <= 0 ? 'Habis' : v <= min ? 'Sisa ' + v.toLocaleString('id-ID') : 'Stok ' + v.toLocaleString('id-ID');
      el.classList.toggle('sold-out', v <= 0);
    });
  }

  function filter() {
    const q = st.q.trim().toLowerCase();
    document.querySelectorAll('.pc').forEach(el => {
      const ok = (!st.cat || el.dataset.cat === st.cat) && (!q || el.dataset.name.includes(q));
      el.hidden = !ok;
    });
  }

  /* ---------- event ---------- */
  $('pgrid').addEventListener('click', e => { const b = e.target.closest('.pc'); if (b) add(Number(b.dataset.id)); });
  $('items').addEventListener('click', e => { const b = e.target.closest('[data-q]'); if (b) change(Number(b.dataset.q), Number(b.dataset.d)); });
  $('chips').addEventListener('click', e => {
    const b = e.target.closest('.chip'); if (!b) return;
    document.querySelectorAll('.chip').forEach(c => c.classList.toggle('on', c === b));
    st.cat = b.dataset.cat; filter();
  });
  $('q').addEventListener('input', e => { st.q = e.target.value; filter(); });
  $('btnClear').addEventListener('click', () => { if (!st.cart.length || confirm('Kosongkan pesanan?')) reset(); });
  $('btnCpn').addEventListener('click', () => {
    const code = $('cpn').value.trim().toUpperCase();
    if (!code) { msg('Masukkan kode kupon', true); return; }
    if (!st.cart.length) { msg('Tambahkan produk terlebih dahulu', true); return; }
    st.coupon = code; msg(''); $('cpn').value = ''; recalc();
  });
  $('cpn').addEventListener('keydown', e => { if (e.key === 'Enter') $('btnCpn').click(); });
  $('methods').addEventListener('click', e => {
    const b = e.target.closest('.mt'); if (!b) return;
    document.querySelectorAll('.mt').forEach(m => m.classList.toggle('on', m === b));
    st.method = b.dataset.m; render();
  });
  $('quick').addEventListener('click', e => { const b = e.target.closest('[data-paid]'); if (b) { $('paid').value = b.dataset.paid; render(); } });
  $('paid').addEventListener('input', render);
  $('custName').addEventListener('input', render);
  $('otype').addEventListener('click', e => {
    const b = e.target.closest('.ot'); if (!b) return;
    document.querySelectorAll('.ot').forEach(o => o.classList.toggle('on', o === b));
    st.orderType = b.dataset.t; render();
  });
  $('paid').addEventListener('keydown', e => { if (e.key === 'Enter') pay(); });
  $('btnPay').addEventListener('click', pay);
  document.addEventListener('keydown', e => {
    if (e.key === '/' && document.activeElement.tagName !== 'INPUT') { e.preventDefault(); $('q').focus(); }
  });

  render();
})();

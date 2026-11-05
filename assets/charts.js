/* Kasir POS - grafik SVG ringan tanpa library eksternal (line, bar, donut) */
window.Charts = (function () {
  'use strict';
  const PALETTE = ['#1d4ed8', '#16a34a', '#f59e0b', '#db2777', '#7c3aed', '#0891b2', '#ea580c', '#64748b'];
  const NS = 'http://www.w3.org/2000/svg';
  const full = (v, o) => o.money ? 'Rp ' + Math.round(v).toLocaleString('id-ID') : Math.round(v).toLocaleString('id-ID') + (o.unit ? ' ' + o.unit : '');
  const short = (v, o) => {
    const a = Math.abs(v);
    const s = a >= 1e6 ? (v / 1e6).toLocaleString('id-ID', {maximumFractionDigits: 1}) + ' jt'
      : a >= 1e3 ? (v / 1e3).toLocaleString('id-ID', {maximumFractionDigits: 0}) + ' rb' : Math.round(v).toLocaleString('id-ID');
    return s;
  };
  const niceMax = m => {
    if (m <= 0) return 4;
    const p = Math.pow(10, Math.floor(Math.log10(m)));
    const n = m / p;
    return (n <= 1 ? 1 : n <= 1.5 ? 1.5 : n <= 2 ? 2 : n <= 2.5 ? 2.5 : n <= 4 ? 4 : n <= 5 ? 5 : n <= 8 ? 8 : 10) * p;
  };
  const esc = s => String(s).replace(/[&<>"]/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[c]));

  function node(tag, attrs, parent) {
    const n = document.createElementNS(NS, tag);
    for (const k in attrs) n.setAttribute(k, attrs[k]);
    if (parent) parent.appendChild(n);
    return n;
  }
  function tip(el) {
    let t = el.querySelector('.chart-tip');
    if (!t) { t = document.createElement('div'); t.className = 'chart-tip'; el.appendChild(t); }
    return {
      show(html, x, y) {
        t.innerHTML = html; t.style.display = 'block';
        const w = t.offsetWidth, W = el.clientWidth;
        t.style.left = Math.min(Math.max(4, x - w / 2), W - w - 4) + 'px';
        t.style.top = Math.max(0, y - t.offsetHeight - 10) + 'px';
      },
      hide() { t.style.display = 'none'; }
    };
  }
  // Lebar area isi (tanpa padding) agar grafik tidak meluber
  function innerW(el, def) {
    const cs = getComputedStyle(el);
    const w = el.clientWidth - parseFloat(cs.paddingLeft || 0) - parseFloat(cs.paddingRight || 0);
    return w > 50 ? w : def;
  }
  function innerH(el, def) {
    const cs = getComputedStyle(el);
    const h = el.clientHeight - parseFloat(cs.paddingTop || 0) - parseFloat(cs.paddingBottom || 0);
    return h > 50 ? h : def;
  }
  function empty(el) { el.innerHTML = '<div class="chart-empty">Belum ada data</div>'; }
  function legend(items) {
    return '<div class="chart-legend">' + items.map(i =>
      `<span><i style="background:${i.color}"></i>${esc(i.name)}</span>`).join('') + '</div>';
  }
  function onResize(el, fn) {
    let w = el.clientWidth, t;
    window.addEventListener('resize', () => {
      clearTimeout(t);
      t = setTimeout(() => { if (Math.abs(el.clientWidth - w) > 20) { w = el.clientWidth; fn(); } }, 150);
    });
  }

  function axes(svg, o, W, H, P, max) {
    const ticks = 4;
    for (let i = 0; i <= ticks; i++) {
      const v = max / ticks * i;
      const y = H - P.b - (H - P.t - P.b) * i / ticks;
      node('line', {x1: P.l, x2: W - P.r, y1: y, y2: y, class: 'ch-grid'}, svg);
      node('text', {x: P.l - 8, y: y + 4, 'text-anchor': 'end', class: 'ch-axis'}, svg).textContent = short(v, o);
    }
  }

  function line(el, o) {
    const draw = () => {
      const series = o.series || [];
      const n = o.labels.length;
      const all = series.flatMap(s => s.values);
      if (!n || !all.some(v => v)) { empty(el); return; }
      el.innerHTML = '';
      const W = innerW(el, 600), H = innerH(el, 240) - 26;
      const P = {l: 52, r: 14, t: 12, b: 26};
      const max = niceMax(Math.max(...all, 0));
      const svg = node('svg', {viewBox: `0 0 ${W} ${H}`, width: W, height: H, class: 'ch-svg'});
      el.appendChild(svg);
      axes(svg, o, W, H, P, max);
      const x = i => P.l + (n === 1 ? (W - P.l - P.r) / 2 : (W - P.l - P.r) * i / (n - 1));
      const y = v => H - P.b - (H - P.t - P.b) * (v / max);
      const step = Math.ceil(n / Math.max(1, Math.floor((W - P.l) / 52)));
      o.labels.forEach((l, i) => { if (i % step === 0 || i === n - 1) node('text', {x: x(i), y: H - 6, 'text-anchor': 'middle', class: 'ch-axis'}, svg).textContent = l; });
      series.forEach((s, si) => {
        const c = s.color || PALETTE[si];
        const pts = s.values.map((v, i) => `${x(i)},${y(v)}`);
        if (si === 0) node('path', {d: `M${x(0)},${H - P.b} L${pts.join(' L')} L${x(n - 1)},${H - P.b} Z`, fill: c, opacity: .1}, svg);
        node('polyline', {points: pts.join(' '), fill: 'none', stroke: c, 'stroke-width': 2.5, 'stroke-linejoin': 'round', 'stroke-linecap': 'round'}, svg);
        s.values.forEach((v, i) => node('circle', {cx: x(i), cy: y(v), r: 3, fill: '#fff', stroke: c, 'stroke-width': 2}, svg));
      });
      const guide = node('line', {y1: P.t, y2: H - P.b, class: 'ch-guide', style: 'display:none'}, svg);
      const t = tip(el);
      const hit = node('rect', {x: P.l, y: P.t, width: W - P.l - P.r, height: H - P.t - P.b, fill: 'transparent'}, svg);
      hit.addEventListener('mousemove', ev => {
        const r = svg.getBoundingClientRect();
        const px = (ev.clientX - r.left) * (W / r.width);
        const i = Math.max(0, Math.min(n - 1, Math.round((px - P.l) / ((W - P.l - P.r) / Math.max(1, n - 1)))));
        guide.setAttribute('x1', x(i)); guide.setAttribute('x2', x(i)); guide.style.display = '';
        t.show(`<b>${esc(o.labels[i])}</b>` + series.map((s, si) =>
          `<div><i style="background:${s.color || PALETTE[si]}"></i>${esc(s.name)}: <b>${full(s.values[i], o)}</b></div>`).join(''),
          x(i) * (r.width / W), y(Math.max(...series.map(s => s.values[i]))) * (r.height / H));
      });
      hit.addEventListener('mouseleave', () => { guide.style.display = 'none'; t.hide(); });
      el.insertAdjacentHTML('beforeend', legend(series.map((s, si) => ({name: s.name, color: s.color || PALETTE[si]}))));
    };
    draw(); onResize(el, draw);
  }

  function bar(el, o) {
    const draw = () => {
      const n = o.labels.length;
      if (!n || !o.values.some(v => v)) { empty(el); return; }
      el.innerHTML = '';
      const W = innerW(el, 500), H = innerH(el, 200);
      const P = {l: 40, r: 8, t: 12, b: 24};
      const max = niceMax(Math.max(...o.values));
      const svg = node('svg', {viewBox: `0 0 ${W} ${H}`, width: W, height: H, class: 'ch-svg'});
      el.appendChild(svg);
      axes(svg, o, W, H, P, max);
      const slot = (W - P.l - P.r) / n, bw = Math.max(4, Math.min(34, slot * .62));
      const t = tip(el);
      const step = Math.ceil(n / Math.max(1, Math.floor((W - P.l) / 30)));
      o.values.forEach((v, i) => {
        const h = (H - P.t - P.b) * (v / max);
        const cx = P.l + slot * i + slot / 2;
        const r = node('rect', {x: cx - bw / 2, y: H - P.b - h, width: bw, height: Math.max(h, v ? 2 : 0), rx: 3, fill: o.color || PALETTE[0], class: 'ch-bar'}, svg);
        if (i % step === 0) node('text', {x: cx, y: H - 6, 'text-anchor': 'middle', class: 'ch-axis'}, svg).textContent = o.labels[i];
        r.addEventListener('mousemove', () => {
          const b = svg.getBoundingClientRect();
          t.show(`<b>Jam ${esc(o.labels[i])}.00</b><div>${full(v, o)}</div>`, cx * (b.width / W), (H - P.b - h) * (b.height / H));
        });
        r.addEventListener('mouseleave', () => t.hide());
      });
    };
    draw(); onResize(el, draw);
  }

  function donut(el, o) {
    const data = (o.data || []).filter(d => d[1] > 0);
    if (!data.length) { empty(el); return; }
    const total = data.reduce((a, d) => a + d[1], 0);
    const R = 54, C = 2 * Math.PI * R;
    let off = 0;
    const svg = document.createElementNS(NS, 'svg');
    svg.setAttribute('viewBox', '0 0 140 140');
    svg.setAttribute('class', 'donut-svg');
    node('circle', {cx: 70, cy: 70, r: R, fill: 'none', stroke: '#eef2f7', 'stroke-width': 20}, svg);
    data.forEach((d, i) => {
      const len = C * d[1] / total;
      const c = node('circle', {cx: 70, cy: 70, r: R, fill: 'none', stroke: PALETTE[i % PALETTE.length], 'stroke-width': 20,
        'stroke-dasharray': `${Math.max(0, len - 1.5)} ${C}`, 'stroke-dashoffset': -off, transform: 'rotate(-90 70 70)'}, svg);
      node('title', {}, c).textContent = `${d[0]}: ${full(d[1], o)} (${(d[1] / total * 100).toFixed(1)}%)`;
      off += len;
    });
    node('text', {x: 70, y: 66, 'text-anchor': 'middle', class: 'donut-total'}, svg).textContent = o.money ? short(total, o) : Math.round(total).toLocaleString('id-ID');
    node('text', {x: 70, y: 84, 'text-anchor': 'middle', class: 'donut-sub'}, svg).textContent = o.money ? 'total' : (o.unit || 'total');
    el.innerHTML = '';
    const wrap = document.createElement('div');
    wrap.className = 'donut-wrap';
    wrap.appendChild(svg);
    wrap.insertAdjacentHTML('beforeend', '<div class="donut-legend">' + data.map((d, i) =>
      `<div><i style="background:${PALETTE[i % PALETTE.length]}"></i><span>${esc(d[0])}</span>` +
      `<b>${(d[1] / total * 100).toFixed(0)}%</b><small>${full(d[1], o)}</small></div>`).join('') + '</div>');
    el.appendChild(wrap);
  }

  return {line, bar, donut};
})();

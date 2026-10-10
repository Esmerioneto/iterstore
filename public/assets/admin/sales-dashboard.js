// Vendas → Painel de vendas (DASHBOARD-PLAN): asks /admin/salesdashboard/data on open and every 60 s and
// redraws in place. SVG by hand, no library. One screen: each chart measures its cell and draws in it.
(function () {
  var root = document.getElementById('sales-dash');
  if (!root) { return; }
  var NS = 'http://www.w3.org/2000/svg';
  var money = function (v) { return 'R$ ' + v.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
  var kmoney = function (v) { return v >= 1000 ? 'R$ ' + Math.round(v / 1000).toLocaleString('pt-BR') + ' mil' : money(v); };
  var int = function (v) { return Math.round(v).toLocaleString('pt-BR'); };
  var qty = function (v) { return v.toLocaleString('pt-BR', { maximumFractionDigits: 3 }); };
  var css = function (n) { return getComputedStyle(root).getPropertyValue(n).trim(); };
  var $ = function (id) { return document.getElementById('dash-' + id); };
  var WEEK = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
  var MONTHS = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];
  // status colors are reserved for status and always go with an icon + label
  var STATUS = ['paid', 'pending', 'canceled'], STATUSL = ['Pagos', 'Aguardando pagamento', 'Cancelados'];
  var STATUSC = ['--good', '--warning', '--critical'], STATUSI = ['✓', '◷', '✕'];
  // the 4 payment methods that sell most get the categorical palette; the others share the grey
  var series = function (i) { return css(i < 4 ? '--series-' + (i + 1) : '--deemph'); };
  var date = function (s) { var p = s.split('-'); return new Date(+p[0], p[1] - 1, +p[2]); };
  var dm = function (d) { return d.getDate() + '/' + (d.getMonth() + 1); };

  // --- helpers ---
  var tip = $('tip');
  function showTip(e, rows) {
    tip.textContent = '';
    rows.forEach(function (r, i) {
      var line = document.createElement('div');
      if (r.color) { var k = document.createElement('span'); k.className = 'k'; k.style.borderColor = r.color; line.appendChild(k); }
      var v = document.createElement(i === 0 ? 'span' : 'b'); v.textContent = r.value; if (i === 0) { v.className = 'muted'; }
      line.appendChild(v);
      if (r.label) { var l = document.createElement('span'); l.className = 'muted'; l.textContent = ' ' + r.label; line.appendChild(l); }
      tip.appendChild(line);
    });
    tip.style.display = 'block';
    var x = e.clientX + 14, y = e.clientY + 14;
    if (x + tip.offsetWidth > innerWidth - 8) { x = e.clientX - tip.offsetWidth - 14; }
    if (y + tip.offsetHeight > innerHeight - 8) { y = e.clientY - tip.offsetHeight - 14; }
    tip.style.left = x + 'px'; tip.style.top = y + 'px';
  }
  function hideTip() { tip.style.display = 'none'; }
  function el(name, attrs, parent) { var n = document.createElementNS(NS, name); for (var k in attrs) { n.setAttribute(k, attrs[k]); } parent && parent.appendChild(n); return n; }
  function text(parent, x, y, s, attrs) { var t = el('text', Object.assign({ x: x, y: y, 'font-size': 10, fill: css('--text-muted') }, attrs || {}), parent); t.textContent = s; return t; }
  // the svg takes the size of its cell: [svg, width, height]; null (and a note) when there's nothing to draw
  function svgIn(id, empty) {
    var box = $(id), W = Math.max(200, box.clientWidth), H = Math.max(30, box.clientHeight);
    box.textContent = '';
    if (empty) { var p = document.createElement('div'); p.className = 'dash-empty'; p.textContent = 'Sem vendas no período.'; box.appendChild(p); return null; }
    return [el('svg', { viewBox: '0 0 ' + W + ' ' + H, role: 'img' }, box), W, H];
  }
  function hover(node, rows) { node.addEventListener('pointermove', function (e) { showTip(e, rows()); }); node.addEventListener('pointerleave', hideTip); }
  function nice(max) { var p = Math.pow(10, Math.floor(Math.log10(max || 1))); return Math.ceil((max || 1) / p) * p; }
  function barPath(x, y, w, h, horizontal) {
    var r = Math.max(0, Math.min(4, horizontal ? h / 2 : w / 2, horizontal ? w : h));
    if (horizontal) { return 'M' + x + ',' + y + 'h' + (w - r) + 'a' + r + ',' + r + ' 0 0 1 ' + r + ',' + r + 'v' + (h - 2 * r) + 'a' + r + ',' + r + ' 0 0 1 -' + r + ',' + r + 'h-' + (w - r) + 'z'; }
    return 'M' + x + ',' + (y + h) + 'v-' + (h - r) + 'a' + r + ',' + r + ' 0 0 1 ' + r + ',-' + r + 'h' + (w - 2 * r) + 'a' + r + ',' + r + ' 0 0 1 ' + r + ',' + r + 'v' + (h - r) + 'z';
  }
  // stacked columns: values[k] with color(k), the top one rounded
  function stack(svg, x, bottom, bw, values, max, ph, color, rows) {
    var y = bottom, top = values.reduce(function (a, v, k) { return v ? k : a; }, -1);
    values.forEach(function (v, k) {
      if (!v) { return; }
      var h = ph * v / max, gap = k === top ? 0 : 2;
      hover(el(k === top ? 'path' : 'rect', k === top ? { d: barPath(x, y - h, bw, h), fill: color(k), class: 'mark', tabindex: 0 }
        : { x: x, y: y - h + gap, width: bw, height: Math.max(0, h - gap), fill: color(k), class: 'mark', tabindex: 0 }, svg), rows);
      y -= h;
    });
  }

  // "tabela": a dialog over the screen, so nothing below moves
  var tables = {};
  root.addEventListener('click', function (e) {
    var b = e.target.closest('[data-table]'), t = b && tables[b.getAttribute('data-table')];
    if (!t) { return; }
    var tb = document.createElement('table');
    [t.head].concat(t.rows).forEach(function (r, i) { var tr = tb.insertRow(); r.forEach(function (c) { var cell = document.createElement(i ? 'td' : 'th'); cell.textContent = c; tr.appendChild(cell); }); });
    if (!t.rows.length) { tb.insertRow().insertCell().textContent = 'Sem vendas no período.'; }
    $('dialog-title').textContent = t.title;
    $('dialog-body').textContent = ''; $('dialog-body').appendChild(tb);
    $('dialog').showModal();
  });

  var data = null, period = 30, measure = 'orders', grain = 'day';
  function seg(id, set, refetch) {
    $(id).addEventListener('click', function (e) {
      var b = e.target.closest('button'); if (!b) { return; }
      this.querySelectorAll('button').forEach(function (x) { x.setAttribute('aria-pressed', x === b); });
      set(b.getAttribute('data-v'));
      refetch ? load() : render();
    });
  }
  seg('period', function (v) { period = +v; }, true);
  seg('measure', function (v) { measure = v; });
  seg('grain', function (v) { grain = v; });
  $('website') && $('website').addEventListener('change', load);

  function kpis() {
    var t = data.totals, created = t.created.orders, pct = function (v) { return created ? Math.round(100 * v / created) + '% dos criados' : '—'; };
    var prev = t.previous.orders, change = prev ? Math.round((created / prev - 1) * 100) : null;
    var tiles = [['Pedidos criados · ' + period + ' dias', int(created), (change === null ? 'sem pedidos nos ' + period + ' dias antes' : (change >= 0 ? '▲ ' : '▼ ') + Math.abs(change) + '% vs. ' + period + ' dias antes') + ' · hoje ' + int(t.today.orders)]];
    STATUS.forEach(function (s, k) { tiles.push([STATUSL[k], int(t[s].orders), pct(t[s].orders) + ' · ' + kmoney(t[s].revenue), k]); });
    tiles.push(['Ticket médio', created ? money(t.created.revenue / created) : '—', 'faturamento ÷ pedidos criados']);
    var box = $('kpis'); box.textContent = '';
    tiles.forEach(function (tile) {
      var d = document.createElement('div'); d.className = 'dash-kpi';
      ['small', 'strong', 'span'].forEach(function (tag, i) {
        var n = document.createElement(tag);
        if (i === 0 && tile[3] !== undefined) { var ic = document.createElement('span'); ic.className = 'dash-kpi-status'; ic.style.color = css(STATUSC[tile[3]]); ic.textContent = STATUSI[tile[3]]; n.appendChild(ic); }
        n.appendChild(document.createTextNode(tile[i])); d.appendChild(n);
      });
      if (tile[3] !== undefined) { d.style.borderTopColor = css(STATUSC[tile[3]]); }
      box.appendChild(d);
    });
  }

  function evolution() {
    var buckets = [];
    data.days.forEach(function (day) {
      var d = date(day.date), key, label;
      if (grain === 'day') { key = day.date; label = dm(d); }
      else if (grain === 'week') { var m = new Date(d); m.setDate(m.getDate() - ((m.getDay() + 6) % 7)); key = +m; label = 'Sem. ' + dm(m); }
      else { key = d.getFullYear() * 12 + d.getMonth(); label = MONTHS[d.getMonth()] + '/' + String(d.getFullYear()).slice(2); }
      var b = buckets[buckets.length - 1];
      if (!b || b.key !== key) { b = { key: key, label: label, date: d, orders: 0, revenue: 0, status: [0, 0, 0] }; buckets.push(b); }
      b.orders += day.orders; b.revenue += day.revenue; STATUS.forEach(function (s, k) { b.status[k] += day[s].orders; });
    });
    var byStatus = measure === 'status', key = byStatus ? 'orders' : measure;
    var fmt = key === 'orders' ? int : money, short = key === 'orders' ? int : kmoney;
    $('evo-sub').textContent = '· ' + (byStatus ? 'pedidos criados por situação' : key === 'orders' ? 'pedidos criados' : 'faturamento') + ' por ' + { day: 'dia', week: 'semana', month: 'mês' }[grain];
    tables.evo = { title: 'Evolução dos pedidos', head: ['Período', 'Criados'].concat(STATUSL, ['Faturamento']), rows: buckets.map(function (b) { return [b.label, int(b.orders)].concat(b.status.map(int), [money(b.revenue)]); }) };
    var s = svgIn('evo', !data.totals.created.orders); if (!s) { return; }
    var svg = s[0], W = s[1], H = s[2], L = 52, R = 8, T = 8, B = 18, pw = W - L - R, ph = H - T - B;
    var max = nice(Math.max.apply(null, buckets.map(function (b) { return b[key]; })) * 1.05);
    for (var g = 0; g <= 2; g++) { var y = T + ph - ph * g / 2; el('line', { x1: L, x2: W - R, y1: y, y2: y, stroke: css('--grid') }, svg); text(svg, L - 6, y + 3, short(max * g / 2), { 'text-anchor': 'end' }); }
    var step = pw / buckets.length, every = Math.ceil(buckets.length / Math.max(2, Math.floor(pw / 60)));
    var X = function (i) { return L + step * (i + 0.5); }, Y = function (v) { return T + ph - ph * v / max; };
    buckets.forEach(function (b, i) { if (i % every === 0 || i === buckets.length - 1) { text(svg, X(i), H - 4, b.label, { 'text-anchor': 'middle' }); } });
    var tipRows = function (b) {
      var head = { value: b.label + (grain === 'day' ? ' · ' + WEEK[b.date.getDay()] : '') };
      if (byStatus) { return [head, { value: int(b.orders), label: 'criados' }].concat(b.status.map(function (v, k) { return { value: int(v), label: STATUSI[k] + ' ' + STATUSL[k], color: css(STATUSC[k]) }; })); }
      return [head, { value: fmt(b[key]), label: key === 'orders' ? 'pedidos criados' : '', color: css('--seq') }];
    };
    if (byStatus) {
      var sw = Math.max(3, Math.min(40, step * 0.62));
      buckets.forEach(function (b, i) { stack(svg, X(i) - sw / 2, T + ph, sw, b.status, max, ph, function (k) { return css(STATUSC[k]); }, function () { return tipRows(b); }); });
      // legend inside the plot, top-left: icon + label, never color alone
      var lx = L + 8;
      STATUSL.forEach(function (n, k) {
        el('rect', { x: lx, y: T + 2, width: 9, height: 9, rx: 2, fill: css(STATUSC[k]) }, svg);
        lx += text(svg, lx + 13, T + 10, STATUSI[k] + ' ' + n, { fill: css('--text-secondary'), 'font-size': 11 }).getComputedTextLength() + 28;
      });
    } else if (grain === 'day') {
      var path = buckets.map(function (b, i) { return (i ? 'L' : 'M') + X(i) + ',' + Y(b[key]); }).join(''), lastI = buckets.length - 1;
      el('path', { d: path + 'L' + X(lastI) + ',' + (T + ph) + 'L' + X(0) + ',' + (T + ph) + 'Z', fill: css('--seq'), 'fill-opacity': 0.08 }, svg);
      el('path', { d: path, fill: 'none', stroke: css('--seq'), 'stroke-width': 2, 'stroke-linejoin': 'round' }, svg);
      el('circle', { cx: X(lastI), cy: Y(buckets[lastI][key]), r: 4, fill: css('--seq'), stroke: '#fff', 'stroke-width': 2 }, svg);
      var hair = el('line', { y1: T, y2: T + ph, stroke: css('--text-muted'), visibility: 'hidden' }, svg);
      var dot = el('circle', { r: 4, fill: css('--seq'), stroke: '#fff', 'stroke-width': 2, visibility: 'hidden' }, svg);
      var hit = el('rect', { x: L, y: T, width: pw, height: ph, fill: 'transparent' }, svg);
      hit.addEventListener('pointermove', function (e) {
        var pt = svg.createSVGPoint(); pt.x = e.clientX; pt.y = e.clientY; var p = pt.matrixTransform(svg.getScreenCTM().inverse());
        var i = Math.max(0, Math.min(lastI, Math.round((p.x - L) / step - 0.5))), b = buckets[i];
        hair.setAttribute('x1', X(i)); hair.setAttribute('x2', X(i)); hair.setAttribute('visibility', 'visible');
        dot.setAttribute('cx', X(i)); dot.setAttribute('cy', Y(b[key])); dot.setAttribute('visibility', 'visible');
        showTip(e, tipRows(b));
      });
      hit.addEventListener('pointerleave', function () { hair.setAttribute('visibility', 'hidden'); dot.setAttribute('visibility', 'hidden'); hideTip(); });
    } else {
      var bw = Math.min(56, step - 4);
      buckets.forEach(function (b, i) {
        var h = ph * b[key] / max;
        hover(el('path', { d: barPath(X(i) - bw / 2, T + ph - h, bw, h), fill: css('--seq'), class: 'mark', tabindex: 0 }, svg), function () { return tipRows(b); });
        if (buckets.length <= 14) { text(svg, X(i), T + ph - h - 4, short(b[key]), { 'text-anchor': 'middle', fill: css('--text-secondary') }); }
      });
    }
  }

  function heatmap() {
    var heat = data.heat, max = 0, peak = null;
    heat.forEach(function (r, w) { r.forEach(function (v, h) { if (v > max) { max = v; peak = [w, h]; } }); });
    $('heat-sub').textContent = peak ? '· pico ' + WEEK[peak[0]] + ' às ' + peak[1] + 'h' : '';
    tables.heat = { title: 'Pedidos por dia da semana e hora', head: ['Hora'].concat(WEEK), rows: heat[0].map(function (_, h) { return [h + 'h'].concat(heat.map(function (r) { return int(r[h]); })); }) };
    var s = svgIn('heat', !max); if (!s) { return; }
    var svg = s[0], W = s[1], H = s[2], L = 28, T = 4, B = 16, cw = (W - L) / 24, ch = (H - T - B) / 7;
    heat.forEach(function (r, w) {
      text(svg, 0, T + w * ch + ch / 2 + 3, WEEK[w]);
      r.forEach(function (v, h) {
        var c = el('rect', { x: L + h * cw + 1, y: T + w * ch + 1, width: Math.max(1, cw - 2), height: Math.max(1, ch - 2), rx: 2, fill: v ? css('--seq') : css('--grid'), 'fill-opacity': v ? 0.1 + 0.9 * v / max : 1, class: 'mark', tabindex: 0 }, svg);
        if (w === peak[0] && h === peak[1]) { c.setAttribute('stroke', css('--text-primary')); c.setAttribute('stroke-width', 1.5); }
        hover(c, function () { return [{ value: WEEK[w] + ', ' + h + 'h–' + (h + 1) + 'h' }, { value: int(v), label: 'pedidos', color: css('--seq') }]; });
      });
    });
    [0, 6, 12, 18, 23].forEach(function (h) { text(svg, L + h * cw + cw / 2, H - 3, h + 'h', { 'text-anchor': 'middle' }); });
  }

  function payByDay() {
    var methods = data.payment.methods, names = methods.map(function (m) { return m.label; }), days = data.payment.days;
    var total = methods.reduce(function (a, m) { return a + m.orders; }, 0), lg = $('pay-legend'); lg.textContent = '';
    // the legend carries the period's share: "Pix 43%"
    methods.forEach(function (m, i) {
      var s2 = document.createElement('span'), k = document.createElement('i'), b = document.createElement('b');
      k.style.background = series(i); b.textContent = ' ' + Math.round(100 * m.orders / total) + '%';
      s2.appendChild(k); s2.appendChild(document.createTextNode(m.label)); s2.appendChild(b); lg.appendChild(s2);
    });
    // a method sold in the last 14 days but not in the period still gets its column
    days.forEach(function (d) { Object.keys(d.orders).forEach(function (n) { names.indexOf(n) < 0 && names.push(n); }); });
    var counts = days.map(function (d) { return names.map(function (n) { return d.orders[n] || 0; }); });
    var sums = counts.map(function (c) { return c.reduce(function (a, b) { return a + b; }, 0); });
    tables.pay = { title: 'Forma de pagamento por dia', head: ['Dia'].concat(names, ['Total']), rows: days.map(function (d, i) { return [date(d.date).toLocaleDateString('pt-BR')].concat(counts[i].map(int), [int(sums[i])]); }) };
    var s = svgIn('pay', !Math.max.apply(null, sums)); if (!s) { return; }
    var svg = s[0], W = s[1], H = s[2], L = 24, T = 4, B = 16, ph = H - T - B;
    var max = nice(Math.max.apply(null, sums)), step = (W - L) / days.length, bw = Math.max(4, Math.min(28, step * 0.62));
    [0, 1].forEach(function (g) { var y = T + ph - ph * g; el('line', { x1: L, x2: W, y1: y, y2: y, stroke: css('--grid') }, svg); text(svg, L - 4, y + 3, int(max * g), { 'text-anchor': 'end' }); });
    days.forEach(function (d, i) {
      var x = L + step * i + (step - bw) / 2, day = date(d.date);
      stack(svg, x, T + ph, bw, counts[i], max, ph, series, function () {
        return [{ value: dm(day) + ' · ' + int(sums[i]) + ' pedidos' }].concat(counts[i].map(function (v, k) { return { value: int(v), label: names[k], color: series(k) }; }).filter(function (r) { return r.value !== '0'; }));
      });
      if (i % 2 === 0 || i === days.length - 1) { text(svg, x + bw / 2, H - 3, dm(day), { 'text-anchor': 'middle' }); }
    });
  }

  function hbars(id, items, key, fmt, rows, title, head, row) {
    tables[id] = { title: title, head: head, rows: items.map(row) };
    var s = svgIn(id, !items.length); if (!s) { return; }
    var svg = s[0], W = s[1], H = s[2], rowH = H / Math.max(items.length, 5), L = Math.min(150, W * 0.42), R = 64;
    var max = Math.max.apply(null, items.map(function (it) { return it[key]; })) || 1, bh = Math.max(4, Math.min(14, rowH - 6));
    items.forEach(function (it, i) {
      var y = i * rowH, w = Math.max(4, (W - L - R) * it[key] / max), name = (i + 1) + '. ' + it.name;
      var t = text(svg, 0, y + rowH / 2 + 4, name, { fill: css('--text-secondary'), 'font-size': 11 });
      while (t.getComputedTextLength() > L - 8 && name.length > 4) { name = name.slice(0, -2); t.textContent = name + '…'; }
      hover(el('path', { d: barPath(L, y + (rowH - bh) / 2, w, bh, true), fill: css('--seq'), class: 'mark', tabindex: 0 }, svg), function () { return [{ value: it.name }].concat(rows(it)); });
      text(svg, L + w + 5, y + rowH / 2 + 4, fmt(it[key]), { fill: css('--text-primary'), 'font-size': 11 });
    });
  }

  function channels() {
    var items = data.channels, total = items.reduce(function (t, it) { return t + it.revenue; }, 0), x = 0;
    var colors = ['--seq', '--deemph', '--grid'];
    tables.chan = { title: 'Canal de venda', head: ['Canal', 'Pedidos', 'Faturamento', '%'], rows: items.map(function (it) { return [it.name, int(it.orders), money(it.revenue), (total ? Math.round(100 * it.revenue / total) : 0) + '%']; }) };
    var s = svgIn('chan', !total); if (!s) { return; }
    var svg = s[0], W = s[1];
    items.forEach(function (it, i) {
      var w = W * it.revenue / total, pct = Math.round(100 * it.revenue / total), color = css(colors[Math.min(i, colors.length - 1)]);
      hover(el('rect', { x: x, y: 2, width: Math.max(0, w - (i === items.length - 1 ? 0 : 2)), height: 14, rx: 3, fill: color, class: 'mark', tabindex: 0 }, svg),
        function () { return [{ value: it.name }, { value: pct + '%', label: money(it.revenue) + ' · ' + int(it.orders) + ' pedidos', color: color }]; });
      if (i < 2) { // the last label ends at the right edge; more than two would overlap: they're in the tip and the table
        var last = i === 1;
        text(svg, last ? W : x, 32, pct + '% ' + it.name, { fill: css('--text-primary'), 'font-size': 11, 'text-anchor': last ? 'end' : 'start' });
      }
      x += w;
    });
  }

  function render() {
    if (!data) { return; }
    hideTip();
    kpis(); evolution(); heatmap(); payByDay();
    hbars('top', data.products, 'quantity', function (v) { return qty(v) + ' un.'; }, function (it) { return [{ value: qty(it.quantity), label: 'unidades', color: css('--seq') }, { value: money(it.revenue), label: 'faturamento' }]; },
      'Top 10 produtos', ['#', 'Produto', 'Unidades', 'Faturamento'], function (it, i) { return [i + 1, it.name, qty(it.quantity), money(it.revenue)]; });
    hbars('cats', data.categories, 'revenue', kmoney, function (it) { return [{ value: money(it.revenue), label: 'faturamento', color: css('--seq') }]; },
      'Categorias que mais vendem', ['#', 'Categoria', 'Faturamento'], function (it, i) { return [i + 1, it.name, money(it.revenue)]; });
    channels();
  }

  // the status line: when, and the last full check; a failed refresh keeps the charts and says so
  function status(stale, message) {
    var box = $('updated'); box.textContent = ''; box.classList.toggle('is-stale', stale);
    var live = document.createElement('span'); live.className = 'live'; box.appendChild(live);
    if (message) { box.appendChild(message); return; }
    box.appendChild(document.createTextNode('Atualizado às ')); var b = document.createElement('b'); b.textContent = data.updated_at; box.appendChild(b);
    box.appendChild(document.createTextNode(' · a cada minuto'));
    box.title = data.check ? 'Conferência às ' + data.check.at + ': ' + (data.check.fixed ? 'corrigiu ' + data.check.fixed + ' dia(s)' : 'ok') : '';
  }
  var seq = 0;
  function load() {
    var mine = ++seq, website = $('website') ? $('website').value : '';
    // X-Admin-Poll: this refresh isn't use, so it doesn't keep the session alive
    fetch(root.getAttribute('data-url') + '?period=' + period + '&website_id=' + encodeURIComponent(website), { headers: { 'X-Admin-Poll': '1' }, credentials: 'same-origin' })
      .then(function (r) {
        if (r.status === 401) { throw 401; }
        if (!r.ok) { throw r.status; }
        return r.json();
      })
      .then(function (json) {
        if (mine !== seq) { return; } // a newer request (another filter) answers instead
        data = json; render(); status(false);
      })
      .catch(function (err) {
        if (mine !== seq) { return; }
        var msg = document.createElement('span');
        if (err === 401) {
          clearInterval(timer);
          msg.appendChild(document.createTextNode('Sua sessão expirou. '));
          var a = document.createElement('a'); a.href = '/admin/auth/login'; a.textContent = 'Entrar de novo'; msg.appendChild(a);
        } else {
          msg.textContent = 'Não foi possível atualizar' + (data ? ' (mostrando a busca das ' + data.updated_at + ')' : '') + '. Nova tentativa em 1 minuto.';
        }
        status(true, msg);
      });
  }
  load();
  var timer = setInterval(load, 60000);
  var rt; addEventListener('resize', function () { clearTimeout(rt); rt = setTimeout(render, 150); });
})();

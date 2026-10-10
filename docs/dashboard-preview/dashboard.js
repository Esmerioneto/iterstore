// Prévia: dados fictícios no formato que /admin/salesdashboard/data vai devolver. SVG puro, sem biblioteca.
// Cabe numa tela: cada gráfico mede a célula onde está e desenha nela; a cada minuto redesenha no lugar.
(function () {
  var seed = 7;
  var rnd = function () { seed = (seed * 16807) % 2147483647; return (seed - 1) / 2147483646; };
  var NS = 'http://www.w3.org/2000/svg';
  var money = function (v) { return 'R$ ' + v.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
  var kmoney = function (v) { return v >= 1000 ? 'R$ ' + Math.round(v / 1000).toLocaleString('pt-BR') + ' mil' : money(v); };
  var int = function (v) { return v.toLocaleString('pt-BR'); };
  var root = document.getElementById('dash');
  var css = function (n) { return getComputedStyle(root).getPropertyValue(n).trim(); };
  var PAY = ['Pix', 'Crédito', 'Débito', 'Combinar'];
  var PAYC = ['--series-1', '--series-2', '--series-3', '--series-4'];
  var WEEK = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
  // status colors are reserved for status and always go with an icon + label
  var STATUS = ['Pagos', 'Aguardando pagamento', 'Cancelados'], STATUSC = ['--good', '--warning', '--critical'], STATUSI = ['✓', '◷', '✕'];

  // --- fake data: 90 days ---
  var today = new Date(2026, 9, 10), days = [];
  for (var i = 89; i >= 0; i--) {
    var d = new Date(today); d.setDate(d.getDate() - i);
    var n = Math.max(2, Math.round(9 + 6 * Math.sin((90 - i) / 9) + (d.getDay() >= 5 ? 6 : 0) + (90 - i) / 12 + rnd() * 6 - 3));
    var pay = [Math.round(n * (0.38 + rnd() * 0.1)), Math.round(n * (0.33 + rnd() * 0.08)), Math.round(n * (0.1 + rnd() * 0.05)), 0];
    pay[3] = Math.max(0, n - pay[0] - pay[1] - pay[2]);
    var canceled = Math.round(n * (0.06 + rnd() * 0.06)), pending = i < 2 ? Math.round(n * (0.15 + rnd() * 0.1)) : 0;
    days.push({ date: d, orders: n, revenue: n * (150 + rnd() * 70), pay: pay, status: [n - canceled - pending, pending, canceled] });
  }
  var heat = WEEK.map(function (_, w) {
    var out = [];
    for (var h = 0; h < 24; h++) {
      var v = h < 7 ? rnd() * 0.6 : Math.exp(-Math.pow((h - 12.5) / 2.2, 2)) * 9 + Math.exp(-Math.pow((h - 20.5) / 1.6, 2)) * 13 + rnd() * 2;
      out.push(Math.round(v * (w >= 5 ? 1.4 : w === 0 ? 0.8 : 1)));
    }
    return out;
  });
  var products = [['Vestido linho', 88], ['Conjunto acetinado', 74], ['Camisa flow listrada', 69], ['Macacão Lin', 57], ['Conjunto assimetria', 51],
    ['Vestido chemy', 46], ['Ecobag babados e listras', 40], ['Conjunto terra', 33], ['Macacão Era', 29], ['Ecobag babados e poá', 22]]
    .map(function (p) { return { name: p[0], qty: p[1], revenue: p[1] * (120 + rnd() * 160) }; });
  var cats = [['Vestidos', 21840], ['Conjuntos', 18390], ['Camisas', 11270], ['Macacões', 9810], ['Acessórios', 4120]].map(function (c) { return { name: c[0], revenue: c[1] }; });
  var channels = [{ name: 'App mobile', revenue: 58210 }, { name: 'Atendimento', revenue: 7220 }];

  // --- helpers ---
  var tip = document.getElementById('tip');
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
  // the svg takes the size of its cell: [svg, width, height]
  function svgIn(id) {
    var box = document.getElementById(id), W = Math.max(200, box.clientWidth), H = Math.max(30, box.clientHeight);
    box.textContent = '';
    return [el('svg', { viewBox: '0 0 ' + W + ' ' + H, role: 'img' }, box), W, H];
  }
  function hover(node, rows) { node.addEventListener('pointermove', function (e) { showTip(e, rows()); }); node.addEventListener('pointerleave', hideTip); }
  function nice(max) { var p = Math.pow(10, Math.floor(Math.log10(max || 1))); return Math.ceil(max / p) * p; }
  function barPath(x, y, w, h, horizontal) {
    var r = Math.max(0, Math.min(4, horizontal ? h / 2 : w / 2, horizontal ? w : h));
    if (horizontal) { return 'M' + x + ',' + y + 'h' + (w - r) + 'a' + r + ',' + r + ' 0 0 1 ' + r + ',' + r + 'v' + (h - 2 * r) + 'a' + r + ',' + r + ' 0 0 1 -' + r + ',' + r + 'h-' + (w - r) + 'z'; }
    return 'M' + x + ',' + (y + h) + 'v-' + (h - r) + 'a' + r + ',' + r + ' 0 0 1 ' + r + ',-' + r + 'h' + (w - 2 * r) + 'a' + r + ',' + r + ' 0 0 1 ' + r + ',' + r + 'v' + (h - r) + 'z';
  }
  // "tabela": a dialog over the screen, so nothing below moves
  var tables = {};
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-table]'); if (!b) { return; }
    var t = tables[b.getAttribute('data-table')], tb = document.createElement('table');
    [t.head].concat(t.rows).forEach(function (r, i) { var tr = tb.insertRow(); r.forEach(function (c) { var cell = document.createElement(i ? 'td' : 'th'); cell.textContent = c; tr.appendChild(cell); }); });
    document.getElementById('tableTitle').textContent = t.title;
    var body = document.getElementById('tableBody'); body.textContent = ''; body.appendChild(tb);
    document.getElementById('tableDialog').showModal();
  });

  var range = 30, measure = 'orders', grain = 'day';
  function slice() { return days.slice(days.length - range); }
  function seg(id, attr, set) {
    document.getElementById(id).addEventListener('click', function (e) {
      var b = e.target.closest('button'); if (!b) { return; }
      this.querySelectorAll('button').forEach(function (x) { x.setAttribute('aria-pressed', x === b); });
      set(b.getAttribute(attr)); render();
    });
  }
  seg('range', 'data-d', function (v) { range = +v; });
  seg('measure', 'data-m', function (v) { measure = v; });
  seg('grain', 'data-g', function (v) { grain = v; });

  function kpis() {
    var s = slice(), prev = days.slice(Math.max(0, days.length - 2 * range), days.length - range);
    var sum = function (a, f) { return a.reduce(function (t, d) { return t + f(d); }, 0); };
    var o = sum(s, function (d) { return d.orders; }), po = sum(prev, function (d) { return d.orders; }) || 1;
    var st = [0, 1, 2].map(function (k) { return sum(s, function (d) { return d.status[k]; }); });
    var avg = sum(s, function (d) { return d.revenue; }) / o, last = s[s.length - 1];
    var p = Math.round((o / po - 1) * 100);
    var tiles = [['Pedidos criados · ' + range + ' dias', int(o), (p >= 0 ? '▲ ' : '▼ ') + Math.abs(p) + '% vs. ' + range + ' dias antes · hoje ' + int(last.orders)]];
    st.forEach(function (v, k) { tiles.push([STATUS[k], int(v), Math.round(100 * v / o) + '% dos criados · ' + kmoney(v * avg), k]); });
    tiles.push(['Ticket médio', money(avg), 'faturamento ÷ pedidos criados']);
    var box = document.getElementById('kpis'); box.textContent = '';
    tiles.forEach(function (t) {
      var d = document.createElement('div'); d.className = 'kpi';
      ['small', 'strong', 'span'].forEach(function (tag, i) {
        var n = document.createElement(tag);
        if (i === 0 && t[3] !== undefined) { var ic = document.createElement('span'); ic.className = 'kpi-status'; ic.style.color = css(STATUSC[t[3]]); ic.textContent = STATUSI[t[3]]; n.appendChild(ic); }
        n.appendChild(document.createTextNode(t[i])); d.appendChild(n);
      });
      if (t[3] !== undefined) { d.style.borderTopColor = css(STATUSC[t[3]]); }
      box.appendChild(d);
    });
  }

  function evolution() {
    var buckets = [];
    slice().forEach(function (d) {
      var key, label;
      if (grain === 'day') { key = +d.date; label = d.date.getDate() + '/' + (d.date.getMonth() + 1); }
      else if (grain === 'week') { var m = new Date(d.date); m.setDate(m.getDate() - ((m.getDay() + 6) % 7)); key = +m; label = 'Sem. ' + m.getDate() + '/' + (m.getMonth() + 1); }
      else { key = d.date.getFullYear() * 12 + d.date.getMonth(); label = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'][d.date.getMonth()] + '/' + String(d.date.getFullYear()).slice(2); }
      var b = buckets[buckets.length - 1];
      if (!b || b.key !== key) { b = { key: key, label: label, orders: 0, revenue: 0, date: d.date, status: [0, 0, 0] }; buckets.push(b); }
      b.orders += d.orders; b.revenue += d.revenue; d.status.forEach(function (v, k) { b.status[k] += v; });
    });
    var byStatus = measure === 'status', key = byStatus ? 'orders' : measure;
    var fmt = key === 'orders' ? int : money, short = key === 'orders' ? int : kmoney;
    document.getElementById('evoSub').textContent = '· ' + (byStatus ? 'pedidos criados por situação' : key === 'orders' ? 'pedidos criados' : 'faturamento') + ' por ' + { day: 'dia', week: 'semana', month: 'mês' }[grain];
    var s = svgIn('evo'), svg = s[0], W = s[1], H = s[2], L = 52, R = 8, T = 8, B = 18, pw = W - L - R, ph = H - T - B;
    var max = nice(Math.max.apply(null, buckets.map(function (b) { return b[key]; })) * 1.05);
    for (var g = 0; g <= 2; g++) { var y = T + ph - ph * g / 2; el('line', { x1: L, x2: W - R, y1: y, y2: y, stroke: css('--grid') }, svg); text(svg, L - 6, y + 3, short(max * g / 2), { 'text-anchor': 'end' }); }
    var step = pw / buckets.length, every = Math.ceil(buckets.length / Math.max(2, Math.floor(pw / 60)));
    var X = function (i) { return L + step * (i + 0.5); }, Y = function (v) { return T + ph - ph * v / max; };
    buckets.forEach(function (b, i) { if (i % every === 0 || i === buckets.length - 1) { text(svg, X(i), H - 4, b.label, { 'text-anchor': 'middle' }); } });
    var tipRows = function (b) {
      var head = { value: b.label + (grain === 'day' ? ' · ' + WEEK[b.date.getDay()] : '') };
      if (byStatus) { return [head, { value: int(b.orders), label: 'criados' }].concat(b.status.map(function (v, k) { return { value: int(v), label: STATUSI[k] + ' ' + STATUS[k], color: css(STATUSC[k]) }; })); }
      return [head, { value: fmt(b[key]), label: key === 'orders' ? 'pedidos criados' : '', color: css('--seq') }];
    };
    if (byStatus) {
      var sw = Math.max(3, Math.min(40, step * 0.62));
      buckets.forEach(function (b, i) {
        var y = T + ph, top = b.status.reduce(function (a, v, k) { return v ? k : a; }, 0);
        b.status.forEach(function (v, k) {
          if (!v) { return; }
          var h = ph * v / max, gap = k === top ? 0 : 2;
          hover(el(k === top ? 'path' : 'rect', k === top ? { d: barPath(X(i) - sw / 2, y - h, sw, h), fill: css(STATUSC[k]), class: 'mark', tabindex: 0 }
            : { x: X(i) - sw / 2, y: y - h + gap, width: sw, height: Math.max(0, h - gap), fill: css(STATUSC[k]), class: 'mark', tabindex: 0 }, svg), function () { return tipRows(b); });
          y -= h;
        });
      });
      // legend inside the plot, top-left: icon + label, never color alone
      var lx = L + 8;
      STATUS.forEach(function (n, k) {
        el('rect', { x: lx, y: T + 2, width: 9, height: 9, rx: 2, fill: css(STATUSC[k]) }, svg);
        var t = text(svg, lx + 13, T + 10, STATUSI[k] + ' ' + n, { fill: css('--text-secondary'), 'font-size': 11 });
        lx += t.getComputedTextLength() + 28;
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
    tables.evo = { title: 'Evolução dos pedidos', head: ['Período', 'Criados'].concat(STATUS, ['Faturamento']), rows: buckets.map(function (b) { return [b.label, int(b.orders)].concat(b.status.map(int), [money(b.revenue)]); }) };
  }

  function heatmap() {
    var max = 0, peak = [0, 0];
    heat.forEach(function (r, w) { r.forEach(function (v, h) { if (v > max) { max = v; peak = [w, h]; } }); });
    document.getElementById('heatSub').textContent = '· pico ' + WEEK[peak[0]] + ' às ' + peak[1] + 'h';
    var s = svgIn('heat'), svg = s[0], W = s[1], H = s[2], L = 28, T = 4, B = 16, cw = (W - L) / 24, ch = (H - T - B) / 7;
    heat.forEach(function (r, w) {
      text(svg, 0, T + w * ch + ch / 2 + 3, WEEK[w]);
      r.forEach(function (v, h) {
        var c = el('rect', { x: L + h * cw + 1, y: T + w * ch + 1, width: Math.max(1, cw - 2), height: Math.max(1, ch - 2), rx: 2, fill: v ? css('--seq') : css('--grid'), 'fill-opacity': v ? 0.1 + 0.9 * v / max : 1, class: 'mark', tabindex: 0 }, svg);
        if (w === peak[0] && h === peak[1]) { c.setAttribute('stroke', css('--text-primary')); c.setAttribute('stroke-width', 1.5); }
        hover(c, function () { return [{ value: WEEK[w] + ', ' + h + 'h–' + (h + 1) + 'h' }, { value: int(v), label: 'pedidos', color: css('--seq') }]; });
      });
    });
    [0, 6, 12, 18, 23].forEach(function (h) { text(svg, L + h * cw + cw / 2, H - 3, h + 'h', { 'text-anchor': 'middle' }); });
    tables.heat = { title: 'Pedidos por dia da semana e hora', head: ['Hora'].concat(WEEK), rows: heat[0].map(function (_, h) { return [h + 'h'].concat(heat.map(function (r) { return int(r[h]); })); }) };
  }

  function payByDay() {
    var s14 = days.slice(-14), tp = [0, 0, 0, 0];
    slice().forEach(function (d) { d.pay.forEach(function (v, k) { tp[k] += v; }); });
    var tot = tp.reduce(function (a, b) { return a + b; }, 0), lg = document.getElementById('payLegend'); lg.textContent = '';
    // the legend carries the period's share: "Pix 43%"
    PAY.forEach(function (n, i) {
      var s2 = document.createElement('span'), k = document.createElement('i'), b = document.createElement('b');
      k.style.background = css(PAYC[i]); b.textContent = ' ' + Math.round(100 * tp[i] / tot) + '%';
      s2.appendChild(k); s2.appendChild(document.createTextNode(n)); s2.appendChild(b); lg.appendChild(s2);
    });
    var s = svgIn('pay'), svg = s[0], W = s[1], H = s[2], L = 24, T = 4, B = 16, ph = H - T - B;
    var max = nice(Math.max.apply(null, s14.map(function (d) { return d.orders; }))), step = (W - L) / s14.length, bw = Math.max(4, Math.min(28, step * 0.62));
    [0, 1].forEach(function (g) { var y = T + ph - ph * g; el('line', { x1: L, x2: W, y1: y, y2: y, stroke: css('--grid') }, svg); text(svg, L - 4, y + 3, int(max * g), { 'text-anchor': 'end' }); });
    s14.forEach(function (d, i) {
      var x = L + step * i + (step - bw) / 2, y = T + ph, top = d.pay.reduce(function (a, v, k) { return v ? k : a; }, 0);
      var rows = function () { return [{ value: d.date.getDate() + '/' + (d.date.getMonth() + 1) + ' · ' + int(d.orders) + ' pedidos' }].concat(d.pay.map(function (pv, pk) { return { value: int(pv), label: PAY[pk], color: css(PAYC[pk]) }; })); };
      d.pay.forEach(function (v, k) {
        if (!v) { return; }
        var h = ph * v / max, gap = k === top ? 0 : 2;
        hover(el(k === top ? 'path' : 'rect', k === top ? { d: barPath(x, y - h, bw, h), fill: css(PAYC[k]), class: 'mark', tabindex: 0 }
          : { x: x, y: y - h + gap, width: bw, height: Math.max(0, h - gap), fill: css(PAYC[k]), class: 'mark', tabindex: 0 }, svg), rows);
        y -= h;
      });
      if (i % 2 === 0 || i === s14.length - 1) { text(svg, x + bw / 2, H - 3, d.date.getDate() + '/' + (d.date.getMonth() + 1), { 'text-anchor': 'middle' }); }
    });
    tables.pay = { title: 'Forma de pagamento por dia', head: ['Dia'].concat(PAY, ['Total']), rows: s14.map(function (d) { return [d.date.toLocaleDateString('pt-BR')].concat(d.pay.map(int), [int(d.orders)]); }) };
  }

  function hbars(id, items, key, fmt, rows, title, head, row) {
    var s = svgIn(id), svg = s[0], W = s[1], H = s[2], rowH = H / items.length, L = Math.min(150, W * 0.42), R = 64;
    var max = Math.max.apply(null, items.map(function (it) { return it[key]; })), bh = Math.max(4, Math.min(14, rowH - 6));
    items.forEach(function (it, i) {
      var y = i * rowH, w = Math.max(4, (W - L - R) * it[key] / max), name = (i + 1) + '. ' + it.name;
      var t = text(svg, 0, y + rowH / 2 + 4, name, { fill: css('--text-secondary'), 'font-size': 11 });
      while (t.getComputedTextLength() > L - 8 && name.length > 4) { name = name.slice(0, -2); t.textContent = name + '…'; }
      hover(el('path', { d: barPath(L, y + (rowH - bh) / 2, w, bh, true), fill: css('--seq'), class: 'mark', tabindex: 0 }, svg), function () { return [{ value: it.name }].concat(rows(it)); });
      text(svg, L + w + 5, y + rowH / 2 + 4, fmt(it[key]), { fill: css('--text-primary'), 'font-size': 11 });
    });
    tables[id] = { title: title, head: head, rows: items.map(row) };
  }

  function share(id, items, key, colors, fmt) {
    var s = svgIn(id), svg = s[0], W = s[1], total = items.reduce(function (t, it) { return t + it[key]; }, 0), x = 0;
    items.forEach(function (it, i) {
      var w = W * it[key] / total, pct = Math.round(100 * it[key] / total);
      hover(el('rect', { x: x, y: 2, width: Math.max(0, w - (i === items.length - 1 ? 0 : 2)), height: 14, rx: 3, fill: css(colors[i]), class: 'mark', tabindex: 0 }, svg),
        function () { return [{ value: it.name }, { value: pct + '%', label: fmt(it[key]), color: css(colors[i]) }]; });
      var lastOne = i === items.length - 1 && i > 0; // the last label ends at the right edge
      text(svg, lastOne ? W : x, 32, pct + '% ' + it.name, { fill: css('--text-primary'), 'font-size': 11, 'text-anchor': lastOne ? 'end' : 'start' });
      x += w;
    });
  }

  function render() {
    kpis(); evolution(); heatmap(); payByDay();
    hbars('top', products, 'qty', function (v) { return int(v) + ' un.'; }, function (it) { return [{ value: int(it.qty), label: 'unidades', color: css('--seq') }, { value: money(it.revenue), label: 'faturamento' }]; },
      'Top 10 produtos', ['#', 'Produto', 'Unidades', 'Faturamento'], function (it, i) { return [i + 1, it.name, int(it.qty), money(it.revenue)]; });
    hbars('cats', cats, 'revenue', kmoney, function (it) { return [{ value: money(it.revenue), label: 'faturamento', color: css('--seq') }]; },
      'Categorias que mais vendem', ['#', 'Categoria', 'Faturamento'], function (it, i) { return [i + 1, it.name, money(it.revenue)]; });
    share('chan', channels, 'revenue', ['--series-1', '--deemph'], money);
    document.getElementById('upd').textContent = new Date().toLocaleTimeString('pt-BR');
  }
  render();
  setInterval(render, 60000); // no sistema: busca /admin/salesdashboard/data e redesenha no lugar
  var rt; addEventListener('resize', function () { clearTimeout(rt); rt = setTimeout(render, 150); });
})();

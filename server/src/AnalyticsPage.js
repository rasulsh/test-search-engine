/* Dashboard of public/analytics.php (M35). Renders the report JSON that
 * Analytics::report() produced; it computes no numbers. No third-party code and
 * no network request except a same-origin fetch of analytics.php?format=json. All
 * user text (queries, brand names) goes in through textContent. */
(function () {
  'use strict';
  var SVG = 'http://www.w3.org/2000/svg';
  var WEEKDAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
  var WINDOWS = [['24h', 'Last 24 hours'], ['7d', 'Last 7 days'], ['30d', 'Last 30 days'],
    ['90d', 'Last 90 days'], ['all', 'All time']];
  var report = JSON.parse(document.getElementById('report').textContent);
  var root = document.getElementById('app');
  var filter = '';
  var sorts = {};
  var timer = null;
  var tip = null;

  function el(tag, attrs, children) {
    var node = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) {
      if (k === 'text') { node.textContent = attrs[k]; } else { node.setAttribute(k, attrs[k]); }
    });
    (children || []).forEach(function (c) { node.appendChild(c); });
    return node;
  }
  function svg(tag, attrs, children) {
    var node = document.createElementNS(SVG, tag);
    Object.keys(attrs || {}).forEach(function (k) {
      if (k === 'text') { node.textContent = attrs[k]; } else { node.setAttribute(k, attrs[k]); }
    });
    (children || []).forEach(function (c) { node.appendChild(c); });
    return node;
  }
  function num(n) { return Number(n).toLocaleString('en-US'); }
  function max(values) { return Math.max.apply(null, values.concat([0])); }

  function showTip(event, lines) {
    if (!tip) { tip = el('div', { 'class': 'tip', role: 'status' }); document.body.appendChild(tip); }
    tip.replaceChildren.apply(tip, lines.map(function (l, i) {
      return el(i === 0 ? 'b' : 'div', { text: l });
    }));
    tip.style.display = 'block';
    var x = Math.min(event.clientX + 14, window.innerWidth - tip.offsetWidth - 8);
    tip.style.left = Math.max(4, x) + 'px';
    tip.style.top = (event.clientY + window.scrollY + 14) + 'px';
  }
  function hideTip() { if (tip) { tip.style.display = 'none'; } }
  function hover(node, lines) {
    node.addEventListener('mousemove', function (e) { showTip(e, lines); });
    node.addEventListener('mouseleave', hideTip);
  }

  /* Time series: bars (volume) or lines (trends). series = [{name, cls, values}] */
  function timeChart(title, labels, series, kind, fmt) {
    var W = 640, H = 190, L = 44, R = 8, T = 10, B = 24;
    var top = max([].concat.apply([], series.map(function (s) { return s.values; }))) || 1;
    var n = labels.length;
    var step = (W - L - R) / Math.max(1, n);
    var y = function (v) { return T + (H - T - B) * (1 - v / top); };
    var x = function (i) { return L + step * (i + 0.5); };
    var s = svg('svg', { viewBox: '0 0 ' + W + ' ' + H, role: 'img', 'aria-label': title, 'class': 'chart' });
    [0, 0.5, 1].forEach(function (f) {
      s.appendChild(svg('line', { x1: L, x2: W - R, y1: y(top * f), y2: y(top * f), 'class': 'grid' }));
      s.appendChild(svg('text', { x: L - 6, y: y(top * f) + 4, 'class': 'axis end', text: fmt(top * f) }));
    });
    if (n > 0) {
      s.appendChild(svg('text', { x: L, y: H - 6, 'class': 'axis', text: labels[0] }));
      s.appendChild(svg('text', { x: W - R, y: H - 6, 'class': 'axis end', text: labels[n - 1] }));
    }
    series.forEach(function (ser) {
      if (kind === 'bar') {
        ser.values.forEach(function (v, i) {
          var w = Math.max(1, step - 2);
          s.appendChild(svg('rect', { x: x(i) - w / 2, y: y(v), width: w, height: Math.max(0, H - B - y(v)),
            rx: Math.min(4, w / 2), 'class': ser.cls }));
        });
      } else {
        var d = ser.values.map(function (v, i) { return (i ? 'L' : 'M') + x(i) + ' ' + y(v); }).join(' ');
        s.appendChild(svg('path', { d: d, 'class': 'line ' + ser.cls }));
        if (n <= 40) {
          ser.values.forEach(function (v, i) {
            s.appendChild(svg('circle', { cx: x(i), cy: y(v), r: 3, 'class': 'dot ' + ser.cls }));
          });
        }
      }
    });
    var guide = svg('line', { y1: T, y2: H - B, 'class': 'guide', visibility: 'hidden' });
    s.appendChild(guide);
    var hit = svg('rect', { x: L, y: T, width: W - L - R, height: H - T - B, fill: 'transparent' });
    hit.addEventListener('mousemove', function (e) {
      var box = s.getBoundingClientRect();
      var px = (e.clientX - box.left) * (W / box.width);
      var i = Math.max(0, Math.min(n - 1, Math.floor((px - L) / step)));
      guide.setAttribute('x1', x(i)); guide.setAttribute('x2', x(i)); guide.setAttribute('visibility', 'visible');
      showTip(e, [labels[i]].concat(series.map(function (ser) { return ser.name + ': ' + fmt(ser.values[i]); })));
    });
    hit.addEventListener('mouseleave', function () { guide.setAttribute('visibility', 'hidden'); hideTip(); });
    s.appendChild(hit);
    return s;
  }

  function legend(items) {
    return el('div', { 'class': 'legend' }, items.map(function (i) {
      return el('span', {}, [el('i', { 'class': 'sw ' + i[1] }), document.createTextNode(i[0])]);
    }));
  }
  function card(title, body, note) {
    return el('section', { 'class': 'card' }, [el('h2', { text: title })].concat(body, note ? [el('p', { 'class': 'note', text: note })] : []));
  }
  function empty() { return el('p', { 'class': 'note', text: 'No searches in this window.' }); }

  function tile(label, value) {
    return el('div', { 'class': 'tile' }, [el('b', { text: value }), el('span', { text: label })]);
  }

  /* Heatmap: weekday x hour, one hue, opacity = share of the busiest cell. */
  function heatmap(cells) {
    var C = 22, W = 40 + 24 * C, H = 18 + 7 * C;
    var top = max(cells.map(function (c) { return c.searches; })) || 1;
    var s = svg('svg', { viewBox: '0 0 ' + W + ' ' + H, role: 'img', 'aria-label': 'Searches by weekday and hour', 'class': 'chart' });
    WEEKDAYS.forEach(function (d, r) {
      s.appendChild(svg('text', { x: 34, y: 18 + r * C + 15, 'class': 'axis end', text: d }));
    });
    for (var h = 0; h < 24; h += 3) {
      s.appendChild(svg('text', { x: 40 + h * C + C / 2, y: 12, 'class': 'axis mid', text: String(h) }));
    }
    var byKey = {};
    cells.forEach(function (c) { byKey[c.weekday + ':' + c.hour] = c.searches; });
    for (var r = 0; r < 7; r++) {
      for (var hh = 0; hh < 24; hh++) {
        var v = byKey[r + ':' + hh] || 0;
        var rect = svg('rect', { x: 40 + hh * C + 1, y: 18 + r * C + 1, width: C - 2, height: C - 2, rx: 3,
          'class': 'cell', 'fill-opacity': v ? (0.15 + 0.85 * v / top).toFixed(2) : 0.05 });
        hover(rect, [WEEKDAYS[r] + ' ' + hh + ':00', num(v) + ' searches']);
        s.appendChild(rect);
      }
    }
    return s;
  }

  function histogram(rows) {
    var W = 320, H = 170, B = 24, T = 18;
    var top = max(rows.map(function (r) { return r.searches; })) || 1;
    var w = (W - 20) / rows.length;
    var s = svg('svg', { viewBox: '0 0 ' + W + ' ' + H, role: 'img', 'aria-label': 'Query length in words', 'class': 'chart small' });
    rows.forEach(function (r, i) {
      var bh = (H - B - T) * r.searches / top;
      var rect = svg('rect', { x: 10 + i * w + 4, y: H - B - bh, width: w - 8, height: Math.max(0, bh), rx: 4, 'class': 's1' });
      hover(rect, [r.tokens + (r.tokens === 5 ? '+ words' : r.tokens === 1 ? ' word' : ' words'), num(r.searches) + ' searches']);
      s.appendChild(rect);
      s.appendChild(svg('text', { x: 10 + i * w + w / 2, y: H - 8, 'class': 'axis mid', text: r.tokens === 5 ? '5+' : String(r.tokens) }));
      s.appendChild(svg('text', { x: 10 + i * w + w / 2, y: H - B - bh - 5, 'class': 'axis mid', text: num(r.searches) }));
    });
    return s;
  }

  /* One stacked bar: segments [{label, value, cls}], 2px gaps, legend with counts. */
  function split(title, segments) {
    var total = segments.reduce(function (a, s) { return a + s.value; }, 0);
    var bar = el('div', { 'class': 'split', role: 'img', 'aria-label': title });
    segments.forEach(function (s) {
      if (!s.value) { return; }
      var seg = el('div', { 'class': 'seg ' + s.cls });
      seg.style.flexGrow = String(s.value);
      hover(seg, [s.label, num(s.value) + ' (' + (100 * s.value / total).toFixed(1) + '%)']);
      bar.appendChild(seg);
    });
    return el('div', {}, [el('div', { 'class': 'splitTitle', text: title }), total ? bar : empty(),
      legend(segments.map(function (s) { return [s.label + ': ' + num(s.value), s.cls]; }))]);
  }

  /* Sortable table; the filter box narrows rows whose first cell is user text. */
  function table(id, headers, rows, filterable) {
    var sort = sorts[id] || { col: null, dir: 1 };
    var shown = rows.filter(function (r) {
      return !filterable || !filter || String(r[0]).toLowerCase().indexOf(filter) !== -1;
    });
    if (sort.col !== null) {
      shown = shown.slice().sort(function (a, b) {
        var x = a[sort.col], y = b[sort.col];
        return (typeof x === 'number' ? x - y : String(x).localeCompare(String(y))) * sort.dir;
      });
    }
    var head = el('tr', {}, headers.map(function (h, i) {
      var th = el('th', { text: h + (sort.col === i ? (sort.dir > 0 ? ' ▲' : ' ▼') : ''), tabindex: '0',
        'aria-sort': sort.col === i ? (sort.dir > 0 ? 'ascending' : 'descending') : 'none' });
      var go = function () { sorts[id] = { col: i, dir: sort.col === i ? -sort.dir : (i === 0 ? 1 : -1) }; render(); };
      th.addEventListener('click', go);
      th.addEventListener('keydown', function (e) { if (e.key === 'Enter') { go(); } });
      return th;
    }));
    var body = shown.map(function (r) {
      return el('tr', {}, r.map(function (v, i) {
        var td = el('td', { text: typeof v === 'number' ? num(v) : v });
        if (typeof v === 'number') { td.className = 'n'; } else if (i === 0) { td.setAttribute('dir', 'auto'); }
        return td;
      }));
    });
    if (!body.length) {
      body = [el('tr', {}, [el('td', { colspan: String(headers.length), text: rows.length ? 'No match for the filter.' : 'Nothing in this window.' })])];
    }
    return el('div', { 'class': 'scroll' }, [el('table', {}, [el('thead', {}, [head]), el('tbody', {}, body)])]);
  }

  function pct(v) { return v.toFixed(1) + '%'; }
  function ms(v) { return Math.round(v) + ' ms'; }

  function render() {
    var r = report, s = r.summary, L = r.latency;
    var buckets = r.volume.map(function (v) { return v.bucket; });
    var empty0 = s.total === 0;
    var focus = document.activeElement && document.activeElement.id;
    var controls = el('div', { 'class': 'controls' });
    var select = el('select', { id: 'window', 'aria-label': 'Time window' }, WINDOWS.map(function (w) {
      var o = el('option', { value: w[0], text: w[1] }); if (w[0] === r.window) { o.selected = true; } return o;
    }));
    select.addEventListener('change', function () { load(select.value); });
    var box = el('input', { id: 'filter', type: 'search', placeholder: 'Filter queries and names', 'aria-label': 'Filter queries', value: filter });
    box.addEventListener('input', function () { filter = box.value.trim().toLowerCase(); render(); });
    var json = el('a', { href: '#', text: 'JSON' });
    json.addEventListener('click', function (e) { e.preventDefault(); location.href = url(r.window, true); });
    controls.append(select, box, json,
      el('span', { 'class': 'note', id: 'status', text: r.refresh_seconds > 0 ? 'Auto-refresh every ' + r.refresh_seconds + ' s' : 'Auto-refresh off' }));

    var tiles = el('div', { 'class': 'tiles' }, [
      tile('Searches', num(s.total)), tile('Zero-result rate', pct(s.zero_rate)),
      tile('Cache hit rate', pct(r.cache.hit_rate)), tile('Hybrid share', pct(r.tier.hybrid_share)),
      tile('Avg latency', ms(L.avg)), tile('p95 latency', ms(L.p95)),
      tile('Repeated queries', pct(r.repeat_queries.rate)),
    ]);

    var rowsOf = function (list) { return list.map(function (q) { return [q.query, q.searches, q.last_seen]; }); };
    var names = function (list) { return list.map(function (b) { return [b.name, b.searches]; }); };
    var zeroTrend = r.zero_rate_trend, lat = r.latency_trend;
    var grid = el('div', { 'class': 'grid2' }, empty0 ? [card('Searches', [empty()])] : [
      card('Volume', [timeChart('Searches over time', buckets,
        [{ name: 'Searches', cls: 's1', values: r.volume.map(function (v) { return v.searches; }) }], 'bar', num)]),
      card('Zero-result rate', [timeChart('Zero-result rate over time', zeroTrend.map(function (v) { return v.bucket; }),
        [{ name: 'Zero-result rate', cls: 's2', values: zeroTrend.map(function (v) { return v.zero_rate; }) }], 'line', pct)]),
      card('Latency', [timeChart('Latency over time', lat.map(function (v) { return v.bucket; }), [
        { name: 'Average', cls: 's1', values: lat.map(function (v) { return v.avg; }) },
        { name: 'p95', cls: 's2', values: lat.map(function (v) { return v.p95; }) }], 'line', ms),
      legend([['Average', 's1'], ['p95', 's2']])]),
      card('Busiest hours', [heatmap(r.hourly_heatmap)], 'Database clock; darker = more searches.'),
      card('Query length', [histogram(r.query_length)], 'Words per query; 5+ is five or more.'),
      card('Tier and cache', [
        split('Tier', [{ label: 'Keyword-only', value: r.tier.keyword_only, cls: 's1' }, { label: 'Hybrid', value: r.tier.hybrid, cls: 's2' }]),
        split('Cache', [{ label: 'Served from cache', value: r.cache.hits, cls: 's1' }, { label: 'Computed', value: r.cache.misses, cls: 's2' }]),
      ], 'Did-you-mean offered: ' + pct(r.did_you_mean.share) + '. Average results per search: ' + r.summary.avg_results + '.'),
    ]);

    var tables = el('div', { 'class': 'grid2' }, [
      card('Top queries', [table('top', ['Query', 'Searches', 'Last seen'], rowsOf(r.top_queries), true)]),
      card('Zero-result queries', [table('zero', ['Query', 'Searches', 'Last seen'], rowsOf(r.zero_result_queries), true)],
        'The gap list: candidates for aliases or the intent map.'),
      card('Top brands', [table('brands', ['Brand', 'Searches'], names(r.top_brands_categories.brands), true)],
        'Counted when every word of the catalog name is in the query.'),
      card('Top categories', [table('cats', ['Category', 'Searches'], names(r.top_brands_categories.categories), true)]),
    ]);

    root.replaceChildren(el('header', {}, [el('h1', { text: 'Search analytics' }),
      el('p', { 'class': 'note', text: (r.since ? 'Since ' + r.since : 'All time') + ' (database clock)' })]),
    controls, tiles, grid, tables);
    if (focus) { var again = document.getElementById(focus); if (again) { again.focus(); if (again.setSelectionRange && again.value) { again.setSelectionRange(again.value.length, again.value.length); } } }
  }

  function url(window_, json) {
    var p = new URLSearchParams(location.search);
    p.set('window', window_);
    if (json) { p.set('format', 'json'); } else { p.delete('format'); }
    return location.pathname + '?' + p.toString();
  }

  function load(window_) {
    var status = document.getElementById('status');
    return fetch(url(window_ || report.window, true), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (res) { if (!res.ok) { throw new Error('HTTP ' + res.status); } return res.json(); })
      .then(function (data) {
        report = data;
        history.replaceState(null, '', url(report.window, false));
        schedule();
        render();
        var st = document.getElementById('status');
        if (st) { st.textContent = (report.refresh_seconds > 0 ? 'Auto-refresh every ' + report.refresh_seconds + ' s. ' : 'Auto-refresh off. ') + 'Updated ' + new Date().toLocaleTimeString(); }
      })
      .catch(function (err) { if (status) { status.textContent = 'Refresh failed (' + err.message + '); showing the last data.'; } });
  }

  function schedule() {
    if (timer) { clearInterval(timer); timer = null; }
    if (report.refresh_seconds > 0) {
      timer = setInterval(function () { if (!document.hidden) { load(report.window); } }, report.refresh_seconds * 1000);
    }
  }

  render();
  schedule();
})();

/* Dashboard of public/analytics.php (M35, Persian / RTL in M38). Renders the report JSON that
 * Analytics::report() produced; it computes no numbers. No third-party code and no network
 * request except a same-origin fetch of analytics.php?format=json. All user text (queries, brand
 * names) goes in through textContent. Every UI string comes from App\AnalyticsStrings (the
 * #strings block); this file holds layout and logic only. */
(function () {
  'use strict';
  var SVG = 'http://www.w3.org/2000/svg';
  var THEME_KEY = 'admin-theme';
  var T = JSON.parse(document.getElementById('strings').textContent);
  var META = JSON.parse(document.getElementById('meta').textContent);
  var report = JSON.parse(document.getElementById('report').textContent);
  var root = document.getElementById('app');
  var WINDOWS = ['24h', '7d', '30d', '90d', 'all'];
  var ROW_ORDER = [5, 6, 0, 1, 2, 3, 4]; // report weekdays are 0 = Monday; the Persian week starts on Saturday
  var filter = '';
  var sorts = {};
  var openHelp = {};
  var tileHelp = null;
  var timer = null;
  var tip = null;

  /* ---- formatting (display only: the report values are never changed) ----
   * The one place that turns values into text: Latin digits, "," thousands, "." decimals, "%" for
   * percentages, Jalali dates in the Latin numbering system. Nothing below formats a number itself. */
  var fmt = (function () {
    var plain = function (min, max) { return new Intl.NumberFormat('en-US', { minimumFractionDigits: min, maximumFractionDigits: max }); };
    var int = plain(0, 0), one = plain(1, 1), two = plain(0, 2);
    var clock = null, jalali = null;
    try { clock = new Intl.DateTimeFormat('en-GB', { hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23' }); } catch (e) { clock = null; }
    try {
      jalali = new Intl.DateTimeFormat('fa-IR-u-ca-persian-nu-latn', {
        year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit',
        hourCycle: 'h23', timeZone: 'UTC' });
    } catch (e) { jalali = null; }
    var part = function (parts, type) {
      for (var i = 0; i < parts.length; i++) { if (parts[i].type === type) { return parts[i].value; } }
      return '';
    };
    return {
      n: function (v) { return int.format(Number(v)); },
      d1: function (v) { return one.format(Number(v)); },
      d2: function (v) { return two.format(Number(v)); },
      pct: function (v) { return one.format(Number(v)) + '%'; },
      ms: function (v) { return t(T.ui.ms, [int.format(Math.round(Number(v)))]); },
      /* "1405/08/03 09:56" (or the date alone), or '' when the browser lacks the Persian calendar. */
      jalali: function (date, withTime) {
        if (!jalali) { return ''; }
        var p = jalali.formatToParts(date);
        var day = part(p, 'year') + '/' + part(p, 'month') + '/' + part(p, 'day');
        return withTime ? day + ' ' + part(p, 'hour') + ':' + part(p, 'minute') : day;
      },
      now: function () { return clock ? clock.format(new Date()) : ''; }
    };
  })();

  function t(text, args) {
    return String(text).replace(/\{(\d+)\}/g, function (m, i) { return args && args[i] !== undefined ? args[i] : m; });
  }
  function max(values) { return Math.max.apply(null, values.concat([0])); }

  /* The stored DATETIME is a wall-clock string in the database's zone; its calendar fields are
   * converted as-is (read as UTC, formatted as UTC), so no timezone shift is ever applied. */
  var STAMP = /^(\d{4})-(\d\d)-(\d\d)(?:[ T](\d\d):(\d\d)(?::(\d\d))?)?/;
  function jalaliOf(stamp) {
    var m = STAMP.exec(String(stamp));
    if (!m) { return ''; }
    return fmt.jalali(new Date(Date.UTC(+m[1], +m[2] - 1, +m[3], +(m[4] || 0), +(m[5] || 0), +(m[6] || 0))), m[4] !== undefined);
  }
  function shortStamp(stamp) { return String(stamp).replace(/^(\S+ \d\d:\d\d):\d\d$/, '$1'); }
  function dualText(stamp) {
    var j = jalaliOf(stamp);
    return '\u2066' + shortStamp(stamp) + '\u2069' + (j ? ' — ' + j : '');
  }
  function dbClockLabel() {
    var c = META.db_clock;
    if (!c || c.offset_minutes === null || c.offset_minutes === undefined) { return T.ui.db_clock_unknown; }
    var o = Math.abs(c.offset_minutes);
    var hh = ('0' + Math.floor(o / 60)).slice(-2), mm = ('0' + (o % 60)).slice(-2);
    var zone = c.zone && c.zone !== 'SYSTEM' ? c.zone + ' ' : '';
    return T.ui.db_clock + ' (' + zone + 'UTC' + (c.offset_minutes < 0 ? '-' : '+') + hh + ':' + mm + ')';
  }

  /* ---- DOM helpers ---- */
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

  /* ---- theme ---- */
  function currentTheme() {
    var set = document.documentElement.getAttribute('data-theme');
    if (set === 'dark' || set === 'light') { return set; }
    return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  }
  function setTheme(name) {
    document.documentElement.setAttribute('data-theme', name);
    try { localStorage.setItem(THEME_KEY, name); } catch (e) { /* private mode: the choice lasts this visit */ }
    render();
  }
  if (window.matchMedia) {
    var scheme = window.matchMedia('(prefers-color-scheme: dark)');
    var follow = function () { if (!document.documentElement.getAttribute('data-theme')) { render(); } };
    if (scheme.addEventListener) { scheme.addEventListener('change', follow); }
  }

  /* ---- tooltip ---- */
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

  /* ---- help ("؟") ---- */
  function helpPanel(key) {
    var h = T.help[key];
    var rows = [];
    if (h) {
      ['what', 'calc', 'read', 'act'].forEach(function (part) {
        if (h[part]) {
          rows.push(el('dt', { text: T.help_labels[part] }), el('dd', { text: h[part] }));
        }
      });
      if (h.needs) { rows.push(el('dt', { 'class': 'needs', text: T.help_labels.needs }), el('dd', { 'class': 'needs', text: h.needs })); }
    }
    return el('dl', { 'class': 'help', id: 'help-' + key }, rows);
  }
  function helpButton(key, onToggle, isOpen) {
    var b = el('button', { type: 'button', 'class': 'q', 'aria-label': T.ui.help_open, 'aria-expanded': isOpen ? 'true' : 'false',
      'aria-controls': 'help-' + key, title: T.ui.help_open, text: '؟' });
    b.addEventListener('click', function () { onToggle(b); });
    return b;
  }

  /* ---- dates in text and SVG ---- */
  function dateCell(stamp) {
    var j = jalaliOf(stamp);
    return el('span', { 'class': 'dt' }, [el('span', { 'class': 'g', dir: 'ltr', text: shortStamp(stamp) }),
      el('span', { 'class': 'j', text: j })]);
  }

  /* Time series: bars (volume) or lines (trends). Time runs left to right. series = [{name, cls, values}] */
  function timeChart(title, labels, series, kind, fmt, axisFmt) {
    var W = 640, H = 206, L = 44, R = 8, T0 = 10, B = 40;
    var top = max([].concat.apply([], series.map(function (s) { return s.values; }))) || 1;
    var n = labels.length;
    var step = (W - L - R) / Math.max(1, n);
    var y = function (v) { return T0 + (H - T0 - B) * (1 - v / top); };
    var x = function (i) { return L + step * (i + 0.5); };
    var s = svg('svg', { viewBox: '0 0 ' + W + ' ' + H, role: 'img', 'aria-label': title, 'class': 'chart', direction: 'ltr' });
    [0, 0.5, 1].forEach(function (f) {
      s.appendChild(svg('line', { x1: L, x2: W - R, y1: y(top * f), y2: y(top * f), 'class': 'grid' }));
      s.appendChild(svg('text', { x: L - 6, y: y(top * f) + 4, 'class': 'axis end', text: (axisFmt || fmt)(top * f) }));
    });
    if (n > 0) {
      [[0, '', L], [n - 1, ' end', W - R]].forEach(function (e) {
        s.appendChild(svg('text', { x: e[2], y: H - 20, 'class': 'axis' + e[1], text: shortStamp(labels[e[0]]) }));
        s.appendChild(svg('text', { x: e[2], y: H - 6, 'class': 'axis jal' + e[1], text: jalaliOf(labels[e[0]]) }));
      });
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
    var guide = svg('line', { y1: T0, y2: H - B, 'class': 'guide', visibility: 'hidden' });
    s.appendChild(guide);
    var hit = svg('rect', { x: L, y: T0, width: W - L - R, height: H - T0 - B, fill: 'transparent' });
    hit.addEventListener('mousemove', function (e) {
      var box = s.getBoundingClientRect();
      var px = (e.clientX - box.left) * (W / box.width);
      var i = Math.max(0, Math.min(n - 1, Math.floor((px - L) / step)));
      guide.setAttribute('x1', x(i)); guide.setAttribute('x2', x(i)); guide.setAttribute('visibility', 'visible');
      showTip(e, [dualText(labels[i])].concat(series.map(function (ser) { return ser.name + ': ' + fmt(ser.values[i]); })));
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
  function note(text) { return el('p', { 'class': 'note', text: text }); }
  function empty(text) { return note(text || T.empty.window); }

  /* A card with an optional "?" panel. helpKey names the entry of T.help. */
  function card(title, body, noteText, helpKey, wide) {
    var panel = helpKey ? helpPanel(helpKey) : null;
    var head = [el('h2', { text: title })];
    if (panel) {
      panel.hidden = !openHelp[helpKey];
      head.push(helpButton(helpKey, function (b) {
        openHelp[helpKey] = panel.hidden;
        panel.hidden = !panel.hidden;
        b.setAttribute('aria-expanded', panel.hidden ? 'false' : 'true');
      }, !!openHelp[helpKey]));
    }
    return el('section', { 'class': 'card' + (wide ? ' wide' : '') },
      [el('div', { 'class': 'cardhead' }, head)].concat(panel ? [panel] : [], body, noteText ? [note(noteText)] : []));
  }

  /* Tile values as [number, unit]; the text forms (tables, tooltips) stay in fmt. */
  function P(v) { return [fmt.d1(v), '%']; }
  function M(v) { return [fmt.n(Math.round(Number(v))), T.ui.unit_ms]; }

  function tile(label, value, helpKey) {
    var q = helpButton(helpKey, function () {
      tileHelp = tileHelp === helpKey ? null : helpKey;
      var holder = document.getElementById('tilehelp');
      if (holder) { holder.replaceChildren.apply(holder, tileHelp ? [helpPanel(tileHelp)] : []); }
      Array.prototype.forEach.call(document.querySelectorAll('.tile .q'), function (b) {
        b.setAttribute('aria-expanded', b.getAttribute('aria-controls') === 'help-' + tileHelp ? 'true' : 'false');
      });
    }, tileHelp === helpKey);
    // value is text, or [number, unit]: the unit is drawn smaller and muted after the number.
    var big = el('b', { 'class': 'val', text: Array.isArray(value) ? value[0] : value },
      Array.isArray(value) ? [el('small', { text: value[1] })] : []);
    return el('div', { 'class': 'tile' }, [q, big, el('span', { text: label })]);
  }

  /* Heatmap: weekday x hour, one hue, opacity = share of the busiest cell. Hours run left to right. */
  function heatmap(cells) {
    var C = 22, W = 56 + 24 * C, H = 18 + 7 * C;
    var top = max(cells.map(function (c) { return c.searches; })) || 1;
    var s = svg('svg', { viewBox: '0 0 ' + W + ' ' + H, role: 'img', 'aria-label': T.chart.heatmap, 'class': 'chart', direction: 'ltr' });
    ROW_ORDER.forEach(function (d, r) {
      s.appendChild(svg('text', { x: 50, y: 18 + r * C + 15, 'class': 'axis end', text: T.weekdays[d] }));
    });
    for (var h = 0; h < 24; h += 3) {
      s.appendChild(svg('text', { x: 56 + h * C + C / 2, y: 12, 'class': 'axis mid', text: fmt.n(h) }));
    }
    var byKey = {};
    cells.forEach(function (c) { byKey[c.weekday + ':' + c.hour] = c.searches; });
    ROW_ORDER.forEach(function (d, r) {
      for (var hh = 0; hh < 24; hh++) {
        var v = byKey[d + ':' + hh] || 0;
        var rect = svg('rect', { x: 56 + hh * C + 1, y: 18 + r * C + 1, width: C - 2, height: C - 2, rx: 3,
          'class': 'cell', 'fill-opacity': v ? (0.15 + 0.85 * v / top).toFixed(2) : 0.05 });
        hover(rect, [t(T.ui.hour_at, [T.weekdays[d], fmt.n(hh)]), t(T.ui.searches_n, [fmt.n(v)])]);
        s.appendChild(rect);
      }
    });
    return s;
  }

  function histogram(rows) {
    var W = 320, H = 170, B = 24, T0 = 18;
    var top = max(rows.map(function (r) { return r.searches; })) || 1;
    var w = (W - 20) / rows.length;
    var s = svg('svg', { viewBox: '0 0 ' + W + ' ' + H, role: 'img', 'aria-label': T.chart.length, 'class': 'chart small', direction: 'ltr' });
    rows.forEach(function (r, i) {
      var bh = (H - B - T0) * r.searches / top;
      var rect = svg('rect', { x: 10 + i * w + 4, y: H - B - bh, width: w - 8, height: Math.max(0, bh), rx: 4, 'class': 's1' });
      hover(rect, [r.tokens === 5 ? T.ui.word_5plus : t(T.ui.word_one, [fmt.n(r.tokens)]), t(T.ui.searches_n, [fmt.n(r.searches)])]);
      s.appendChild(rect);
      s.appendChild(svg('text', { x: 10 + i * w + w / 2, y: H - 8, 'class': 'axis mid', text: fmt.n(r.tokens) + (r.tokens === 5 ? '+' : '') }));
      s.appendChild(svg('text', { x: 10 + i * w + w / 2, y: H - B - bh - 5, 'class': 'axis mid', text: fmt.n(r.searches) }));
    });
    return s;
  }

  /* One stacked bar: segments [{label, value, cls}], 2px gaps, legend with counts. */
  function split(title, segments) {
    var total = segments.reduce(function (a, s) { return a + s.value; }, 0);
    var bar = el('div', { 'class': 'split', role: 'img', 'aria-label': title, dir: 'ltr' });
    segments.forEach(function (s) {
      if (!s.value) { return; }
      var seg = el('div', { 'class': 'seg ' + s.cls });
      seg.style.flexGrow = String(s.value);
      hover(seg, [s.label, fmt.n(s.value) + ' (' + fmt.pct(100 * s.value / total) + ')']);
      bar.appendChild(seg);
    });
    return el('div', {}, [el('div', { 'class': 'splitTitle', text: title }), total ? bar : empty(),
      legend(segments.map(function (s) { return [s.label + ': ' + fmt.n(s.value), s.cls]; }))]);
  }

  /* Column kinds: t = free text (start-aligned, own direction), n = count, p = percent, m = milliseconds,
   * d = date / time (Gregorian + Jalali), x = short preformatted value. All but t are centered. */
  function cellFor(kind, v) {
    var td;
    if (kind === 't') { td = el('td', {}, [el('span', { dir: 'auto', 'class': 'qt', text: String(v) })]); }
    else if (kind === 'd') { td = el('td', { 'class': 'c' }, [dateCell(v)]); }
    else {
      var text = kind === 'n' ? fmt.n(v) : kind === 'p' ? fmt.pct(v) : kind === 'm' ? fmt.ms(v) : String(v);
      td = el('td', { 'class': 'c', text: text });
    }
    return td;
  }

  /* Sortable table; the filter box narrows rows whose first cell is user text. */
  function table(id, headers, kinds, rows, filterable, emptyText) {
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
      var th = el('th', { text: h + (sort.col === i ? (sort.dir > 0 ? ' ▲' : ' ▼') : ''), tabindex: '0', scope: 'col',
        'class': kinds[i] === 't' ? '' : 'c', 'aria-sort': sort.col === i ? (sort.dir > 0 ? 'ascending' : 'descending') : 'none' });
      var go = function () { sorts[id] = { col: i, dir: sort.col === i ? -sort.dir : (kinds[i] === 't' ? 1 : -1) }; render(); };
      th.addEventListener('click', go);
      th.addEventListener('keydown', function (e) { if (e.key === 'Enter') { go(); } });
      return th;
    }));
    var body = shown.map(function (r) {
      return el('tr', {}, r.map(function (v, i) { return cellFor(kinds[i], v); }));
    });
    if (!body.length) {
      body = [el('tr', {}, [el('td', { colspan: String(headers.length),
        text: rows.length ? T.empty.filter : (emptyText || T.empty.rows) })])];
    }
    return el('div', { 'class': 'scroll' }, [el('table', {}, [el('thead', {}, [head]), el('tbody', {}, body)])]);
  }

  function guideCard() {
    var G = T.guide;
    var parts = [el('p', { text: G.intro }), el('h3', { text: G.metrics_title }),
      el('dl', { 'class': 'help open' }, [].concat.apply([], G.metrics.map(function (m) {
        return [el('dt', { text: m[0] }), el('dd', { text: m[1] })];
      }))),
      el('h3', { text: G.versions_title }), el('p', { text: G.versions }),
      el('h3', { text: G.needs_title }),
      el('dl', { 'class': 'help open' }, [].concat.apply([], G.needs.map(function (m) {
        return [el('dt', { 'class': 'needs', text: m[0] }), el('dd', { text: m[1] })];
      }))),
      el('h3', { text: G.time_title }), el('p', { text: G.time })];
    return el('section', { 'class': 'card wide', id: 'guide' }, [el('div', { 'class': 'cardhead' }, [el('h2', { text: G.title })])].concat(parts));
  }

  function render() {
    var r = report, s = r.summary, L = r.latency, C = T.cards, S = T.series, H = T.th;
    var buckets = r.volume.map(function (v) { return v.bucket; });
    var empty0 = s.total === 0;
    var focus = document.activeElement && document.activeElement.id;
    var dark = currentTheme() === 'dark';

    var controls = el('div', { 'class': 'controls' });
    var select = el('select', { id: 'window', 'aria-label': T.ui.window }, WINDOWS.map(function (w) {
      var o = el('option', { value: w, text: T.windows[w] }); if (w === r.window) { o.selected = true; } return o;
    }));
    select.addEventListener('change', function () { load(select.value); });
    var box = el('input', { id: 'filter', type: 'search', placeholder: T.ui.filter_placeholder, 'aria-label': T.ui.filter_label, value: filter, dir: 'auto' });
    box.addEventListener('input', function () { filter = box.value.trim().toLowerCase(); render(); });
    var json = el('a', { href: '#', text: T.ui.json });
    json.addEventListener('click', function (e) { e.preventDefault(); location.href = url(r.window, true); });
    var guideLink = el('a', { href: '#guide', text: T.guide.title });
    var themeBtn = el('button', { type: 'button', id: 'theme', 'class': 'btn', 'aria-label': T.ui.theme_label,
      title: T.ui.theme_label, text: dark ? T.ui.theme_to_light : T.ui.theme_to_dark });
    themeBtn.addEventListener('click', function () { setTheme(dark ? 'light' : 'dark'); });
    controls.append(select, box, json, guideLink,
      el('span', { 'class': 'note', id: 'status', text: r.refresh_seconds > 0 ? t(T.ui.refresh_on, [fmt.n(r.refresh_seconds)]) : T.ui.refresh_off }));

    var tiles = el('div', { 'class': 'tiles' }, [
      tile(T.tiles.total, fmt.n(s.total), 'tile_total'), tile(T.tiles.zero_rate, P(s.zero_rate), 'tile_zero_rate'),
      tile(T.tiles.cache, P(r.cache.hit_rate), 'tile_cache'), tile(T.tiles.hybrid, P(r.tier.hybrid_share), 'tile_hybrid'),
      tile(T.tiles.avg, M(L.avg), 'tile_avg'), tile(T.tiles.p95, M(L.p95), 'tile_p95'),
      tile(T.tiles.repeat, P(r.repeat_queries.rate), 'tile_repeat'),
    ]);
    var tileHelpHolder = el('div', { id: 'tilehelp' }, tileHelp ? [helpPanel(tileHelp)] : []);

    var rowsOf = function (list) { return list.map(function (q) { return [q.query, q.searches, q.last_seen]; }); };
    var names = function (list) { return list.map(function (b) { return [b.name, b.searches]; }); };
    var zeroTrend = r.zero_rate_trend, lat = r.latency_trend;
    var grid = el('div', { 'class': 'grid2' }, empty0 ? [card(C.searches, [empty()])] : [
      card(C.volume, [timeChart(T.chart.volume, buckets,
        [{ name: S.searches, cls: 's1', values: r.volume.map(function (v) { return v.searches; }) }], 'bar', fmt.n)], null, 'volume'),
      card(C.zero_rate, [timeChart(T.chart.zero_rate, zeroTrend.map(function (v) { return v.bucket; }),
        [{ name: S.zero_rate, cls: 's2', values: zeroTrend.map(function (v) { return v.zero_rate; }) }], 'line', fmt.pct)], null, 'zero_rate'),
      card(C.latency, [timeChart(T.chart.latency, lat.map(function (v) { return v.bucket; }), [
        { name: S.avg, cls: 's1', values: lat.map(function (v) { return v.avg; }) },
        { name: S.p95, cls: 's2', values: lat.map(function (v) { return v.p95; }) }], 'line', fmt.ms, function (v) { return fmt.n(Math.round(v)); }),
      legend([[S.avg, 's1'], [S.p95, 's2']])], null, 'latency'),
      card(C.heatmap, [heatmap(r.hourly_heatmap)], T.notes.heatmap, 'heatmap'),
      card(C.length, [histogram(r.query_length)], T.notes.length, 'length'),
      card(C.tier_cache, [
        split(S.tier, [{ label: S.keyword_only, value: r.tier.keyword_only, cls: 's1' }, { label: S.hybrid, value: r.tier.hybrid, cls: 's2' }]),
        split(S.cache, [{ label: S.cached, value: r.cache.hits, cls: 's1' }, { label: S.computed, value: r.cache.misses, cls: 's2' }]),
      ], t(T.notes.tier_cache, [fmt.pct(r.did_you_mean.share), fmt.d2(r.summary.avg_results)]), 'tier_cache'),
    ]);

    var tables = el('div', { 'class': 'grid2' }, [
      card(C.top, [table('top', [H.query, H.searches, H.last_seen], ['t', 'n', 'd'], rowsOf(r.top_queries), true)], null, 'top'),
      card(C.zero, [table('zero', [H.query, H.searches, H.last_seen], ['t', 'n', 'd'], rowsOf(r.zero_result_queries), true)],
        T.notes.zero, 'zero'),
      card(C.brands, [table('brands', [H.brand, H.searches], ['t', 'n'], names(r.top_brands_categories.brands), true)],
        T.notes.brands, 'brands'),
      card(C.cats, [table('cats', [H.category, H.searches], ['t', 'n'], names(r.top_brands_categories.categories), true)], null, 'cats'),
    ]);

    // Log-v2 figures: only when the migration ran and the logger writes the new columns.
    var quality = [];
    if (r.log_v2 && r.log_v2.available) {
      var e = r.engagement, c = r.latency_cold, delta = r.compare ? r.compare.metrics : null;
      var extra = [tile(T.tiles.unique, fmt.n(r.unique.searches), 'tile_unique'),
        tile(T.tiles.per_session, fmt.d2(r.unique.searches_per_session), 'tile_per_session'),
        tile(T.tiles.zero_unique, P(r.zero_result.rate), 'tile_zero_unique'), tile(T.tiles.cold_p95, M(c.p95), 'tile_cold_p95'),
        tile(T.tiles.capped, P(r.capped_share.share), 'tile_capped')];
      if (e.tracking) {
        extra.push(tile(T.tiles.ctr, P(e.ctr), 'tile_ctr'), tile(T.tiles.mrr, fmt.d2(e.mrr), 'tile_mrr'),
          tile(T.tiles.cart, P(e.cart_rate), 'tile_cart'));
      }
      tiles.append.apply(tiles, extra);
      if (delta) {
        var fmtMetric = function (k, v) { return /_ms$/.test(k) ? fmt.ms(v) : /rate|share|ctr/.test(k) ? fmt.pct(v) : fmt.n(v); };
        quality.push(card(C.compare, [table('compare', [H.metric, H.now, H.before, H.change], ['t', 'x', 'x', 'x'],
          Object.keys(delta).map(function (k) {
            var d = delta[k];
            return [T.metrics[k] || k, fmtMetric(k, d.current), fmtMetric(k, d.previous),
              d.change_pct === null ? T.ui.new : '\u200E' + (d.change_pct > 0 ? '+' : '') + fmt.d1(d.change_pct) + '%'];
          }), false)], null, 'compare'));
      }
      if (r.tier_timeline.outages.length) {
        quality.push(card(C.outages, [table('outages', [H.from, H.to, H.hours, H.searches], ['d', 'd', 'n', 'n'],
          r.tier_timeline.outages.map(function (o) { return [o.from, o.to, o.hours, o.searches]; }), false)], T.notes.outages, 'outages'));
      }
      quality.push(
        card(C.alias, [table('alias', [H.zero_query, H.then, H.pairs, H.clicks], ['t', 't', 'n', 'n'], r.alias_candidates.map(function (a) {
          return [a.query, a.then, a.pairs, a.clicks]; }), true, T.empty.needs_session)], T.notes.alias, 'alias'),
        card(C.silent, [table('silent', [H.query, H.searches, H.clicks, H.ctr], ['t', 'n', 'n', 'p'], r.silent_failures.map(function (q) {
          return [q.query, q.searches, q.clicks, q.ctr]; }), true, e.tracking ? T.empty.rows : T.empty.needs_events)], T.notes.silent, 'silent'),
        card(C.gap, [table('gap', [H.query, H.searches, H.last_seen], ['t', 'n', 'd'], rowsOf(r.demand_gap), true)], T.notes.gap, 'gap'),
        card(C.trending, [table('trend', [H.query, H.searches, H.previous, H.growth], ['t', 'n', 'n', 'n'], r.trending.map(function (q) {
          return [q.query, q.searches, q.previous, q.growth]; }), true)], null, 'trending'),
        card(C.dym, [table('dym', [H.query, H.suggested, H.shown, H.clicked], ['t', 't', 'n', 'n'], r.did_you_mean.top.map(function (d) {
          return [d.query, d.suggestion, d.shown, d.clicked]; }), true)],
          t(T.notes.dym, [fmt.pct(r.did_you_mean.shown_rate), fmt.pct(r.did_you_mean.ctr), fmt.n(r.did_you_mean.zero_result_suggestions)]), 'dym'),
        card(C.slow, [table('slow', [H.query, H.max_ms, H.searches], ['t', 'n', 'n'], c.slowest.map(function (q) {
          return [q.query, q.max_ms, q.searches]; }), true)], null, 'slow'),
        card(C.probes, [table('probes', [H.time, H.query], ['d', 't'], r.suspicious.samples.map(function (p) { return [p.ts, p.query]; }), false)],
          t(T.notes.probes, [fmt.n(r.suspicious.count)]), 'probes'));
    } else {
      quality.push(el('section', { 'class': 'card wide' }, [note(T.empty.v1)]));
    }

    var headerNote = (r.since ? t(T.ui.since, [dualText(r.since)]) : T.ui.all_time) + ' — ' + dbClockLabel();
    var toolbar = META.embedded ? el('div', {}) : el('div', { 'class': 'topbar' }, [el('h1', { text: T.title }), themeBtn]);
    root.replaceChildren(el('header', {}, [toolbar, el('p', { 'class': 'note', text: headerNote })]),
      controls, tiles, tileHelpHolder, grid, tables, el('div', { 'class': 'grid2' }, quality), guideCard());
    if (focus) { var again = document.getElementById(focus); if (again) { again.focus(); if (again.setSelectionRange && again.value) { again.setSelectionRange(again.value.length, again.value.length); } } }
  }

  function url(window_, json) {
    var p = new URLSearchParams(location.search);
    p.set('window', window_);
    if (new URLSearchParams(location.search).get('compare')) { p.set('compare', '1'); }
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
        if (st) {
          st.textContent = (report.refresh_seconds > 0 ? t(T.ui.refresh_on, [fmt.n(report.refresh_seconds)]) : T.ui.refresh_off)
            + '. ' + t(T.ui.updated, [fmt.now()]);
        }
      })
      .catch(function (err) { if (status) { status.textContent = t(T.ui.refresh_failed, [err.message]); } });
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

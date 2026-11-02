(function () {
  'use strict';
  var KEY = 'admin-theme';
  var root = document.documentElement;
  var btn = document.getElementById('theme');

  function dark() {
    var set = root.getAttribute('data-theme');
    if (set === 'dark' || set === 'light') { return set === 'dark'; }
    return !!(window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
  }
  function label() {
    if (btn) { btn.textContent = dark() ? btn.getAttribute('data-to-light') : btn.getAttribute('data-to-dark'); }
  }
  if (btn) {
    btn.addEventListener('click', function () {
      var next = dark() ? 'light' : 'dark';
      root.setAttribute('data-theme', next);
      try { localStorage.setItem(KEY, next); } catch (e) { /* private mode: the choice lasts this visit */ }
      label();
    });
    label();
  }

  // A table with a text filter and an "only changed" switch: <input data-filter="id">, <input data-changed-only="id">.
  Array.prototype.forEach.call(document.querySelectorAll('input[data-filter]'), function (box) {
    var table = document.getElementById(box.getAttribute('data-filter'));
    var only = document.querySelector('input[data-changed-only="' + box.getAttribute('data-filter') + '"]');
    function apply() {
      var needle = box.value.trim().toLowerCase();
      Array.prototype.forEach.call(table.tBodies[0].rows, function (row) {
        var hit = !needle || row.textContent.toLowerCase().indexOf(needle) !== -1;
        var changed = !only || !only.checked || row.getAttribute('data-changed') === '1';
        row.hidden = !(hit && changed);
      });
    }
    box.addEventListener('input', apply);
    if (only) { only.addEventListener('change', apply); }
  });

  // A page-size <select data-autosubmit> sends its GET form at once (a <noscript> button covers no-JS).
  Array.prototype.forEach.call(document.querySelectorAll('select[data-autosubmit]'), function (box) {
    box.addEventListener('change', function () { if (box.form) { box.form.submit(); } });
  });

  // Every write action asks first: data-confirm on the form, or on the button that was pressed.
  var confirmed = true;
  Array.prototype.forEach.call(document.querySelectorAll('form'), function (form) {
    form.addEventListener('submit', function (e) {
      var by = e.submitter;
      var text = (by && by.getAttribute('data-confirm')) || form.getAttribute('data-confirm');
      confirmed = !text || window.confirm(text);
      if (!confirmed) { e.preventDefault(); }
    });
  });

  // A long action (reload, rollback, VPS reload): disable every button of the page against a second click
  // and show the elapsed seconds. The server runs the action to the end even if this page goes away.
  var busy = document.getElementById('running');
  Array.prototype.forEach.call(document.querySelectorAll('form[data-busy]'), function (form) {
    form.addEventListener('submit', function () {
      if (!confirmed) { return; }
      // Disabling a pressed button would drop its name / value from the request: defer it.
      setTimeout(function () {
        Array.prototype.forEach.call(document.querySelectorAll('button'), function (b) { b.disabled = true; });
      }, 0);
      if (!busy) { return; }
      var started = Date.now();
      var show = function () {
        busy.textContent = busy.getAttribute('data-text').replace('{0}', String(Math.floor((Date.now() - started) / 1000)));
      };
      busy.hidden = false;
      show();
      setInterval(show, 1000);
    });
  });
  // Settings page. Without JavaScript the page is the plain form (Check / Save all). With it: the filter, a
  // "differs from default" switch, collapsed groups, an unsaved-changes counter, and a per-row Apply that posts
  // one key (action=settings_apply_one) and updates the row in place. The server validates and saves; nothing
  // here repeats its rules.
  var sform = document.getElementById('settings-form');
  if (sform) {
    (function () {
      var words = {};
      try { words = JSON.parse(sform.getAttribute('data-apply') || '{}'); } catch (e) { /* the form still works */ }
      var live = !sform.hasAttribute('data-readonly') && !!window.fetch && !!window.URLSearchParams && !!words.apply;
      var filter = document.getElementById('settings-filter');
      var only = document.getElementById('settings-only');
      var onlyLabel = document.getElementById('settings-only-label');
      var pending = document.getElementById('settings-pending');
      var flushNotice = document.getElementById('settings-flush');
      var versionInput = sform.querySelector('input[name="version"]');
      var csrf = sform.querySelector('input[name="csrf"]').value;
      var version = versionInput.value;
      var chain = Promise.resolve();
      var rows = [];
      var badgeClass = { 'panel': 'ok', 'config.php': 'warn', 'default': 'off' };

      function make(tag, cls, text) {
        var el = document.createElement(tag);
        if (cls) { el.className = cls; }
        if (text) { el.textContent = text; }
        return el;
      }
      function button(label, cls, handler) {
        var b = make('button', 'btn ' + (cls || ''), label);
        b.type = 'button';
        b.addEventListener('click', handler);
        return b;
      }
      function compare(c) {
        if (c.field.type === 'checkbox') { return c.field.checked ? '1' : '0'; }
        return c.field.value.replace(/\s*[\r\n]+\s*/g, ',').trim();
      }
      function dirty(c) {
        return compare(c) !== c.field.getAttribute('data-initial')
          || !!(c.reset && c.reset.checked) || !!(c.empty && c.empty.checked);
      }
      function setField(c, text) {
        if (c.field.type === 'checkbox') {
          c.field.checked = text === '1';
          var label = c.field.parentNode.querySelector('span');
          if (label) { label.textContent = c.field.getAttribute(c.field.checked ? 'data-on' : 'data-off'); }
        } else {
          c.field.value = c.field.tagName === 'TEXTAREA' ? text.replace(/,/g, '\n') : text;
        }
      }
      function count() {
        var n = rows.filter(function (c) { return c.row.classList.contains('dirty'); }).length;
        if (pending) {
          pending.hidden = n === 0;
          pending.textContent = words.pending ? words.pending.replace('{0}', n.toLocaleString('fa-IR')) : '';
        }
      }
      function update(c) {
        var d = dirty(c);
        c.row.classList.toggle('dirty', d);
        if (c.mark) { c.mark.hidden = !d; }
        if (c.actions) { c.actions.hidden = !d; }
        count();
      }
      function say(c, kind, text, items, extra) {
        window.clearTimeout(c.timer);
        c.msg.textContent = '';
        if (!text) { return; }
        var box = make('div', 'msg ' + kind);
        box.appendChild(make('div', '', text));
        if (items && items.length) {
          var list = make('ul');
          items.forEach(function (item) { list.appendChild(make('li', '', item.text)); });
          box.appendChild(list);
        }
        if (extra) { box.appendChild(extra); }
        c.msg.appendChild(box);
      }
      function lock(c, on) {
        Array.prototype.forEach.call(c.row.querySelectorAll('.rowact button, .rowmsg button'), function (b) {
          b.disabled = on;
        });
      }
      function syncReset(c, panel) {
        if (panel && !c.reset) {
          var label = make('label', 'note');
          c.reset = make('input');
          c.reset.type = 'checkbox';
          c.reset.name = 'r[' + c.key + ']';
          c.reset.value = '1';
          c.reset.addEventListener('change', function () { edited(c); });
          label.appendChild(c.reset);
          label.appendChild(document.createTextNode(' ' + words.reset_box));
          c.cell.insertBefore(label, c.actions);
        } else if (!panel && c.reset) {
          c.reset.parentNode.parentNode.removeChild(c.reset.parentNode);
          c.reset = null;
        }
      }
      function applied(c, d) {
        version = d.version;
        versionInput.value = d.version;
        c.field.setAttribute('data-initial', d.value);
        setField(c, d.value);
        if (c.reset) { c.reset.checked = false; }
        if (c.empty) { c.empty.checked = false; }
        syncReset(c, d.source === 'panel');
        c.row.setAttribute('data-differs', d.value === d.default ? '0' : '1');
        var badge = c.state.querySelector('.badge:not(.mark)');
        badge.className = 'badge ' + (badgeClass[d.source] || 'off');
        badge.textContent = d.source_label;
        var shown = c.state.querySelectorAll('bdi.trunc');
        shown[0].textContent = d.value === '' ? words.empty_value : d.value;
        shown[1].textContent = d.default === '' ? words.empty_value : d.default;
        update(c);
        say(c, 'ok', d.message);
        c.timer = window.setTimeout(function () { say(c, 'ok', ''); }, 4000);
        if (d.flush && flushNotice) { flushNotice.hidden = false; }
      }
      function answer(c, d) {
        if (d.status === 'saved' || d.status === 'no_change') { applied(c, d); return; }
        if (d.status === 'confirm') {
          say(c, 'warn', d.message, d.warnings, button(words.apply_anyway, '', function () { send(c, true); }));
        } else if (d.status === 'stale') {
          say(c, 'bad', d.message, null, button(words.reload, 'primary', function () { window.location.reload(); }));
        } else {
          say(c, 'bad', d.message || words.network, d.errors);
        }
      }
      function send(c, confirmed) {
        say(c, 'ok', words.working);
        lock(c, true);
        // One request at a time: each answer carries the version the next one must start from.
        chain = chain.then(function () {
          var body = new URLSearchParams();
          body.set('action', 'settings_apply_one');
          body.set('csrf', csrf);
          body.set('key', c.key);
          body.set('value', c.field.type === 'checkbox' ? compare(c) : c.field.value);
          body.set('version', version);
          if (c.reset && c.reset.checked) { body.set('reset', '1'); }
          if (c.empty && c.empty.checked) { body.set('empty', '1'); }
          if (confirmed) { body.set('confirm', '1'); }
          return fetch('?p=settings', {
            method: 'POST', credentials: 'same-origin', body: body,
            headers: { 'X-CSRF-Token': csrf, 'Accept': 'application/json' }
          }).then(function (res) {
            if ((res.headers.get('Content-Type') || '').indexOf('application/json') === -1) { throw new Error('not json'); }
            return res.json();
          }).then(function (d) { answer(c, d); }).catch(function () { say(c, 'bad', words.network); });
        }).then(function () { lock(c, false); });
      }
      function edited(c) {
        say(c, 'ok', '');
        if (c.field.type === 'checkbox') { setField(c, compare(c)); }
        update(c);
      }

      Array.prototype.forEach.call(sform.querySelectorAll('tr.srow'), function (row) {
        var field = row.querySelector('[data-initial]');
        var c = {
          row: row, key: row.getAttribute('data-key'), field: field, cell: row.cells[1], state: row.cells[2],
          mark: row.querySelector('.mark'), reset: row.querySelector('input[name^="r["]'),
          empty: row.querySelector('input[name^="e["]'), actions: null, msg: null, timer: 0
        };
        rows.push(c);
        if (live) {
          c.actions = make('div', 'rowact');
          c.actions.hidden = true;
          c.actions.appendChild(button(words.apply, 'primary', function () { send(c, false); }));
          c.actions.appendChild(document.createTextNode(' '));
          c.actions.appendChild(button(words.undo, '', function () {
            setField(c, field.getAttribute('data-initial'));
            if (c.reset) { c.reset.checked = false; }
            if (c.empty) { c.empty.checked = false; }
            edited(c);
          }));
          c.msg = make('div', 'rowmsg');
          c.msg.setAttribute('role', 'status');
          c.cell.appendChild(c.actions);
          c.cell.appendChild(c.msg);
          if (field.tagName === 'INPUT' && field.type !== 'checkbox') {
            field.addEventListener('keydown', function (e) {
              if (e.key === 'Enter') { e.preventDefault(); if (dirty(c)) { send(c, false); } }
            });
          }
        } else {
          c.msg = make('div');
        }
        [field, c.reset, c.empty].forEach(function (el) {
          if (el) { el.addEventListener('input', function () { edited(c); }); el.addEventListener('change', function () { edited(c); }); }
        });
        update(c);
      });

      // Groups: collapsed unless they hold an unsaved change, an error, or (while filtering) a match.
      function refresh() {
        var needle = filter ? filter.value.trim().toLowerCase() : '';
        var narrow = !!(only && only.checked);
        Array.prototype.forEach.call(sform.querySelectorAll('details.sgroup'), function (group) {
          var trs = group.querySelectorAll('tr.srow');
          if (!trs.length) { return; }
          var visible = 0;
          var keep = 0;
          Array.prototype.forEach.call(trs, function (tr) {
            var hit = !needle || tr.getAttribute('data-search').indexOf(needle) !== -1;
            var differs = !narrow || tr.getAttribute('data-differs') === '1' || tr.classList.contains('dirty');
            tr.hidden = !(hit && differs);
            if (!tr.hidden) { visible++; }
            if (tr.classList.contains('dirty') || tr.querySelector('.msg.bad, .msg.warn')) { keep++; }
          });
          group.hidden = visible === 0;
          group.open = (needle || narrow) ? visible > 0 : keep > 0;
        });
      }
      if (filter) { filter.hidden = false; filter.addEventListener('input', refresh); }
      if (onlyLabel) { onlyLabel.hidden = false; only.addEventListener('change', refresh); }
      refresh();
    })();
  }
})();

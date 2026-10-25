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
})();

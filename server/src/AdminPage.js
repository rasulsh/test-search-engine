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

  // Every write action asks first: <form data-confirm="...">.
  Array.prototype.forEach.call(document.querySelectorAll('form[data-confirm]'), function (form) {
    form.addEventListener('submit', function (e) {
      if (!window.confirm(form.getAttribute('data-confirm'))) { e.preventDefault(); }
    });
  });
})();

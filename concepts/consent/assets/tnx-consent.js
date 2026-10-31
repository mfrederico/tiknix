// The visitor's answer, in the page: tiknixConsent.allows('analytics'), and <script type="text/plain" data-consent="analytics">
// runs only on a yes. The banner's buttons post the answer; the page then reloads, because what may load is decided by its header.
(function () {
  var m = document.cookie.match(/(?:^|;\s*)tiknix_consent=(\d+)\.([a-f0-9]+)\.([a-z-]*)/);
  var yes = m ? m[3].split('-').filter(Boolean) : [];
  window.tiknixConsent = {
    allows: function (g) { return g === 'necessary' || yes.indexOf(g) >= 0; },
    groups: function () { return yes.slice(); }
  };
  document.querySelectorAll('script[type="text/plain"][data-consent]').forEach(function (old) {
    if (!window.tiknixConsent.allows(old.getAttribute('data-consent'))) return;
    var s = document.createElement('script');
    for (var i = 0; i < old.attributes.length; i++) { var a = old.attributes[i]; if (a.name !== 'type' && a.name !== 'data-consent') s.setAttribute(a.name, a.value); }
    s.text = old.text;
    old.parentNode.replaceChild(s, old);
  });

  var box = document.getElementById('tcConsent');
  if (!box) return;
  var err = box.querySelector('.tc-err');
  function answer(how, groups) {
    var body = new URLSearchParams({how: how});
    groups.forEach(function (g) { body.append('groups[]', g); });
    fetch('/consent/choose', {method: 'POST', credentials: 'same-origin', headers: {'X-Requested-With': 'XMLHttpRequest'}, body: body})
      .then(function (r) { return r.json(); })
      .then(function (j) { if (j && j.success) location.reload(); else fail((j && j.message) || 'Your choice was not saved.'); })
      .catch(function () { fail('Your choice was not saved. Please try again.'); });
  }
  function fail(text) { err.textContent = text; err.hidden = false; }
  box.addEventListener('click', function (e) {
    var b = e.target.closest('[data-consent-do]');
    if (!b) return;
    var d = b.getAttribute('data-consent-do');
    if (d === 'choose') {
      box.querySelector('.tc-pick').hidden = false;
      box.querySelector('[data-consent-main]').hidden = true;
      box.querySelector('[data-consent-save]').hidden = false;
    } else if (d === 'pick') {
      answer('pick', Array.prototype.map.call(box.querySelectorAll('[data-consent-group]:checked'), function (c) { return c.getAttribute('data-consent-group'); }));
    } else answer(d, []);
  });
})();

<?php // ui: hand-made — a calendar: days, weeks or a month of messages going out and what came of the ones that did
/** @var ?string $scope @var string $label @var string $backUrl */
$h = fn($v) => htmlspecialchars((string) $v);
$dataUrl = '/messaging/calendardata' . ($scope !== null ? '?scope=' . urlencode($scope) : '');
?>
<?= \app\Ui::page(['title' => 'Message calendar', 'lead' => ($scope !== null ? 'Messages to ' . $label : 'Every message') . ': when they go out and how many people, and what came of the ones that did.',
    'back' => ['label' => $scope !== null ? 'Messages to ' . $label : 'Messages', 'url' => $backUrl]]) ?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3" id="cal-bar">
  <div class="btn-group btn-group-sm" role="group" aria-label="View">
    <button type="button" class="btn btn-outline-secondary" data-view="day">Day</button>
    <button type="button" class="btn btn-outline-secondary" data-view="week">Week</button>
    <button type="button" class="btn btn-outline-secondary active" data-view="month">Month</button>
  </div>
  <div class="btn-group btn-group-sm" role="group" aria-label="Move">
    <button type="button" class="btn btn-outline-secondary" data-move="-1" title="Earlier"><i class="bi bi-chevron-left"></i></button>
    <button type="button" class="btn btn-outline-secondary" data-move="0">Today</button>
    <button type="button" class="btn btn-outline-secondary" data-move="1" title="Later"><i class="bi bi-chevron-right"></i></button>
  </div>
  <div class="fw-semibold" id="cal-title"></div>
  <div class="ms-auto small text-body-secondary" id="cal-sum"></div>
</div>
<div id="cal" class="ui-panel"><div class="ui-panel-body"><div class="text-body-secondary small">Loading…</div></div></div>
<div id="cal-hourly" class="small text-body-secondary mt-2"></div>
<style>
  .cal-grid{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:.25rem}
  .cal-cell{border:1px solid var(--bs-border-color);border-radius:.375rem;padding:.35rem;min-height:5.5rem;background:var(--bs-body-bg);min-width:0}
  .cal-cell.muted{opacity:.55}.cal-cell.today{box-shadow:0 0 0 2px var(--bs-primary) inset}
  .cal-day{font-size:.75rem;color:var(--bs-secondary-color);margin-bottom:.2rem}
  .cal-item{font-size:.75rem;line-height:1.25;border-radius:.25rem;padding:.15rem .3rem;margin-bottom:.2rem;overflow:hidden;text-overflow:ellipsis}
  .cal-item.future{background:var(--bs-primary-bg-subtle);color:var(--bs-primary-text-emphasis)}
  .cal-item.past{background:var(--bs-success-bg-subtle);color:var(--bs-success-text-emphasis)}
  .cal-item .n{font-variant-numeric:tabular-nums;opacity:.85}
  .cal-list .cal-item{font-size:.875rem;padding:.4rem .6rem}
  @media (max-width: 767px){.cal-grid{grid-template-columns:repeat(1,minmax(0,1fr))}.cal-cell{min-height:auto}.cal-cell.empty{display:none}}
</style>
<script>
(function () {
  var dataUrl = <?= json_encode($dataUrl) ?>;
  var view = 'month', anchor = new Date(); anchor.setHours(0,0,0,0);
  try { var v = localStorage.getItem('msgcal.view'); if (v === 'day' || v === 'week' || v === 'month') view = v; } catch (e) {}
  var el = document.getElementById('cal'), title = document.getElementById('cal-title'), sum = document.getElementById('cal-sum'), hourly = document.getElementById('cal-hourly');
  var pad = function (n) { return (n < 10 ? '0' : '') + n; };
  var ymd = function (d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); };
  var addDays = function (d, n) { var x = new Date(d); x.setDate(x.getDate() + n); return x; };
  var esc = function (s) { return String(s).replace(/[&<>"]/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); };
  var fmt = { day: function (d) { return d.toLocaleDateString(undefined, {weekday:'long', year:'numeric', month:'long', day:'numeric'}); },
              month: function (d) { return d.toLocaleDateString(undefined, {year:'numeric', month:'long'}); } };
  function range() {
    if (view === 'day') return [anchor, anchor];
    if (view === 'week') { var s = addDays(anchor, -((anchor.getDay() + 6) % 7)); return [s, addDays(s, 6)]; }   // Monday to Sunday
    var f = new Date(anchor.getFullYear(), anchor.getMonth(), 1), l = new Date(anchor.getFullYear(), anchor.getMonth() + 1, 0);
    return [f, l];
  }
  function itemHtml(it, past) {
    var c = it.campaign, s = esc(c.subject);
    if (past) {
      var eng = it.sent ? Math.round(100 * it.engaged / it.sent) : 0;
      return '<div class="cal-item past" title="' + s + ' — sent ' + it.sent + ', delivered ' + it.delivered + ', opened ' + it.opened + ', engaged ' + it.engaged + ' (clicked, replied or acted), unsubscribed ' + it.unsubscribed + ', bounced ' + it.bounced + '">'
        + '<a class="text-reset text-decoration-none" href="/messaging/' + (c.scope ? 'message' : 'view') + '?id=' + c.id + '">' + s + '</a> <span class="n">· sent ' + it.sent + ' · engaged ' + it.engaged + (it.sent ? ' (' + eng + '%)' : '') + ' · unsubs ' + it.unsubscribed + '</span></div>';
    }
    return '<div class="cal-item future" title="' + s + ' — ' + esc(it.why) + ', about ' + it.estimate + ' people">'
      + '<a class="text-reset text-decoration-none" href="/messaging/' + (c.scope ? 'message' : 'view') + '?id=' + c.id + '">' + s + '</a> <span class="n">· ~' + it.estimate + ' · ' + esc(it.why) + '</span></div>';
  }
  function render(data) {
    var byDay = {};
    data.past.forEach(function (p) { (byDay[p.date] = byDay[p.date] || {past: [], future: []}).past.push(p); });
    data.future.forEach(function (f) { (byDay[f.date] = byDay[f.date] || {past: [], future: []}).future.push(f); });
    var r = range(), html = '', today = ymd(new Date());
    if (view === 'month') {
      var first = r[0], lead = (first.getDay() + 6) % 7, start = addDays(first, -lead);
      html = '<div class="cal-grid">' + ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'].map(function (d) { return '<div class="cal-day d-none d-md-block fw-semibold">' + d + '</div>'; }).join('');
      for (var i = 0; i < 42; i++) {
        var d = addDays(start, i), k = ymd(d), inMonth = d.getMonth() === first.getMonth(), items = byDay[k] || {past: [], future: []};
        if (i >= 35 && d.getMonth() !== first.getMonth()) break;
        html += '<div class="cal-cell' + (inMonth ? '' : ' muted') + (k === today ? ' today' : '') + (!items.past.length && !items.future.length ? ' empty' : '') + '"><div class="cal-day">' + d.getDate() + (d.getDate() === 1 || i === 0 ? ' ' + d.toLocaleDateString(undefined, {month:'short'}) : '') + '</div>'
          + items.past.map(function (it) { return itemHtml(it, true); }).join('') + items.future.map(function (it) { return itemHtml(it, false); }).join('') + '</div>';
      }
      html += '</div>';
    } else {
      html = '<div class="cal-list">';
      for (var d2 = r[0]; d2 <= r[1]; d2 = addDays(d2, 1)) {
        var k2 = ymd(d2), it2 = byDay[k2] || {past: [], future: []};
        html += '<div class="mb-3"><div class="fw-semibold small ' + (k2 === today ? 'text-primary' : '') + '">' + esc(fmt.day(d2)) + (k2 === today ? ' — today' : '') + '</div>'
          + (it2.past.length || it2.future.length ? it2.past.map(function (it) { return itemHtml(it, true); }).join('') + it2.future.map(function (it) { return itemHtml(it, false); }).join('') : '<div class="small text-body-secondary">Nothing goes out.</div>') + '</div>';
      }
      html += '</div>';
    }
    el.innerHTML = '<div class="ui-panel-body">' + html + '</div>';
    var tp = {sent: 0, engaged: 0, unsubscribed: 0}, tf = {messages: 0, people: 0};
    data.past.forEach(function (p) { tp.sent += p.sent; tp.engaged += p.engaged; tp.unsubscribed += p.unsubscribed; });
    data.future.forEach(function (f) { tf.messages++; tf.people += f.estimate; });
    sum.textContent = 'Going out: ' + tf.messages + ' message' + (tf.messages === 1 ? '' : 's') + ' to about ' + tf.people + ' people · Went out: sent ' + tp.sent + ', engaged ' + tp.engaged + (tp.sent ? ' (' + Math.round(100 * tp.engaged / tp.sent) + '%)' : '') + ', unsubscribed ' + tp.unsubscribed;
    hourly.innerHTML = data.hourly.length ? '<i class="bi bi-clock me-1"></i>Evaluated hourly by the app, no fixed day: ' + data.hourly.map(function (x) { return esc(x.campaign.subject) + ' (up to ' + x.eligible + ' eligible)'; }).join(' · ') : '';
  }
  function load() {
    var r = range();
    title.textContent = view === 'day' ? fmt.day(anchor) : (view === 'week' ? fmt.day(r[0]).replace(/^[^,]+, /, '') + ' – ' + fmt.day(r[1]).replace(/^[^,]+, /, '') : fmt.month(anchor));
    document.querySelectorAll('[data-view]').forEach(function (b) { b.classList.toggle('active', b.getAttribute('data-view') === view); });
    el.innerHTML = '<div class="ui-panel-body"><div class="text-body-secondary small">Loading…</div></div>';
    fetch(dataUrl + (dataUrl.indexOf('?') > -1 ? '&' : '?') + 'from=' + ymd(r[0]) + '&to=' + ymd(r[1]), {headers: {'Accept': 'application/json'}})
      .then(function (x) { return x.json(); })
      .then(function (j) { if (!j || !j.ok) throw new Error((j && j.error) || 'no answer'); render(j); })
      .catch(function (e) { el.innerHTML = '<div class="ui-panel-body"><div class="text-danger small">The calendar could not be loaded: ' + esc(e.message) + '</div></div>'; sum.textContent = ''; });
  }
  document.querySelectorAll('[data-view]').forEach(function (b) { b.addEventListener('click', function () { view = b.getAttribute('data-view'); try { localStorage.setItem('msgcal.view', view); } catch (e) {} load(); }); });
  document.querySelectorAll('[data-move]').forEach(function (b) { b.addEventListener('click', function () {
    var n = parseInt(b.getAttribute('data-move'), 10);
    if (n === 0) { anchor = new Date(); anchor.setHours(0,0,0,0); }
    else if (view === 'month') anchor = new Date(anchor.getFullYear(), anchor.getMonth() + n, 1);
    else anchor = addDays(anchor, n * (view === 'week' ? 7 : 1));
    load();
  }); });
  load();
})();
</script>

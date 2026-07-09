<?php
/**
 * Nav notification bell — polls /communications/unreadjson for the viewer's
 * scoped unread total and recent threads. Renders a badge (pulses when the
 * count goes UP) and a dropdown of recent conversations. Self-contained:
 * markup + scoped CSS + poller. Included from layouts/header.php for logged-in
 * users. The count starts empty and is filled by the first poll on load, so
 * this partial adds no per-request DB query to the global header.
 *
 * Exposes window.notifyBellRefresh() so the thread view can nudge it the instant
 * a new message lands, without waiting for the 30s interval.
 */
?>
<li class="nav-item dropdown" id="notify-bell">
    <a class="nav-link position-relative" href="#" role="button"
       data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-label="Messages">
        <i class="bi bi-bell"></i>
        <span class="notify-bell-count badge rounded-pill bg-danger position-absolute top-0 start-100 translate-middle d-none">0</span>
    </a>
    <ul class="dropdown-menu dropdown-menu-end notify-bell-menu shadow">
        <li class="dropdown-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-chat-left-dots me-1"></i>Communications</span>
            <span class="notify-bell-pill badge bg-secondary-subtle text-secondary-emphasis">All clear</span>
        </li>
        <li><hr class="dropdown-divider my-1"></li>
        <li><ul class="notify-bell-list list-unstyled mb-0"></ul></li>
        <li class="notify-bell-empty text-center text-muted small py-3">Nothing new right now.</li>
        <li><hr class="dropdown-divider my-1"></li>
        <li><a class="dropdown-item text-center small text-primary" href="/communications">Open Communications</a></li>
    </ul>
</li>

<style>
#notify-bell .notify-bell-count { font-size: .6rem; }
#notify-bell .notify-bell-menu { min-width: 320px; max-width: 360px; }
#notify-bell .notify-bell-row {
    display: flex; gap: .5rem; align-items: flex-start;
    padding: .5rem .9rem; text-decoration: none; color: inherit;
}
#notify-bell .notify-bell-row:hover { background: var(--bs-tertiary-bg); }
#notify-bell .notify-bell-dot {
    width: 8px; height: 8px; border-radius: 50%; background: var(--bs-primary);
    margin-top: .35rem; flex-shrink: 0; visibility: hidden;
}
#notify-bell .notify-bell-row.unread .notify-bell-dot { visibility: visible; }
#notify-bell .notify-bell-row.unread .notify-bell-subject { font-weight: 700; }
#notify-bell .notify-bell-subject { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
#notify-bell .notify-bell-preview {
    font-size: .78rem; color: var(--bs-secondary-color);
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
@keyframes notifyBellPulse {
    0%, 100% { transform: translate(-50%, -50%) scale(1); }
    40%      { transform: translate(-50%, -50%) scale(1.4); }
    70%      { transform: translate(-50%, -50%) scale(0.9); }
}
#notify-bell .notify-bell-count.pulse { animation: notifyBellPulse 900ms ease-out; }
</style>

<script>
(function () {
    var wrap = document.getElementById('notify-bell');
    if (!wrap) return;
    var POLL_MS = 30000;
    var badge = wrap.querySelector('.notify-bell-count');
    var pill  = wrap.querySelector('.notify-bell-pill');
    var list  = wrap.querySelector('.notify-bell-list');
    var empty = wrap.querySelector('.notify-bell-empty');
    var prev  = 0;

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' })[c];
        });
    }
    function relTime(ts) {
        if (!ts) return '';
        var t = Date.parse(String(ts).replace(' ', 'T'));
        if (!t) return '';
        var d = Math.max(0, Math.floor((Date.now() - t) / 1000));
        if (d < 60)    return d + 's';
        if (d < 3600)  return Math.floor(d / 60) + 'm';
        if (d < 86400) return Math.floor(d / 3600) + 'h';
        return Math.floor(d / 86400) + 'd';
    }

    function applyCount(n) {
        if (n > 0) {
            badge.textContent = n > 99 ? '99+' : String(n);
            badge.classList.remove('d-none');
            if (n > prev) {                       // pulse only when it grows
                badge.classList.remove('pulse');
                void badge.offsetWidth;           // reflow to restart the animation
                badge.classList.add('pulse');
            }
        } else {
            badge.classList.add('d-none');
        }
        prev = n;
        if (pill) {
            pill.textContent = n > 0 ? (n + ' unread') : 'All clear';
            pill.classList.toggle('bg-danger-subtle', n > 0);
            pill.classList.toggle('text-danger-emphasis', n > 0);
        }
    }

    function renderList(threads) {
        threads = threads || [];
        if (!threads.length) { list.innerHTML = ''; empty.classList.remove('d-none'); return; }
        empty.classList.add('d-none');
        list.innerHTML = threads.map(function (t) {
            var unread = (t.unread_count || 0) > 0;
            return '<li><a class="notify-bell-row ' + (unread ? 'unread' : '') + '" href="/communications/thread/' + t.id + '">' +
                '<span class="notify-bell-dot"></span>' +
                '<span class="flex-grow-1 min-w-0" style="min-width:0;">' +
                    '<span class="notify-bell-subject d-block">' + esc(t.subject || '(no subject)') + '</span>' +
                    '<span class="notify-bell-preview d-block">' + esc(t.preview || t.who || '') + '</span>' +
                '</span>' +
                '<span class="text-muted small flex-shrink-0">' + esc(relTime(t.last_message_at)) + '</span>' +
            '</a></li>';
        }).join('');
    }

    function poll() {
        fetch('/communications/unreadjson', {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (data) {
            if (!data) return;
            applyCount(parseInt(data.unread || 0, 10));
            renderList(data.threads);
            // Broadcast to any live thread-list rail on the page (comms pages).
            document.dispatchEvent(new CustomEvent('comms:threads', { detail: data.threads || [] }));
        })
        .catch(function () { /* swallow — next tick retries */ });
    }

    setInterval(poll, POLL_MS);
    window.addEventListener('focus', poll);
    window.notifyBellRefresh = poll;   // let the thread poller trigger us instantly
    poll();                            // initial fill
})();
</script>

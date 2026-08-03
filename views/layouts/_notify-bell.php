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
 *
 * Also the one place live delivery is started, because it is the one component
 * already on every page for a signed-in member. When the broker is reachable a
 * wake arrives in milliseconds and drives the poll, which drops the interval to
 * a safety net; when it is not, the interval alone is unchanged and still the
 * guarantee. Nothing below depends on MQTT being configured.
 *
 * The credential is derived, not stored (app\Mqtt::passwordFor), and grants read
 * on tnx/<this member>/# and nothing else — so it is no more sensitive than the
 * session cookie already sitting in the same document.
 */
// member_id() rather than a bare $__mid ?? 0: this partial is only ever reached
// inside header.php's signed-in branch, so a missing id is a broken include and
// not a guest. Falling back to 0 would quietly turn live delivery off and leave
// nothing to find later.
$__mqtt = \app\Mqtt::browserCredentials(member_id($member, 'notify bell'));
?>
<?php if ($__mqtt): ?>
<script src="/js/tnx-live.js"></script>
<script>window.__tnxLiveCfg = <?= json_encode($__mqtt, JSON_UNESCAPED_SLASHES) ?>;</script>
<?php endif; ?>
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
            maybeNotify(data);
        })
        .catch(function () { /* swallow — next tick retries */ });
    }

    /* Browser notifications.
     *
     * Only while the tab is HIDDEN — telling someone about a message they are looking at
     * is noise, and noise is how a notification permission gets revoked.
     *
     * Permission is never requested on page load. A prompt nobody asked for is the thing
     * everyone dismisses forever, and once dismissed it cannot be asked again. It is
     * requested from a click on the bell instead, where the person has just expressed
     * interest in being told about messages.
     */
    var lastUnread = null, lastMentions = null;

    function canNotify() {
        return ('Notification' in window) && Notification.permission === 'granted';
    }

    function maybeNotify(data) {
        var unread   = parseInt(data.unread || 0, 10);
        var mentions = parseInt(data.mentions || 0, 10);

        // First poll of the page establishes the baseline; it is not news.
        if (lastUnread === null) { lastUnread = unread; lastMentions = mentions; return; }

        var newMentions = mentions > lastMentions;
        var newUnread   = unread > lastUnread;
        lastUnread = unread; lastMentions = mentions;

        if (!document.hidden || !canNotify()) return;
        if (!newMentions && !newUnread) return;

        var top = (data.threads || [])[0] || {};
        var title = newMentions
            ? 'You were mentioned'
            : (top.subject || 'New message');
        var body = (top.preview || top.who || '').slice(0, 140);

        try {
            var n = new Notification(title, {
                body: body,
                tag: 'tiknix-comms',      // collapse a burst into one, rather than stacking
                renotify: newMentions
            });
            n.onclick = function () {
                window.focus();
                if (top.id) window.location = '/communications/thread/' + top.id;
                n.close();
            };
        } catch (e) { /* some browsers throw outside a service worker; not worth breaking the poll */ }
    }

    // Asking at the moment of interest, not on arrival.
    document.addEventListener('click', function (e) {
        if (!e.target.closest || !e.target.closest('#notify-bell')) return;
        if (!('Notification' in window) || Notification.permission !== 'default') return;
        Notification.requestPermission();
    });

    // ---- live delivery -------------------------------------------------------
    // MQTT decides WHEN to fetch; it never carries what was said. A wake is ids
    // only, so everything below still goes through poll() and the server's own
    // authorisation — the broker cannot be used to show somebody a message they
    // would not otherwise be handed.
    var live = false;
    if (window.TnxLive && window.__tnxLiveCfg) {
        live = TnxLive.start(window.__tnxLiveCfg);
        TnxLive.onWake(function (data) {
            poll();
            // Let the open thread view react to its own messages without giving
            // this component any knowledge of what a thread view is.
            document.dispatchEvent(new CustomEvent('tnx:wake', { detail: data || {} }));
        });
    }

    var ticks = 0;
    setInterval(function () {
        ticks++;
        // Connected, so a wake already covers anything that arrives. Keep a slow
        // beat anyway — a dropped wake would otherwise be invisible until the
        // next page load, and "usually instant, occasionally never" is worse than
        // consistently slow.
        if (live && TnxLive.isConnected() && (ticks % 4) !== 0) return;
        poll();
    }, POLL_MS);

    window.addEventListener('focus', poll);
    window.notifyBellRefresh = poll;   // let the thread poller trigger us instantly
    poll();                            // initial fill
})();
</script>

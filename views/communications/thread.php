<?php
/**
 * Communications hub — thread-list rail + open conversation detail.
 *
 * @var array  $threads
 * @var int    $activeId
 * @var string $search
 * @var object $thread
 * @var array  $messages
 * @var array  $attachments  keyed by notify id
 * @var object|null $related
 * @var bool   $isAdmin
 * @var int    $unreadTotal
 */
$ownerName = '';
if (!empty($thread->ownerMemberId)) {
    $owner = \app\Bean::load('member', (int)$thread->ownerMemberId);
    if ($owner->id) $ownerName = $owner->username ?: $owner->email;
}
/* Who this conversation is WITH — used for the avatar initials and the sub-line.
 *
 * The email fields only answer that for email threads. A room is addressed to no inbox
 * and a DM's other side is a member, so both fell through to "Unknown" with a "U" avatar,
 * which is worse than saying nothing. */
$headWho = '';
$__me    = member_id($member ?? null, 'communications/thread');
switch ((string)$thread->kind) {
    case 'room':
        $__t = $thread->teamId ? \app\Bean::load('team', (int)$thread->teamId) : null;
        $headWho = ($__t && $__t->id) ? (string)$__t->name : 'Team';
        break;
    case 'dm':
        $__others = array_values(array_filter(
            \app\ThreadMembers::participants((int)$thread->id), fn($id) => $id !== $__me));
        $headWho = $__others
            ? member_display_name(\app\Bean::load('member', $__others[0]), 'Someone')
            : 'Just you';
        break;
    default:
        $headWho = $thread->recipientName ?: $thread->recipientEmail ?: 'Unknown';
}
?>
<?php include __DIR__ . '/_styles.php'; ?>

<div class="comms-hub container-fluid py-4">

    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
            <h1 class="h3 fw-bold mb-0">
                <i class="bi bi-chat-left-dots"></i> Communications
                <?php if (!empty($unreadTotal)): ?>
                    <span class="badge bg-danger align-middle"><?= (int)$unreadTotal ?> unread</span>
                <?php endif; ?>
            </h1>
        </div>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#comms-compose-modal">
            <i class="bi bi-pencil-square me-1"></i>New Conversation
        </button>
    </div>

    <div class="row g-3">
        <?php $railClass = 'comms-rail-hide-mobile'; ?>
        <?php include __DIR__ . '/_thread-list.php'; ?>

        <div class="col-lg-8">
            <div class="card border-0 shadow-sm comms-panel">

                <!-- conversation header -->
                <div class="card-header bg-body-tertiary d-flex align-items-center gap-2">
                    <a href="/communications" class="btn btn-sm btn-outline-secondary d-lg-none">
                        <i class="bi bi-arrow-left"></i>
                    </a>
                    <span class="comms-avatar"><?= htmlspecialchars((comms_initials($headWho)) ?? '') ?></span>
                    <div class="min-w-0 flex-grow-1">
                        <?php
                        /* "#general" on its own is ambiguous the moment you are on more than
                           one team — every team has one. The team name is what distinguishes
                           them, so it belongs in the title, not only in the line beneath. */
                        $headTeam = '';
                        if ((string)$thread->kind === 'dm') {
                            // A DM has no subject on purpose — it is named by who is in it.
                            // "(no subject)" is the email default leaking into something
                            // that was never addressed to a subject line.
                            $headTitle = $headWho;
                        } else {
                            $headTitle = (string)($thread->subject ?: '(no subject)');
                            if ((string)$thread->kind === 'room' && !empty($thread->teamId)) {
                                $t = \app\Bean::load('team', (int)$thread->teamId);
                                if ($t->id) $headTeam = (string)$t->name;
                            }
                        }
                        ?>
                        <div class="fw-semibold text-truncate">
                            <?= htmlspecialchars($headTitle) ?>
                            <?php if ($headTeam !== ''): ?>
                                <span class="text-body-secondary fw-normal">· <?= htmlspecialchars($headTeam) ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="small text-muted text-truncate">
                            <?= count($messages) ?> msg<?= count($messages) === 1 ? '' : 's' ?>
                            <?php
                            /* Do not repeat what the title just said. For a room the team is
                               already up there, so the useful second fact is how many people
                               are in it; "owned by <person>" is actively wrong for a room,
                               which belongs to the team rather than to whoever created it. */
                            if ((string)$thread->kind === 'room'):
                                $n = count(\app\ThreadMembers::participants((int)$thread->id));
                                ?> · <?= $n ?> member<?= $n === 1 ? '' : 's' ?><?php
                            elseif ((string)$thread->kind !== 'dm'): ?>
                                · <?= htmlspecialchars(($headWho) ?? '') ?>
                                <?php if ($ownerName !== ''): ?> · owned by <?= htmlspecialchars(($ownerName) ?? '') ?><?php endif; ?>
                            <?php endif; ?>
                            <?php if (!empty($related)): ?>
                                · <span class="badge bg-info-subtle text-info-emphasis"><?= htmlspecialchars(($thread->relatedType) ?? '') ?> #<?= (int)$thread->relatedId ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if (($thread->status ?? 'open') === 'closed'): ?>
                        <span class="badge bg-secondary">closed</span>
                    <?php endif; ?>
                </div>

                <!-- message feed -->
                <div class="card-body comms-scroll flex-grow-1" id="comms-feed">
                    <?php if (empty($messages)): ?>
                        <div class="text-center text-muted py-5">No messages yet.</div>
                    <?php else: ?>
                        <?php foreach ($messages as $m): ?>
                            <?php
                                /* "Mine" is what decides the side, and direction only answers
                                   that for email — where "out" means this system sent it.
                                   postInApp() writes direction='out' for EVERY in-app
                                   message, because from the application's point of view it
                                   is always outbound, so in a room everybody's messages
                                   rendered as though they were yours.

                                   For anything with a sender account, compare that account
                                   to the reader. Fall back to direction for email, which has
                                   no sender_member_id. */
                                $sender   = (int)($m->senderMemberId ?? 0);
                                $isOut    = $sender > 0 ? ($sender === $__me) : ($m->direction === 'out');
                                $isSystem = $m->notifyType === 'system';
                                $when     = $m->createdAt ? date('M j, g:i a', strtotime($m->createdAt)) : '';
                                $atts     = $attachments[(int)$m->id] ?? [];
                                $who      = $m->fromName ?: $m->fromEmail ?: ($isOut ? 'You' : 'Them');
                            ?>
                            <?php if ($isSystem): ?>
                                <div class="comms-msg-system" data-msg-id="<?= (int)$m->id ?>">
                                    <span class="comms-msg-system-inner">
                                        <i class="bi bi-info-circle me-1"></i><?= $m->content ?>
                                        <span class="ms-1 opacity-75"><?= htmlspecialchars(($when) ?? '') ?></span>
                                    </span>
                                </div>
                            <?php else: ?>
                                <div class="comms-msg-row d-flex <?= $isOut ? 'justify-content-end' : 'justify-content-start' ?> align-items-end gap-2" data-msg-id="<?= (int)$m->id ?>">
                                    <?php if (!$isOut): ?>
                                        <span class="comms-avatar sm"><?= htmlspecialchars((comms_initials($who)) ?? '') ?></span>
                                    <?php endif; ?>
                                    <div class="comms-msg-bubble-wrap">
                                        <div class="comms-msg-meta <?= $isOut ? 'text-end' : '' ?>">
                                            <?= htmlspecialchars(($who) ?? '') ?> · <?= htmlspecialchars(($when) ?? '') ?>
                                            <?php if ($m->status === 'failed'): ?>
                                                <span class="text-danger"><i class="bi bi-exclamation-triangle"></i> failed</span>
                                            <?php elseif ($isOut && $m->status === 'sent'): ?>
                                                <i class="bi bi-check2 text-success" title="sent"></i>
                                            <?php endif; ?>
                                        </div>
                                        <div class="comms-msg-bubble <?= $isOut ? 'out' : 'in' ?>">
                                            <?php /* Highlight only names that RESOLVED to a
                                                     participant — marking up every @word
                                                     would make an unmatched name look as
                                                     though it had reached somebody. */ ?>
                                            <?= \app\Mentions::highlight((string)$m->content,
                                                    \app\ThreadMembers::participants((int)$thread->id),
                                                    $__me) ?>
                                            <?php if ($m->status === 'failed' && $m->errorMessage): ?>
                                                <div class="text-danger small mt-1 border-top pt-1"><?= htmlspecialchars(($m->errorMessage) ?? '') ?></div>
                                            <?php endif; ?>
                                            <?php if (!empty($atts)): ?>
                                                <div class="mt-2 pt-2 border-top">
                                                    <?php foreach ($atts as $a): ?>
                                                        <a href="<?= htmlspecialchars(($a->diskPath) ?? '') ?>" target="_blank" rel="noopener"
                                                           class="badge bg-secondary-subtle text-secondary-emphasis border text-decoration-none me-1">
                                                            <i class="bi bi-paperclip"></i> <?= htmlspecialchars(($a->filename) ?? '') ?>
                                                        </a>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php if ($isOut): ?>
                                        <span class="comms-avatar sm"><?= htmlspecialchars((comms_initials($who)) ?? '') ?></span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- composer -->
                <div class="comms-composer p-2">
                    <form method="post" action="/communications/reply/<?= (int)$thread->id ?>">
                        <?= csrf_field() ?>
                        <div class="input-group">
                            <textarea name="body" class="form-control" rows="1"
                                      placeholder="Write a message…" required
                                      style="resize:none; max-height:120px;"
                                      oninput="this.style.height='auto';this.style.height=Math.min(this.scrollHeight,120)+'px';"
                                      id="comms-body" autocomplete="off"></textarea>
                            <?php /* Autocomplete list, positioned by the script below. */ ?>
                            <?php /* max-height and position are set by place() at open time,
                                     from the space actually available. */ ?>
                            <div id="comms-at" class="dropdown-menu p-1" style="overflow-y:auto"></div>
                            <button type="submit" class="btn btn-primary" title="Send">
                                <i class="bi bi-send"></i>
                            </button>
                        </div>
                    </form>
                </div>

            </div>
        </div>
    </div>

</div>


<script>
/* @mention autocomplete.
 *
 * Roster comes from the THREAD's participants, not from every account — you cannot
 * mention someone who is not in the conversation, so offering them would be offering
 * something that silently does nothing. Fetched once on first '@' rather than on page
 * load; most messages contain no mention at all.
 */
(function () {
    var box  = document.getElementById('comms-body');
    var menu = document.getElementById('comms-at');
    if (!box || !menu) return;

    var THREAD = <?= (int)$thread->id ?>;
    var roster = null, active = -1, start = -1;

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c];
        });
    }
    function hide() { menu.classList.remove('show'); active = -1; start = -1; }

    /* Position the menu.
     *
     * position:fixed, not absolute. The composer sits inside .comms-panel, which is
     * overflow:hidden so the message list can scroll inside it — and an absolutely
     * positioned child of an overflow:hidden ancestor gets clipped by it. The menu was
     * losing its bottom rows to exactly that.
     *
     * It also opens UPWARD. The composer is pinned to the bottom of the panel, so a menu
     * that drops down has nowhere to go; above the box is where the room actually is. It
     * flips back down only if the space above is somehow smaller.
     */
    function place() {
        var r = box.getBoundingClientRect();
        var above = r.top - 8;
        var below = window.innerHeight - r.bottom - 8;
        var up = above >= below;
        var max = Math.max(120, Math.min(260, (up ? above : below)));

        menu.style.position  = 'fixed';
        menu.style.left      = Math.round(r.left) + 'px';
        menu.style.width     = Math.round(Math.min(320, r.width)) + 'px';
        menu.style.maxHeight = Math.round(max) + 'px';
        menu.style.zIndex    = '1080';          // above the panel, below a modal backdrop

        if (up) {
            menu.style.top    = 'auto';
            menu.style.bottom = Math.round(window.innerHeight - r.top + 4) + 'px';
        } else {
            menu.style.bottom = 'auto';
            menu.style.top    = Math.round(r.bottom + 4) + 'px';
        }
    }

    function render(matches) {
        if (!matches.length) { hide(); return; }
        menu.innerHTML = matches.map(function (m, i) {
            return '<button type="button" class="dropdown-item small text-truncate' + (i === 0 ? ' active' : '') +
                   '" data-handle="' + esc(m.handle) + '">' +
                   '<strong>@' + esc(m.handle) + '</strong> <span class="text-body-secondary">' +
                   esc(m.name) + '</span></button>';
        }).join('');
        active = 0;
        menu.classList.add('show');
        place();
    }

    // The box grows as you type, and the page can scroll under a fixed menu.
    window.addEventListener('resize', function () { if (menu.classList.contains('show')) place(); });
    window.addEventListener('scroll', function () { if (menu.classList.contains('show')) place(); }, true);

    function currentToken() {
        var pos = box.selectionStart;
        var upto = box.value.slice(0, pos);
        var m = upto.match(/(?:^|\s)@([A-Za-z0-9_.-]*)$/);
        if (!m) return null;
        start = pos - m[1].length - 1;      // index of the '@'
        return m[1].toLowerCase();
    }

    function choose(handle) {
        if (start < 0) return;
        var pos = box.selectionStart;
        box.value = box.value.slice(0, start) + '@' + handle + ' ' + box.value.slice(pos);
        var caret = start + handle.length + 2;
        box.setSelectionRange(caret, caret);
        hide();
        box.focus();
    }

    box.addEventListener('input', function () {
        var token = currentToken();
        if (token === null) { hide(); return; }

        var go = function () {
            render((roster || []).filter(function (m) {
                return !token || m.handle.toLowerCase().indexOf(token) === 0
                    || m.name.toLowerCase().indexOf(token) === 0;
            }).slice(0, 8));
        };

        if (roster) { go(); return; }
        fetch('/communications/roster?thread=' + THREAD, { credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : []; })
            .then(function (list) { roster = list || []; go(); })
            .catch(function () { roster = []; });   // never leave it null, or every keystroke refetches
    });

    box.addEventListener('keydown', function (e) {
        if (!menu.classList.contains('show')) return;
        var items = menu.querySelectorAll('.dropdown-item');
        if (!items.length) return;

        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            items[active] && items[active].classList.remove('active');
            active = (active + (e.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length;
            items[active].classList.add('active');
            items[active].scrollIntoView({ block: 'nearest' });
        } else if (e.key === 'Enter' || e.key === 'Tab') {
            // Enter completes the name rather than sending — sending a half-typed
            // mention is the more annoying of the two mistakes.
            e.preventDefault();
            choose(items[active].dataset.handle);
        } else if (e.key === 'Escape') {
            hide();
        }
    });

    menu.addEventListener('click', function (e) {
        var b = e.target.closest('.dropdown-item');
        if (b) choose(b.dataset.handle);
    });
    document.addEventListener('click', function (e) {
        if (!menu.contains(e.target) && e.target !== box) hide();
    });
})();
</script>

<?php include __DIR__ . '/_compose-modal.php'; ?>

<script>
// Live thread — appends only NEW messages every 10s. Scoped to #comms-feed so
// nothing else on the page re-renders; scroll position is preserved unless the
// viewer was already pinned to the bottom. Ported from the dealeryes pattern.
(function () {
    var feed = document.getElementById('comms-feed');
    if (!feed) return;

    var threadId = <?= (int)$thread->id ?>;
    var ME       = <?= $__me ?>;   // who is reading, to decide "mine" (never defaulted)
    var POLL_MS  = 10000;

    // Newest message id currently in the DOM (0 if the feed is empty).
    function currentLastId() {
        var last = 0;
        feed.querySelectorAll('[data-msg-id]').forEach(function (el) {
            var n = parseInt(el.getAttribute('data-msg-id'), 10) || 0;
            if (n > last) last = n;
        });
        return last;
    }
    var lastId = currentLastId();

    // Start pinned to the newest message (replaces the old scroll-on-load).
    feed.scrollTop = feed.scrollHeight;

    function atBottom() {
        return (feed.scrollHeight - feed.clientHeight - feed.scrollTop) < 24;
    }

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' })[c];
        });
    }
    function initials(name) {
        var p = String(name || '').trim().split(/\s+/).filter(Boolean);
        if (!p.length) return '?';
        return (p[0][0] + (p.length > 1 ? p[p.length - 1][0] : '')).toUpperCase();
    }
    function fmt(ts) {
        if (!ts) return '';
        var d = new Date(String(ts).replace(' ', 'T'));
        if (isNaN(d)) return esc(ts);
        return d.toLocaleString(undefined, { month:'short', day:'numeric', hour:'numeric', minute:'2-digit' });
    }

    // Build a bubble that mirrors the server-rendered markup (see thread.php).
    function renderMessage(m) {
        var when = fmt(m.ts);
        if (m.notify_type === 'system') {
            var sys = document.createElement('div');
            sys.className = 'comms-msg-system';
            sys.setAttribute('data-msg-id', m.id);
            sys.innerHTML = '<span class="comms-msg-system-inner">' +
                '<i class="bi bi-info-circle me-1"></i>' + (m.content || '') +
                '<span class="ms-1 opacity-75">' + esc(when) + '</span></span>';
            return sys;
        }
        // Same rule as the server render: a sender account decides the side, and
        // direction is only the fallback for email, which has no sender account.
        var sender = parseInt(m.sender_member_id || 0, 10);
        var isOut  = sender > 0 ? (sender === ME) : (m.direction === 'out');
        var who    = m.from_name || (isOut ? 'You' : 'Them');
        var avatar = '<span class="comms-avatar sm">' + esc(initials(who)) + '</span>';
        var statusHtml = '';
        if (m.status === 'failed')       statusHtml = ' <span class="text-danger"><i class="bi bi-exclamation-triangle"></i> failed</span>';
        else if (isOut && m.status === 'sent') statusHtml = ' <i class="bi bi-check2 text-success" title="sent"></i>';
        var errHtml = (m.status === 'failed' && m.error)
            ? '<div class="text-danger small mt-1 border-top pt-1">' + esc(m.error) + '</div>' : '';

        var row = document.createElement('div');
        row.className = 'comms-msg-row d-flex ' + (isOut ? 'justify-content-end' : 'justify-content-start') + ' align-items-end gap-2';
        row.setAttribute('data-msg-id', m.id);
        row.innerHTML =
            (isOut ? '' : avatar) +
            '<div class="comms-msg-bubble-wrap">' +
                '<div class="comms-msg-meta ' + (isOut ? 'text-end' : '') + '">' +
                    esc(who) + ' &middot; ' + esc(when) + statusHtml +
                '</div>' +
                '<div class="comms-msg-bubble ' + (isOut ? 'out' : 'in') + '">' +
                    (m.content || '') + errHtml +
                '</div>' +
            '</div>' +
            (isOut ? avatar : '');
        return row;
    }

    async function tick() {
        if (document.hidden) return;
        try {
            var pinned = atBottom();
            var r = await fetch('/communications/poll?thread=' + threadId + '&since_msg=' + lastId,
                                { credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
            if (!r.ok) return;
            var data = await r.json();
            var added = false;
            (data.new_messages || []).forEach(function (m) {
                if (m.id <= lastId) return;               // de-dupe
                // Drop the "No messages yet." placeholder on first arrival.
                var ph = feed.querySelector('.text-center.text-muted');
                if (ph) ph.remove();
                feed.appendChild(renderMessage(m));
                lastId = m.id;
                added = true;
            });
            if (added && pinned) feed.scrollTop = feed.scrollHeight;
            // Refresh the nav bell right away (it owns its own 30s cadence).
            if (added && window.notifyBellRefresh) window.notifyBellRefresh();
        } catch (e) { /* transient — next tick retries */ }
    }

    setInterval(tick, POLL_MS);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) tick(); });
})();
</script>

<?php
/**
 * Thread-list rail (left pane). Shared by index.php + thread.php.
 *
 * @var array $threads    emailthread beans, newest-first
 * @var int   $activeId   currently open thread id (0 on index)
 * @var string $search    current search query
 */
if (!function_exists('comms_initials')) {
    function comms_initials(string $s): string {
        $s = trim($s);
        if ($s === '') return '?';
        $parts = preg_split('/[\s@._-]+/', $s, -1, PREG_SPLIT_NO_EMPTY);
        $a = mb_strtoupper(mb_substr($parts[0] ?? '', 0, 1));
        $b = isset($parts[1]) ? mb_strtoupper(mb_substr($parts[1], 0, 1)) : '';
        return ($a . $b) ?: '?';
    }
}
?>
<?php /* $railClass lets the thread view hide this column on narrow screens, where the
         rail and the conversation stack instead of sitting side by side. */ ?>
<div class="col-lg-4 <?= htmlspecialchars($railClass ?? '') ?>">
    <div class="card border-0 shadow-sm comms-panel">
<?php
/* Rooms are separated from conversations because they are not the same kind of thing.
   A room is a PLACE — you are a member of it, it has no end, and sorting it by recency
   makes it wander around the list. A conversation has a beginning and an end and should
   sort by recency. Mixed together, one of the two always sorts wrongly, and a #room
   reads as just another message thread.

   Still one rail, one bell, one poll: this is a grouping, not a second inbox. */
$__rooms   = [];
$__convos  = [];
foreach (($threads ?? []) as $__t) {
    if ((string)$__t->kind === 'room') $__rooms[] = $__t; else $__convos[] = $__t;
}
$__me = member_id($member ?? null, 'communications thread list');

/* Stable order — by team, then room name. Recency is the right sort for a conversation
   and the wrong one for a place: a room that moves when somebody speaks is harder to
   find, not easier, and the muscle memory of "mine is third" is worth more than knowing
   which had the last message (the unread dot already says that). */
usort($__rooms, function ($a, $b) {
    $ta = $a->teamId ? (string)(\app\Bean::load('team', (int)$a->teamId)->name ?? '') : '';
    $tb = $b->teamId ? (string)(\app\Bean::load('team', (int)$b->teamId)->name ?? '') : '';
    return [$ta, (string)$a->slug] <=> [$tb, (string)$b->slug];
});
?>
        <div class="card-header bg-body-tertiary d-flex justify-content-between align-items-center">
            <span class="fw-semibold"><i class="bi bi-inbox me-1"></i>Messages</span>
            <span class="text-muted small"><?= count($threads) ?></span>
        </div>

        <div class="p-2 border-bottom">
            <form method="get" action="/communications" class="input-group input-group-sm">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input type="text" name="q" class="form-control" placeholder="Search…"
                       value="<?= htmlspecialchars($search ?? '') ?>">
                <?php if (!empty($search)): ?>
                    <a href="/communications" class="btn btn-outline-secondary">&times;</a>
                <?php endif; ?>
            </form>
        </div>

        <?php if ($__rooms): ?>
        <div class="comms-rooms border-bottom" id="comms-room-list">
            <div class="comms-section-head">
                <i class="bi bi-hash"></i> Team messaging
            </div>
            <?php foreach ($__rooms as $r): ?>
                <?php
                    $rUnread   = \app\ThreadMembers::unreadFor((int)$r->id, $__me) > 0;
                    $rMentions = \app\Mentions::unreadInThread((int)$r->id, $__me);
                    $rTeam     = $r->teamId ? \app\Bean::load('team', (int)$r->teamId) : null;
                    $rActive   = (int)$r->id === (int)($activeId ?? 0);
                ?>
                <a href="/communications/thread/<?= (int)$r->id ?>"
                   data-room-id="<?= (int)$r->id ?>"
                   class="comms-room-row <?= $rUnread ? 'unread' : '' ?> <?= $rActive ? 'active' : '' ?>">
                    <span class="comms-room-name">#<?= htmlspecialchars((string)($r->slug ?: 'room')) ?></span>
                    <span class="comms-room-team"><?= htmlspecialchars($rTeam && $rTeam->id ? (string)$rTeam->name : 'Team') ?></span>
                    <span class="comms-mention-badge badge rounded-pill bg-warning text-dark ms-1 <?= $rMentions ? '' : 'd-none' ?>"
                          title="You were mentioned">@<?= (int)$rMentions ?></span>
                    <span class="comms-unread-dot-room <?= $rUnread ? '' : 'd-none' ?>" title="New messages"></span>
                </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="comms-scroll flex-grow-1" id="comms-thread-list">
            <?php if ($__rooms && $__convos): ?>
                <div class="comms-section-head"><i class="bi bi-chat-left-text"></i> Conversations</div>
            <?php endif; ?>
            <?php if (empty($__convos)): ?>
                <div class="text-center text-muted small py-4">
                    <i class="bi bi-chat-left-text" style="font-size:1.6rem;"></i>
                    <div class="mt-2"><?= !empty($search) ? 'No matches.' : 'No conversations yet.' ?></div>
                </div>
            <?php else: ?>
                <?php foreach ($__convos as $t): ?>
                    <?php
                        $me      = $__me;
                        // Per-person unread now. The thread-level counter cannot answer this
                        // once a conversation has more than one participant.
                        $unread  = \app\ThreadMembers::unreadFor((int)$t->id, $me) > 0
                                   || ((int)$t->unreadCount > 0 && !\app\ThreadMembers::isMember((int)$t->id, $me));
                        $active  = (int)$t->id === (int)($activeId ?? 0);
                        $kind    = (string)($t->kind ?: 'email');

                        // A DM is named by WHO IS IN IT — it has no subject and no
                        // recipient address, so the email fields would render "(no
                        // subject)" from "Unknown".
                        if ($kind === 'dm') {
                            $others = array_values(array_filter(
                                \app\ThreadMembers::participants((int)$t->id), fn($id) => $id !== $me));
                            $who = $others
                                ? member_display_name(\app\Bean::load('member', $others[0]), 'Someone')
                                : 'Just you';
                            $label = $who;
                            $kindIcon = 'bi-person-circle';
                        } elseif ($kind === 'room') {
                            // Named by its handle, and by the team it belongs to — two
                            // teams may each have a #general and they are not the same room.
                            $team  = $t->teamId ? \app\Bean::load('team', (int)$t->teamId) : null;
                            $label = '#' . ($t->slug ?: 'room');
                            $who   = $team && $team->id ? (string)$team->name : 'Team';
                            $kindIcon = 'bi-hash';
                        } else {
                            $who   = $t->recipientName ?: $t->recipientEmail ?: 'Unknown';
                            $label = $t->subject ?: '(no subject)';
                            $kindIcon = 'bi-envelope';
                        }
                        $dirIcon = $t->lastDirection === 'in' ? 'bi-arrow-down-left text-success' : 'bi-arrow-up-right text-primary';
                        $when    = $t->lastMessageAt ? date('M j', strtotime($t->lastMessageAt)) : '';
                    ?>
                    <?php
                    /* The row is the WRAPPER now, not the anchor. It has to be: the live-rail
                       JS below re-sorts by prepending `.comms-thread-row`, and if that were
                       still the <a> the sort would rip it out of its actions. The anchor keeps
                       covering the whole row via .stretched-link, so clicking anywhere still
                       opens the thread — except on the buttons, which sit above it. */
                    ?>
                    <div data-thread-id="<?= (int)$t->id ?>"
                         class="comms-thread-row position-relative <?= $unread ? 'unread' : '' ?> <?= $active ? 'active' : '' ?>">
                        <a href="/communications/thread/<?= (int)$t->id ?>"
                           class="stretched-link" aria-label="Open conversation"></a>
                        <div class="comms-thread-actions">
                            <button type="button" class="btn btn-sm btn-link p-0 comms-act" data-act="read"
                                    data-id="<?= (int)$t->id ?>" data-read="<?= $unread ? 1 : 0 ?>"
                                    title="<?= $unread ? 'Mark as read' : 'Mark as unread' ?>">
                                <i class="bi <?= $unread ? 'bi-envelope-open' : 'bi-envelope' ?>"></i>
                            </button>
                            <button type="button" class="btn btn-sm btn-link p-0 text-danger comms-act" data-act="del"
                                    data-id="<?= (int)$t->id ?>" title="Delete conversation">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                        <div class="d-flex gap-2">
                            <span class="comms-avatar"><?= htmlspecialchars((comms_initials($who)) ?? '') ?></span>
                            <div class="min-w-0 flex-grow-1">
                                <div class="d-flex align-items-center">
                                    <span class="comms-unread-dot"></span>
                                    <span class="comms-thread-subject flex-grow-1"><i class="bi <?= $kindIcon ?> me-1 opacity-50 small"></i><?= htmlspecialchars($label) ?></span>
                                    <?php /* A mention outranks unread: in a busy room "new
                                             messages" stops meaning anything, and "someone
                                             asked YOU something" still does. */ ?>
                                    <?php $mentions = \app\Mentions::unreadInThread((int)$t->id, $me); ?>
                                    <span class="comms-mention-badge badge rounded-pill bg-warning text-dark ms-1 flex-shrink-0 <?= $mentions ? '' : 'd-none' ?>"
                                          title="You were mentioned">@<?= (int)$mentions ?></span>
                                    <span class="comms-unread-badge badge rounded-pill bg-danger ms-1 flex-shrink-0 <?= $unread ? '' : 'd-none' ?>"><?= $unread ? 1 : 0 ?></span>
                                    <small class="comms-thread-when text-muted ms-2 flex-shrink-0"><?= htmlspecialchars(($when) ?? '') ?></small>
                                </div>
                                <div class="comms-thread-preview">
                                    <i class="bi <?= $dirIcon ?>"></i>
                                    <?= htmlspecialchars(($t->lastPreview ?: $who) ?? '') ?>
                                </div>
                                <div class="small text-muted text-truncate">
                                    <i class="bi bi-person"></i> <?= htmlspecialchars(($who) ?? '') ?>
                                    <?php if (!empty($t->relatedType)): ?>
                                        · <span class="badge bg-info-subtle text-info-emphasis"><?= htmlspecialchars(($t->relatedType) ?? '') ?> #<?= (int)$t->relatedId ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
// Live rail — updates thread rows in place from the nav bell's poll data
// (comms:threads event). Strictly scoped to #comms-thread-list: it never touches
// the message feed or the composer, so a background refresh can't overtake what
// you're typing or sending on the right pane.
(function () {
    var rail = document.getElementById('comms-thread-list');
    if (!rail) return;

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' })[c];
        });
    }
    function fmtDay(ts) {
        if (!ts) return '';
        var d = new Date(String(ts).replace(' ', 'T'));
        return isNaN(d) ? '' : d.toLocaleString(undefined, { month: 'short', day: 'numeric' });
    }

    var roomList = document.getElementById('comms-room-list');

    /* Rooms live in their own list and are NOT re-sorted: a room is a place, and a place
       that moves around when somebody speaks is harder to find, not easier. Only their
       badges change. */
    function updateRooms(threads) {
        if (!roomList) return;
        threads.forEach(function (t) {
            var row = roomList.querySelector('.comms-room-row[data-room-id="' + t.id + '"]');
            if (!row) return;
            var unread = (t.unread_count || 0) > 0;
            row.classList.toggle('unread', unread);
            var dot = row.querySelector('.comms-unread-dot-room');
            if (dot) dot.classList.toggle('d-none', !unread);
            var mb = row.querySelector('.comms-mention-badge');
            if (mb) {
                var mc = t.mentions || 0;
                mb.textContent = '@' + mc;
                mb.classList.toggle('d-none', !mc);
            }
        });
    }

    function update(threads) {
        if (!Array.isArray(threads) || !threads.length) return;
        updateRooms(threads);

        threads.forEach(function (t) {
            var row = rail.querySelector('.comms-thread-row[data-thread-id="' + t.id + '"]');
            if (!row) return;                       // new threads show on next full load

            var unread = (t.unread_count || 0) > 0;
            row.classList.toggle('unread', unread);

            var mb = row.querySelector('.comms-mention-badge');
            if (mb) {
                var mc = t.mentions || 0;
                mb.textContent = '@' + mc;
                mb.classList.toggle('d-none', !mc);
            }
            var badge = row.querySelector('.comms-unread-badge');
            if (badge) {
                badge.textContent = t.unread_count;
                badge.classList.toggle('d-none', !unread);
            }
            var prev = row.querySelector('.comms-thread-preview');
            if (prev) {
                var icon = t.last_direction === 'in'
                    ? 'bi-arrow-down-left text-success' : 'bi-arrow-up-right text-primary';
                prev.innerHTML = '<i class="bi ' + icon + '"></i> ' + esc(t.preview || t.who || '');
            }
            var when = row.querySelector('.comms-thread-when');
            if (when) when.textContent = fmtDay(t.last_message_at);
        });

        // Re-sort to newest-first (server order), but only if the top actually
        // changed — avoids needless DOM churn on every poll.
        //
        // Inserted BEFORE the first row rather than prepended to the rail: the rail now
        // starts with a "Conversations" heading, and prepending would stack rows above
        // their own heading.
        var first  = rail.querySelector('.comms-thread-row');
        var topRow = rail.querySelector('.comms-thread-row[data-thread-id="' + threads[0].id + '"]');
        if (topRow && first !== topRow) {
            for (var i = threads.length - 1; i >= 0; i--) {
                var r = rail.querySelector('.comms-thread-row[data-thread-id="' + threads[i].id + '"]');
                var anchor = rail.querySelector('.comms-thread-row');
                if (r && anchor) rail.insertBefore(r, anchor);
            }
        }
    }

    // Row actions. Delegated, so rows the poll re-orders keep working.
    rail.addEventListener('click', function (e) {
        var btn = e.target.closest('.comms-act');
        if (!btn) return;
        e.preventDefault();          // the row is a stretched-link; don't follow it
        e.stopPropagation();

        var csrf = document.querySelector('meta[name="csrf-token"]');
        var id   = btn.dataset.id;
        var row  = rail.querySelector('.comms-thread-row[data-thread-id="' + id + '"]');
        var fd   = new FormData();
        fd.append('id', id);
        if (csrf) fd.append('_csrf_token', csrf.content);

        if (btn.dataset.act === 'del') {
            if (!confirm('Delete this conversation and its messages? This cannot be undone.')) return;
            btn.disabled = true;
            fetch('/communications/remove', { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (j) {
                    if (!j.success) { alert(j.message || 'Could not delete that conversation.'); btn.disabled = false; return; }
                    if (row) row.remove();
                    // If the deleted thread is the one on screen, there is nothing left to show.
                    if (window.location.pathname === '/communications/thread/' + id) window.location = '/communications';
                })
                .catch(function (err) { alert('Could not delete that conversation: ' + err); btn.disabled = false; });
            return;
        }

        // Mark read / unread. data-read is "is it currently unread", i.e. what to set it to.
        var makeRead = btn.dataset.read === '1';
        fd.append('read', makeRead ? '1' : '0');
        btn.disabled = true;
        fetch('/communications/markread', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                btn.disabled = false;
                if (!j.success) { alert(j.message || 'Could not update that conversation.'); return; }
                var unread = (j.data && j.data.unread) > 0;
                if (row) row.classList.toggle('unread', unread);
                var badge = row && row.querySelector('.comms-unread-badge');
                if (badge) { badge.textContent = unread ? 1 : 0; badge.classList.toggle('d-none', !unread); }
                btn.dataset.read = unread ? '1' : '0';
                btn.title = unread ? 'Mark as read' : 'Mark as unread';
                var ic = btn.querySelector('i');
                if (ic) ic.className = 'bi ' + (unread ? 'bi-envelope-open' : 'bi-envelope');
            })
            .catch(function (err) { btn.disabled = false; alert('Could not update that conversation: ' + err); });
    });

    document.addEventListener('comms:threads', function (e) { update(e.detail); });
})();
</script>

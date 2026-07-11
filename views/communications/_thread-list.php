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
<div class="col-lg-4">
    <div class="card border-0 shadow-sm comms-panel">
        <div class="card-header bg-body-tertiary d-flex justify-content-between align-items-center">
            <span class="fw-semibold"><i class="bi bi-inbox me-1"></i>Threads</span>
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

        <div class="comms-scroll flex-grow-1" id="comms-thread-list">
            <?php if (empty($threads)): ?>
                <div class="text-center text-muted small py-5">
                    <i class="bi bi-inbox" style="font-size:1.8rem;"></i>
                    <div class="mt-2"><?= !empty($search) ? 'No matches.' : 'No conversations yet.' ?></div>
                </div>
            <?php else: ?>
                <?php foreach ($threads as $t): ?>
                    <?php
                        $unread  = (int)$t->unreadCount > 0;
                        $active  = (int)$t->id === (int)($activeId ?? 0);
                        $who     = $t->recipientName ?: $t->recipientEmail ?: 'Unknown';
                        $dirIcon = $t->lastDirection === 'in' ? 'bi-arrow-down-left text-success' : 'bi-arrow-up-right text-primary';
                        $when    = $t->lastMessageAt ? date('M j', strtotime($t->lastMessageAt)) : '';
                    ?>
                    <a href="/communications/thread/<?= (int)$t->id ?>"
                       data-thread-id="<?= (int)$t->id ?>"
                       class="comms-thread-row <?= $unread ? 'unread' : '' ?> <?= $active ? 'active' : '' ?>">
                        <div class="d-flex gap-2">
                            <span class="comms-avatar"><?= htmlspecialchars((comms_initials($who)) ?? '') ?></span>
                            <div class="min-w-0 flex-grow-1">
                                <div class="d-flex align-items-center">
                                    <span class="comms-unread-dot"></span>
                                    <span class="comms-thread-subject flex-grow-1"><?= htmlspecialchars(($t->subject ?: '(no subject)') ?? '') ?></span>
                                    <span class="comms-unread-badge badge rounded-pill bg-danger ms-1 flex-shrink-0 <?= $unread ? '' : 'd-none' ?>"><?= (int)$t->unreadCount ?></span>
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
                    </a>
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

    function update(threads) {
        if (!Array.isArray(threads) || !threads.length) return;

        threads.forEach(function (t) {
            var row = rail.querySelector('.comms-thread-row[data-thread-id="' + t.id + '"]');
            if (!row) return;                       // new threads show on next full load

            var unread = (t.unread_count || 0) > 0;
            row.classList.toggle('unread', unread);

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
        var topRow = rail.querySelector('.comms-thread-row[data-thread-id="' + threads[0].id + '"]');
        if (topRow && rail.firstElementChild !== topRow) {
            for (var i = threads.length - 1; i >= 0; i--) {
                var r = rail.querySelector('.comms-thread-row[data-thread-id="' + threads[i].id + '"]');
                if (r) rail.prepend(r);
            }
        }
    }

    document.addEventListener('comms:threads', function (e) { update(e.detail); });
})();
</script>

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
$headWho = $thread->recipientName ?: $thread->recipientEmail ?: 'Unknown';
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
                        <div class="fw-semibold text-truncate"><?= htmlspecialchars(($thread->subject ?: '(no subject)') ?? '') ?></div>
                        <div class="small text-muted text-truncate">
                            <?= count($messages) ?> msg<?= count($messages) === 1 ? '' : 's' ?>
                            · <?= htmlspecialchars(($headWho) ?? '') ?>
                            <?php if ($ownerName !== ''): ?> · owned by <?= htmlspecialchars(($ownerName) ?? '') ?><?php endif; ?>
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
                                $isOut    = $m->direction === 'out';
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
                                            <?= $m->content ?>
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
                                      oninput="this.style.height='auto';this.style.height=Math.min(this.scrollHeight,120)+'px';"></textarea>
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

<?php include __DIR__ . '/_compose-modal.php'; ?>

<script>
// Live thread — appends only NEW messages every 10s. Scoped to #comms-feed so
// nothing else on the page re-renders; scroll position is preserved unless the
// viewer was already pinned to the bottom. Ported from the dealeryes pattern.
(function () {
    var feed = document.getElementById('comms-feed');
    if (!feed) return;

    var threadId = <?= (int)$thread->id ?>;
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
        var isOut = m.direction === 'out';
        var who   = m.from_name || (isOut ? 'You' : 'Them');
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

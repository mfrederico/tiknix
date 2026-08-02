<?php
/**
 * "New Conversation" compose modal. Posts to /communications/create.
 *
 * Two kinds of conversation, and which ones you are offered depends on what you have:
 *
 *  - A PERSON you share a team with. Stays inside the application, needs no grant, and
 *    is the default because it is what most messages are.
 *  - An EMAIL address. Leaves the building under Tiknix's name, so it needs the
 *    'email_out' grant and is HIDDEN — not disabled — without it. A control that is
 *    always refused teaches nothing; its absence at least does not promise anything.
 *
 * Someone with no teammates and no grant gets an honest empty state instead of a form
 * that cannot be submitted.
 */
$__mid      = member_id($member ?? null, 'communications compose');
$__level    = (int) $member['level'];
$__mates    = \app\Teammates::of($__mid);
$__canEmail = \app\Teammates::canSendEmail($__mid, $__level);
$__canDm    = !empty($__mates);

// Rooms of every team this person is on. Posting in one is how you reach the whole team.
$__rooms = [];
foreach (\app\Teammates::teamIds($__mid) as $__tid) {
    $__team = \app\Bean::load('team', $__tid);
    foreach (\app\Rooms::forTeam($__tid) as $__r) {
        $__rooms[] = ['id' => (int)$__r->id, 'slug' => (string)$__r->slug,
                      'team' => (string)($__team->name ?? 'Team')];
    }
}
$__canRoom = !empty($__rooms);
?>
<div class="modal fade" id="comms-compose-modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" action="/communications/create">
                <?= csrf_field() ?>
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-pencil-square me-1"></i>New Conversation</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body">

                <?php if (!$__canDm && !$__canEmail && !$__canRoom): ?>
                    <div class="text-center py-3">
                        <i class="bi bi-people text-body-secondary" style="font-size:2rem"></i>
                        <p class="mt-2 mb-1">There is nobody to message yet.</p>
                        <p class="small text-body-secondary mb-3">
                            You can message people you share a team with. Join or create a team and
                            whoever is in it will appear here.
                        </p>
                        <a href="/teams" class="btn btn-sm btn-primary">Teams</a>
                    </div>
                <?php else: ?>

                    <?php
                    /* Tabs only when there is more than one thing to choose between. */
                    $__tabs = [];
                    if ($__canRoom)  $__tabs['room']   = ['#compose-room',   'bi-hash',     'Whole team'];
                    if ($__canDm)    $__tabs['person'] = ['#compose-person', 'bi-person',   'One person'];
                    if ($__canEmail) $__tabs['email']  = ['#compose-email',  'bi-envelope', 'Email address'];
                    $__first = array_key_first($__tabs);
                    ?>
                    <?php if (count($__tabs) > 1): ?>
                        <ul class="nav nav-pills nav-fill mb-3" role="tablist">
                            <?php foreach ($__tabs as $__k => [$__target, $__icon, $__lbl]): ?>
                                <li class="nav-item">
                                    <button class="nav-link <?= $__k === $__first ? 'active' : '' ?>"
                                            data-bs-toggle="pill" data-bs-target="<?= $__target ?>" type="button">
                                        <i class="bi <?= $__icon ?> me-1"></i><?= htmlspecialchars($__lbl) ?>
                                    </button>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>

                    <div class="tab-content">
                        <?php if ($__canRoom): ?>
                        <div class="tab-pane fade <?= $__first === 'room' ? 'show active' : '' ?>" id="compose-room">
                            <div class="mb-2">
                                <label class="form-label small mb-1">Team room</label>
                                <select name="to_room" class="form-select">
                                    <option value="">Choose a team room…</option>
                                    <?php foreach ($__rooms as $__r): ?>
                                        <option value="<?= (int)$__r['id'] ?>">
                                            #<?= htmlspecialchars($__r['slug']) ?> &middot; <?= htmlspecialchars($__r['team']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">Everyone on that team sees it. This is how you tell the whole team something.</div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if ($__canDm): ?>
                        <div class="tab-pane fade <?= $__first === 'person' ? 'show active' : '' ?>" id="compose-person">
                            <div class="mb-2">
                                <label class="form-label small mb-1">To</label>
                                <select name="to_member" class="form-select" id="compose-to-member">
                                    <option value="">Choose someone…</option>
                                    <?php foreach ($__mates as $m): ?>
                                        <option value="<?= (int)$m->id ?>">
                                            <?= htmlspecialchars(member_display_name($m, (string)$m->username)) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">Stays inside Tiknix — no email is sent.</div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if ($__canEmail): ?>
                        <div class="tab-pane fade <?= $__first === 'email' ? 'show active' : '' ?>" id="compose-email">
                            <div class="row g-2 mb-2">
                                <div class="col-sm-7">
                                    <label class="form-label small mb-1">Recipient email</label>
                                    <input type="email" name="to" class="form-control" placeholder="name@example.com">
                                </div>
                                <div class="col-sm-5">
                                    <label class="form-label small mb-1">Name <span class="text-muted">(optional)</span></label>
                                    <input type="text" name="to_name" class="form-control" placeholder="Jane Smith">
                                </div>
                            </div>
                            <div class="mb-2">
                                <label class="form-label small mb-1">Subject</label>
                                <input type="text" name="subject" class="form-control" placeholder="Subject">
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="mb-1">
                        <label class="form-label small mb-1">Message</label>
                        <textarea name="body" class="form-control" rows="5" placeholder="Write your message…" required></textarea>
                    </div>
                <?php endif; ?>
                </div>

                <?php if ($__canDm || $__canEmail || $__canRoom): ?>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-send me-1"></i>Send</button>
                </div>
                <?php endif; ?>
            </form>
        </div>
    </div>
</div>

<?php if (count($__tabs ?? []) > 1): ?>
<script>
/* Whichever tab is showing is the one that submits. Without this both sets of fields post
   together and the server has to guess which was meant — and it would guess "person",
   silently dropping an email address somebody had typed. Clearing the hidden side makes
   the visible tab the answer. */
(function () {
    var modal = document.getElementById('comms-compose-modal');
    if (!modal) return;

    var fields = {
        '#compose-room':   ['to_room'],
        '#compose-person': ['to_member'],
        '#compose-email':  ['to', 'subject']
    };
    function clearAllBut(keep) {
        Object.keys(fields).forEach(function (pane) {
            if (pane === keep) return;
            fields[pane].forEach(function (n) {
                var el = modal.querySelector('[name="' + n + '"]');
                if (el) el.value = '';
            });
        });
    }
    modal.addEventListener('shown.bs.tab', function (e) {
        clearAllBut(e.target.dataset.bsTarget);
    }, true);

    modal.querySelector('form').addEventListener('submit', function (e) {
        var any = ['to_room', 'to_member', 'to'].some(function (n) {
            var el = modal.querySelector('[name="' + n + '"]');
            return el && el.value;
        });
        if (!any) {
            e.preventDefault();
            alert('Choose a room, someone on your team, or an email address.');
        }
    });
})();
</script>
<?php endif; ?>

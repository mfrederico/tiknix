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
$__mid      = (int) ($member['id'] ?? 0);
$__level    = (int) ($member['level'] ?? 100);
$__mates    = \app\Teammates::of($__mid);
$__canEmail = \app\Teammates::canSendEmail($__mid, $__level);
$__canDm    = !empty($__mates);
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

                <?php if (!$__canDm && !$__canEmail): ?>
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

                    <?php if ($__canDm && $__canEmail): ?>
                        <?php /* Only worth a choice when there IS one. */ ?>
                        <ul class="nav nav-pills nav-fill mb-3" role="tablist">
                            <li class="nav-item">
                                <button class="nav-link active" data-bs-toggle="pill" data-bs-target="#compose-person" type="button">
                                    <i class="bi bi-person me-1"></i>Someone on my team
                                </button>
                            </li>
                            <li class="nav-item">
                                <button class="nav-link" data-bs-toggle="pill" data-bs-target="#compose-email" type="button">
                                    <i class="bi bi-envelope me-1"></i>Email address
                                </button>
                            </li>
                        </ul>
                    <?php endif; ?>

                    <div class="tab-content">
                        <?php if ($__canDm): ?>
                        <div class="tab-pane fade show active" id="compose-person">
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
                        <div class="tab-pane fade <?= $__canDm ? '' : 'show active' ?>" id="compose-email">
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

                <?php if ($__canDm || $__canEmail): ?>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-send me-1"></i>Send</button>
                </div>
                <?php endif; ?>
            </form>
        </div>
    </div>
</div>

<?php if ($__canDm && $__canEmail): ?>
<script>
/* Whichever tab is showing is the one that submits. Without this both sets of fields post
   together and the server has to guess which was meant — and it would guess "person",
   silently dropping an email address somebody had typed. Clearing the hidden side makes
   the visible tab the answer. */
(function () {
    var modal = document.getElementById('comms-compose-modal');
    if (!modal) return;

    modal.addEventListener('shown.bs.tab', function (e) {
        var toPerson = e.target.dataset.bsTarget === '#compose-person';
        var sel = modal.querySelector('[name="to_member"]');
        var eml = modal.querySelector('[name="to"]');
        var sub = modal.querySelector('[name="subject"]');
        if (toPerson) { if (eml) eml.value = ''; if (sub) sub.value = ''; }
        else if (sel) { sel.value = ''; }
    }, true);

    modal.querySelector('form').addEventListener('submit', function (e) {
        var sel = modal.querySelector('[name="to_member"]');
        var eml = modal.querySelector('[name="to"]');
        if ((!sel || !sel.value) && (!eml || !eml.value)) {
            e.preventDefault();
            alert('Choose someone on your team, or enter an email address.');
        }
    });
})();
</script>
<?php endif; ?>

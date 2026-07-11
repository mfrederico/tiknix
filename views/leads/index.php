<div class="container-fluid">
    <div class="row g-4">
        <!-- Left sub-nav (admins) -->
        <aside class="col-12 col-md-3 col-lg-2">
            <div class="list-group shadow-sm">
                <div class="list-group-item bg-body-tertiary text-uppercase small fw-bold text-secondary">
                    Admin
                </div>
                <a href="/leads" class="list-group-item list-group-item-action active">
                    <i class="bi bi-person-lines-fill"></i> Leads
                    <span class="badge bg-light text-dark float-end"><?= (int)($total ?? 0) ?></span>
                </a>
                <a href="/admin" class="list-group-item list-group-item-action">
                    <i class="bi bi-speedometer2"></i> Dashboard
                </a>
                <a href="/admin/members" class="list-group-item list-group-item-action">
                    <i class="bi bi-people"></i> Members
                </a>
            </div>
        </aside>

        <!-- Main content -->
        <main class="col-12 col-md-9 col-lg-10">
            <div class="ui-page-header d-flex justify-content-between align-items-end flex-wrap gap-2">
                <div>
                    <span class="ui-eyebrow">Admin</span>
                    <h1>Leads</h1>
                    <div class="ui-sub"><?= (int)($total ?? 0) ?> total &middot; email them straight from here.</div>
                </div>
            </div>

            <?php if (empty($leads)): ?>
                <div class="alert alert-info">
                    <i class="bi bi-info-circle"></i> No leads yet. They'll appear here as people sign up on your Coming Soon page.
                </div>
            <?php else: ?>
                <div class="ui-panel">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 align-middle">
                            <thead>
                                <tr>
                                    <th>First Name</th>
                                    <th>Last Name</th>
                                    <th>Email</th>
                                    <th>Signed Up</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($leads as $lead): ?>
                                    <?php
                                        $_email = (string)($lead->email ?? '');
                                        $_name  = trim(((string)($lead->firstName ?? '')) . ' ' . ((string)($lead->lastName ?? '')));
                                    ?>
                                    <tr>
                                        <td><?= htmlspecialchars($lead->firstName ?? '') ?></td>
                                        <td><?= htmlspecialchars($lead->lastName ?? '') ?></td>
                                        <td>
                                            <a href="mailto:<?= htmlspecialchars($_email) ?>"><?= htmlspecialchars($_email) ?></a>
                                        </td>
                                        <td class="ui-mono small text-secondary"><?= htmlspecialchars($lead->createdAt ?? '') ?></td>
                                        <td class="text-end">
                                            <?php if ($_email !== ''): ?>
                                            <button type="button" class="btn btn-sm btn-outline-primary lead-email-btn"
                                                    data-email="<?= htmlspecialchars($_email, ENT_QUOTES) ?>"
                                                    data-name="<?= htmlspecialchars($_name, ENT_QUOTES) ?>">
                                                <i class="bi bi-envelope"></i> Email
                                            </button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </main>
    </div>
</div>

<!-- Compose modal — posts to the existing Communications endpoint, which sends
     via Mailgun and opens a threaded conversation with the lead. -->
<div class="modal fade" id="leadEmailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content ui-panel border-0">
            <form method="POST" action="/communications/create">
                <?= function_exists('csrf_field') ? csrf_field() : '' ?>
                <input type="hidden" name="to" id="leadEmailTo">
                <input type="hidden" name="to_name" id="leadEmailToName">
                <div class="modal-header">
                    <h3 class="modal-title h5 mb-0"><i class="bi bi-envelope me-2"></i>Email lead</h3>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">To</label>
                        <div class="form-control-plaintext ui-chip" id="leadEmailRecipient" style="width:fit-content"></div>
                    </div>
                    <div class="mb-3">
                        <label for="leadEmailSubject" class="form-label">Subject</label>
                        <input type="text" class="form-control" name="subject" id="leadEmailSubject" placeholder="Subject" required>
                    </div>
                    <div class="mb-0">
                        <label for="leadEmailBody" class="form-label">Message</label>
                        <textarea class="form-control" name="body" id="leadEmailBody" rows="8" placeholder="Write your message…" required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-send"></i> Send email</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Runs on DOMContentLoaded so the Bootstrap bundle (loaded at the end of the
// layout) is defined before we touch bootstrap.Modal.
document.addEventListener('DOMContentLoaded', function () {
    var modalEl = document.getElementById('leadEmailModal');
    if (!modalEl || typeof bootstrap === 'undefined') return;
    var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    document.querySelectorAll('.lead-email-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var email = btn.getAttribute('data-email') || '';
            var name  = btn.getAttribute('data-name') || '';
            document.getElementById('leadEmailTo').value = email;
            document.getElementById('leadEmailToName').value = name;
            document.getElementById('leadEmailRecipient').textContent = (name ? name + ' · ' : '') + email;
            document.getElementById('leadEmailSubject').value = '';
            document.getElementById('leadEmailBody').value = '';
            modal.show();
        });
    });
});
</script>

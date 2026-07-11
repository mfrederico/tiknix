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

            <?php if ((int)($total ?? 0) === 0): ?>
                <div class="alert alert-info">
                    <i class="bi bi-info-circle"></i> No leads yet. They'll appear here as people sign up on your Coming Soon page.
                </div>
            <?php else: ?>
                <div class="ui-panel">
                    <div class="ui-panel-body">
                        <div class="table-responsive">
                            <!-- Rows are fetched over AJAX from /leads/data (server-side processing
                                 via the shared dt-server primitive). -->
                            <table id="leadsTable" class="dt-server table table-hover align-middle mb-0"
                                   data-dt-url="/leads/data"
                                   data-dt-order="3:desc"
                                   data-dt-page-length="25"
                                   data-dt-search-placeholder="name, email…"
                                   style="width:100%">
                                <thead>
                                    <tr>
                                        <th>First Name</th>
                                        <th>Last Name</th>
                                        <th>Email</th>
                                        <th>Signed Up</th>
                                        <th data-dt-noorder data-dt-nosearch data-dt-class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
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
// Rows (and their Email buttons) are injected dynamically by the server-side
// DataTable, so the click is handled by delegation on document rather than
// bound per-button. Runs on DOMContentLoaded so bootstrap.Modal is defined.
document.addEventListener('DOMContentLoaded', function () {
    var modalEl = document.getElementById('leadEmailModal');
    if (!modalEl || typeof bootstrap === 'undefined') return;
    var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.lead-email-btn');
        if (!btn) return;
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
</script>

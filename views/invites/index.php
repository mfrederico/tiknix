<div class="container-fluid">
  <div class="row g-4">
    <main class="col-12">

      <div class="ui-page-header">
        <span class="ui-eyebrow">Workspace</span>
        <h1>Invitations</h1>
        <div class="ui-sub">
          Sign-ups are closed, so an invitation is the only way someone gets an account.
          Each one is tied to a single email address and lasts <?= (int) $ttlDays ?> days.
        </div>
      </div>

      <?php
      $flash = $_SESSION['flash'] ?? [];
      unset($_SESSION['flash']);
      foreach ($flash as $m):
      ?>
        <div class="alert alert-<?= $m['type'] === 'error' ? 'danger' : htmlspecialchars($m['type']) ?>">
          <?= htmlspecialchars($m['message']) ?>
        </div>
      <?php endforeach; ?>

      <div class="row g-4">
        <div class="col-lg-5">
          <div class="card">
            <div class="card-body">
              <h5 class="card-title mb-3">Invite someone</h5>

              <?php /* Say the allowance BEFORE they type, not after they hit the limit. */ ?>
              <?php if (!empty($blocked)): ?>
                <?php /* A granted member who has not built yet. They reached this page, so
                         the grant is on — what is missing is the activity, and saying which
                         is the whole value of the message. */ ?>
                <div class="alert alert-warning py-2 small mb-3">
                  <i class="bi bi-lock me-1"></i><?= htmlspecialchars($blocked) ?>
                </div>
              <?php elseif ($isAdmin): ?>
                <div class="alert alert-secondary py-2 small mb-3">
                  <i class="bi bi-infinity me-1"></i>
                  As an admin your invitations are unlimited.
                </div>
              <?php elseif ($remaining > 0): ?>
                <div class="alert alert-secondary py-2 small mb-3">
                  <strong><?= (int) $remaining ?></strong> of <?= (int) $perWindow ?> invitations left
                  for the last <?= (int) $windowDays ?> days.
                </div>
              <?php else: ?>
                <div class="alert alert-warning py-2 small mb-3">
                  You've used all <?= (int) $perWindow ?> invitations for the last <?= (int) $windowDays ?> days.
                  <?php if (!empty($nextSlot)): ?>
                    The next one frees up on <strong><?= htmlspecialchars(date('j M Y', strtotime((string) $nextSlot))) ?></strong>.
                  <?php endif; ?>
                  <div class="mt-1">Withdrawing an unaccepted invitation gives its allowance back.</div>
                </div>
              <?php endif; ?>

              <form method="POST" action="/invites/send">
                <?php foreach (($csrf ?? []) as $n => $v): ?>
                  <input type="hidden" name="<?= htmlspecialchars($n) ?>" value="<?= htmlspecialchars($v) ?>">
                <?php endforeach; ?>

                <div class="mb-3">
                  <label for="email" class="form-label">Their email address</label>
                  <input type="email" class="form-control" id="email" name="email" required
                         placeholder="jane@example.com"
                         <?= (!empty($blocked) || (!$isAdmin && $remaining <= 0)) ? 'disabled' : '' ?>>
                </div>

                <div class="mb-3">
                  <label for="note" class="form-label">A short note <span class="text-body-secondary">(optional)</span></label>
                  <textarea class="form-control" id="note" name="note" rows="2" maxlength="500"
                            placeholder="Why you're inviting them — it goes in the email."
                            <?= (!empty($blocked) || (!$isAdmin && $remaining <= 0)) ? 'disabled' : '' ?>></textarea>
                </div>

                <button type="submit" class="btn btn-primary w-100"
                        <?= (!empty($blocked) || (!$isAdmin && $remaining <= 0)) ? 'disabled' : '' ?>>
                  <i class="bi bi-envelope-plus me-1"></i> Send invitation
                </button>
              </form>
            </div>
          </div>
        </div>

        <div class="col-lg-7">
          <div class="card">
            <div class="card-body">
              <h5 class="card-title mb-3">
                <?= $isAdmin ? 'All invitations' : 'Invitations you\'ve sent' ?>
              </h5>

              <?php if (empty($invites)): ?>
                <div class="text-body-secondary small">Nothing sent yet.</div>
              <?php else: ?>
                <div class="table-responsive">
                  <table class="table table-sm align-middle mb-0">
                    <thead>
                      <tr>
                        <th>Email</th><th>Status</th><th>Sent</th><th class="text-end">Action</th>
                      </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($invites as $i):
                        // One state per row, decided here so the badge and the action agree.
                        $expired = strtotime((string) $i->expiresAt) < time();
                        if ($i->acceptedAt)      { $state = 'accepted'; $cls = 'success'; }
                        elseif ($i->revokedAt)   { $state = 'withdrawn'; $cls = 'secondary'; }
                        elseif ($expired)        { $state = 'expired';  $cls = 'warning'; }
                        else                     { $state = 'pending';  $cls = 'info'; }
                    ?>
                      <tr>
                        <td>
                          <?= htmlspecialchars((string) $i->email) ?>
                          <?php if (!$i->emailSent && $state === 'pending'): ?>
                            <?php /* The invite exists and its link works; only the email failed.
                                     Saying so is the difference between "resend" and "paste it yourself". */ ?>
                            <span class="badge bg-danger-subtle text-danger-emphasis border border-danger-subtle ms-1"
                                  title="The invitation was created but the email could not be sent">not emailed</span>
                          <?php endif; ?>
                        </td>
                        <td>
                          <span class="badge bg-<?= $cls ?>-subtle text-<?= $cls ?>-emphasis border border-<?= $cls ?>-subtle">
                            <?= $state ?>
                          </span>
                          <?php if ($state === 'pending'): ?>
                            <div class="small text-body-secondary">
                              expires <?= htmlspecialchars(date('j M', strtotime((string) $i->expiresAt))) ?>
                            </div>
                          <?php endif; ?>
                        </td>
                        <td class="small text-body-secondary">
                          <?= htmlspecialchars(date('j M Y', strtotime((string) $i->createdAt))) ?>
                        </td>
                        <td class="text-end">
                          <?php if ($state === 'pending'): ?>
                            <button class="btn btn-sm btn-outline-danger inv-revoke" data-id="<?= (int) $i->id ?>">
                              Withdraw
                            </button>
                          <?php endif; ?>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              <?php endif; ?>
            </div>
          </div>

          <?php
          /* The downline. Rendered from member.invited_by, not from the invite rows, so it
             survives invites being tidied away. `built` is shown per person because the
             useful question about someone you brought in is whether they are actually
             using it — a downline of dormant accounts is not growth. */
          $renderTree = function (array $nodes, int $depth = 0) use (&$renderTree) {
              foreach ($nodes as $n) {
                  $m = $n['member'];
                  printf(
                      '<div class="d-flex align-items-center gap-2 py-1" style="padding-left:%dpx">'
                      . '<i class="bi bi-%s text-body-secondary"></i>'
                      . '<span>%s</span>'
                      . '<span class="badge bg-%s-subtle text-%s-emphasis border border-%s-subtle">%s</span>'
                      . '<span class="small text-body-secondary ms-auto">%s</span></div>',
                      $depth * 18,
                      $depth === 0 ? 'person' : 'arrow-return-right',
                      htmlspecialchars((string) ($m->username ?: $m->email)),
                      $n['built'] ? 'success' : 'secondary',
                      $n['built'] ? 'success' : 'secondary',
                      $n['built'] ? 'success' : 'secondary',
                      $n['built'] ? 'building' : 'not started',
                      htmlspecialchars($n['joined'] ? date('j M Y', strtotime($n['joined'])) : '')
                  );
                  if ($n['children']) $renderTree($n['children'], $depth + 1);
              }
          };
          ?>
          <div class="card mt-4">
            <div class="card-body">
              <h5 class="card-title mb-1">Who you've brought in</h5>
              <div class="text-body-secondary small mb-3">
                <?= (int) $downlineN ?> <?= (int) $downlineN === 1 ? 'person' : 'people' ?>,
                including anyone they invited in turn.
              </div>
              <?php if (empty($downline)): ?>
                <div class="text-body-secondary small">Nobody yet — accepted invitations appear here.</div>
              <?php else: ?>
                <?php $renderTree($downline); ?>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    </main>
  </div>
</div>

<script>
(function () {
    var csrf = document.querySelector('meta[name="csrf-token"]');
    document.querySelectorAll('.inv-revoke').forEach(function (b) {
        b.addEventListener('click', async function () {
            if (!confirm('Withdraw this invitation? The link stops working immediately.')) return;
            var was = b.innerHTML;
            b.disabled = true; b.textContent = '…';
            try {
                var fd = new FormData();
                fd.append('id', b.dataset.id);
                if (csrf) fd.append('_csrf_token', csrf.content);
                var r = await fetch('/invites/revoke', { method: 'POST', body: fd });
                var j = await r.json();
                if (j.success) { location.reload(); return; }
                alert(j.message || 'Could not withdraw that invitation.');
            } catch (e) {
                alert('Could not withdraw that invitation: ' + e);
            }
            b.disabled = false; b.innerHTML = was;
        });
    });
})();
</script>

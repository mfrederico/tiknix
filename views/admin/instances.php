<?php
/**
 * @var list<array{bean:object,on:bool,last:?object,by:string,owner:string}> $rows
 * @var array $trail
 */
$when = fn(?string $s) => $s ? date('M j, Y g:i A', strtotime($s)) : '—';
?>
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <h1 class="h2">Instances — Unattended Builds</h1>
        <span class="badge text-bg-secondary"><?= count(array_filter($rows, fn($r) => $r['on'])) ?> of <?= count($rows) ?> enabled</span>
    </div>

    <p class="text-secondary mb-4" style="max-width:60rem">
        With this on, the control plane launches agent builds against the instance on its own —
        <strong>Firehose</strong> starts one when a new error arrives, and <strong>plan-audit</strong>
        sweeps deferred errors whenever the instance goes idle, so a fix can lead to a re-audit and
        another build. Both refuse to start while an agent is already working that repo. It is off
        for everyone by default and there is no client-facing switch: enabling it commits spend on
        their behalf, so every change is recorded below with who made it.
    </p>

    <?php if (!empty($flash)): ?>
        <div class="alert alert-<?= htmlspecialchars($flash['type'] ?? 'info') ?>"><?= htmlspecialchars($flash['message'] ?? '') ?></div>
    <?php endif; ?>

    <div class="table-responsive">
    <table class="table table-hover align-middle">
        <thead>
            <tr>
                <th>Instance</th>
                <th>Owner</th>
                <th>Unattended builds</th>
                <th>Last change</th>
                <th class="text-end">Action</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): $b = $r['bean']; ?>
            <tr>
                <td>
                    <span class="fw-semibold"><?= htmlspecialchars((string) $b->slug) ?></span>
                    <div class="small text-secondary"><?= htmlspecialchars((string) ($b->displayName ?: '')) ?></div>
                </td>
                <td class="small text-secondary">
                    <?= htmlspecialchars($r['owner']) ?>
                    <?php if ((int) $b->memberId > 0 && (int) $b->memberId !== (int) (\Flight::getMember()->id ?? 0)): ?>
                        <button type="button" class="btn btn-link btn-sm p-0 ms-1 align-baseline" title="Message owner"
                                onclick="noteOpen(<?= (int) $b->memberId ?>, <?= htmlspecialchars(json_encode($r['owner'] . ' (' . $b->slug . ')'), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode((string) ($b->displayName ?: $b->slug)), ENT_QUOTES) ?>)">
                            <i class="bi bi-chat-left-text"></i><span class="visually-hidden">Message owner</span>
                        </button>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($r['on']): ?>
                        <span class="badge text-bg-warning">ON — builds unattended</span>
                    <?php else: ?>
                        <span class="badge text-bg-light text-secondary">off</span>
                    <?php endif; ?>
                </td>
                <td class="small">
                    <?php if ($r['last']): ?>
                        <?= $r['last']->newValue === '1' ? 'enabled' : 'disabled' ?>
                        by <strong><?= htmlspecialchars($r['by']) ?></strong>
                        <div class="text-secondary"><?= $when((string) $r['last']->createdAt) ?>
                        <?php if (!empty($r['last']->note)): ?>
                            — <?= htmlspecialchars((string) $r['last']->note) ?>
                        <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <span class="text-secondary">never changed</span>
                    <?php endif; ?>
                </td>
                <td class="text-end">
                    <form method="POST" action="/admin/instances" class="d-inline-flex gap-2 justify-content-end"
                          <?= $r['on'] ? '' : 'data-confirm="Let the control plane build against ' . htmlspecialchars((string) $b->slug, ENT_QUOTES) . ' with nobody watching?"' ?>>
                        <?= csrf_field() ?>
                        <input type="hidden" name="instance_id" value="<?= (int) $b->id ?>">
                        <input type="hidden" name="enabled" value="<?= $r['on'] ? '' : '1' ?>">
                        <input type="text" name="note" class="form-control form-control-sm" style="max-width:16rem"
                               placeholder="why (e.g. premium plan)" <?= $r['on'] ? '' : 'required' ?>>
                        <button class="btn btn-sm <?= $r['on'] ? 'btn-outline-secondary' : 'btn-warning' ?>">
                            <?= $r['on'] ? 'Disable' : 'Enable' ?>
                        </button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
            <tr><td colspan="5" class="text-secondary py-4">No instances.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>

    <h2 class="h5 mt-5 mb-3">Audit trail</h2>
    <?php /* The whole history, not just current state. "It was on for three weeks in
             March" is the question a billing dispute actually asks, and a current-state
             stamp cannot answer it. */ ?>
    <div class="table-responsive">
    <table class="table table-sm">
        <thead><tr><th>When</th><th>Instance</th><th>Change</th><th>By</th><th>Note</th></tr></thead>
        <tbody>
        <?php foreach ($trail as $a):
            $inst = \app\Bean::load('instance', (int) $a->instanceId);
            $who  = \app\Bean::load('member', (int) $a->memberId);
        ?>
            <tr>
                <td class="small text-nowrap"><?= $when((string) $a->createdAt) ?></td>
                <td class="small"><?= htmlspecialchars((string) ($inst->slug ?: 'instance #' . (int) $a->instanceId)) ?></td>
                <td class="small">
                    <?= htmlspecialchars((string) $a->field) ?>:
                    <?= $a->oldValue === '1' ? 'on' : 'off' ?> &rarr;
                    <strong><?= $a->newValue === '1' ? 'on' : 'off' ?></strong>
                </td>
                <td class="small"><?= htmlspecialchars((string) ($who->email ?: 'member #' . (int) $a->memberId)) ?></td>
                <td class="small text-secondary"><?= htmlspecialchars((string) $a->note) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!count($trail)): ?>
            <tr><td colspan="5" class="text-secondary py-3">Nothing recorded yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<?php /* Message owner: lib/Notes.php via /communications/note — in the app and by email,
         signed by you; their reply comes back into the same conversation. */ ?>
<div class="modal fade" id="noteModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h2 class="modal-title h6">Message <span id="noteTo"></span></h2>
      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body">
      <input type="hidden" id="noteMember">
      <label class="form-label small" for="noteSubject">Subject</label>
      <input class="form-control form-control-sm mb-2" id="noteSubject">
      <label class="form-label small" for="noteBody">Message</label>
      <textarea class="form-control form-control-sm" id="noteBody" rows="8"></textarea>
      <div class="small text-secondary mt-2">Goes to their Communications and their email, from you. Their reply comes back to the same conversation.</div>
      <div id="noteMsg" class="small mt-2"></div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
      <button type="button" class="btn btn-sm btn-primary" id="noteSend" onclick="noteSend()"><i class="bi bi-send"></i> Send</button>
    </div>
  </div></div>
</div>
<script>
function noteOpen(member, label, project){
  document.getElementById('noteMember').value = member;
  document.getElementById('noteTo').textContent = label;
  document.getElementById('noteSubject').value = project;
  document.getElementById('noteBody').value = '';
  document.getElementById('noteMsg').textContent = '';
  bootstrap.Modal.getOrCreateInstance(document.getElementById('noteModal')).show();
}
async function noteSend(){
  const msg = document.getElementById('noteMsg'), btn = document.getElementById('noteSend');
  const fd = new FormData();
  fd.append('_csrf_token', <?= json_encode(csrf_token()) ?>);
  fd.append('to_member', document.getElementById('noteMember').value);
  fd.append('subject', document.getElementById('noteSubject').value);
  fd.append('body', document.getElementById('noteBody').value);
  btn.disabled = true; msg.className = 'small mt-2'; msg.textContent = 'Sending…';
  try {
    const d = await (await fetch('/communications/note', {method:'POST', body:fd, headers:{'X-Requested-With':'XMLHttpRequest'}})).json();
    if (!d.ok) { msg.className = 'small mt-2 text-danger'; msg.textContent = d.error || 'Not sent.'; return; }
    const email = d.email === 'sent' ? 'and by email' : 'in the app only — the email was not sent (' + d.email + (d.email_error ? ': ' + d.email_error : '') + ')';
    msg.className = 'small mt-2 ' + (d.email === 'sent' ? 'text-success' : 'text-warning');
    msg.innerHTML = '';
    msg.append('Sent ' + email + '. ');
    const a = document.createElement('a'); a.href = d.url; a.textContent = 'Open conversation'; msg.append(a);
  } catch (e) { msg.className = 'small mt-2 text-danger'; msg.textContent = e.message; }
  finally { btn.disabled = false; }
}
</script>

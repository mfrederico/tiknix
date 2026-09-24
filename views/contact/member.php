<?php
/**
 * Support, for a signed-in member: write to support from inside the app (Contact::ask).
 * The message joins the admin queue (/contact/admin) and opens a conversation the member
 * is part of, so the answer arrives in Communications and by email.
 *
 * Vars: $project (instance bean|null — the selected project), $tickets [['ticket' => contact, 'thread' => int]]
 */
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$statusBadge = ['new' => 'text-bg-warning', 'responded' => 'text-bg-success', 'closed' => 'text-bg-secondary'];
?>
<div class="container-fluid" style="max-width:52rem">
  <h1 class="h4 mb-1"><i class="bi bi-life-preserver me-2"></i>Support</h1>
  <p class="text-muted small mb-4">Something not working, or a question? Write it here. The answer comes back to your
    <a href="/communications">Communications</a> and your email, and you can reply from either.</p>

  <div class="card shadow-sm mb-4">
    <div class="card-body">
      <form method="POST" action="/contact/ask">
        <?= csrf_field() ?>
        <div class="row g-2">
          <div class="col-sm-8">
            <label class="form-label small" for="supSubject">Subject</label>
            <input class="form-control" id="supSubject" name="subject" required maxlength="200" placeholder="What is it about?">
          </div>
          <div class="col-sm-4">
            <label class="form-label small" for="supCategory">Kind</label>
            <select class="form-select" id="supCategory" name="category">
              <option value="problem">Something is broken</option>
              <option value="general" selected>Question</option>
              <option value="billing">Billing</option>
              <option value="feature">Idea / request</option>
            </select>
          </div>
          <div class="col-12">
            <label class="form-label small" for="supMessage">Message</label>
            <textarea class="form-control" id="supMessage" name="message" rows="7" required
                      placeholder="What you did, what you expected, what happened instead. Paste any error you saw."></textarea>
          </div>
          <?php if ($project): ?>
          <div class="col-12">
            <label class="form-check-label small">
              <input type="checkbox" class="form-check-input" name="about_project" value="<?= (int) $project->id ?>" checked>
              About <strong><?= $h($project->displayName ?: $project->slug) ?></strong>
            </label>
          </div>
          <?php endif; ?>
        </div>
        <button class="btn btn-primary mt-3"><i class="bi bi-send me-1"></i>Send to support</button>
      </form>
    </div>
  </div>

  <?php if ($tickets): ?>
  <h2 class="h6 text-uppercase text-body-secondary fw-semibold mb-2" style="letter-spacing:.06em">Your messages</h2>
  <div class="list-group shadow-sm">
    <?php foreach ($tickets as $t): $c = $t['ticket']; ?>
      <a class="list-group-item list-group-item-action d-flex align-items-center gap-2"
         href="<?= $t['thread'] ? '/communications/thread/' . $t['thread'] : '#' ?>">
        <span class="flex-grow-1 text-truncate"><?= $h($c->subject) ?></span>
        <span class="badge <?= $statusBadge[(string) $c->status] ?? 'text-bg-light' ?>"><?= $h($c->status === 'new' ? 'waiting' : $c->status) ?></span>
        <span class="small text-muted text-nowrap"><?= $h(substr((string) $c->createdAt, 0, 10)) ?></span>
      </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

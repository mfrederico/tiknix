<?php
/**
 * Help Center.
 *
 * Rebuilt because the previous version was scaffolding that described a different
 * product: a search box with no handler, three cards of links to anchors that did not
 * exist anywhere on the page, "Creating an Account" on a site where sign-ups are closed,
 * and an assurance that "we implement CSRF protection on all forms" which was not true.
 *
 * Everything here points at a route that exists, and says only what the system does.
 * Where a capability is gated, it says so rather than sending someone to a 403.
 */
/* Help is PUBLIC — someone who cannot sign in still needs it — so a guest here is a
 * legitimate state rather than a broken one, and this branches instead of demanding a
 * member.
 *
 * Level comes from the member itself rather than a literal default: a guest IS the
 * public-user bean at level PUBLIC, so nothing gated gets offered to one. */
$__signedIn = !empty($isLoggedIn) && !empty($member);
$__mid      = $__signedIn ? member_id($member, 'help') : 0;
$__lvl      = $__signedIn ? (int) member_field($member, 'level') : LEVELS['PUBLIC'];
$__isAdmin  = $__lvl <= LEVELS['ADMIN'];
$has = fn(string $flag) => $__signedIn
    && class_exists('\app\Feature') && \app\Feature::allows($flag, $__mid, $__lvl);
?>
<div class="container py-4">

  <div class="ui-page-header mb-4">
    <span class="ui-eyebrow">Help</span>
    <h1>How Tiknix works</h1>
    <div class="ui-sub">
      You describe what you want built; agents build it into a real application with a
      database, a web server and a place to run. This is the short version of how the
      pieces fit together.
    </div>
  </div>

  <?php /* Support first, and prominent — it is the reason most people open a help page,
           and burying it under four cards of reading is how you get someone leaving
           instead of asking. */ ?>
  <div class="card border-primary mb-4">
    <div class="card-body d-flex flex-wrap align-items-center gap-3">
      <div class="flex-grow-1" style="min-width:16rem">
        <h5 class="card-title mb-1"><i class="bi bi-life-preserver text-primary me-2"></i>Stuck on something?</h5>
        <p class="card-text text-body-secondary mb-0">
          Send us a message and a person will read it. Tell us what you were doing and what
          happened instead — that is usually enough to sort it out in one reply.
        </p>
      </div>
      <div class="d-flex gap-2 flex-shrink-0">
        <a href="/contact" class="btn btn-primary btn-lg">
          <i class="bi bi-envelope me-1"></i> Message support
        </a>
        <a href="https://docs.tiknix.com" target="_blank" rel="noopener" class="btn btn-outline-secondary btn-lg">
          <i class="bi bi-book me-1"></i> Docs
        </a>
      </div>
    </div>
  </div>

  <h2 class="h4 mb-3">Building something</h2>
  <div class="row g-3 mb-4">

    <div class="col-md-6 col-lg-3">
      <div class="card h-100">
        <div class="card-body">
          <h5 class="card-title"><i class="bi bi-folder2-open text-primary me-2"></i>1. A project</h5>
          <p class="card-text small text-body-secondary">
            Everything you build lives in a project. It is a real application with its own
            database and its own address — not a sandbox you later have to port out of.
          </p>
          <a href="/projects" class="stretched-link small">Projects &rarr;</a>
        </div>
      </div>
    </div>

    <div class="col-md-6 col-lg-3">
      <div class="card h-100">
        <div class="card-body">
          <h5 class="card-title"><i class="bi bi-stars text-primary me-2"></i>2. Builder</h5>
          <p class="card-text small text-body-secondary">
            Describe a change in plain language. It is broken into a plan you can read and
            approve, then built, and you can watch it happen. Small and specific beats
            large and vague — "add a stop button to the arrangement panel", not "improve
            the UI".
          </p>
          <?php if ($has('workbench')): ?>
            <a href="/sidecar/app/workbench" class="stretched-link small">Open Builder &rarr;</a>
          <?php else: ?>
            <span class="small text-body-secondary"><i class="bi bi-lock"></i> Ask an admin to enable this</span>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="col-md-6 col-lg-3">
      <div class="card h-100">
        <div class="card-body">
          <h5 class="card-title"><i class="bi bi-database text-primary me-2"></i>3. Data</h5>
          <p class="card-text small text-body-secondary">
            Pipelines move and shape data on a schedule — imports, syncs, scheduled jobs.
            Each step is visible and re-runnable, so when something breaks you can see
            which step it was.
          </p>
          <?php if ($has('pipelines')): ?>
            <a href="/sidecar/app/pipelines" class="stretched-link small">Open Data &rarr;</a>
          <?php else: ?>
            <span class="small text-body-secondary"><i class="bi bi-lock"></i> Ask an admin to enable this</span>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="col-md-6 col-lg-3">
      <div class="card h-100">
        <div class="card-body">
          <h5 class="card-title"><i class="bi bi-rocket-takeoff text-primary me-2"></i>4. Deploy</h5>
          <p class="card-text small text-body-secondary">
            Put it live — on a domain of yours, or on infrastructure of yours. Build with
            our system, deploy to yours.
          </p>
          <?php if ($has('publisher')): ?>
            <a href="/sidecar/app/publisher" class="stretched-link small">Open Deploy &rarr;</a>
          <?php else: ?>
            <span class="small text-body-secondary"><i class="bi bi-lock"></i> Ask an admin to enable this</span>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <h2 class="h4 mb-3">Working with other people</h2>
  <div class="row g-3 mb-4">
    <div class="col-md-4">
      <div class="card h-100">
        <div class="card-body">
          <h5 class="card-title h6"><i class="bi bi-people text-success me-2"></i>Teams</h5>
          <p class="card-text small text-body-secondary">
            Share a project with people you work with. A team controls who can see and run
            what.
          </p>
          <a href="/teams" class="stretched-link small">Teams &rarr;</a>
        </div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="card h-100">
        <div class="card-body">
          <h5 class="card-title h6"><i class="bi bi-chat-left-text text-success me-2"></i>Communications</h5>
          <p class="card-text small text-body-secondary">
            Conversations tied to your work, in one inbox. Replies to a thread continue it
            rather than starting a new one.
          </p>
          <a href="/communications" class="stretched-link small">Communications &rarr;</a>
        </div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="card h-100">
        <div class="card-body">
          <h5 class="card-title h6"><i class="bi bi-envelope-plus text-success me-2"></i>Invitations</h5>
          <p class="card-text small text-body-secondary">
            Sign-ups are closed, so an invitation is the only way someone new gets an
            account. Each one is tied to a single email address.
          </p>
          <?php if ($has('invites')): ?>
            <a href="/invites" class="stretched-link small">Invitations &rarr;</a>
          <?php else: ?>
            <span class="small text-body-secondary"><i class="bi bi-lock"></i> Enabled per member by an admin</span>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <h2 class="h4 mb-3">Questions people actually ask</h2>
  <div class="accordion mb-4" id="faq">

    <div class="accordion-item">
      <h2 class="accordion-header">
        <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#faq-start">
          I've just got an account. What do I do first?
        </button>
      </h2>
      <div id="faq-start" class="accordion-collapse collapse show" data-bs-parent="#faq">
        <div class="accordion-body">
          Make a project. Nothing else in the system does anything until there is one to
          act on — the Builder, Data and Deploy all work <em>on</em> a project, which is
          why they show <strong>New Project</strong> until you have one.
          Then describe one small, real thing you want it to do and let the Builder do it
          end to end. A working small thing beats a plan.
          <div class="mt-2"><a href="/projects" class="btn btn-sm btn-primary">Start a project</a></div>
        </div>
      </div>
    </div>

    <div class="accordion-item">
      <h2 class="accordion-header">
        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq-gated">
          Why is something in the sidebar locked, or missing entirely?
        </button>
      </h2>
      <div id="faq-gated" class="accordion-collapse collapse" data-bs-parent="#faq">
        <div class="accordion-body">
          Some capabilities are switched on per person by an administrator rather than
          being on for everyone — Builder, Data, Deploy, the Store, the Architecture
          Explorer, API/MCP access and Invitations. If one is not enabled for you it is
          hidden rather than shown-and-refused, because a link that always fails is a
          worse error message than no link.
          <?php if ($__isAdmin): ?>
            <div class="mt-2 small">
              You are an administrator, so you have all of them. To grant one to someone
              else, open their account and use the <strong>Features</strong> switches:
              <a href="/admin/members">Members</a>.
            </div>
          <?php else: ?>
            <div class="mt-2 small">Ask an administrator to enable what you need.</div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="accordion-item">
      <h2 class="accordion-header">
        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq-levels">
          What do the permission levels mean?
        </button>
      </h2>
      <div id="faq-levels" class="accordion-collapse collapse" data-bs-parent="#faq">
        <div class="accordion-body">
          Lower numbers have more access.
          <ul class="mb-2">
            <li><strong>Root (1)</strong> — full system access</li>
            <li><strong>Admin (50)</strong> — administrative access</li>
            <li><strong>Member (100)</strong> — a normal account</li>
            <li><strong>Guest (101)</strong> — not signed in</li>
          </ul>
          Level is separate from the per-person switches above: being a Member says what
          you <em>are</em>, the switches say what you have been <em>given</em>.
        </div>
      </div>
    </div>

    <div class="accordion-item">
      <h2 class="accordion-header">
        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq-password">
          How do I change my password, or my name?
        </button>
      </h2>
      <div id="faq-password" class="accordion-collapse collapse" data-bs-parent="#faq">
        <div class="accordion-body">
          Both live on <a href="/member/edit">your profile</a>. The password section is
          optional — you can change your display name without touching it. Your
          <strong>display name</strong> is what other people see, including in invitation
          emails; leave it blank and it falls back to your name, then your username.
          <div class="mt-2">
            Locked out instead? Use <a href="/auth/forgot">Forgot password</a> on the sign-in page.
          </div>
        </div>
      </div>
    </div>

    <div class="accordion-item">
      <h2 class="accordion-header">
        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq-build-failed">
          A build failed or did something I didn't ask for. Now what?
        </button>
      </h2>
      <div id="faq-build-failed" class="accordion-collapse collapse" data-bs-parent="#faq">
        <div class="accordion-body">
          Nothing is silently lost — every task keeps its plan and its log, so you can read
          what it decided and where it stopped. The usual fix is a smaller, more specific
          request: name the file, page or behaviour you mean. If it looks like the system
          misbehaved rather than the instruction being unclear,
          <a href="/contact">tell us</a> and include the task.
        </div>
      </div>
    </div>
  </div>

  <div class="card bg-body-tertiary">
    <div class="card-body text-center">
      <h5 class="mb-2">Didn't answer it?</h5>
      <p class="text-body-secondary mb-3">A person reads every message that comes through here.</p>
      <a href="/contact" class="btn btn-primary"><i class="bi bi-envelope me-1"></i> Message support</a>
    </div>
  </div>
</div>

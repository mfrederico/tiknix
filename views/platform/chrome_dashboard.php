<?php
/* The Builder on the dashboard (Chrome slot 'dashboard', lib/controlplane.php).
   Not tied to one model: a project builds with whichever agent it is connected to (Claude, an API
   key, or a model of the member's own). A member with no project yet is shown how to start. */
$__me = \Flight::getMember();
$__projects = $__me && (int) $__me->id > 0 ? \app\MemberProjects::accessible((int) $__me->id) : [];
?>
<div class="ui-panel mb-4 feature-panel">
    <div class="ui-panel-body">
        <div class="row align-items-center g-3">
            <div class="col-md-8">
                <h3 class="text-primary mb-2"><i class="bi bi-hammer me-2"></i>Build apps with AI agents</h3>
                <p class="text-secondary mb-3">
                    Say what you want in plain words. An AI agent plans it, writes the code in your project's
                    own conventions, checks every page it changed, and puts it live — you answer its questions
                    and approve the plan.
                </p>
                <?php if (!$__projects): ?>
                    <p class="ui-eyebrow mb-2">Your first project, in three steps</p>
                    <ol class="small mb-3 ps-3">
                        <li class="mb-1"><strong>Create a project.</strong> Give it a name; its own server is ready in a few minutes.</li>
                        <li class="mb-1"><strong>Connect an agent.</strong> On the project's Agents page, sign in with Claude, add an API key, or use a model of your own.</li>
                        <li class="mb-1"><strong>Ask for what you want.</strong> Open Ask in the Builder and describe it — "a booking page for my classes". The agent plans it, asks what it needs, and builds.</li>
                    </ol>
                    <a href="/projects" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Create your first project</a>
                <?php else: ?>
                    <ul class="list-unstyled mb-3 small">
                        <li class="mb-1"><i class="bi bi-check-circle-fill text-success me-2"></i>Ask for features, fixes and changes in your own words</li>
                        <li class="mb-1"><i class="bi bi-check-circle-fill text-success me-2"></i>Agents plan, build and test the work, then check it in a browser</li>
                        <li class="mb-1"><i class="bi bi-check-circle-fill text-success me-2"></i>Work on shared projects with your team</li>
                    </ul>
                    <a href="/projects" class="btn btn-primary"><i class="bi bi-grid-3x3-gap"></i> Projects</a>
                    <a href="/teams" class="btn btn-outline-primary ms-2"><i class="bi bi-people"></i> Manage Teams</a>
                <?php endif; ?>
            </div>
            <div class="col-md-4 text-center d-none d-md-block">
                <i class="bi bi-cpu display-1 text-primary opacity-50"></i>
            </div>
        </div>
    </div>
</div>

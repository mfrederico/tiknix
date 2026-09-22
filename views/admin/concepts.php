<?php
/**
 * Concepts — installed pluggable features and their on/off switch (ROOT).
 *
 * Vars: $installed — name => [manifest, error, enabled, problems[], provenance]
 *       $catalog   — [results[], broken[], source, error]
 *
 * This page switches concepts. It cannot install one: that is a build task
 * (clitool --concept-install in a worktree), because the web process must never write
 * executable PHP into its own tree.
 */
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
?>
<div class="container-fluid">
    <div class="row">
        <div class="col-12 d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
            <div>
                <h1 class="h3 mb-1">Plugins</h1>
                <p class="text-muted mb-0">
                    Pluggable features ("concepts") installed under <code>concepts/</code>. Switched off, a plugin is inert:
                    none of its classes load, none of its routes answer, none of its partials render.
                </p>
            </div>
            <a href="/admin" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Admin</a>
        </div>
    </div>

    <!-- Installed -->
    <div class="row mb-4">
        <div class="col-12">
            <h2 class="h5">Installed <span class="badge bg-secondary"><?= count($installed) ?></span></h2>

            <?php if (!$installed): ?>
                <div class="alert alert-light border">
                    No plugins are installed on this install. To add one to a project, use
                    <strong>Install</strong> in the catalog below — it runs as a build (a commit and merge on that
                    project), and once merged the plugin is switched on with
                    <code>clitool --concept-enable=&lt;name&gt;</code> in that project, or on its Plugins page.
                </div>
            <?php endif; ?>

            <?php foreach ($installed as $name => $c): ?>
                <?php
                $m = $c['manifest'];
                $broken = $m === null;
                $ready = !$broken && !$c['problems'];
                $border = $broken ? 'danger' : ($c['enabled'] ? ($c['problems'] ? 'danger' : 'success') : 'secondary');
                ?>
                <div class="card mb-3 border-<?= $border ?>">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                            <div>
                                <h3 class="h5 mb-1">
                                    <?= $h($name) ?>
                                    <?php if (!$broken): ?><small class="text-muted">v<?= $h($m->version) ?></small><?php endif; ?>
                                    <?php if ($broken): ?>
                                        <span class="badge bg-danger">broken</span>
                                    <?php elseif ($c['enabled']): ?>
                                        <span class="badge bg-success">enabled</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">disabled</span>
                                    <?php endif; ?>
                                </h3>
                                <?php if (!$broken && ($m->title !== '' || $m->blurb !== '')): ?>
                                    <p class="mb-2"><?= $m->title !== '' ? '<strong>' . $h($m->title) . '</strong> — ' : '' ?><?= $h($m->blurb) ?></p>
                                <?php endif; ?>
                            </div>

                            <?php if (!$broken): ?>
                                <?php if ($c['enabled']): ?>
                                    <form method="POST" action="/admin/conceptdisable"
                                          onsubmit="return confirm('Disable <?= $h($name) ?>? Its routes stop answering immediately. Its data is kept.')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="name" value="<?= $h($name) ?>">
                                        <button type="submit" class="btn btn-outline-danger btn-sm"><i class="bi bi-power"></i> Disable</button>
                                    </form>
                                <?php else: ?>
                                    <form method="POST" action="/admin/conceptenable"
                                          onsubmit="return confirm('Enable <?= $h($name) ?>? Its seeds run against the database and its routes start answering.')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="name" value="<?= $h($name) ?>">
                                        <button type="submit" class="btn btn-success btn-sm" <?= $ready ? '' : 'disabled' ?>
                                                title="<?= $ready ? '' : 'Fix the problems listed below first' ?>">
                                            <i class="bi bi-power"></i> Enable
                                        </button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>

                        <?php if ($broken): ?>
                            <div class="alert alert-danger mb-0"><?= $h($c['error']) ?></div>
                        <?php else: ?>
                            <dl class="row small mb-0">
                                <?php
                                $facts = [
                                    'Routes'            => $m->controllers ? implode(', ', array_map(fn($x) => '/' . strtolower($x), $m->controllers)) : '',
                                    'Owns beans'        => implode(', ', $m->beans),
                                    'Uses beans'        => implode(', ', $m->usesBeans),
                                    'Requires concepts' => implode(', ', $m->requiresConcepts),
                                    'Requires core'     => implode(', ', $m->requiresLib),
                                    'Hosts slots'       => implode(', ', array_merge(array_keys($m->hostsSlots), $m->hostsCollect)),
                                    'Fills slots'       => implode(', ', array_merge(array_keys($m->slots), array_keys($m->collect))),
                                    'Capabilities'      => implode(', ', $m->capabilities),
                                ];
                                foreach ($facts as $label => $value):
                                    if ($value === '') continue; ?>
                                    <dt class="col-sm-3 col-lg-2 text-muted fw-normal"><?= $h($label) ?></dt>
                                    <dd class="col-sm-9 col-lg-10 mb-1"><code><?= $h($value) ?></code></dd>
                                <?php endforeach; ?>

                                <dt class="col-sm-3 col-lg-2 text-muted fw-normal">Origin</dt>
                                <dd class="col-sm-9 col-lg-10 mb-1">
                                    <?php if ($c['provenance']): ?>
                                        catalog <code><?= $h($c['provenance']['source'] ?? '?') ?></code>,
                                        v<?= $h($c['provenance']['version'] ?? '?') ?>,
                                        installed <?= $h(substr((string) ($c['provenance']['installed_at'] ?? ''), 0, 10)) ?>
                                        <?php if (($c['provenance']['version'] ?? null) !== $m->version): ?>
                                            <span class="badge bg-warning text-dark">adapted: manifest now says v<?= $h($m->version) ?></span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        authored on this install
                                    <?php endif; ?>
                                </dd>
                            </dl>

                            <?php if ($c['problems']): ?>
                                <div class="alert alert-<?= $c['enabled'] ? 'danger' : 'warning' ?> mt-3 mb-0">
                                    <strong><?= $c['enabled']
                                        ? 'This concept is ENABLED but no longer verifies — pages that use it will error:'
                                        : 'Cannot be enabled yet:' ?></strong>
                                    <ul class="mb-0">
                                        <?php foreach ($c['problems'] as $p): ?><li><?= $h($p) ?></li><?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Catalog -->
    <div class="row">
        <div class="col-12">
            <h2 class="h5">In the catalog</h2>

            <?php if ($catalog['error'] !== null): ?>
                <div class="alert alert-danger">
                    <strong>The catalog could not be read.</strong> This is a failure to reach it, not an empty catalog.
                    <div class="small mt-1"><?= $h($catalog['error']) ?></div>
                </div>
            <?php else: ?>
                <p class="text-muted small mb-2">Source: <code><?= $h($catalog['source']) ?></code></p>
                <?php if ($project !== null && !empty($project['here'])): ?>
                    <p class="small mb-2">
                        <i class="bi bi-box-arrow-in-down"></i>
                        This install, <strong><?= $h($project['name']) ?></strong>, is the project. A plugin is installed
                        by a command run in its directory (it must end as a commit, so the web process never writes it),
                        then switched on above.
                    </p>
                <?php elseif ($project !== null): ?>
                    <p class="small mb-2">
                        <i class="bi bi-box-arrow-in-down"></i>
                        Installs go into the selected project, <strong><?= $h($project['name']) ?></strong>
                        (<code><?= $h($project['slug']) ?></code>) — change it from the project switcher in the header.
                    </p>
                <?php else: ?>
                    <div class="alert alert-warning small py-2">
                        No project is selected, so there is nowhere to install into. <a href="/projects">Choose a project</a> first.
                    </div>
                <?php endif; ?>
                <?php if (!$catalog['results']): ?>
                    <div class="alert alert-light border">The catalog was reached and holds no concepts yet.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead><tr><th>Plugin</th><th>Version</th><th>What it does</th><th>Requires</th><th></th></tr></thead>
                            <tbody>
                            <?php foreach ($catalog['results'] as $r): ?>
                                <tr>
                                    <td><strong><?= $h($r['name']) ?></strong><br><small class="text-muted"><?= $h($r['kind']) ?></small></td>
                                    <td>v<?= $h($r['version']) ?></td>
                                    <td>
                                        <?= $h($r['title']) ?>
                                        <div class="small text-muted"><?= $h($r['blurb']) ?></div>
                                    </td>
                                    <td class="small"><?= $h(implode(', ', array_merge($r['requires']['concepts'], $r['requires']['lib'])) ?: '—') ?></td>
                                    <td class="text-nowrap">
                                        <?php if ($project === null): ?>
                                            <span class="text-muted small">select a project</span>
                                        <?php elseif (!empty($r['in_project'])): ?>
                                            <span class="badge bg-success"><?= !empty($project['here']) ? 'installed' : 'in ' . $h($project['name']) ?></span>
                                            <?php if (empty($installed[$r['name']]['enabled'])): ?>
                                            <div class="small text-muted mt-1">switch on:
                                                <code><?= $h('cd ' . $project['dir'] . ' && php scripts/clitool.php --concept-enable=' . $r['name']) ?></code></div>
                                            <?php endif; ?>
                                        <?php elseif (!empty($project['here'])): ?>
                                            <div class="small">install here:
                                                <code><?= $h('cd ' . $project['dir'] . ' && php scripts/clitool.php --concept-install=' . $r['name']) ?></code></div>
                                        <?php else: ?>
                                            <form method="POST" action="/admin/conceptinstall" class="d-inline"
                                                  onsubmit="return confirm('Install <?= $h($r['name']) ?> into <?= $h($project['name']) ?>? It runs now as a build with no agent — a commit and merge on that project, usually under a minute.')">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="name" value="<?= $h($r['name']) ?>">
                                                <button type="submit" class="btn btn-primary btn-sm">
                                                    <i class="bi bi-box-arrow-in-down"></i> Install into <?= $h($project['name']) ?>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
                <?php foreach ($catalog['broken'] as $bname => $why): ?>
                    <div class="alert alert-warning small">Catalog entry <code><?= $h($bname) ?></code> is broken and was skipped: <?= $h($why) ?></div>
                <?php endforeach; ?>
            <?php endif; ?>

            <p class="small text-muted mb-0">
                <strong>Install</strong> queues a build rather than copying files: the plugin (and anything it requires) becomes
                a plan in Builder, and running it commits and merges the code like any other task. It has to end as a commit —
                build agents work in worktrees cut from the committed base, so files dropped into the live tree would be
                invisible to them. A plugin is <em>copied</em> in and becomes that project's own code.
            </p>
        </div>
    </div>
</div>

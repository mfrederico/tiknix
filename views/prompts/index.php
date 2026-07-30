<div class="container-fluid">
    <div class="row g-4">
        <main class="col-12">
            <div class="ui-page-header">
                <span class="ui-eyebrow">Workspace</span>
                <h1>Prompt Log</h1>
                <div class="ui-sub">
                    Everything you have asked this system to build &mdash; goals you decomposed, tasks you
                    wrote, and what you typed in the Terminal. Kept when you write it, so it survives a
                    planner that fails and the next thing you start.
                </div>
            </div>

            <?php if (!empty($harvestError)): ?>
                <div class="alert alert-warning">
                    <i class="bi bi-exclamation-triangle"></i>
                    <strong>Terminal prompts could not be read just now</strong>, so this list may be missing
                    recent ones: <code><?= htmlspecialchars((string) $harvestError) ?></code>
                </div>
            <?php endif; ?>

            <?php
            /* Private, and said out loud. People type credentials into prompts, so it matters
               that the person reading this knows nobody else can. */
            ?>
            <div class="alert alert-secondary d-flex align-items-start gap-2 py-2">
                <i class="bi bi-lock-fill mt-1"></i>
                <div class="small mb-0">
                    <strong>Only you can see this.</strong> Prompts often contain things you would not
                    put in a shared log &mdash; passwords, keys, customer names &mdash; so there is no
                    cross-member view of this page, including for admins.
                </div>
            </div>

            <form method="GET" action="/prompts" class="d-flex flex-wrap gap-2 align-items-center mb-3">
                <div class="btn-group">
                    <a href="/prompts<?= $q !== '' ? '?q=' . urlencode($q) : '' ?>"
                       class="btn btn-sm <?= $source === '' ? 'btn-primary' : 'btn-outline-secondary' ?>">
                        All <span class="badge bg-light text-dark ms-1"><?= (int) ($counts[''] ?? 0) ?></span>
                    </a>
                    <?php foreach ($sources as $key => $label): ?>
                        <a href="/prompts?source=<?= urlencode($key) ?><?= $q !== '' ? '&q=' . urlencode($q) : '' ?>"
                           class="btn btn-sm <?= $source === $key ? 'btn-primary' : 'btn-outline-secondary' ?>">
                            <?= htmlspecialchars($label) ?>
                            <span class="badge bg-light text-dark ms-1"><?= (int) ($counts[$key] ?? 0) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
                <?php if ($source !== ''): ?><input type="hidden" name="source" value="<?= htmlspecialchars($source) ?>"><?php endif; ?>
                <div class="ms-auto d-flex gap-2">
                    <input type="search" name="q" class="form-control form-control-sm" style="min-width:240px"
                           placeholder="Search your prompts…" value="<?= htmlspecialchars($q) ?>">
                    <button class="btn btn-sm btn-outline-primary" type="submit"><i class="bi bi-search"></i></button>
                </div>
            </form>

            <?php if (empty($rows)): ?>
                <div class="alert alert-info">
                    <i class="bi bi-info-circle"></i>
                    <?php if ($q !== '' || $source !== ''): ?>
                        Nothing matches that filter.
                        <a href="/prompts">Show everything</a>.
                    <?php else: ?>
                        No prompts recorded yet. They appear here as you decompose a goal, create a task,
                        or type in the Terminal.
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="ui-panel">
                    <div class="ui-panel-body p-0">
                        <div class="list-group list-group-flush">
                            <?php foreach ($rows as $i => $r): ?>
                                <?php
                                    $src   = (string) $r->source;
                                    $badge = ['decompose' => 'info', 'task' => 'secondary', 'terminal' => 'dark'][$src] ?? 'secondary';
                                    $icon  = ['decompose' => 'diagram-3', 'task' => 'card-checklist', 'terminal' => 'terminal'][$src] ?? 'chat-left-text';
                                    $body  = (string) $r->body;
                                    $long  = mb_strlen($body) > 400;
                                ?>
                                <div class="list-group-item">
                                    <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                                        <span class="badge bg-<?= $badge ?>">
                                            <i class="bi bi-<?= $icon ?> me-1"></i><?= htmlspecialchars($sources[$src] ?? $src) ?>
                                        </span>
                                        <?php if (!empty($r->instanceTag)): ?>
                                            <span class="badge text-bg-light border"><?= htmlspecialchars((string) $r->instanceTag) ?></span>
                                        <?php endif; ?>
                                        <span class="text-body-secondary small"><?= htmlspecialchars((string) $r->createdAt) ?></span>
                                        <?php if ((int) $r->planRef > 0): ?>
                                            <a class="small ms-auto" target="_top"
                                               href="/sidecar/app/workbench">what it became &rarr; plan #<?= (int) $r->planRef ?></a>
                                        <?php endif; ?>
                                    </div>

                                    <?php /* Escaped, never parsed: a prompt is the literal text you wrote, and
                                             rendering it as markdown would reflow the very thing you came to read. */ ?>
                                    <pre class="mb-1 p-2 bg-body-tertiary border rounded prompt-body<?= $long ? ' prompt-clipped' : '' ?>"
                                         id="pb<?= (int) $r->id ?>"
                                         style="white-space:pre-wrap;word-break:break-word;font-size:.85rem"><?= htmlspecialchars($body) ?></pre>

                                    <div class="d-flex gap-3">
                                        <?php if ($long): ?>
                                            <button type="button" class="btn btn-link btn-sm p-0 prompt-more" data-target="pb<?= (int) $r->id ?>">Show all</button>
                                        <?php endif; ?>
                                        <button type="button" class="btn btn-link btn-sm p-0 prompt-copy" data-target="pb<?= (int) $r->id ?>">Copy</button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <div class="form-text mt-2">
                    Showing <?= count($rows) ?> prompt<?= count($rows) === 1 ? '' : 's' ?>, newest first.
                </div>
            <?php endif; ?>
        </main>
    </div>
</div>

<style>
.prompt-clipped { max-height: 9rem; overflow: hidden; }
</style>

<script>
(function () {
    document.querySelectorAll('.prompt-more').forEach(function (b) {
        b.addEventListener('click', function () {
            var el = document.getElementById(b.dataset.target);
            var clipped = el.classList.toggle('prompt-clipped');
            b.textContent = clipped ? 'Show all' : 'Show less';
        });
    });
    document.querySelectorAll('.prompt-copy').forEach(function (b) {
        b.addEventListener('click', function () {
            var el = document.getElementById(b.dataset.target);
            // Say plainly when the copy did not happen — clipboard access is refused often
            // enough (insecure context, permissions) that a silent no-op is misleading.
            navigator.clipboard.writeText(el.textContent).then(function () {
                var was = b.textContent; b.textContent = 'Copied';
                setTimeout(function () { b.textContent = was; }, 1200);
            }, function (err) {
                b.textContent = 'Copy failed';
                console.error('Clipboard write refused:', err);
            });
        });
    });
})();
</script>

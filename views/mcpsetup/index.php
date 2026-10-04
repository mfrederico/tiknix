<div class="container-fluid py-4">
    <?php
    $flash = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    foreach ($flash as $msg):
    ?>
        <div class="alert alert-<?= $msg['type'] === 'error' ? 'danger' : $msg['type'] ?> alert-dismissible fade show">
            <?= htmlspecialchars(($msg['message']) ?? '') ?>
            <button type="button" class="btn-close" aria-label="Close" data-bs-dismiss="alert"></button>
        </div>
    <?php endforeach; ?>

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
        <h2><i class="bi bi-robot me-2"></i>MCP services</h2>
        <?php if (!empty($project)): ?>
          <div class="text-muted small">
            Configuring <strong><?= htmlspecialchars($project['name']) ?></strong>
            (<code><?= htmlspecialchars($project['url']) ?></code>)<?= $project['here'] ? '' : ' — change it from the project switcher in the header' ?>.
          </div>
        <?php endif; ?>
        </div>
    </div>

    <!-- Tabs Navigation -->
    <ul class="nav nav-tabs mb-4" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link <?= $activeTab === 'servers' ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#servers" type="button">
                <i class="bi bi-hdd-network me-1"></i> MCP Servers
                <span class="badge bg-secondary ms-1"><?= (empty($tiknixOff) ? 1 : 0) + count($userServers) ?></span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link <?= $activeTab === 'skills' ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#skills" type="button" id="skillsTabBtn">
                <i class="bi bi-stars me-1"></i> Skills &amp; plugins
                <span class="badge bg-secondary ms-1"><?= count($skills) ?></span>
            </button>
        </li>
        <?php if ($isRoot): ?>
        <li class="nav-item" role="presentation">
            <button class="nav-link <?= $activeTab === 'tools' ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#tools" type="button">
                <i class="bi bi-tools me-1"></i> MCP Tools
                <span class="badge bg-secondary ms-1"><?= count($tools) ?></span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link <?= $activeTab === 'hooks' ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#hooks" type="button">
                <i class="bi bi-lightning me-1"></i> Hooks
                <span class="badge bg-secondary ms-1"><?= count($hookFiles) ?></span>
            </button>
        </li>
        <?php endif; ?>
    </ul>

    <!-- Tab Content -->
    <div class="tab-content">
        <!-- MCP Servers Tab -->
        <div class="tab-pane fade <?= $activeTab === 'servers' ? 'show active' : '' ?>" id="servers" role="tabpanel">
            <div class="row">
                <div class="col-lg-8">
                    <div class="alert alert-light border small mb-4">
                        <strong>What MCP servers are.</strong> An MCP server is an outside service an agent can call as a tool &mdash; search a knowledge base, read a ticket, drive a browser. The servers listed here are given to <em>every</em> agent of this project: build tasks, plans, the terminal and pipeline agent steps. Each one's tools are described to the agent at the start of every session, so add only what your agents will use.
                    </div>

                    <!-- Connectivity: asked in the project's container, where its agents run (Mcpsetup::test) -->
                    <div class="card mb-4" id="mcpTestCard">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h6 class="mb-0"><i class="bi bi-activity me-1"></i> Can this project's agents reach their MCP servers?</h6>
                            <button class="btn btn-sm btn-outline-primary" id="mcpTestBtn" type="button"><i class="bi bi-play me-1"></i>Test now</button>
                        </div>
                        <div class="card-body small" id="mcpTestOut">
                            <span class="text-muted">Runs inside the project's container: the app's own server (what every build task is given) and each server in its <code>.mcp.json</code> are asked to <code>initialize</code> and list their tools. Keys and headers stay in the container.</span>
                        </div>
                    </div>

                    <!-- The app's own server: its own card, its danger zone, its one-click restore -->
                    <?php if (!empty($tiknixOff)): ?>
                    <div class="alert alert-danger border-danger border-2 d-flex flex-wrap align-items-center gap-3 mb-4" id="tiknixOffBanner">
                        <i class="bi bi-exclamation-octagon-fill fs-4"></i>
                        <div class="me-auto"><strong>The tiknix server is removed from this project's agents.</strong><br><span class="small"><?= htmlspecialchars($tiknixBreaks) ?></span></div>
                        <form method="POST" action="/mcpsetup/restoreTiknix"><?= csrf_field() ?><button class="btn btn-danger"><i class="bi bi-arrow-counterclockwise me-1"></i>Restore the tiknix server</button></form>
                    </div>
                    <?php endif; ?>
                    <div class="card mb-4 <?= !empty($tiknixOff) ? 'border-danger' : '' ?>" id="tiknixCard">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h6 class="mb-0"><i class="bi bi-shield-check me-1"></i> <code>tiknix</code> &mdash; this project's own server</h6>
                            <?php if (!empty($tiknixOff)): ?><span class="badge bg-danger">removed</span><?php else: ?><span class="badge bg-success">given to every agent</span><?php endif; ?>
                        </div>
                        <div class="card-body small">
                            <p class="mb-2">The project serves its own tools to its agents: the codebase map, logs, the database schema, the plan and task tools, and every tool in its <code>mcptools/</code>. Every build task, plan and terminal session is given it automatically &mdash; there is nothing to configure.</p>
                            <?php if (empty($tiknixOff)): ?>
                            <details>
                                <summary class="text-danger">Danger zone: remove it from this project's agents</summary>
                                <div class="border border-danger rounded p-3 mt-2">
                                    <p class="mb-2"><strong>What stops working:</strong> <?= htmlspecialchars($tiknixBreaks) ?></p>
                                    <p class="mb-2">It is your project and you may do this &mdash; for example to give agents only servers of your own. It is undone with one click, here.</p>
                                    <form method="POST" action="/mcpsetup/removeTiknix" class="d-flex flex-wrap gap-2 align-items-center">
                                        <?= csrf_field() ?>
                                        <label for="tiknixConfirm" class="mb-0">Type <code><?= htmlspecialchars(\app\Mcpsetup::TIKNIX_CONFIRM) ?></code> to confirm:</label>
                                        <input type="text" class="form-control form-control-sm" id="tiknixConfirm" name="confirm" autocomplete="off" style="max-width:12rem" required>
                                        <button class="btn btn-sm btn-outline-danger">Remove the tiknix server</button>
                                    </form>
                                </div>
                            </details>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Custom Servers -->
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h6 class="mb-0"><i class="bi bi-server me-1"></i> Custom Servers</h6>
                            <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addServerModal">
                                <i class="bi bi-plus-lg"></i> Add Server
                            </button>
                        </div>
                        <?php if (empty($userServers)): ?>
                        <div class="card-body text-center text-muted py-4">No custom servers configured.</div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr><th>Name</th><th>Type</th><th>Configuration</th><th></th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($userServers as $slug => $server): $cfg = $server['config']; ?>
                                    <tr>
                                        <td><code><?= htmlspecialchars(($slug) ?? '') ?></code></td>
                                        <td><span class="badge bg-<?= ($cfg['type'] ?? 'stdio') === 'http' ? 'info' : 'success' ?>"><?= strtoupper($cfg['type'] ?? 'stdio') ?></span></td>
                                        <td class="small font-monospace text-truncate" style="max-width:300px;">
                                            <?= htmlspecialchars(($cfg['type'] ?? 'stdio') === 'http' ? ($cfg['url'] ?? '') : ($cfg['command'] ?? '')) ?>
                                        </td>
                                        <td class="text-end">
                                            <button class="btn btn-sm btn-outline-primary" aria-label="Edit server" title="Edit server" onclick="editServer('<?= htmlspecialchars(($slug) ?? '', ENT_QUOTES) ?>', <?= htmlspecialchars((json_encode($cfg)) ?? '', ENT_QUOTES) ?>)"><i class="bi bi-pencil"></i></button>
                                            <button class="btn btn-sm btn-outline-danger" aria-label="Delete server" title="Delete server" onclick="deleteServer('<?= htmlspecialchars(($slug) ?? '', ENT_QUOTES) ?>')"><i class="bi bi-trash"></i></button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="card">
                        <div class="card-header"><h6 class="mb-0"><i class="bi bi-info-circle me-1"></i> About MCP Servers</h6></div>
                        <div class="card-body small">
                            <p>MCP (Model Context Protocol) servers give an agent more tools.</p>
                            <p><strong>STDIO:</strong> Local process communicating via stdin/stdout</p>
                            <p class="mb-0"><strong>HTTP:</strong> Remote server via HTTP requests</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Skills & plugins Tab -->
        <div class="tab-pane fade <?= $activeTab === 'skills' ? 'show active' : '' ?>" id="skills" role="tabpanel">
            <div class="alert alert-light border small mb-4">
                <strong>What skills and plugins are.</strong> A <em>skill</em> is a set of instructions an agent loads when a task matches it &mdash; &ldquo;how we write release notes&rdquo;, &ldquo;how to add a report page&rdquo;. A <em>plugin</em> is a bundle from a marketplace that can bring skills, commands and tools of its own. Both belong to <strong>this project</strong> and are given to every one of its agents, whichever model they run on. Each skill's one-line description, and everything a plugin adds, is read at the start of every session &mdash; so keep what your agents use and remove what they don't.
            </div>
            <div id="skillsMsg" class="small mb-3"></div>
            <div class="row">
                <div class="col-lg-6">
                    <div class="card mb-4">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h6 class="mb-0"><i class="bi bi-journal-text me-1"></i> Skills</h6>
                            <button class="btn btn-sm btn-primary" type="button" id="skillNewBtn"><i class="bi bi-plus-lg"></i> Add a skill</button>
                        </div>
                        <?php if ($skillsError !== ''): ?>
                        <div class="card-body text-danger small"><?= htmlspecialchars($skillsError) ?></div>
                        <?php elseif (!$skills): ?>
                        <div class="card-body text-muted small">No skills yet. Add one to teach this project's agents how you want something done.</div>
                        <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($skills as $sk): ?>
                            <div class="list-group-item d-flex justify-content-between align-items-start gap-2">
                                <div>
                                    <code><?= htmlspecialchars($sk['name']) ?></code>
                                    <?php if ($sk['managed']): ?><span class="badge bg-secondary" title="Installed by an enabled Tiknix plugin; managed with that plugin">from a Tiknix plugin</span><?php endif; ?>
                                    <div class="small text-muted"><?= htmlspecialchars($sk['description'] ?: 'no description') ?></div>
                                </div>
                                <?php if (!$sk['managed']): ?>
                                <div class="text-nowrap">
                                    <button class="btn btn-sm btn-outline-secondary" type="button" data-skill-edit="<?= htmlspecialchars($sk['name']) ?>"><i class="bi bi-pencil"></i></button>
                                    <button class="btn btn-sm btn-outline-danger" type="button" data-skill-del="<?= htmlspecialchars($sk['name']) ?>"><i class="bi bi-trash"></i></button>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="card mb-4">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h6 class="mb-0"><i class="bi bi-puzzle me-1"></i> Plugins</h6>
                            <button class="btn btn-sm btn-primary" type="button" id="pluginBrowseBtn"><i class="bi bi-plus-lg"></i> Add from a marketplace</button>
                        </div>
                        <div class="card-body small" id="pluginsInstalled"><span class="text-muted">Open this tab to load what is installed.</span></div>
                    </div>
                    <div class="card mb-4">
                        <div class="card-header"><h6 class="mb-0"><i class="bi bi-shop me-1"></i> Marketplaces</h6></div>
                        <div class="card-body small">
                            <div id="marketplacesList" class="mb-2 text-muted">&hellip;</div>
                            <form id="marketAddForm" class="d-flex gap-2">
                                <input class="form-control form-control-sm font-monospace" id="marketSource" placeholder="owner/repo on GitHub, or an https:// URL" required>
                                <button class="btn btn-sm btn-outline-primary text-nowrap">Add marketplace</button>
                            </form>
                            <div class="form-text">A marketplace is a catalogue of plugins. Anyone can publish one: a plugin from it runs inside this project's container with its agents' access, so add only a source you trust.</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($isRoot): ?>
        <!-- MCP Tools Tab -->
        <div class="tab-pane fade <?= $activeTab === 'tools' ? 'show active' : '' ?>" id="tools" role="tabpanel">
            <div class="alert alert-light border small mb-4">
                <strong>What MCP tools are.</strong> These are this project's <em>own</em> tools: PHP files in its <code>mcptools/</code> folder that the <code>tiknix</code> server offers to its agents (and to anyone holding one of its API keys). A tool is how you give an agent a safe, named action on your data &mdash; &ldquo;list today's bookings&rdquo;, &ldquo;refund an order&rdquo; &mdash; instead of letting it write queries. They are code, so they are written in the app and committed like the rest of it; this tab lists what the project has.
            </div>
            <div class="row">
                <div class="col-lg-9">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h6 class="mb-0"><i class="bi bi-tools me-1"></i> MCP Tools</h6>
                            <a href="<?= htmlspecialchars($project['url']) ?>/mcptools/create" class="btn btn-sm btn-primary" title="Opens the tool editor in the app itself"><i class="bi bi-plus-lg"></i> Create Tool</a>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover table-sm mb-0">
                                <thead class="table-light">
                                    <tr><th>Name</th><th>File</th><th>Changed</th><th></th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($tools as $tool): ?>
                                    <tr>
                                        <td><code class="small">tiknix_<?= htmlspecialchars(($tool['name']) ?? '') ?></code></td>
                                        <td class="small"><code><?= htmlspecialchars(($tool['file']) ?? '') ?></code></td>
                                        <td class="small text-muted"><?= date('M j, g:ia', $tool['modTime']) ?></td>
                                        <td class="text-end">
                                            <a href="<?= htmlspecialchars($project['url']) ?>/mcptools/edit?name=<?= urlencode($tool['name']) ?>" class="btn btn-sm btn-outline-primary" aria-label="Edit tool" title="Edit tool in the app"><i class="bi bi-pencil"></i></a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="col-lg-3">
                    <div class="card">
                        <div class="card-header"><h6 class="mb-0"><i class="bi bi-lightbulb me-1"></i> Quick Reference</h6></div>
                        <div class="card-body small">
                            <p>Tools extend Claude's capabilities. They are the app's own code (<code>mcptools/</code>), served by its MCP and edited in the app itself. Each tool needs:</p>
                            <ul class="mb-0">
                                <li><code>$name</code> - identifier</li>
                                <li><code>$description</code> - for Claude</li>
                                <li><code>$inputSchema</code> - params</li>
                                <li><code>execute()</code> - logic</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Hooks Tab -->
        <div class="tab-pane fade <?= $activeTab === 'hooks' ? 'show active' : '' ?>" id="hooks" role="tabpanel">
            <div class="alert alert-light border small mb-4">
                <strong>What hooks are.</strong> A hook is a script that runs automatically at a moment in an agent's work &mdash; before it edits a file, after it runs a command, when it finishes. Hooks are the project's guard rails: the ones here check an edit against the project's rules before it is written (and refuse it when it breaks one), and keep agents inside the project. A hook that exits with an error stops the action and tells the agent why. They run on the server with the agent's access, so only a ROOT administrator can change them.
            </div>
            <div class="row">
                <div class="col-lg-6">
                    <div class="card mb-4">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h6 class="mb-0"><i class="bi bi-file-code me-1"></i> Hook Scripts</h6>
                            <a href="/hooks/create" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg"></i> Create Hook</a>
                        </div>
                        <div class="list-group list-group-flush">
                            <?php foreach ($hookFiles as $file): ?>
                            <div class="list-group-item d-flex justify-content-between align-items-center">
                                <div>
                                    <strong><?= htmlspecialchars(($file['name']) ?? '') ?></strong>
                                    <br><small class="text-muted"><?= date('M j, g:ia', $file['modTime']) ?> - <?= number_format($file['size']) ?> bytes</small>
                                </div>
                                <a href="/hooks/edit?name=<?= urlencode($file['name']) ?>" class="btn btn-sm btn-outline-primary" aria-label="Edit hook" title="Edit hook"><i class="bi bi-pencil"></i></a>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="card mb-4">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h6 class="mb-0"><i class="bi bi-sliders me-1"></i> Active Configuration</h6>
                            <a href="/hooks/config" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i> Edit</a>
                        </div>
                        <div class="card-body p-0">
                            <?php if (empty($hookConfig)): ?>
                            <div class="p-3 text-muted text-center">No hooks configured</div>
                            <?php else: ?>
                            <div class="accordion accordion-flush" id="hookConfigAcc">
                                <?php foreach ($hookConfig as $event => $matchers): ?>
                                <div class="accordion-item">
                                    <h2 class="accordion-header">
                                        <button class="accordion-button collapsed py-2" type="button" data-bs-toggle="collapse" data-bs-target="#cfg-<?= $event ?>">
                                            <span class="badge bg-<?= $event === 'PreToolUse' ? 'warning' : ($event === 'PostToolUse' ? 'success' : 'info') ?> me-2"><?= $event ?></span>
                                            <small class="text-muted"><?= count($matchers) ?> matcher(s)</small>
                                        </button>
                                    </h2>
                                    <div id="cfg-<?= $event ?>" class="accordion-collapse collapse">
                                        <div class="accordion-body small py-2">
                                            <?php foreach ($matchers as $m): ?>
                                            <div class="mb-1"><strong>Matcher:</strong> <code><?= htmlspecialchars(($m['matcher'] ?: '*') ?? '') ?></code></div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card">
                        <div class="card-header"><h6 class="mb-0"><i class="bi bi-book me-1"></i> Hook Events</h6></div>
                        <div class="card-body small">
                            <p><span class="badge bg-warning">PreToolUse</span> Before tool executes. Can block with exit(2).</p>
                            <p><span class="badge bg-success">PostToolUse</span> After tool executes. For logging.</p>
                            <p class="mb-0"><span class="badge bg-info">Stop</span> When session ends. For cleanup.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Add Server Modal -->
<div class="modal fade" id="addServerModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="/mcpsetup/storeServer">
                <?php foreach ($csrf as $name => $value): ?>
                    <input type="hidden" name="<?= $name ?>" value="<?= $value ?>">
                <?php endforeach; ?>
                <div class="modal-header">
                    <h5 class="modal-title">Add MCP Server</h5>
                    <button type="button" class="btn-close" aria-label="Close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Name</label>
                        <input type="text" class="form-control" name="slug" required pattern="[a-z0-9][a-z0-9\-]*" placeholder="my-server">
                        <div class="form-text">Lowercase alphanumeric with dashes</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Type</label>
                        <select class="form-select" name="type" id="addServerType" onchange="toggleServerFields('add')">
                            <option value="stdio">STDIO (Local Process)</option>
                            <option value="http">HTTP (Remote Server)</option>
                        </select>
                    </div>
                    <div id="addStdioFields">
                        <div class="mb-3">
                            <label class="form-label">Command</label>
                            <input type="text" class="form-control font-monospace" name="command" placeholder="npx">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Arguments (JSON array)</label>
                            <textarea class="form-control font-monospace" name="args" rows="2" placeholder='["-y", "@modelcontextprotocol/server"]'></textarea>
                        </div>
                    </div>
                    <div id="addHttpFields" class="d-none">
                        <div class="mb-3">
                            <label class="form-label">URL</label>
                            <input type="url" class="form-control font-monospace" name="url" placeholder="https://api.example.com/mcp">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Headers (JSON object)</label>
                            <textarea class="form-control font-monospace" name="headers" rows="2" placeholder='{"Authorization": "Bearer token"}'></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Server</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Server Modal -->
<div class="modal fade" id="editServerModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="/mcpsetup/updateServer">
                <?php foreach ($csrf as $name => $value): ?>
                    <input type="hidden" name="<?= $name ?>" value="<?= $value ?>">
                <?php endforeach; ?>
                <input type="hidden" name="slug" id="editServerSlug">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Server: <span id="editServerName"></span></h5>
                    <button type="button" class="btn-close" aria-label="Close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Type</label>
                        <select class="form-select" name="type" id="editServerType" onchange="toggleServerFields('edit')">
                            <option value="stdio">STDIO</option>
                            <option value="http">HTTP</option>
                        </select>
                    </div>
                    <div id="editStdioFields">
                        <div class="mb-3">
                            <label class="form-label">Command</label>
                            <input type="text" class="form-control font-monospace" name="command" id="editServerCommand">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Arguments (JSON)</label>
                            <textarea class="form-control font-monospace" name="args" id="editServerArgs" rows="2"></textarea>
                        </div>
                    </div>
                    <div id="editHttpFields" class="d-none">
                        <div class="mb-3">
                            <label class="form-label">URL</label>
                            <input type="url" class="form-control font-monospace" name="url" id="editServerUrl">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Headers (JSON)</label>
                            <textarea class="form-control font-monospace" name="headers" id="editServerHeaders" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Server Modal -->
<div class="modal fade" id="deleteServerModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Delete Server</h5>
                <button type="button" class="btn-close" aria-label="Close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Delete server <strong id="deleteServerName"></strong>?</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" action="/mcpsetup/deleteServer" class="d-inline">
                    <?php foreach ($csrf as $name => $value): ?>
                        <input type="hidden" name="<?= $name ?>" value="<?= $value ?>">
                    <?php endforeach; ?>
                    <input type="hidden" name="slug" id="deleteServerSlug">
                    <button type="submit" class="btn btn-danger">Delete</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function toggleServerFields(prefix) {
    const type = document.getElementById(prefix + 'ServerType').value;
    document.getElementById(prefix + 'StdioFields').classList.toggle('d-none', type !== 'stdio');
    document.getElementById(prefix + 'HttpFields').classList.toggle('d-none', type !== 'http');
}

function editServer(slug, config) {
    document.getElementById('editServerSlug').value = slug;
    document.getElementById('editServerName').textContent = slug;
    document.getElementById('editServerType').value = config.type || 'stdio';
    document.getElementById('editServerCommand').value = config.command || '';
    document.getElementById('editServerArgs').value = config.args ? JSON.stringify(config.args, null, 2) : '';
    document.getElementById('editServerUrl').value = config.url || '';
    document.getElementById('editServerHeaders').value = config.headers ? JSON.stringify(config.headers, null, 2) : '';
    toggleServerFields('edit');
    new bootstrap.Modal(document.getElementById('editServerModal')).show();
}

function deleteServer(slug) {
    document.getElementById('deleteServerSlug').value = slug;
    document.getElementById('deleteServerName').textContent = slug;
    new bootstrap.Modal(document.getElementById('deleteServerModal')).show();
}

// Persist active tab in URL
document.querySelectorAll('[data-bs-toggle="tab"]').forEach(tab => {
    tab.addEventListener('shown.bs.tab', e => {
        const tabId = e.target.getAttribute('data-bs-target').replace('#', '');
        history.replaceState(null, '', '?tab=' + tabId);
    });
});
</script>

<script>
(function () {
    var btn = document.getElementById('mcpTestBtn'), out = document.getElementById('mcpTestOut'); if (!btn) return;
    var esc = function (t) { var d = document.createElement('div'); d.textContent = t == null ? '' : String(t); return d.innerHTML; };
    btn.addEventListener('click', async function () {
        btn.disabled = true; out.innerHTML = '<span class="text-muted"><span class="spinner-border spinner-border-sm me-1"></span>Asking each server from inside the container…</span>';
        try {
            var fd = new FormData(); fd.append('_csrf_token', <?= json_encode(csrf_token()) ?>);
            var r = await fetch('/mcpsetup/test', {method: 'POST', body: fd, headers: {'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': <?= json_encode(csrf_token()) ?>}});
            var d = await r.json();
            if (!d.success) { out.innerHTML = '<span class="text-danger">' + esc(d.message || 'The test could not run.') + '</span>'; btn.disabled = false; return; }
            var rows = Object.entries(d.data.servers).map(function (e) {
                var n = e[0], s = e[1];
                return '<tr><td><code>' + esc(n) + '</code></td><td><span class="badge bg-secondary">' + esc(String(s.type).toUpperCase()) + '</span></td><td>'
                     + (s.ok ? '<span class="text-success"><i class="bi bi-check-circle-fill"></i> reachable — ' + esc(s.tools) + ' tool(s)</span>'
                             : '<span class="text-danger"><i class="bi bi-x-circle-fill"></i> ' + esc(s.error) + '</span>')
                     + '</td><td class="text-end text-muted">' + esc(s.ms) + ' ms</td></tr>';
            }).join('');
            out.innerHTML = '<table class="table table-sm mb-1"><thead><tr><th>Server</th><th>Type</th><th>From ' + esc(d.data.project) + "'s container</th><th></th></tr></thead><tbody>" + rows + '</tbody></table>'
                          + '<div class="text-muted">Tested ' + new Date().toLocaleTimeString() + '.</div>';
        } catch (e) { out.innerHTML = '<span class="text-danger">' + esc(e.message) + '</span>'; }
        btn.disabled = false;
    });
})();
</script>

<!-- Skill editor -->
<div class="modal fade" id="skillModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title" id="skillModalTitle">Add a skill</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body">
      <div class="mb-2"><label class="form-label small mb-0" for="skName">Name <span class="text-muted">— lowercase, dashes; the skill's folder</span></label><input class="form-control form-control-sm font-monospace" id="skName" placeholder="e.g. release-notes" maxlength="63"></div>
      <div class="mb-2"><label class="form-label small mb-0" for="skDesc">When to use it <span class="text-muted">— one line; an agent reads this to decide whether the skill fits the task</span></label><input class="form-control form-control-sm" id="skDesc" maxlength="300" placeholder="Use when writing release notes for a deploy: what changed, for whom, in plain words."></div>
      <div class="mb-2"><label class="form-label small mb-0" for="skBody">Instructions <span class="text-muted">— Markdown; what the agent should do, step by step</span></label><textarea class="form-control form-control-sm font-monospace" id="skBody" rows="12"></textarea></div>
      <div id="skMsg" class="small"></div>
    </div>
    <div class="modal-footer"><span class="small text-muted me-auto">Saved into the project and committed as you.</span><button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" id="skSave">Save skill</button></div>
  </div></div>
</div>

<!-- Plugin browser -->
<div class="modal fade" id="pluginModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Add a plugin</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body">
      <input class="form-control form-control-sm mb-2" id="pluginSearch" placeholder="Search plugins by name or what they do…">
      <div id="pluginMsg" class="small mb-2"></div>
      <div id="pluginList" class="list-group small"><div class="text-muted p-2">Loading the marketplaces from the project's container…</div></div>
    </div>
    <div class="modal-footer"><span class="small text-muted me-auto">Installed for every agent of this project. A plugin's skills and tools are loaded into each session.</span><button class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>
  </div></div>
</div>

<script>
(function () {
    var $ = function (id) { return document.getElementById(id); };
    if (!$('skills')) return;
    var CSRF = <?= json_encode(csrf_token()) ?>;
    var esc = function (t) { var d = document.createElement('div'); d.textContent = t == null ? '' : String(t); return d.innerHTML; };
    var say = function (el, text, cls) { el.className = 'small ' + (cls || ''); el.textContent = text; };
    async function post(url, fields) {
        var fd = new FormData(); fd.append('_csrf_token', CSRF); for (var k in fields) fd.append(k, fields[k]);
        var r = await fetch(url, {method: 'POST', body: fd, headers: {'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': CSRF}});
        var d = await r.json(); if (!d.success) throw new Error(d.message || 'It did not work.'); return d;
    }
    async function get(url) { var r = await fetch(url, {headers: {'X-Requested-With': 'XMLHttpRequest'}}); var d = await r.json(); if (!d.success) throw new Error(d.message || 'It did not work.'); return d.data; }
    var reloadTo = function () { location.href = '/mcpsetup?tab=skills'; };

    // ---- skills
    var skModal = null, editing = '';
    function openSkill(name, data) {
        editing = name || '';
        $('skillModalTitle').textContent = name ? 'Edit ' + name : 'Add a skill';
        $('skName').value = name || ''; $('skName').disabled = !!name;
        $('skDesc').value = data ? data.description : ''; $('skBody').value = data ? data.body : '';
        say($('skMsg'), '');
        skModal = skModal || new bootstrap.Modal($('skillModal')); skModal.show();
    }
    $('skillNewBtn').addEventListener('click', function () { openSkill('', null); });
    document.querySelectorAll('[data-skill-edit]').forEach(function (b) { b.addEventListener('click', async function () {
        try { openSkill(b.dataset.skillEdit, await get('/mcpsetup/skill?name=' + encodeURIComponent(b.dataset.skillEdit))); } catch (e) { say($('skillsMsg'), e.message, 'text-danger mb-3'); }
    }); });
    document.querySelectorAll('[data-skill-del]').forEach(function (b) { b.addEventListener('click', async function () {
        if (!await tkConfirm('Remove the skill "' + b.dataset.skillDel + '" from this project?', {okText: 'Remove', danger: true})) return;
        try { await post('/mcpsetup/skillDelete', {name: b.dataset.skillDel}); reloadTo(); } catch (e) { say($('skillsMsg'), e.message, 'text-danger mb-3'); }
    }); });
    $('skSave').addEventListener('click', async function () {
        $('skSave').disabled = true; say($('skMsg'), 'Saving…', 'text-muted');
        try { await post('/mcpsetup/skillSave', {name: $('skName').value.trim(), description: $('skDesc').value, body: $('skBody').value}); reloadTo(); }
        catch (e) { say($('skMsg'), e.message, 'text-danger'); $('skSave').disabled = false; }
    });

    // ---- plugins (loaded when the tab is first shown: the listing is asked of the container)
    var PLUGINS = null, plModal = null;
    function renderInstalled() {
        var el = $('pluginsInstalled');
        if (!PLUGINS.installed.length) { el.innerHTML = '<span class="text-muted">No plugins installed for this project.</span>'; }
        else el.innerHTML = '<div class="list-group list-group-flush">' + PLUGINS.installed.map(function (p) {
            var id = p.id || p.pluginId || (p.name + '@' + (p.marketplace || p.marketplaceName || ''));
            return '<div class="list-group-item px-0 d-flex justify-content-between align-items-center gap-2"><div><code>' + esc(id) + '</code>' + (p.version ? ' <span class="text-muted">' + esc(p.version) + '</span>' : '') + (p.enabled === false ? ' <span class="badge bg-secondary">disabled</span>' : '') + '</div>'
                 + '<button class="btn btn-sm btn-outline-danger" data-plugin-rm="' + esc(id) + '"><i class="bi bi-trash"></i> Remove</button></div>';
        }).join('') + '</div>';
        el.querySelectorAll('[data-plugin-rm]').forEach(function (b) { b.addEventListener('click', async function () {
            if (!await tkConfirm('Remove the plugin ' + b.dataset.pluginRm + ' from this project\'s agents?', {okText: 'Remove', danger: true})) return;
            b.disabled = true;
            try { await post('/mcpsetup/pluginRemove', {id: b.dataset.pluginRm}); await loadPlugins(true); say($('skillsMsg'), 'Removed ' + b.dataset.pluginRm + '.', 'text-success mb-3'); }
            catch (e) { say($('skillsMsg'), e.message, 'text-danger mb-3'); b.disabled = false; }
        }); });
        $('marketplacesList').className = 'mb-2';
        $('marketplacesList').innerHTML = PLUGINS.marketplaces.length ? PLUGINS.marketplaces.map(function (m) {
            return '<div class="d-flex justify-content-between align-items-center"><span><code>' + esc(m.name) + '</code> <span class="text-muted">' + esc(m.repo || m.url || m.source || '') + '</span></span>'
                 + '<button class="btn btn-link btn-sm text-danger p-0" data-market-rm="' + esc(m.name) + '">Remove</button></div>';
        }).join('') : '<span class="text-muted">None added. Claude Code\'s built-in plugin directory is always available.</span>';
        $('marketplacesList').querySelectorAll('[data-market-rm]').forEach(function (b) { b.addEventListener('click', async function () {
            if (!await tkConfirm('Remove the marketplace ' + b.dataset.marketRm + '? Plugins already installed from it stay installed.', {okText: 'Remove', danger: true})) return;
            try { await post('/mcpsetup/marketplaceRemove', {name: b.dataset.marketRm}); await loadPlugins(true); } catch (e) { say($('skillsMsg'), e.message, 'text-danger mb-3'); }
        }); });
    }
    function renderAvailable() {
        var q = $('pluginSearch').value.trim().toLowerCase();
        var have = {}; PLUGINS.installed.forEach(function (p) { have[p.id || p.pluginId || ''] = true; });
        var rows = PLUGINS.available.filter(function (p) { return !q || (p.name + ' ' + p.description).toLowerCase().indexOf(q) >= 0; })
            .sort(function (a, b) { return b.installs - a.installs; }).slice(0, 60);
        $('pluginList').innerHTML = rows.length ? rows.map(function (p) {
            return '<div class="list-group-item d-flex justify-content-between align-items-start gap-3"><div><strong>' + esc(p.name) + '</strong> <span class="text-muted">' + esc(p.marketplace) + (p.installs ? ' · ' + p.installs.toLocaleString() + ' installs' : '') + '</span>'
                 + '<div class="text-muted">' + esc(p.description) + '</div></div>'
                 + (have[p.id] ? '<span class="badge bg-success mt-1">installed</span>' : '<button class="btn btn-sm btn-primary text-nowrap" data-plugin-add="' + esc(p.id) + '">Add</button>') + '</div>';
        }).join('') : '<div class="text-muted p-2">Nothing matches.</div>';
        if (!q && PLUGINS.available.length > 60) $('pluginList').insertAdjacentHTML('beforeend', '<div class="text-muted p-2">Showing the 60 most installed of ' + PLUGINS.available.length + ' — search to find the rest.</div>');
        $('pluginList').querySelectorAll('[data-plugin-add]').forEach(function (b) { b.addEventListener('click', async function () {
            b.disabled = true; b.innerHTML = '<span class="spinner-border spinner-border-sm"></span>'; say($('pluginMsg'), 'Installing ' + b.dataset.pluginAdd + ' in the project\'s container…', 'text-muted mb-2');
            try { var d = await post('/mcpsetup/pluginInstall', {id: b.dataset.pluginAdd}); await loadPlugins(true); renderAvailable(); say($('pluginMsg'), 'Installed ' + b.dataset.pluginAdd + '. ' + (d.message || ''), 'text-success mb-2'); }
            catch (e) { say($('pluginMsg'), e.message, 'text-danger mb-2'); b.disabled = false; b.textContent = 'Add'; }
        }); });
    }
    async function loadPlugins(force) {
        if (PLUGINS && !force) return;
        try { PLUGINS = await get('/mcpsetup/plugins'); renderInstalled(); }
        catch (e) { $('pluginsInstalled').innerHTML = '<span class="text-danger">' + esc(e.message) + '</span>'; throw e; }
    }
    var first = function () { loadPlugins(false).catch(function () {}); };
    $('skillsTabBtn').addEventListener('shown.bs.tab', first);
    if ($('skills').classList.contains('active')) first();
    $('pluginBrowseBtn').addEventListener('click', async function () {
        plModal = plModal || new bootstrap.Modal($('pluginModal')); plModal.show(); say($('pluginMsg'), '');
        try { await loadPlugins(false); renderAvailable(); } catch (e) { $('pluginList').innerHTML = '<div class="text-danger p-2">' + esc(e.message) + '</div>'; }
    });
    $('pluginSearch').addEventListener('input', function () { if (PLUGINS) renderAvailable(); });
    $('marketAddForm').addEventListener('submit', async function (ev) {
        ev.preventDefault(); say($('skillsMsg'), 'Adding the marketplace…', 'text-muted mb-3');
        try { var d = await post('/mcpsetup/marketplaceAdd', {source: $('marketSource').value.trim()}); $('marketSource').value = ''; await loadPlugins(true); say($('skillsMsg'), d.message || 'Marketplace added.', 'text-success mb-3'); }
        catch (e) { say($('skillsMsg'), e.message, 'text-danger mb-3'); }
    });
})();
</script>

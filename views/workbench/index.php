<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h2">Workbench</h1>
        <div>
            <a href="/teams" class="btn btn-outline-secondary me-2">
                <i class="bi bi-people"></i> Teams
            </a>
            <?php
            $createUrl = '/workbench/create';
            if (!empty($filters['team_id']) && $filters['team_id'] !== 'personal' && is_numeric($filters['team_id'])) {
                $createUrl .= '?team_id=' . (int)$filters['team_id'];
            }
            ?>
            <a href="<?= $createUrl ?>" class="btn btn-primary">
                <i class="bi bi-plus-lg"></i> New Task
            </a>
        </div>
    </div>

    <?php
    $flash = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    foreach ($flash as $msg):
    ?>
        <div class="alert alert-<?= $msg['type'] === 'error' ? 'danger' : $msg['type'] ?> alert-dismissible fade show">
            <?= htmlspecialchars($msg['message']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endforeach; ?>

    <div class="row">
        <!-- Sidebar Filters -->
        <div class="col-lg-3 col-md-4 mb-4">
            <h6 class="text-muted text-uppercase mb-3"><i class="bi bi-funnel"></i> Filters</h6>

            <!-- Instance filter now lives in the top tabs (All Tasks / per-instance). -->

            <!-- Status Counts -->
            <div class="card mb-3">
                <div class="card-header">
                    <h6 class="mb-0">Status</h6>
                </div>
                <div class="list-group list-group-flush">
                    <a href="/workbench" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center <?= empty($filters['status']) ? 'active' : '' ?>">
                        All Tasks
                        <span class="badge bg-secondary rounded-pill"><?= $counts['total'] ?></span>
                    </a>
                    <a href="/workbench?status=pending" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center <?= $filters['status'] === 'pending' ? 'active' : '' ?>">
                        <span><i class="bi bi-circle text-secondary"></i> Pending</span>
                        <span class="badge bg-secondary rounded-pill"><?= $counts['pending'] ?></span>
                    </a>
                    <a href="/workbench?status=running" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center <?= $filters['status'] === 'running' ? 'active' : '' ?>">
                        <span><i class="bi bi-play-circle text-primary"></i> Running</span>
                        <span class="badge bg-primary rounded-pill"><?= $counts['running'] + $counts['queued'] ?></span>
                    </a>
                    <a href="/workbench?status=completed" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center <?= $filters['status'] === 'completed' ? 'active' : '' ?>">
                        <span><i class="bi bi-check-circle text-success"></i> Completed</span>
                        <span class="badge bg-success rounded-pill"><?= $counts['completed'] ?></span>
                    </a>
                    <a href="/workbench?status=failed" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center <?= $filters['status'] === 'failed' ? 'active' : '' ?>">
                        <span><i class="bi bi-x-circle text-danger"></i> Failed</span>
                        <span class="badge bg-danger rounded-pill"><?= $counts['failed'] ?></span>
                    </a>
                </div>
            </div>

            <!-- Type Filter -->
            <div class="card">
                <div class="card-header">
                    <h6 class="mb-0">Type</h6>
                </div>
                <div class="list-group list-group-flush">
                    <?php foreach ($taskTypes as $type => $info): ?>
                        <a href="/workbench?type=<?= $type ?>" class="list-group-item list-group-item-action <?= $filters['task_type'] === $type ? 'active' : '' ?>">
                            <i class="bi bi-<?= $info['icon'] ?> text-<?= $info['color'] ?>"></i> <?= $info['label'] ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Task List -->
        <div class="col-lg-9 col-md-8">
            <!-- Instance Tabs — All Tasks + one tab per AI Builder instance (tenant tag) -->
            <?php $statusQ = !empty($filters['status']) ? $filters['status'] : ''; ?>
            <ul class="nav nav-tabs mb-3">
                <li class="nav-item">
                    <a class="nav-link <?= empty($filters['instance_tag']) ? 'active' : '' ?>" href="/workbench<?= $statusQ !== '' ? '?status=' . urlencode($statusQ) : '' ?>">
                        <i class="bi bi-grid-1x2"></i> All Tasks
                        <span class="badge bg-secondary rounded-pill ms-1"><?= (int)($counts['total'] ?? 0) ?></span>
                    </a>
                </li>
                <?php foreach ($instanceTags ?? [] as $it): ?>
                <li class="nav-item">
                    <a class="nav-link <?= ($filters['instance_tag'] ?? '') === $it['tag'] ? 'active' : '' ?>" href="/workbench?instance_tag=<?= urlencode($it['tag']) ?><?= $statusQ !== '' ? '&status=' . urlencode($statusQ) : '' ?>">
                        <i class="bi bi-hdd-network"></i> <?= htmlspecialchars($it['tag']) ?>
                        <span class="badge bg-info rounded-pill ms-1"><?= (int)$it['n'] ?></span>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>

            <script>window.WB_CSRF = <?= json_encode(csrf_token(), JSON_UNESCAPED_SLASHES) ?>;</script>

            <?php if (!empty($decomposingInstance)): ?>
                <div id="wbDecomposeBanner" class="alert alert-info d-flex align-items-center" data-instance-id="<?= (int)$decomposingInstance ?>">
                    <span class="spinner-border spinner-border-sm me-2" role="status"></span>
                    <div>
                        <strong>Decomposing your goal into a plan…</strong>
                        <div class="small text-muted">The planner is grounding itself in the codebase and drafting tasks. This page refreshes automatically when the plan is ready.</div>
                    </div>
                </div>
                <script>
                (function(){
                    var el = document.getElementById('wbDecomposeBanner');
                    if (!el) return;
                    var iid = el.getAttribute('data-instance-id');
                    var baseline = null, tries = 0;
                    function done(){
                        var u = new URL(window.location.href);
                        u.searchParams.delete('decomposing');   // drop so the banner does not re-arm
                        window.location.href = u.toString();
                    }
                    function poll(){
                        fetch('/workbench/decomposestatus?instance_id=' + encodeURIComponent(iid), {headers:{'X-Requested-With':'XMLHttpRequest'}})
                            .then(function(r){ return r.json(); })
                            .then(function(j){
                                var d = (j && j.data) ? j.data : j;   // jsonSuccess wraps payload in .data
                                var newest = d ? (d.newest_plan_id || 0) : 0;
                                if (baseline === null) baseline = newest;
                                if (newest > baseline) return done();       // a new plan landed
                                if (d && d.running === false) return done(); // planner exited (ingest runs before it dies)
                                if (++tries < 240) setTimeout(poll, 3000);   // ~12 min cap
                            })
                            .catch(function(){ if (++tries < 240) setTimeout(poll, 3000); });
                    }
                    setTimeout(poll, 3000);
                })();
                </script>
            <?php endif; ?>

            <?php if (empty($tasks)): ?>
                <div class="card">
                    <div class="card-body text-center py-5">
                        <i class="bi bi-inbox" style="font-size: 3rem; color: #6c757d;"></i>
                        <h4 class="mt-3">No Tasks Found</h4>
                        <p class="text-muted">Create a new task to get started with AI-assisted development.</p>
                        <a href="<?= $createUrl ?>" class="btn btn-primary">
                            <i class="bi bi-plus-lg"></i> Create Task
                        </a>
                    </div>
                </div>
            <?php else: ?>
                <?php
                // Grouping: subtasks nest under their plan parent (a rendered group header).
                // A plan parent that HAS visible children becomes the header, so it is
                // skipped as a body row; everything else (subtasks, standalone tasks) is a leaf.
                $parentSet = array_flip($parentIdsWithChildren ?? []);
                $planMetaJs = [];
                foreach (($planMeta ?? []) as $pid => $m) {
                    $planMetaJs['plan:' . $pid] = [
                        'id'          => (int)$pid,
                        'title'       => $m['title'],
                        'tag'         => $m['instanceTag'],
                        'status'      => $m['planStatus'] ?: $m['status'],
                        'plan_status' => $m['planStatus'] ?: '',
                        'url'         => '/workbench/view?id=' . $pid,
                    ];
                }
                $planMetaJs['solo'] = ['id' => 0, 'title' => 'Standalone tasks', 'tag' => null, 'status' => null, 'plan_status' => '', 'url' => null];

                // Group ordering key: the most recent createdAt in each group, so the
                // whole table defaults to newest-first BY GROUP (not by plan-id string).
                // Must be one constant per group (incl. the parent header row) or the
                // RowGroup rows won't stay contiguous.
                $groupOrder = [];
                foreach ($tasks as $t) {
                    $gk = !empty($t->parentTaskId) ? ('plan:' . (int)$t->parentTaskId)
                        : (isset($parentSet[(int)$t->id]) ? ('plan:' . (int)$t->id) : 'solo');
                    $ts = $t->createdAt ? strtotime((string)$t->createdAt) : 0;
                    if (!isset($groupOrder[$gk]) || $ts > $groupOrder[$gk]) $groupOrder[$gk] = $ts;
                }
                ?>
                <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css">
                <link rel="stylesheet" href="https://cdn.datatables.net/rowgroup/1.4.1/css/rowGroup.bootstrap5.min.css">
                <style>#wbTasks .wb-grp{display:none}</style>
                <div class="card">
                    <div class="card-body">
                        <table id="wbTasks" class="table table-hover align-middle mb-0" style="width:100%">
                            <thead>
                                <tr>
                                    <th class="wb-grp">Group</th>
                                    <th>Task</th>
                                    <th>Instance</th>
                                    <th>Type</th>
                                    <th>Priority</th>
                                    <th>Status</th>
                                    <th>Created</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($tasks as $task): ?>
                                    <?php
                                    if (isset($parentSet[(int)$task->id])) continue; // header row, not a leaf
                                    $typeInfo = $taskTypes[$task->taskType] ?? $taskTypes['feature'];
                                    $priorityInfo = $priorities[$task->priority] ?? $priorities[3];
                                    $isSub = !empty($task->parentTaskId);
                                    $groupKey = $isSub ? ('plan:' . (int)$task->parentTaskId) : 'solo';
                                    ?>
                                    <tr>
                                        <?php // Prefix an inverted-timestamp so string-sorting column 0 puts newest groups first, while the value still groups by key (parsed back out in startRender). ?>
                                        <td class="wb-grp"><?= htmlspecialchars(sprintf('%010d', 9999999999 - (int)($groupOrder[$groupKey] ?? 0)) . '~' . $groupKey) ?></td>
                                        <td>
                                            <?php if ($isSub): ?><i class="bi bi-arrow-return-right text-muted me-1"></i><?php endif; ?>
                                            <a href="/workbench/view?id=<?= $task->id ?>" class="text-decoration-none fw-medium">
                                                <?= htmlspecialchars($task->title) ?>
                                            </a>
                                            <?php if ($task->teamId): ?>
                                                <br><small class="text-muted"><i class="bi bi-people"></i> Team task</small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($task->instanceTag)): ?>
                                                <a href="/workbench?instance_tag=<?= urlencode($task->instanceTag) ?>" class="badge bg-info-subtle text-info-emphasis border border-info-subtle text-decoration-none" title="Filter to this instance">
                                                    <i class="bi bi-hdd-network me-1"></i><?= htmlspecialchars($task->instanceTag) ?>
                                                </a>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?= $typeInfo['color'] ?>-subtle text-<?= $typeInfo['color'] ?>">
                                                <i class="bi bi-<?= $typeInfo['icon'] ?>"></i> <?= $typeInfo['label'] ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?= $priorityInfo['color'] ?>-subtle text-<?= $priorityInfo['color'] ?>">
                                                <?= $priorityInfo['label'] ?>
                                            </span>
                                        </td>
                                        <td data-order="<?= htmlspecialchars((string)$task->status) ?>">
                                            <?php
                                            $statusBadge = match($task->status) {
                                                'pending' => 'secondary',
                                                'queued' => 'info',
                                                'running' => 'primary',
                                                'completed' => 'success',
                                                'merged' => 'success',
                                                'failed' => 'danger',
                                                'paused' => 'warning',
                                                'awaiting', 'waiting' => 'warning',
                                                default => 'secondary'
                                            };
                                            ?>
                                            <span class="badge bg-<?= $statusBadge ?>">
                                                <?php if ($task->status === 'running'): ?>
                                                    <span class="spinner-border spinner-border-sm me-1" role="status"></span>
                                                <?php elseif ($task->status === 'merged'): ?>
                                                    <i class="bi bi-git me-1"></i>
                                                <?php endif; ?>
                                                <?= ucfirst($task->status) ?>
                                            </span>
                                        </td>
                                        <td data-order="<?= htmlspecialchars((string)$task->createdAt) ?>">
                                            <small class="text-muted"><?= date('M j', strtotime($task->createdAt)) ?></small>
                                        </td>
                                        <td>
                                            <a href="/workbench/view?id=<?= $task->id ?>" class="btn btn-sm btn-outline-primary">View</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <script>
                (function(){
                    var $;   // bound once jQuery is available (this layout loads jQuery after the view)
                    var PLAN_META = <?= json_encode($planMetaJs, JSON_UNESCAPED_SLASHES) ?>;
                    var DT_ASSETS = [
                        'https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js',
                        'https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js',
                        'https://cdn.datatables.net/rowgroup/1.4.1/js/dataTables.rowGroup.min.js'
                    ];
                    function esc(t){ return $('<div>').text(t == null ? '' : t).html(); }
                    // Lifecycle action buttons for a plan group header, keyed on plan_status.
                    function planActions(m){
                        if (!m.id) return '';   // solo group is not a plan
                        var ps = String(m.plan_status || '').toLowerCase();
                        var btn = function(cls, extra, icon, label){
                            return '<button type="button" class="btn btn-sm '+cls+' '+extra+' ms-1" data-plan-id="'+m.id+'">'
                                 + '<i class="bi bi-'+icon+'"></i>' + (label ? ' '+label : '') + '</button>';
                        };
                        var out = '';
                        if (ps === 'draft')                             out += btn('btn-outline-info',   'wb-plan-approve', 'check2-circle', 'Approve');
                        else if (ps === 'approved' || ps === 'stalled') out += btn('btn-info',           'wb-plan-build',   'play-fill',     'Build');
                        else if (ps === 'building')                     out += '<span class="badge bg-primary ms-1"><span class="spinner-border spinner-border-sm me-1" role="status"></span>Building</span>';
                        if (ps !== 'building')                          out += btn('btn-outline-danger',  'wb-plan-delete',  'trash',         '');
                        return '<span class="float-end">'+out+'</span>';
                    }
                    function statusBadge(s){
                        if (!s) return '';
                        var map = {done:'success', completed:'success', merged:'success',
                            building:'primary', running:'primary', approved:'info',
                            draft:'secondary', pending:'secondary', stalled:'danger', failed:'danger'};
                        var cls = map[String(s).toLowerCase()] || 'secondary';
                        return ' <span class="badge bg-'+cls+' ms-1">'+esc(String(s).charAt(0).toUpperCase()+String(s).slice(1))+'</span>';
                    }
                    function loadSeq(list, done){
                        if (!list.length) return done();
                        var s = document.createElement('script');
                        s.src = list[0];
                        s.onload = function(){ loadSeq(list.slice(1), done); };
                        s.onerror = function(){ console.error('DataTables asset failed to load:', list[0]); };
                        document.head.appendChild(s);
                    }
                    function initTable(){
                        if (!$.fn || !$.fn.DataTable) return;   // graceful no-op if a CDN asset is unreachable
                        $('#wbTasks').DataTable({
                            pageLength: 25,
                            lengthMenu: [[10,25,50,-1],[10,25,50,'All']],
                            orderFixed: { pre: [[0,'asc']] },    // group key carries an inverted-ts prefix, so asc = newest group first
                            order: [[6,'desc']],                  // and rows newest-first within each group
                            columnDefs: [
                                { targets: 0, visible: false, searchable: false },
                                { targets: -1, orderable: false, searchable: false }
                            ],
                            rowGroup: {
                                dataSrc: 0,
                                startRender: function(rows, group){
                                    var key = String(group).split('~').pop();   // strip the inverted-ts sort prefix
                                    var m = PLAN_META[key] || { title: key };
                                    var n = rows.count();
                                    var icon = m.url ? '<i class="bi bi-diagram-3 me-2 text-primary"></i>'
                                                     : '<i class="bi bi-collection me-2 text-muted"></i>';
                                    var title = m.url
                                        ? '<a href="'+m.url+'" class="text-decoration-none fw-semibold">'+esc(m.title)+'</a>'
                                        : '<span class="fw-semibold">'+esc(m.title)+'</span>';
                                    var tag = m.tag ? ' <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle ms-2"><i class="bi bi-hdd-network me-1"></i>'+esc(m.tag)+'</span>' : '';
                                    var count = ' <span class="text-muted small ms-2">'+n+' task'+(n===1?'':'s')+'</span>';
                                    return $('<tr class="table-active">').append(
                                        '<td colspan="7">'+planActions(m)+icon+title+tag+statusBadge(m.status)+count+'</td>'
                                    );
                                }
                            },
                            language: { search: 'Filter:', searchPlaceholder: 'title, instance, status…' }
                        });
                        bindPlanActions();
                    }
                    // POST a plan lifecycle action, then refresh so the new state shows.
                    function planAction(url, btn, confirmMsg){
                        var id = btn.getAttribute('data-plan-id');
                        if (!id) return;
                        if (confirmMsg && !window.confirm(confirmMsg)) return;
                        btn.disabled = true;
                        fetch(url, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded',
                                'X-CSRF-TOKEN': window.WB_CSRF || '',
                                'X-Requested-With': 'XMLHttpRequest'
                            },
                            body: 'plan_id=' + encodeURIComponent(id) + '&_csrf_token=' + encodeURIComponent(window.WB_CSRF || '')
                        }).then(function(r){ return r.json(); })
                          .then(function(j){
                              if (j && j.success === false) { window.alert(j.message || 'Action failed'); btn.disabled = false; return; }
                              window.location.reload();
                          })
                          .catch(function(){ window.alert('Network error'); btn.disabled = false; });
                    }
                    // Delegated so the buttons survive DataTables redraws (sort/search/paginate).
                    function bindPlanActions(){
                        $('#wbTasks').off('click.wbplan')
                            .on('click.wbplan', '.wb-plan-approve', function(){ planAction('/workbench/planapprove', this); })
                            .on('click.wbplan', '.wb-plan-build',   function(){ planAction('/workbench/planbuild', this); })
                            .on('click.wbplan', '.wb-plan-delete',  function(){ planAction('/workbench/plandelete', this, 'Delete this entire plan and all of its tasks? This cannot be undone.'); });
                    }
                    // jQuery is loaded near the end of <body> (after this view), so wait for it,
                    // then load DataTables + RowGroup in order and initialise.
                    (function waitJQ(n){
                        if (window.jQuery){ $ = window.jQuery; loadSeq(DT_ASSETS, initTable); }
                        else if (n < 200){ setTimeout(function(){ waitJQ(n + 1); }, 50); }   // ~10s cap
                    })(0);
                })();
                </script>
            <?php endif; ?>
        </div>
    </div>
</div>

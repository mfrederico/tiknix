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
                        'title'  => $m['title'],
                        'tag'    => $m['instanceTag'],
                        'status' => $m['planStatus'] ?: $m['status'],
                        'url'    => '/workbench/view?id=' . $pid,
                    ];
                }
                $planMetaJs['solo'] = ['title' => 'Standalone tasks', 'tag' => null, 'status' => null, 'url' => null];
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
                                        <td class="wb-grp"><?= htmlspecialchars($groupKey) ?></td>
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
                <script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
                <script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
                <script src="https://cdn.datatables.net/rowgroup/1.4.1/js/dataTables.rowGroup.min.js"></script>
                <script>
                (function(){
                    const PLAN_META = <?= json_encode($planMetaJs, JSON_UNESCAPED_SLASHES) ?>;
                    function esc(t){ return $('<div>').text(t == null ? '' : t).html(); }
                    function statusBadge(s){
                        if (!s) return '';
                        const map = {done:'success', completed:'success', merged:'success',
                            building:'primary', running:'primary', approved:'info',
                            draft:'secondary', pending:'secondary', stalled:'danger', failed:'danger'};
                        const cls = map[String(s).toLowerCase()] || 'secondary';
                        return ' <span class="badge bg-'+cls+' ms-1">'+esc(String(s).charAt(0).toUpperCase()+String(s).slice(1))+'</span>';
                    }
                    $(function(){
                        if (!$.fn || !$.fn.DataTable) return;   // graceful no-op if the CDN is unreachable
                        $('#wbTasks').DataTable({
                            pageLength: 25,
                            lengthMenu: [[10,25,50,-1],[10,25,50,'All']],
                            orderFixed: { pre: [[0,'asc']] },
                            order: [[6,'desc']],
                            columnDefs: [
                                { targets: 0, visible: false, searchable: false },
                                { targets: -1, orderable: false, searchable: false }
                            ],
                            rowGroup: {
                                dataSrc: 0,
                                startRender: function(rows, group){
                                    const m = PLAN_META[group] || { title: group };
                                    const n = rows.count();
                                    const icon = m.url ? '<i class="bi bi-diagram-3 me-2 text-primary"></i>'
                                                       : '<i class="bi bi-collection me-2 text-muted"></i>';
                                    const title = m.url
                                        ? '<a href="'+m.url+'" class="text-decoration-none fw-semibold">'+esc(m.title)+'</a>'
                                        : '<span class="fw-semibold">'+esc(m.title)+'</span>';
                                    const tag = m.tag ? ' <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle ms-2"><i class="bi bi-hdd-network me-1"></i>'+esc(m.tag)+'</span>' : '';
                                    const count = ' <span class="text-muted small ms-2">'+n+' task'+(n===1?'':'s')+'</span>';
                                    return $('<tr class="table-active">').append(
                                        '<td colspan="7">'+icon+title+tag+statusBadge(m.status)+count+'</td>'
                                    );
                                }
                            },
                            language: { search: 'Filter:', searchPlaceholder: 'title, instance, status…' }
                        });
                    });
                })();
                </script>
            <?php endif; ?>
        </div>
    </div>
</div>

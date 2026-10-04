<?php /** @var array $rows  @var int $fresh — Projectreport::index */
$ago = function (?int $s): string { if ($s === null) return 'never'; if ($s < 90) return $s . ' s ago'; if ($s < 5400) return round($s / 60) . ' min ago'; if ($s < 172800) return round($s / 3600) . ' h ago'; return round($s / 86400) . ' d ago'; };
$n = fn($v, $unit = '') => $v === null ? '<span class="text-body-secondary">—</span>' : htmlspecialchars((string) $v) . $unit;
?>
<div class="container-fluid py-4">
  <h1 class="h3 mb-1">Project reports</h1>
  <p class="text-body-secondary">What every hosted app said about itself last — hourly from its <code>status-report</code> pipeline, and at once when a provider changes. A row older than <?= round($fresh / 60) ?> minutes is stale: its app has stopped reporting (its cron, or the app itself).</p>
  <div class="table-responsive">
  <table class="table table-sm align-middle">
    <thead><tr>
      <th>Project</th><th>Reported</th><th>Runtime</th><th>Agent</th><th class="text-end">Disk</th><th class="text-end">of which agent</th><th class="text-end">DB</th>
      <th class="text-end">Memory</th><th class="text-end">CPU</th><th class="text-end">Load</th><th class="text-end">Req/h</th><th class="text-end">Err/h</th><th class="text-end">Members</th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $h = $r['h']; $stale = $r['age'] === null || $r['age'] > $fresh; ?>
      <tr class="<?= $stale ? 'table-warning' : '' ?>">
        <td><a href="/projectreport/show/<?= (int) $r['inst']->id ?>"><?= htmlspecialchars($r['inst']->displayName ?: $r['inst']->slug) ?></a><div class="small text-body-secondary"><?= htmlspecialchars((string) $r['inst']->ctDomain) ?></div></td>
        <td title="<?= htmlspecialchars($r['at']) ?>"><?= $ago($r['age']) ?></td>
        <?php if (!$h): ?><td colspan="11" class="text-body-secondary">no report yet</td>
        <?php else: ?>
        <td><code><?= htmlspecialchars($h['runtime'] ?: '?') ?></code><?= !empty($h['uncommitted']) ? ' <span class="badge text-bg-warning" title="uncommitted edits in the live tree">' . (int) $h['uncommitted'] . ' dirty</span>' : '' ?></td>
        <td><?php if (!empty($h['agent_ready'])): ?><span class="badge text-bg-success">ready</span> <span class="small"><?= htmlspecialchars(implode(', ', $h['providers'] ?? [])) ?></span><?php else: ?><span class="badge text-bg-danger" title="<?= htmlspecialchars($h['agent_problem'] ?? '') ?>">none</span><?php endif; ?></td>
        <td class="text-end"><?= $n($h['disk_mb'], ' MB') ?></td>
        <td class="text-end text-body-secondary"><?= $n($h['disk_agent_mb'], ' MB') ?></td>
        <td class="text-end"><?= $n($h['db_mb'], ' MB') ?></td>
        <td class="text-end"><?= $n($h['mem_mb'], ' MB') ?><?= isset($h['mem_total_mb']) ? '<span class="text-body-secondary"> / ' . (int) $h['mem_total_mb'] . '</span>' : '' ?></td>
        <td class="text-end"><?= $n($h['cpu_pct'], '%') ?></td>
        <td class="text-end"><?= $n($h['load1']) ?></td>
        <td class="text-end"><?= $n($h['requests_h']) ?></td>
        <td class="text-end <?= !empty($h['errors_h']) ? 'text-danger fw-semibold' : '' ?>"><?= $n($h['errors_h']) ?></td>
        <td class="text-end"><?= $n($h['members']) ?></td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <p class="small text-body-secondary">CPU is the container's usage since its previous report as a share of one core. Memory is the container's own view (lxcfs). Disk is the app tree; "of which agent" is the agent's binary and state (<code>bin/</code>, <code>.aibuilder/</code>), which a project does not pay for in code. Requests and errors are counted from the app's own log.</p>
</div>

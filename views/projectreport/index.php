<?php
/** @var array $rows  @var int $fresh  @var array $sum — Projectreport::index: the fleet dashboard */
$ago = function (?int $s): string { if ($s === null) return 'never'; if ($s < 90) return $s . ' s ago'; if ($s < 5400) return round($s / 60) . ' min ago'; if ($s < 172800) return round($s / 3600) . ' h ago'; return round($s / 86400) . ' d ago'; };
$n = fn($v, $unit = '') => $v === null ? '<span class="text-body-secondary">—</span>' : htmlspecialchars((string) $v) . $unit;
$gb = fn(float $mb) => $mb >= 1024 ? round($mb / 1024, 1) . ' GB' : round($mb) . ' MB';
/**
 * An inline sparkline: the series' non-null points over its own range. No library — it is 60×18
 * pixels of polyline. A series with fewer than two points draws nothing (a line needs two).
 */
$spark = function (array $pts, string $color = 'currentColor', int $w = 96, int $h = 22): string {
    $vals = array_values(array_filter($pts, fn($v) => $v !== null));
    if (count($vals) < 2) return '<span class="text-body-secondary small">·</span>';
    $min = min($vals); $max = max($vals); $range = $max - $min ?: 1.0;
    $xs = []; $i = 0; $count = count($vals);
    foreach ($vals as $v) { $x = $i * ($w - 2) / ($count - 1) + 1; $y = $h - 2 - ($v - $min) / $range * ($h - 4); $xs[] = round($x, 1) . ',' . round($y, 1); $i++; }
    $last = end($vals);
    return '<svg width="' . $w . '" height="' . $h . '" viewBox="0 0 ' . $w . ' ' . $h . '" aria-hidden="true"><polyline fill="none" stroke="' . $color . '" stroke-width="1.5" points="' . implode(' ', $xs) . '"/></svg>'
         . '<span class="small ms-1">' . htmlspecialchars(is_float($last) && floor($last) != $last ? number_format($last, 1) : (string) round($last)) . '</span>';
};
?>
<div class="container-fluid py-4">
  <div class="d-flex flex-wrap align-items-end justify-content-between gap-2 mb-3">
    <div>
      <h1 class="h3 mb-1">Project reports</h1>
      <div class="text-body-secondary">Every hosted app reports on itself hourly (its <code>status-report</code> pipeline) and the moment a provider changes. Stale = nothing for <?= round($fresh / 60) ?> minutes: its cron or the app itself has stopped.</div>
    </div>
    <div class="small text-body-secondary">Hover a card's numbers for the raw values; open a project for its full history.</div>
  </div>

  <?php /* ---- the fleet in one row ---- */ ?>
  <div class="row g-2 mb-4">
    <?php $tile = function (string $label, string $value, string $cls = '', string $title = '') { ?>
      <div class="col-6 col-md-4 col-lg-2"><div class="card h-100 <?= $cls ?>" title="<?= htmlspecialchars($title) ?>"><div class="card-body py-2 px-3"><div class="small text-body-secondary"><?= htmlspecialchars($label) ?></div><div class="fs-4 fw-semibold"><?= $value ?></div></div></div></div>
    <?php }; ?>
    <?php $tile('Projects reporting', $sum['reporting'] . ' <span class="fs-6 fw-normal text-body-secondary">of ' . $sum['projects'] . '</span>', $sum['reporting'] < $sum['projects'] ? 'border-warning' : '', 'reported within the last ' . round($fresh / 60) . ' minutes'); ?>
    <?php $tile('Stale / never', $sum['stale'] . ' <span class="fs-6 fw-normal text-body-secondary">/ ' . $sum['silent'] . '</span>', ($sum['stale'] + $sum['silent']) ? 'border-warning' : '', 'stale: reported once but not lately; never: no report at all'); ?>
    <?php $tile('No agent', (string) $sum['no_agent'], $sum['no_agent'] ? 'border-danger' : '', 'projects whose last report says nothing can build in them'); ?>
    <?php $tile('Errors / h', (string) $sum['errors_h'], $sum['errors_h'] ? 'border-danger' : '', 'ERROR lines in the apps\' own logs in their last reported hour, summed'); ?>
    <?php $tile('Requests / h', (string) $sum['requests_h'], '', 'PHP requests in the last reported hour, summed'); ?>
    <?php $tile('Disk', $gb($sum['disk_mb']) . ' <span class="fs-6 fw-normal text-body-secondary">' . $gb($sum['disk_agent_mb']) . ' agent</span>', '', 'all app trees; the agent part (binary + task state) is not the projects\' own'); ?>
    <?php $tile('Domains / TLS', $sum['domains'] . ' <span class="fs-6 fw-normal ' . ($sum['tls_bad'] ? 'text-danger' : 'text-body-secondary') . '">' . ($sum['tls_bad'] ? $sum['tls_bad'] . ' need attention' : 'all ok') . '</span>', $sum['tls_bad'] ? 'border-danger' : '', 'domains served, and how many have a certificate failing or within ' . \app\DomainCerts::WARN_DAYS . ' days of expiry (probed hourly)'); ?>
  </div>

  <?php /* ---- a card per project ---- */ ?>
  <div class="row g-3">
  <?php foreach ($rows as $r): $h = $r['h']; $inst = $r['inst']; $stale = $r['age'] === null || $r['age'] > $fresh; $s = $r['series']; ?>
    <div class="col-12 col-md-6 col-xl-4">
      <div class="card h-100 <?= $stale ? 'border-warning' : '' ?> <?= $h && empty($h['agent_ready']) ? 'border-danger' : '' ?>">
        <div class="card-header d-flex justify-content-between align-items-center gap-2">
          <div class="text-truncate">
            <a class="fw-semibold text-decoration-none" href="/projectreport/show/<?= (int) $inst->id ?>"><?= htmlspecialchars($inst->displayName ?: $inst->slug) ?></a>
            <?php if ($inst->isDefault): ?><span class="badge text-bg-dark ms-1">control plane</span><?php endif; ?>
            <div class="small text-body-secondary text-truncate"><?= htmlspecialchars((string) ($inst->ctDomain ?: $inst->slug)) ?></div>
          </div>
          <div class="text-end text-nowrap small" title="<?= htmlspecialchars($r['at']) ?>">
            <span class="<?= $stale ? 'text-warning fw-semibold' : 'text-success' ?>"><i class="bi bi-broadcast"></i> <?= $ago($r['age']) ?></span>
          </div>
        </div>
        <?php if (!$h): ?>
          <div class="card-body text-body-secondary">No report yet. The app sends one at :23 past the hour once it runs runtime alpha.103 or later, and <code>php scripts/clitool.php --status-report --send</code> sends one now.</div>
        <?php else: ?>
        <div class="card-body py-2">
          <div class="d-flex flex-wrap gap-2 mb-2 small">
            <?php $agentsUrl = '/projects/open?id=' . (int) $inst->id . '&to=' . rawurlencode('/agents'); ?>
            <?php if (!empty($h['agent_ready'])): ?><a class="badge text-bg-success text-decoration-none" href="<?= htmlspecialchars($agentsUrl) ?>" title="open this project's AI agents page">agent: <?= htmlspecialchars(implode(', ', $h['providers'] ?: ['ready'])) ?> <i class="bi bi-box-arrow-up-right"></i></a>
            <?php else: ?><a class="badge text-bg-danger text-decoration-none" href="<?= htmlspecialchars($agentsUrl) ?>" title="<?= htmlspecialchars($h['agent_problem'] ?? '') ?> — open this project's AI agents page">no agent <i class="bi bi-box-arrow-up-right"></i></a><?php endif; ?>
            <span class="badge text-bg-light text-dark border"><?= htmlspecialchars($h['runtime'] ?: 'runtime ?') ?></span>
            <?php if (!empty($h['uncommitted'])): ?><span class="badge text-bg-warning" title="uncommitted edits in the live tree: its updates refuse until they are committed or discarded"><?= (int) $h['uncommitted'] ?> uncommitted</span><?php endif; ?>
            <?php if (!empty($h['errors_h'])): ?><span class="badge text-bg-danger"><?= (int) $h['errors_h'] ?> errors/h</span><?php endif; ?>
            <?php if (isset($h['cron_last_tick']) && $h['cron_last_tick'] === null && isset($h['cron_last_fire'])): ?><span class="badge text-bg-warning" title="the minute heartbeat has not reached this app">no cron tick</span><?php endif; ?>
            <?php foreach ((array) ($h['concepts'] ?? []) as $c): ?><span class="badge text-bg-secondary"><?= htmlspecialchars($c) ?></span><?php endforeach; ?>
          </div>
          <table class="table table-sm table-borderless mb-0 small align-middle">
            <tr><td class="text-body-secondary">Memory</td><td class="text-end" title="used / total, peak <?= htmlspecialchars((string) ($h['mem_peak_mb'] ?? '?')) ?> MB"><?= $n($h['mem_mb'], ' MB') ?><span class="text-body-secondary"> / <?= (int) ($h['mem_total_mb'] ?? 0) ?></span></td><td class="text-end"><?= $spark($s['mem_mb'], '#0d6efd') ?></td></tr>
            <tr><td class="text-body-secondary">CPU</td><td class="text-end" title="share of one core since the previous report; load <?= htmlspecialchars((string) ($h['load1'] ?? '?')) ?>"><?= $n($h['cpu_pct'], '%') ?></td><td class="text-end"><?= $spark($s['cpu_pct'], '#6f42c1') ?></td></tr>
            <tr><td class="text-body-secondary">Requests / h</td><td class="text-end"><?= $n($h['requests_h']) ?></td><td class="text-end"><?= $spark($s['requests_hour'], '#198754') ?></td></tr>
            <tr><td class="text-body-secondary">Errors / h</td><td class="text-end <?= !empty($h['errors_h']) ? 'text-danger fw-semibold' : '' ?>"><?= $n($h['errors_h']) ?></td><td class="text-end"><?= $spark($s['errors_hour'], '#dc3545') ?></td></tr>
            <tr><td class="text-body-secondary">Disk</td><td class="text-end" title="code <?= htmlspecialchars((string) ($h['disk_code_mb'] ?? '?')) ?> MB · agent <?= htmlspecialchars((string) ($h['disk_agent_mb'] ?? '?')) ?> MB · uploads <?= htmlspecialchars((string) ($h['disk_uploads_mb'] ?? '?')) ?> MB · db <?= htmlspecialchars((string) ($h['db_mb'] ?? '?')) ?> MB · <?= (int) ($h['files'] ?? 0) ?> files"><?= $n($h['disk_mb'], ' MB') ?><span class="text-body-secondary"> (<?= htmlspecialchars((string) ($h['disk_agent_mb'] ?? '?')) ?> agent)</span></td><td class="text-end"><?= $spark($s['disk_mb'], '#fd7e14') ?></td></tr>
            <tr><td class="text-body-secondary">Members</td><td class="text-end"><?= $n($h['members']) ?></td><td class="text-end text-body-secondary small"><?= (int) ($h['pipelines'] ?? 0) ?> pipelines</td></tr>
            <?php if (!empty($r['domains'])): $dc = $r['domains']; $dcls = ['ok' => 'text-success', 'expiring' => 'text-warning fw-semibold', 'failing' => 'text-danger fw-semibold', 'unchecked' => 'text-body-secondary'][$dc['state']]; ?>
            <tr><td class="text-body-secondary">Domains</td>
              <td class="text-end"><a href="/deploy?id=<?= (int) $inst->id ?>" title="<?= htmlspecialchars(implode("\n", array_map(fn($d) => $d['domain'] . ': ' . $d['state'] . ($d['days_left'] !== null ? ' (' . $d['days_left'] . ' d)' : '') . ($d['error'] !== '' ? ' — ' . $d['error'] : ''), $dc['domains']))) ?>"><?= (int) $dc['count'] ?> <i class="bi bi-box-arrow-up-right small"></i></a></td>
              <td class="text-end small <?= $dcls ?>"><i class="bi bi-shield-<?= $dc['state'] === 'ok' ? 'check' : ($dc['state'] === 'unchecked' ? 'slash' : 'exclamation') ?>"></i> TLS <?= $dc['state'] === 'ok' ? 'ok' : $dc['state'] ?><?= $dc['days_left'] !== null ? ', ' . (int) $dc['days_left'] . ' d' : '' ?></td></tr>
            <?php endif; ?>
          </table>
        </div>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
  </div>
  <p class="small text-body-secondary mt-3">Sparklines are the last <?= \app\Projectreport::SPARK_POINTS ?> reports (about two days, hourly), each over its own range. CPU is the container's usage since its previous report as a share of one core. Memory is the container's own view (lxcfs). Disk is the app tree; the agent part (<code>bin/</code>, <code>.aibuilder/</code>) is the agent's binary and task state, not the project's code. Requests and errors are counted from each app's own log.</p>
</div>

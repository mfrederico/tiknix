<?php /** @var object $inst  @var array $reports — Projectreport::show */ ?>
<div class="container-fluid py-4">
  <h1 class="h3 mb-1"><?= htmlspecialchars($inst->displayName ?: $inst->slug) ?> — reports</h1>
  <p class="text-body-secondary"><a href="/projectreport">All projects</a> · <?= count($reports) ?> report(s), newest first (90 days are kept).</p>
  <div class="table-responsive">
  <table class="table table-sm align-middle">
    <thead><tr><th>Received</th><th>Why</th><th>Runtime</th><th>Agent</th><th class="text-end">Disk MB</th><th class="text-end">DB MB</th><th class="text-end">Files</th><th class="text-end">Mem MB</th><th class="text-end">Peak</th><th class="text-end">CPU %</th><th class="text-end">Load</th><th class="text-end">Req/h</th><th class="text-end">Err/h</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($reports as $r): ?>
      <tr>
        <td><?= htmlspecialchars((string) $r->receivedAt) ?></td>
        <td class="small text-body-secondary"><?= htmlspecialchars((string) $r->why) ?></td>
        <td><code><?= htmlspecialchars((string) $r->runtime) ?></code> <span class="small text-body-secondary"><?= htmlspecialchars((string) $r->appCommit) ?></span></td>
        <td><?= $r->agentReady ? '<span class="badge text-bg-success">ready</span> <span class="small">' . htmlspecialchars((string) $r->providers) . '</span>' : '<span class="badge text-bg-danger">none</span>' ?></td>
        <td class="text-end"><?= $r->diskMb === null ? '—' : htmlspecialchars((string) $r->diskMb) ?></td>
        <td class="text-end"><?= $r->dbMb === null ? '—' : htmlspecialchars((string) $r->dbMb) ?></td>
        <td class="text-end"><?= $r->files === null ? '—' : (int) $r->files ?></td>
        <td class="text-end"><?= $r->memMb === null ? '—' : htmlspecialchars((string) $r->memMb) ?></td>
        <td class="text-end"><?= $r->memPeakMb === null ? '—' : htmlspecialchars((string) $r->memPeakMb) ?></td>
        <td class="text-end"><?= $r->cpuPct === null ? '—' : htmlspecialchars((string) $r->cpuPct) ?></td>
        <td class="text-end"><?= $r->load1 === null ? '—' : htmlspecialchars((string) $r->load1) ?></td>
        <td class="text-end"><?= $r->requestsHour === null ? '—' : (int) $r->requestsHour ?></td>
        <td class="text-end"><?= $r->errorsHour === null ? '—' : (int) $r->errorsHour ?></td>
        <td><details><summary class="small text-body-secondary">raw</summary><pre class="small mb-0" style="max-width:60ch;white-space:pre-wrap"><?= htmlspecialchars(json_encode(json_decode((string) $r->reportJson, true), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre></details></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>

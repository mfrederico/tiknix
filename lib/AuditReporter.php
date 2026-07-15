<?php
/**
 * AuditReporter — consume an audit manifest and fan the results out.
 *
 * Given the `.aibuilder/audit.json` a jailed QA agent wrote (see AuditRunner),
 * this control-plane class:
 *   1. posts per-subtask comments (mapped via `task_ref` -> workbenchtask.planRef),
 *   2. posts a summary comment on the parent plan task,
 *   3. reports each failure to the firehose (-> auto-triage spins a fix, which on
 *      completion re-audits — the "loop back and retry" path),
 *   4. emails proof-of-life (screenshots attached) to the owner + shared teams,
 *   5. stamps auditStatus/auditAt/auditFailures on the parent plan.
 *
 * Screenshots live on the INSTANCE (public/uploads/audit/<plan>/...), so they are
 * embedded in comments as markdown images pointing at the instance's public URL,
 * and attached to the email from their on-disk path. Every step is best-effort:
 * a reporting failure must never crash the audit pipeline.
 */

namespace app;

use \RedBeanPHP\R as R;
use \app\Bean;

class AuditReporter {

    /** Max audit→fix→re-audit cycles before we stop auto-spawning fixes and ask for
     *  a human. A plan spawned as a fix inherits its parent's cycle + 1 (see Firehose),
     *  so the chain terminates instead of looping forever on an unfixable failure. */
    const MAX_AUDIT_CYCLES = 2;

    /** Consume a parsed manifest for a plan. Returns a small result summary.
     *  $auditCycle = how deep this plan is in an audit→fix chain (0 for a first build). */
    public static function report(array $m, $plan, $inst, string $instanceDir, int $auditCycle = 0): array {
        $slug = (string)$inst->slug;
        $app  = (string)($inst->app ?: 'tiknix');
        $baseUrl  = rtrim((string)($m['base_url'] ?? "https://{$slug}.{$app}.com"), '/');
        $failures = is_array($m['failures'] ?? null) ? $m['failures'] : [];
        $checks   = is_array($m['checks']   ?? null) ? $m['checks']   : [];
        $levels   = is_array($m['levels']   ?? null) ? $m['levels']   : [];
        // Failures win: passed only if the agent said so AND there are none.
        $passed   = empty($failures) && !empty($m['passed']);

        // relative instance path -> public web URL (public/uploads/x -> {base}/uploads/x)
        $web = function ($rel) use ($baseUrl) {
            $rel = ltrim((string)$rel, '/');
            if (strpos($rel, 'public/') === 0) $rel = substr($rel, strlen('public/'));
            return $baseUrl . '/' . $rel;
        };
        // relative instance path -> absolute on-disk path (for email attachments)
        $fs = function ($rel) use ($instanceDir) {
            return $instanceDir . '/' . ltrim((string)$rel, '/');
        };

        // Index subtasks by their planner ref so task_ref maps to a real task.
        $byRef = [];
        foreach (R::find('workbenchtask', 'parent_task_id = ?', [(int)$plan->id]) as $s) {
            if ($s->planRef) $byRef[(string)$s->planRef] = $s;
        }

        // Group checks + failures by task_ref.
        $perTask = [];
        foreach ($checks as $c)   { $r = (string)($c['task_ref'] ?? ''); if ($r !== '') $perTask[$r]['checks'][]   = $c; }
        foreach ($failures as $f) { $r = (string)($f['task_ref'] ?? ''); if ($r !== '') $perTask[$r]['failures'][] = $f; }

        // 1) Per-subtask comment where the ref maps to a subtask.
        foreach ($perTask as $ref => $g) {
            if (!isset($byRef[$ref])) continue;
            self::postComment((int)$byRef[$ref]->id, (int)$inst->memberId, self::subtaskMd($g, $web));
        }

        // Cap the audit->fix->re-audit chain: past the limit we stop spawning fixes
        // and leave it for a human (comment + email still go out).
        $capped = ($auditCycle >= self::MAX_AUDIT_CYCLES);

        // 2) Summary comment on the parent plan task.
        self::postComment((int)$plan->id, (int)$inst->memberId, self::summaryMd($m, $passed, $levels, $checks, $failures, $web, $capped));

        // 3) Report failures to the firehose (auto-triage -> fix -> re-audit loop),
        //    stamping the NEXT cycle so the spawned fix knows its depth.
        $reported = 0;
        if (!$capped) {
            foreach ($failures as $f) { if (self::reportFailure($f, $inst, (int)$plan->id, $auditCycle + 1)) $reported++; }
        }

        // 4) Email owner + shared-team members.
        $emailed = self::emailReport($m, $plan, $inst, $passed, $checks, $failures, $levels, $web, $fs, $capped);

        // 5) Stamp the plan (fluid columns; driver runs unfrozen).
        try {
            $plan->auditStatus   = $passed ? 'passed' : 'failed';
            $plan->auditAt       = date('Y-m-d H:i:s');
            $plan->auditFailures = count($failures);
            $plan->updatedAt     = date('Y-m-d H:i:s');
            Bean::store($plan);
        } catch (\Throwable $e) { /* best-effort */ }

        return ['passed' => $passed, 'failures' => count($failures), 'firehose' => $reported, 'emailed' => $emailed];
    }

    // --- comment builders -----------------------------------------------------

    private static function subtaskMd(array $g, callable $web): string {
        $out = ["**Definition-of-Done audit**"];
        foreach (($g['checks'] ?? []) as $c) {
            $out[] = '- ✅ ' . self::s($c['label'] ?? 'check') . ' _(' . self::s($c['level'] ?? '') . ')_';
            foreach (($c['screens'] ?? []) as $sc) $out[] = '![screenshot](' . $web($sc) . ')';
        }
        foreach (($g['failures'] ?? []) as $f) {
            $out[] = '- ❌ ' . self::s($f['label'] ?? 'failure') . ' _(' . self::s($f['level'] ?? '') . ')_ — ' . self::s($f['message'] ?? '');
            foreach (($f['screens'] ?? []) as $sc) $out[] = '![screenshot](' . $web($sc) . ')';
        }
        return implode("\n", $out);
    }

    private static function summaryMd(array $m, bool $passed, array $levels, array $checks, array $failures, callable $web, bool $capped = false): string {
        $out = [];
        $out[] = ($passed ? '## ✅ Audit passed' : '## ❌ Audit failed');
        if (!empty($m['summary'])) $out[] = '_' . self::s($m['summary']) . '_';
        $out[] = '';
        $out[] = '**Levels tested:** ' . (implode(', ', array_map(function ($k) use ($levels) {
            $lv = $levels[$k] ?? [];
            $ok = !empty($lv['login_ok']) ? '✓' : '✗';
            return ucfirst($k) . ' ' . $ok;
        }, array_keys($levels))) ?: '—');
        $out[] = '**Checks:** ' . count($checks) . ' · **Failures:** ' . count($failures);
        if ($failures) {
            $out[] = '';
            $out[] = '### Failures';
            foreach ($failures as $f) {
                $out[] = '- ❌ ' . self::s($f['label'] ?? '') . ' — ' . self::s($f['message'] ?? '')
                       . (!empty($f['url']) ? ' (`' . self::s($f['url']) . '`)' : '');
                foreach (($f['screens'] ?? []) as $sc) $out[] = '![screenshot](' . $web($sc) . ')';
            }
            $out[] = '';
            $out[] = $capped
                ? '_⚠️ Max audit cycles reached — no more auto-fixes will be spawned. These need manual review._'
                : '_Failures were sent to the firehose — fixes will be triaged and re-audited._';
        }
        return implode("\n", $out);
    }

    // --- persistence ----------------------------------------------------------

    private static function postComment(int $taskId, int $memberId, string $content): void {
        if ($taskId <= 0 || trim($content) === '') return;
        try {
            $c = Bean::dispense('taskcomment');
            $c->taskId       = $taskId;
            $c->memberId     = $memberId ?: null;
            $c->content      = $content;
            $c->isFromClaude = 1;   // render as markdown (parseSafe) so screenshots embed
            $c->isInternal   = 0;
            $c->createdAt    = date('Y-m-d H:i:s');
            Bean::store($c);
        } catch (\Throwable $e) { /* best-effort */ }
    }

    // --- firehose -------------------------------------------------------------

    private static function reportFailure(array $f, $inst, int $planId, int $nextCycle = 1): bool {
        $key = (string)\Flight::get('firehose.ingest_key');
        $url = rtrim((string)\Flight::get('app.baseurl'), '/') . '/firehose/report';
        if ($key === '' || $url === '/firehose/report') return false;

        $instTag = (string)$inst->slug . '.' . (string)($inst->app ?: 'tiknix');
        $payload = [
            // Plan-INDEPENDENT signature: the same logical failure dedups across plans
            // and audit cycles (keyed by instance + label + url, not the plan id).
            'signature'    => sha1('audit:' . $instTag . ':' . trim((string)($f['label'] ?? '')) . ':' . trim((string)($f['url'] ?? ''))),
            'instance'     => $instTag,
            'type'         => 'audit_failure',
            'message'      => mb_substr((string)($f['label'] ?? 'Audit failure'), 0, 500),
            'full_message' => mb_substr((string)($f['message'] ?? ''), 0, 2000),
            'class'        => 'AuditFailure',
            'url'          => mb_substr((string)($f['url'] ?? ''), 0, 500),
            'http_method'  => 'GET',
            // audit_cycle rides in context so the spawned fix plan inherits its depth
            // (see Firehose::createTriageTask / launchViaOrchestrator) and the chain caps.
            'context'      => ['source' => 'audit', 'plan_id' => $planId, 'level' => (string)($f['level'] ?? ''), 'audit_cycle' => $nextCycle],
        ];
        try {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($payload),
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'X-Firehose-Key: ' . $key],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
            ]);
            curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return $code >= 200 && $code < 300;
        } catch (\Throwable $e) { return false; }
    }

    // --- email ----------------------------------------------------------------

    private static function emailReport(array $m, $plan, $inst, bool $passed, array $checks, array $failures, array $levels, callable $web, callable $fs, bool $capped = false): int {
        $recipients = self::recipients($inst);
        if (!$recipients) return 0;
        if (!\app\Mailer::isConfigured()) return 0;

        $slug   = (string)$inst->slug;
        $title  = (string)($plan->title ?: ('Plan #' . $plan->id));
        $status = $passed ? 'PASSED ✅' : 'FAILED ❌';
        $planUrl = rtrim((string)\Flight::get('app.baseurl'), '/') . '/workbench/view?id=' . (int)$plan->id;

        // HTML body.
        $rows = '';
        foreach ($checks as $c) {
            $rows .= '<tr><td>✅</td><td>' . self::h($c['label'] ?? '') . '</td><td>' . self::h($c['level'] ?? '') . '</td></tr>';
        }
        foreach ($failures as $f) {
            $rows .= '<tr><td>❌</td><td>' . self::h($f['label'] ?? '') . ' — ' . self::h($f['message'] ?? '')
                   . '</td><td>' . self::h($f['level'] ?? '') . '</td></tr>';
        }
        $levelsLine = implode(', ', array_map(function ($k) use ($levels) {
            return ucfirst($k) . (!empty($levels[$k]['login_ok']) ? ' ✓' : ' ✗');
        }, array_keys($levels))) ?: '—';

        // Inline thumbnails via public URLs (also attached below for offline viewing).
        $shots = self::allScreens($m);
        $gallery = '';
        foreach (array_slice($shots, 0, 12) as $rel) {
            $gallery .= '<a href="' . self::h($web($rel)) . '"><img src="' . self::h($web($rel))
                     . '" style="max-width:260px;margin:4px;border:1px solid #ddd;border-radius:4px"/></a>';
        }

        $html = '<h2>Build audit — ' . self::h($status) . '</h2>'
              . '<p><strong>' . self::h($title) . '</strong> on <code>' . self::h($slug) . '.tiknix</code><br>'
              . '<em>' . self::h((string)($m['summary'] ?? '')) . '</em></p>'
              . '<p>Levels tested: ' . self::h($levelsLine) . ' &middot; '
              . count($checks) . ' checks &middot; ' . count($failures) . ' failures</p>'
              . ($rows ? '<table cellpadding="6" style="border-collapse:collapse;width:100%">'
                       . '<tr><th></th><th align="left">Check</th><th align="left">Level</th></tr>' . $rows . '</table>' : '')
              . ($gallery ? '<h3>Proof of life</h3><div>' . $gallery . '</div>' : '')
              . '<p><a href="' . self::h($planUrl) . '">Open the plan in the Workbench &rarr;</a></p>'
              . (!$passed ? '<p style="color:#a00">' . ($capped
                    ? 'Max audit cycles reached — no more auto-fixes will be spawned. These failures need manual review.'
                    : 'Failures were sent to triage — fixes will be built and re-audited automatically.') . '</p>' : '');

        $subject = "[{$slug}] Build audit " . ($passed ? 'passed' : 'FAILED') . ' — ' . $title;

        $sent = 0;
        foreach ($recipients as $email => $name) {
            try {
                $mailer = \app\Mailer::create()->to($email, $name)->subject($subject);
                foreach (array_slice($shots, 0, 12) as $rel) {
                    $path = $fs($rel);
                    if (is_file($path)) $mailer->attach($path, basename($path));
                }
                if ($mailer->send($html)) $sent++;
            } catch (\Throwable $e) { /* keep going */ }
        }
        return $sent;
    }

    /** Owner + members of any team the instance is shared with, keyed by email => name. */
    private static function recipients($inst): array {
        $out = [];
        try {
            $owner = R::load('member', (int)$inst->memberId);
            if ($owner->id && $owner->email) $out[strtolower((string)$owner->email)] = (string)($owner->username ?: $owner->email);

            if (in_array('instance_team', R::inspect(), true)) {
                $emails = R::getAll(
                    'SELECT DISTINCT m.email AS email, m.username AS username
                       FROM instance_team it
                       JOIN teammember tm ON tm.team_id = it.team_id
                       JOIN member m ON m.id = tm.member_id
                      WHERE it.instance_id = ? AND m.email IS NOT NULL', [(int)$inst->id]);
                foreach ($emails as $row) {
                    if (!empty($row['email'])) $out[strtolower((string)$row['email'])] = (string)($row['username'] ?: $row['email']);
                }
            }
        } catch (\Throwable $e) { /* best-effort */ }
        return $out;
    }

    // --- helpers --------------------------------------------------------------

    /** Every screenshot path across levels/checks/failures (deduped). */
    private static function allScreens(array $m): array {
        $out = [];
        foreach (($m['levels'] ?? []) as $lv) foreach (($lv['screens'] ?? []) as $s) $out[$s] = true;
        foreach (($m['checks'] ?? []) as $c)  foreach (($c['screens'] ?? []) as $s) $out[$s] = true;
        foreach (($m['failures'] ?? []) as $f) foreach (($f['screens'] ?? []) as $s) $out[$s] = true;
        return array_keys($out);
    }

    private static function s($v): string { return trim((string)$v); }
    private static function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES); }
}

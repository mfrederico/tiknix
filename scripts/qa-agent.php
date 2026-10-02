<?php
/**
 * qa-agent.php — the control plane's side of QA Testing's model work: the door the qa.tiknix
 * sidecar calls, because the agent's key and the QA host are core's.
 *
 *   php scripts/qa-agent.php --author < job.json
 *       job: {agent, system, prompt, schema, timeout, max_usd, slug, browser_job, signed_in}
 *       Runs Tiknix's own agent (lib/PlatformAgent.php — no tools but the browser) with the QA
 *       host's browser (lib/QaHost.php), the project's page inventory added to the prompt.
 *       Prints PlatformAgent's result as JSON.
 *
 *   php scripts/qa-agent.php --ask < job.json
 *       job: {agent, system, prompt, schema, timeout, max_usd}
 *       The same agent with NO tools at all — it reads what it is given and answers (the
 *       report on a run). Prints PlatformAgent's result as JSON.
 *
 *   php scripts/qa-agent.php --to-builder < plan.json
 *       plan: {slug, member_id, title, summary, subtasks:[{title, description}]}
 *       Files the findings an owner chose as ONE DRAFT PLAN on the project's Builder board
 *       (PlanIngestor) — to be reviewed and built like any plan, never started from here.
 *       Refused when the member does not own the project, or a draft plan already waits.
 *       Prints {ok, plan_id, tasks}.
 *
 *   php scripts/qa-agent.php --diff=SLUG [--since=COMMIT]
 *       What changed in the project's code since COMMIT (the first time: its last few
 *       commits), each file with its patch and what the platform's validators say about the
 *       change (lib/QaDiff.php). Read over SSH; nothing is run. Prints JSON.
 *
 *   php scripts/qa-agent.php --notify < note.json
 *       note: {slug, subject, html}
 *       Tells the project's OWNER (in Communications and by email, lib/QaNotifier.php) — a scheduled
 *       suite that went red, or came back. The owner is read from the registry, never taken
 *       from the caller. Prints {ok, email}.
 *
 *   php scripts/qa-agent.php --plan-checks=SLUG
 *       The "Acceptance" part of the project's PLAN.md, as JSON {ok, text} — what the plan
 *       itself says should be true, for the owner to turn into tests.
 */

if (php_sapi_name() !== 'cli') { http_response_code(403); exit("cli only\n"); }
require dirname(__DIR__) . '/vendor/autoload.php';
new \app\Bootstrap();

use app\Bean;

$o = getopt('', ['author', 'ask', 'to-builder', 'notify', 'plan-checks:', 'diff:', 'since:']);
$say = function (array $r): void { echo json_encode($r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), "\n"; exit(empty($r['ok']) ? 1 : 0); };
$project = function (string $slug) use ($say): object {
    $inst = Bean::findOne('instance', 'slug = ?', [$slug]);
    if (!$inst || !$inst->id || !\Model_Instance::tenantRow($inst)) $say(['ok' => false, 'error' => "no project '{$slug}' running in its own container"]);
    return $inst;
};

try {
    if (isset($o['plan-checks'])) {
        $inst = $project((string) $o['plan-checks']);
        [$code, $plan] = \app\TenantHost::ssh($inst, 'app', 'git -C /srv/app show HEAD:PLAN.md 2>/dev/null; true', null, 30);
        if ($code !== 0) $say(['ok' => false, 'error' => "{$inst->slug}'s container did not answer"]);
        if (trim($plan) === '') $say(['ok' => false, 'error' => 'this project has no PLAN.md']);
        // Every section whose heading speaks of acceptance, with its body up to the next heading of the same depth.
        $out = [];
        $lines = explode("\n", $plan); $n = count($lines);
        for ($i = 0; $i < $n; $i++) {
            if (!preg_match('/^(#{1,6})\s+.*accept/i', $lines[$i], $m)) continue;
            $depth = strlen($m[1]); $chunk = [$lines[$i]];
            for ($j = $i + 1; $j < $n; $j++) {
                if (preg_match('/^(#{1,6})\s/', $lines[$j], $h) && strlen($h[1]) <= $depth) break;
                $chunk[] = $lines[$j];
            }
            $out[] = trim(implode("\n", $chunk)); $i = $j - 1;
        }
        if (!$out) $say(['ok' => false, 'error' => 'PLAN.md has no section about acceptance checks']);
        $say(['ok' => true, 'text' => mb_substr(implode("\n\n", $out), 0, 6000)]);
    }

    if (isset($o['diff'])) {
        $say(\app\QaDiff::read($project((string) $o['diff']), (string) ($o['since'] ?? '')));
    }

    if (isset($o['notify'])) {
        $n = json_decode((string) stream_get_contents(STDIN), true);
        if (!is_array($n) || trim((string) ($n['subject'] ?? '')) === '' || trim((string) ($n['html'] ?? '')) === '') $say(['ok' => false, 'error' => 'the note on stdin needs a subject and html']);
        $inst = $project((string) ($n['slug'] ?? ''));
        $say(\app\QaNotifier::tell($inst, mb_substr((string) $n['subject'], 0, 200), (string) $n['html']));
    }

    if (isset($o['ask'])) {
        $job = json_decode((string) stream_get_contents(STDIN), true);
        if (!is_array($job)) $say(['ok' => false, 'error' => 'the job on stdin is not JSON']);
        $job['mcp'] = [];
        $r = \app\PlatformAgent::run($job);
        unset($r['text']);
        $say($r);
    }

    if (isset($o['to-builder'])) {
        $plan = json_decode((string) stream_get_contents(STDIN), true);
        if (!is_array($plan) || !\app\PlanIngestor::isValidPlan($plan)) $say(['ok' => false, 'error' => 'the plan on stdin needs a title and subtasks']);
        $inst = $project((string) ($plan['slug'] ?? ''));
        $memberId = (int) ($plan['member_id'] ?? 0);
        if (!$inst->ownedBy($memberId)) $say(['ok' => false, 'error' => "only the project's owner sends findings to its Builder"]);
        $subtasks = [];
        foreach (array_values($plan['subtasks']) as $i => $st) {
            if (!is_array($st) || trim((string) ($st['title'] ?? '')) === '') continue;
            $subtasks[] = ['id' => 'q' . ($i + 1), 'title' => (string) $st['title'], 'description' => (string) ($st['description'] ?? ''), 'priority' => 2];
        }
        if (!$subtasks) $say(['ok' => false, 'error' => 'no findings to send']);
        $app = (string) ($inst->app ?: \Model_Instance::DEFAULT_APP);
        // The board is the project's OWN workbench.db (its workspace on this host); the registry
        // work above is done, everything from here writes tasks.
        $db = \Model_Instance::dirOf($inst) . '/data/workbench.db';
        if (!is_file($db)) $say(['ok' => false, 'error' => "{$inst->slug} has no Builder board yet — open its Builder once"]);
        Bean::addDatabase('tasks', 'sqlite:' . $db);
        Bean::selectDatabase('tasks');
        Bean::freeze(false);
        $waiting = Bean::findOne('workbenchtask', "plan_status = 'draft' AND (parent_task_id IS NULL OR parent_task_id = 0)");
        if ($waiting && $waiting->id) $say(['ok' => false, 'error' => "a plan is already waiting for review in the Builder (“{$waiting->title}”) — approve or discard it first"]);
        $r = \app\PlanIngestor::ingest($inst, ['title' => (string) $plan['title'], 'summary' => (string) ($plan['summary'] ?? ''), 'subtasks' => $subtasks], $memberId, '', $app);
        $say(['ok' => true, 'plan_id' => (int) $r['parent']['id'], 'tasks' => count($r['subtasks'])]);
    }

    if (isset($o['author'])) {
        $job = json_decode((string) stream_get_contents(STDIN), true);
        if (!is_array($job)) $say(['ok' => false, 'error' => 'the job on stdin is not JSON']);
        $inst = $project((string) ($job['slug'] ?? ''));
        // The app's own pages, from its inventory: where things are, so the agent opens them
        // instead of guessing addresses.
        $digest = \app\TenantBuilder::digest($inst);
        $pages = preg_match('/^### Controllers.*?(?=^### )/ms', $digest, $m) ? trim($m[0]) : '';
        if ($pages !== '') $job['prompt'] .= "\n\n## The app's pages (its inventory; /controller/method is the address)\n\n" . mb_substr($pages, 0, 6000);
        $job['mcp'] = ['playwright' => \app\QaHost::browserMcp((string) ($job['browser_job'] ?? ''), !empty($job['signed_in']))];
        $r = \app\PlatformAgent::run($job);
        unset($r['text']);
        $say($r);
    }
    fwrite(STDERR, "usage: --author < job.json | --ask < job.json | --to-builder < plan.json | --plan-checks=SLUG | --diff=SLUG [--since=COMMIT] | --notify < note.json\n"); exit(2);
} catch (\Throwable $e) {
    $say(['ok' => false, 'error' => $e->getMessage()]);
}

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
 *   php scripts/qa-agent.php --plan-checks=SLUG
 *       The "Acceptance" part of the project's PLAN.md, as JSON {ok, text} — what the plan
 *       itself says should be true, for the owner to turn into tests.
 */

if (php_sapi_name() !== 'cli') { http_response_code(403); exit("cli only\n"); }
require dirname(__DIR__) . '/vendor/autoload.php';
new \app\Bootstrap();

use app\Bean;

$o = getopt('', ['author', 'plan-checks:']);
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
    fwrite(STDERR, "usage: --author < job.json | --plan-checks=SLUG\n"); exit(2);
} catch (\Throwable $e) {
    $say(['ok' => false, 'error' => $e->getMessage()]);
}

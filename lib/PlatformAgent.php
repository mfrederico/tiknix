<?php
/**
 * PlatformAgent — one of Tiknix's OWN agents (the AI agents page here) doing a job for one of
 * Tiknix's own tools, with nothing in its hands but what that job needs.
 *
 * QA Testing's author is the first: it reads pages written by project owners, so whatever a
 * page says ("ignore your instructions and…") must have nothing to act with. This is not the
 * builder's agent run:
 *
 *   - NO built-in tools at all (--tools ""): no shell, no files, no web fetch
 *   - ONLY the MCP servers the job names (--strict-mcp-config), and only their tools allowed
 *   - no settings, hooks, memory or CLAUDE.md from this machine (--bare), no saved session
 *   - a scratch HOME and working folder, removed afterwards
 *   - the agent's key in the child's environment only; a spending cap on every run
 *   - the answer as one JSON document checked against the job's schema
 *
 * It runs on the control plane because that is where the key is. What it drives (a browser)
 * is somewhere else (QaHost).
 */

namespace app;

class PlatformAgent {

    /**
     * @param array $job {agent, system, prompt, schema(array), mcp(array name => {command,args}),
     *                    timeout(int s), max_usd(float)}
     * @return array{ok:bool,error:string,output:?array,text:string,cost_usd:float,turns:int,model:string,agent:string}
     */
    public static function run(array $job): array {
        $out = ['ok' => false, 'error' => '', 'output' => null, 'text' => '', 'cost_usd' => 0.0, 'turns' => 0, 'model' => '', 'agent' => (string) ($job['agent'] ?? '')];
        $fail = fn(string $why) => ['error' => $why] + $out;

        $root = Paths::root();
        $bin = $root . '/bin/claude';
        if (!is_file($bin)) return $fail("this install has no agent program ({$bin}) — php scripts/claude-link.php --root={$root}");
        $name = trim((string) ($job['agent'] ?? ''));
        $agent = $name !== '' ? \Model_Agent::byName($name) : null;
        if (!$agent) return $fail("no AI agent named '{$name}' on Tiknix (the AI agents page)");
        if ((string) $agent->kind !== 'cli') return $fail("agent '{$name}' is a {$agent->kind} agent; this job needs one that runs claude (kind cli)");
        $prompt = trim((string) ($job['prompt'] ?? ''));
        if ($prompt === '') return $fail('the job has no prompt');
        $schema = $job['schema'] ?? null;
        if (!is_array($schema)) return $fail('the job has no answer schema');
        $mcp = (array) ($job['mcp'] ?? []);
        $timeout = max(30, min(1800, (int) ($job['timeout'] ?? 600)));
        $maxUsd = (float) ($job['max_usd'] ?? 0);
        if ($maxUsd <= 0) return $fail('the job has no spending cap (max_usd)');

        $engine = (string) $agent->engine ?: EngineRegistry::defaultEngine();
        [$env, $err, $credential] = $agent->box()->cliEnv($root, $engine);
        if ($err !== '') return $fail($err);
        // The agent's OWN key, or nothing: this must never run on the install's Claude account.
        if ((string) $agent->box()->keyStatus() !== 'set') return $fail("agent '{$name}' has no API key of its own stored (the AI agents page) — a platform tool does not run on the Claude account");
        $model = $agent->box()->modelFor($engine, 'worker');
        $out['model'] = $model;

        $dir = sys_get_temp_dir() . '/platform-agent-' . bin2hex(random_bytes(8));
        if (!mkdir($dir . '/home', 0700, true)) return $fail("could not create {$dir}");
        try {
            $allowed = [];
            foreach (array_keys($mcp) as $server) {
                if (!preg_match('/^[a-z][a-z0-9_]{0,30}$/', (string) $server)) return $fail("'{$server}' is not an MCP server name");
                $allowed[] = 'mcp__' . $server;
            }
            file_put_contents($dir . '/mcp.json', json_encode(['mcpServers' => (object) $mcp], JSON_UNESCAPED_SLASHES));
            $cmd = escapeshellarg($bin) . ' -p ' . escapeshellarg($prompt)
                 . ' --bare --tools "" --strict-mcp-config --mcp-config ' . escapeshellarg($dir . '/mcp.json')
                 . ($allowed ? ' --allowedTools ' . implode(' ', array_map('escapeshellarg', $allowed)) : '')
                 . ' --no-session-persistence --output-format json'
                 . ' --model ' . escapeshellarg($model)
                 . ' --max-budget-usd ' . escapeshellarg(number_format($maxUsd, 2, '.', ''))
                 . ' --system-prompt ' . escapeshellarg((string) ($job['system'] ?? ''))
                 . ' --json-schema ' . escapeshellarg((string) json_encode($schema, JSON_UNESCAPED_SLASHES));
            $env = array_merge($env, ['HOME' => $dir . '/home', 'PATH' => '/usr/bin:/bin', 'CLAUDE_CODE_DISABLE_NONESSENTIAL_TRAFFIC' => '1']);
            $proc = proc_open('timeout --signal=TERM --kill-after=10 ' . $timeout . ' ' . $cmd,
                [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $dir, $env);
            if (!is_resource($proc)) return $fail('the agent program could not be started');
            $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            $code = proc_close($proc);
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }

        if ($code === 124) return $fail("the agent did not finish within {$timeout} seconds");
        $r = json_decode(trim((string) $stdout), true);
        if (!is_array($r)) return $fail('the agent program answered without a result (exit ' . $code . '): ' . Redact::secrets(mb_substr(trim((string) $stderr) ?: trim((string) $stdout), 0, 300)));
        $out['cost_usd'] = (float) ($r['total_cost_usd'] ?? 0);
        $out['turns'] = (int) ($r['num_turns'] ?? 0);
        $out['text'] = (string) ($r['result'] ?? '');
        if (!empty($r['is_error']) || (string) ($r['subtype'] ?? '') !== 'success') {
            return ['error' => 'the agent stopped: ' . ((string) ($r['subtype'] ?? '') ?: 'error') . ' — ' . Redact::secrets(mb_substr($out['text'], 0, 300))] + $out;
        }
        if (!is_array($r['structured_output'] ?? null)) return ['error' => 'the agent finished without the structured answer the job asked for'] + $out;
        $out['ok'] = true;
        $out['output'] = $r['structured_output'];
        return $out;
    }
}

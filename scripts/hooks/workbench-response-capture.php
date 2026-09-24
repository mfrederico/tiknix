#!/usr/bin/env php
<?php
/**
 * Workbench Response Capture Hook (Stop Hook)
 *
 * When a Task Board agent finishes a turn: its reply goes into the task's conversation,
 * and a task still marked `running` becomes `awaiting` (the user's turn). Both through ONE
 * call — add_task_log with as_reply: true — on the PROJECT's MCP server, with the key the
 * agent itself uses (the workspace's .mcp.json, server "tiknix").
 *
 * Through the MCP server, not the database. This hook used to open workbench.db directly
 * (TIKNIX_WORKBENCH_DB), which a JAILED session cannot see: jailed, nothing was saved and
 * no task was ever handed back by it. It also read the reply from `stop_hook_response`,
 * which Claude Code does not send — the reply is `last_assistant_message`, or the last
 * assistant turn in `transcript_path`.
 *
 * Environment (exported by the runner script that launched the agent):
 *   TIKNIX_TASK_ID       the workbench task — absent = not a task session, do nothing
 *   TIKNIX_PROJECT_ROOT  the workspace, whose .mcp.json names the project's MCP server
 *
 * A hook must never stop the agent: every failure is said on stderr and the hook exits 0.
 */

$taskId = (int) (getenv('TIKNIX_TASK_ID') ?: 0);
if ($taskId <= 0) { echo '{}'; exit(0); }   // not a Task Board session (e.g. a developer's own Claude Code)

$say = function (string $msg) use ($taskId): void {
    fwrite(STDERR, "workbench-response-capture (task {$taskId}): {$msg}\n");
};

// ---- the project's MCP server, as the agent reaches it -----------------------------
$root = rtrim((string) (getenv('TIKNIX_PROJECT_ROOT') ?: ''), '/');
$mcp  = $root !== '' ? json_decode((string) @file_get_contents($root . '/.mcp.json'), true) : null;
$srv  = is_array($mcp) ? ($mcp['mcpServers']['tiknix'] ?? null) : null;
$url  = is_array($srv) ? (string) ($srv['url'] ?? '') : '';
if ($url === '') {
    $say("no 'tiknix' MCP server in " . ($root !== '' ? "{$root}/.mcp.json" : '(TIKNIX_PROJECT_ROOT unset)') . '; the reply is NOT saved and the task is NOT handed back');
    echo '{}'; exit(0);
}
$headers = ['Content-Type: application/json', 'Accept: application/json, text/event-stream'];
foreach ((array) ($srv['headers'] ?? []) as $k => $v) $headers[] = "{$k}: {$v}";

// ---- the reply ---------------------------------------------------------------------
$in = json_decode((string) file_get_contents('php://stdin'), true);
$text = is_array($in) ? trim((string) ($in['last_assistant_message'] ?? '')) : '';
if ($text === '' && is_array($in) && !empty($in['transcript_path']) && is_readable($in['transcript_path'])) {
    // Newest assistant turn that has text (tool-only turns have none).
    $lines = file($in['transcript_path'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    for ($i = count($lines) - 1; $i >= 0 && $text === ''; $i--) {
        $e = json_decode($lines[$i], true);
        if (($e['type'] ?? '') !== 'assistant') continue;
        $parts = [];
        foreach ((array) ($e['message']['content'] ?? []) as $b) {
            if (is_array($b) && ($b['type'] ?? '') === 'text') $parts[] = (string) $b['text'];
        }
        $text = trim(implode("\n", $parts));
    }
}
// Nothing worth keeping is still a turn that ended: the task is handed back all the same.
if (strlen($text) < 10 || str_starts_with($text, '{') || str_starts_with($text, '[')) $text = '';

// ---- one call: save the reply, hand the task back ---------------------------------
$body = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => [
    'name' => 'add_task_log', 'arguments' => ['task_id' => $taskId, 'message' => $text, 'as_reply' => true],
]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$ch = curl_init($url);
curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers,
                        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 5]);
$resp = curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
if ($resp === false) {
    $say("could not reach {$url}: " . curl_error($ch) . '; the reply is NOT saved and the task is NOT handed back');
    echo '{}'; exit(0);
}
// The server may answer as SSE (data: …) or plain JSON.
$json = preg_match('/^data:\s*(\{.*\})\s*$/m', (string) $resp, $m) ? $m[1] : (string) $resp;
$r = json_decode($json, true);
$err = $code !== 200 ? "HTTP {$code}" : ($r['error']['message'] ?? (!empty($r['result']['isError']) ? (string) ($r['result']['content'][0]['text'] ?? 'tool error') : ''));
if ($err !== '') $say("add_task_log refused: {$err}");

echo '{}';
exit(0);

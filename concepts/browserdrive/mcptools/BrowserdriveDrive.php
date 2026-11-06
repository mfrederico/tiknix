<?php
namespace app\concepts\browserdrive\mcptools;

use app\concepts\browserdrive\Session;

/** Browser drive for agents and scripts: open a session, send steps one by one (each answered once a person's runner page
 *  has played it), close it. The platform checks the caller's API key at /mcp/message; the Node client (assets/client.js)
 *  calls this tool there. The queue is lib/Session.php. */
class BrowserdriveDrive extends \app\mcptools\BaseTool {
    public static string $name = 'browserdrive_drive';
    public static string $description = '[Browser drive] Drive this site in a real browser, Playwright-style, through the open runner page (/browserdrive) of the member whose API key this is: no browser on the server. action open {path} starts a session on an allowed sandbox page; action step {session, cmd} plays one command and returns its result, e.g. cmd {"do":"click","target":"text=Save"}, {"do":"fill","target":"#name","value":"A4"}, {"do":"press","key":"Enter"}, {"do":"waitFor","text":"Saved"}, {"do":"text","target":".total"}, {"do":"screenshot"}; action result {step} asks again for a step that was still pending; action close {session}. Commands: goto reload back viewport click dblclick hover fill type press check uncheck select drag mouse wheel waitFor wait evaluate text value attr count html url title screenshot console. Targets: CSS, text=…, xpath=…, or {x,y}. evaluate and waitFor with js are refused until an admin switches script on at /browserdrive. Use it to check pages and flows you built; only sandbox pages are allowed.';
    public static array $inputSchema = [
        'type' => 'object',
        'properties' => [
            'action' => ['type' => 'string', 'enum' => ['open', 'step', 'result', 'close'], 'description' => 'open a session, play a step in it, ask again for a step still pending, or close it'],
            'path' => ['type' => 'string', 'description' => 'open: the page to start on (a path of this site the admins allowed, e.g. /demo)'],
            'note' => ['type' => 'string', 'description' => 'open: what this session is for (shown on the runner page)'],
            'session' => ['type' => 'integer', 'description' => 'step, close: the session number open returned'],
            'cmd' => ['type' => 'object', 'description' => 'step: the command, {"do": "<command>", …its arguments}'],
            'step' => ['type' => 'integer', 'description' => 'result: the step number a pending answer gave'],
            'png' => ['type' => 'boolean', 'description' => 'step, result: return a screenshot\'s PNG itself (a data URL; large) instead of only its size'],
            'wait' => ['type' => 'integer', 'description' => 'step, result: seconds to wait for the result (default 25, at most 55); a step not played by then is answered with pending'],
        ],
        'required' => ['action'],
    ];

    public function execute(array $args): string {
        if (!$this->member || (int) ($this->member->level ?? 999) > 50) return json_encode(['ok' => false, 'error' => 'browser drive is for admins: a driven page acts as a person on this site']);
        $wait = min(55, max(0, (int) ($args['wait'] ?? 25)));
        try {
            switch ((string) ($args['action'] ?? '')) {
                case 'open':
                    $s = Session::open((int) $this->member->id, (string) ($args['path'] ?? ''), (string) ($args['note'] ?? ''));
                    return json_encode(['ok' => true, 'session' => (int) $s->id, 'path' => (string) $s->path, 'said' => 'Open. Steps play once your own runner page (/browserdrive, open in a tab signed in as the owner of this API key) claims it; if none does within 5 minutes the session expires and a step says so.'], JSON_UNESCAPED_SLASHES);
                case 'step':
                    $s = Session::mine((int) ($args['session'] ?? 0), $this->member); $st = Session::step($s, (array) ($args['cmd'] ?? []));
                    return $this->answer(Session::wait((int) $st->id, $wait) ?? (Session::view($st) + ['pending' => true, 'said' => 'not played yet: no runner has claimed the session, or the step is long; ask with action result and this step']), !empty($args['png']));
                case 'result':
                    $st = \app\Bean::load('drivestep', (int) ($args['step'] ?? 0)); if (!$st->id) return json_encode(['ok' => false, 'error' => 'no such step']);
                    Session::mine((int) $st->drivesessionId, $this->member);
                    return $this->answer(Session::wait((int) $st->id, $wait) ?? (Session::view($st) + ['pending' => true]), !empty($args['png']));
                case 'close':
                    $s = Session::mine((int) ($args['session'] ?? 0), $this->member); Session::close($s, 'closed by its driver');
                    return json_encode(['ok' => true, 'session' => (int) $s->id, 'status' => 'closed']);
            }
            return json_encode(['ok' => false, 'error' => 'action is open, step, result or close']);
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            return json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    /** A step's view as the answer; a screenshot's PNG only when asked for (it is large). */
    private function answer(array $r, bool $png): string {
        if (!$png && isset($r['result']['png'])) $r['result']['png'] = '(a PNG of ' . (int) (strlen((string) $r['result']['png']) * 3 / 4) . ' bytes, ' . ($r['result']['w'] ?? '?') . '×' . ($r['result']['h'] ?? '?') . '; ask with action result, this step and png true to get it)';
        return json_encode(['ok' => ($r['status'] ?? '') === 'done'] + $r, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }
}

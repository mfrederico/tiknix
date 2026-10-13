<?php
/**
 * AskTriage — what someone typed into Ask, read before anything is planned: is it something to
 * BUILD, or a question to ANSWER? Every message used to become a plan; "what does the game page
 * do?" cost a minute of planning and left a plan nobody wanted.
 *
 * The project's own agent decides, in a quick first reading on its FAST model, and — only when
 * it is a question — answers it in a second run on the builder's own model, in the app's
 * container, with tools that only read (the runtime's AgentTask::ask). Three outcomes:
 *
 *   build   it goes down the Builder's road as before (PromptQueue: decomposed when the project
 *           is free, behind whatever is building)
 *   answer  the agent's words are the reply; nothing is planned. Never waits for a build.
 *   unsure  it could be either: the person is asked, with two buttons. A wrong "answer" costs a
 *           click on "Build it"; a wrong "build" costs minutes and a plan to clean up — so in
 *           doubt it does not build.
 *
 * The verdict is the person's to overrule either way (Workbench::askas). A run that fails says so
 * (state failed, with the reason) and builds nothing.
 */

namespace app;

class AskTriage {
    public const STATES = ['thinking', 'answer', 'unsure', 'failed', 'build'];
    /** How long one run may take, in the container. */
    public const TIMEOUT = 240;

    /** The first runtime whose clitool has --agent-ask with --fast (the quick first reading). */
    public const SINCE = '2.0.0-alpha.191';

    /**
     * Can this app's agent be asked? Only from the runtime that has the read-only run; an app on
     * an older one (it is updated when it is not building) is sent the message as a request to
     * build, as before. $runtime is what the app last reported ("v2.0.0-alpha.188"; '' = unknown).
     */
    public static function appCanAnswer(string $runtime): bool {
        $v = ltrim(trim($runtime), 'v');
        return $v !== '' && version_compare($v, self::SINCE, '>=');
    }

    /**
     * The quick first reading, for the agent's FAST model: which is it? Nothing about the app is
     * looked at — it is a judgement on the words — so it costs a second or two and little money,
     * and a request to build is on its way to the planner without a slower model having read it.
     */
    public static function classifyBrief(string $said): string {
        return <<<MD
        # Ask — what kind of message is this?

        Someone typed the message at the end of this file into the Builder of their web app. Do NOT answer it, do not open any file, do not use any tool. Only say what kind of message it is.

        Reply with exactly one line, one of:

        - `VERDICT: BUILD` — they want the app changed: something added, removed, fixed, restyled, connected, imported. A request phrased as a question ("can you add a login page?", "could the header be darker?") is still BUILD. So is a bug report they want fixed.
        - `VERDICT: ANSWER` — they want to know something: how the app works, what a page does, where something is, why something happened, what is possible, what you would suggest, how a build is going. Nothing is to change.
        - `VERDICT: UNSURE` — it could honestly be either, or it is too vague to tell what would be built. Do not guess.

        Only after `VERDICT: UNSURE`, add ONE short sentence on the next line saying what you could not tell (it is shown above two buttons: "Build it" and "Just answer").

        ## The message

        {$said}
        MD;
    }

    /** What the agent is handed: how to answer, then what was typed. $mode 'answer' = the person already said it is a question. */
    public static function brief(string $said, string $mode, string $busy): string {
        $decide = $mode === 'answer'
            ? "The person has said this is a QUESTION, not something to build. Answer it. Your first line is exactly `VERDICT: ANSWER`."
            : <<<MD
            First decide what it is. Your FIRST LINE is exactly one of:

            - `VERDICT: BUILD` — they want the app changed: something added, removed, fixed, restyled, connected, imported. A request phrased as a question ("can you add a login page?", "could the header be darker?") is still BUILD. So is a bug report they want fixed.
            - `VERDICT: ANSWER` — they want to know something: how the app works, what a page does, where something is, why something happened, what is possible, what you would suggest. Nothing is to change.
            - `VERDICT: UNSURE` — it could honestly be either, or it is too vague to tell what would be built. Do not guess.

            After `VERDICT: BUILD` write nothing else: the request goes to the planner as typed.
            After `VERDICT: UNSURE` write ONE short sentence saying what you could not tell (it is shown above two buttons: "Build it" and "Just answer").
            After `VERDICT: ANSWER` write the answer.
            MD;
        $decide = preg_replace('/^ {12}/m', '', $decide);
        $now = $busy !== '' ? "A build is in progress in this project right now ({$busy}). You are reading the app as it is at this moment, without that work — say so in a few words if the answer could change once it lands." : 'Nothing is being built in this project right now.';
        return <<<MD
        # Ask — read what was typed, and answer it if it is a question

        Someone typed the message at the end of this file into the Builder of this app. You are in the app's own directory.

        {$decide}

        ## How to answer

        - You can only READ: the code (Read, Grep, Glob) and the app's own tools (`reuse_digest`, `describe`, `whatprovides`, `database_schema`, `application_info`, `last_error`, `read_log_entries`). You cannot change a file, run a command or start a build, and must not say you did.
        - Look before you answer. Say what the app really has, naming the page or file (`/game/play`, `controls/Game.php`) — not what an app like it usually has.
        - Write for the person who owns the app, who may not be a programmer: plain words, short. A few sentences, or a short list. No headings unless it is long. Markdown is shown.
        - If you cannot find it, say what you looked at and that you did not find it.
        - If the honest answer is "that would have to be built", say so, and say in one line what you would ask for.
        - {$now}

        ## The message

        {$said}
        MD;
    }

    /** The agent's reply, read: ['verdict' => build|answer|unsure, 'text' => …]; throws when it did not say which. $needAnswer false = the first reading, which only says which. */
    public static function parse(string $reply, bool $needAnswer = true): array {
        $reply = trim($reply);
        if (!preg_match('/^\s*`?VERDICT:\s*(BUILD|ANSWER|UNSURE)`?\s*$/mi', $reply, $m, PREG_OFFSET_CAPTURE)) {
            throw new \RuntimeException('the agent did not say whether this is something to build or a question');
        }
        $text = trim(substr($reply, $m[0][1] + strlen($m[0][0])));
        $verdict = strtolower($m[1][0]);
        if ($verdict === 'answer' && $text === '' && $needAnswer) throw new \RuntimeException('the agent called it a question and gave no answer');
        return ['verdict' => $verdict, 'text' => $verdict === 'build' ? '' : $text];
    }

    /** Record where a message stands. */
    public static function mark(int $promptId, string $state, string $answer = ''): void {
        if (!in_array($state, self::STATES, true)) throw new \InvalidArgumentException("not an ask state: {$state}");
        CoreDb::with(function () use ($promptId, $state, $answer) {
            $row = Bean::load('promptlog', $promptId);
            if (!$row->id) throw new \RuntimeException("no prompt #{$promptId}");
            $row->askState  = $state;
            $row->askAnswer = $answer;
            $row->askAt     = date('Y-m-d H:i:s');
            Bean::store($row);
            return null;
        });
    }

    /**
     * The messages of one member on one project that did not (or not yet) become a plan — for the
     * conversation, oldest first. A thinking one whose run is long over is shown as failed.
     * @return list<array{id:int,asked:string,at:string,state:string,answer:string}>
     */
    public static function talk(int $memberId, string $instanceTag, int $limit = 20): array {
        if ($memberId <= 0 || $instanceTag === '') return [];
        return (array) CoreDb::with(function () use ($memberId, $instanceTag, $limit) {
            if (!in_array('ask_state', array_keys(Bean::inspect('promptlog')), true)) return [];
            $out = [];
            foreach (Bean::find('promptlog', "member_id = ? AND instance_tag = ? AND ask_state IN ('thinking', 'answer', 'unsure', 'failed') ORDER BY id DESC LIMIT " . max(1, $limit), [$memberId, $instanceTag]) as $r) {
                $state = (string) $r->askState; $answer = (string) $r->askAnswer;
                if ($state === 'thinking' && time() - (int) strtotime((string) $r->askAt) > self::TIMEOUT + 420) {
                    $state = 'failed'; $answer = 'No answer came back: the run that was reading it ended without one.';
                }
                $out[] = ['id' => (int) $r->id, 'asked' => trim(GoalBrief::split((string) $r->body)['goal']), 'at' => (string) $r->createdAt, 'state' => $state, 'answer' => $answer];
            }
            return array_reverse($out);
        }, []);
    }

    /**
     * Read one message and act on it (scripts/ask-triage.php, detached from the request that sent
     * it). $mode: auto — decide; answer — the person said it is a question; build — the person
     * said build it. Returns a line for the log.
     */
    public static function run(int $promptId, string $mode): string {
        $q = CoreDb::with(function () use ($promptId) {
            $r = Bean::load('promptlog', $promptId);
            return $r->id ? ['body' => (string) $r->body, 'tag' => (string) $r->instanceTag, 'plan' => (string) $r->planUid] : null;
        }, null);
        if (!$q) return "#{$promptId}: no such prompt";
        if ($q['plan'] !== '') return "#{$promptId}: already a plan";
        if ($mode === 'build') return self::build($promptId);

        $slug = (string) (strstr($q['tag'], '.', true) ?: $q['tag']);
        try {
            $tenant = TenantBuilder::bySlug($slug);
            if (!$tenant) throw new \RuntimeException("{$slug} is not running in its own container, so its agent cannot be asked");
            $busy = BuildQueue::busyWith(\Model_Instance::dirForSlug($slug), $slug);
            $said = trim(GoalBrief::split($q['body'])['goal']);
            // First the quick reading, on the fast model: which is it? Only a question goes on to
            // be answered — by the builder's own model, which reads the app to do it.
            $got = ['verdict' => 'answer', 'text' => ''];
            if ($mode === 'auto') {
                $r = TenantHost::ask($tenant, 'ask-' . $promptId . '-c', self::classifyBrief($said), 90, '', true);
                if (empty($r['ok'])) throw new \RuntimeException((string) ($r['error'] ?? 'the agent gave no answer'));
                $got = self::parse((string) $r['text'], false);
            }
            if ($got['verdict'] === 'answer') {
                $r = TenantHost::ask($tenant, 'ask-' . $promptId, self::brief($said, 'answer', $busy), self::TIMEOUT);
                if (empty($r['ok'])) throw new \RuntimeException((string) ($r['error'] ?? 'the agent gave no answer'));
                $got = self::parse((string) $r['text']);
                if ($got['verdict'] !== 'answer') throw new \RuntimeException('asked to answer, the agent did not');
            }
        } catch (\Throwable $e) {
            error_log("ERROR AskTriage #{$promptId} ({$slug}): " . $e->getMessage());
            self::mark($promptId, 'failed', $e->getMessage());
            return "#{$promptId}: failed — " . $e->getMessage();
        }
        if ($got['verdict'] === 'build') return self::build($promptId);
        self::mark($promptId, $got['verdict'], $got['text']);
        return "#{$promptId}: {$got['verdict']}";
    }

    /** It is something to build: down the Builder's road, behind whatever is building. */
    private static function build(int $promptId): string {
        self::mark($promptId, 'build');
        if (!PromptQueue::enqueue($promptId, true)) return "#{$promptId}: build — but it could not be queued";
        return "#{$promptId}: build — " . implode('; ', PromptQueue::drain());
    }
}

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
 * It is a conversation: each reading is handed the last few things this person said here and
 * what became of them (history()), so "and the footer?" is read as a follow-up. From our own
 * record, cut short — not an agent session that grows, and is paid for, without limit.
 *
 * An OWNER or ADMIN of the project is also answered from its data (read-only rows, through the
 * app's database_query); a member is answered from the code and the counts.
 *
 * The verdict is the person's to overrule either way (Workbench::askas). A run that fails says so
 * (state failed, with the reason) and builds nothing.
 */

namespace app;

class AskTriage {
    public const STATES = ['thinking', 'answer', 'unsure', 'failed', 'build'];
    /** How long one run may take, in the container. */
    public const TIMEOUT = 240;

    /** The first runtime whose clitool has --agent-ask with --fast (the quick first reading) and --rows. */
    public const SINCE = '2.0.0-alpha.194';

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
    public static function classifyBrief(string $said, string $before = ''): string {
        $before = self::beforeSection($before);
        return <<<MD
        # Ask — what kind of message is this?

        Someone typed the message at the end of this file into the Builder of their web app. Do NOT answer it, do not open any file, do not use any tool. Only say what kind of message it is.

        Reply with exactly one line, one of:

        - `VERDICT: BUILD` — they want the app changed: something added, removed, fixed, restyled, connected, imported. A request phrased as a question ("can you add a login page?", "could the header be darker?") is still BUILD. So is a bug report they want fixed.
        - `VERDICT: ANSWER` — they want to know something: how the app works, what a page does, where something is, why something happened, what is possible, what you would suggest, how a build is going. Nothing is to change.
        - `VERDICT: UNSURE` — it could honestly be either, or it is too vague to tell what would be built. Do not guess.

        Only after `VERDICT: UNSURE`, add ONE short sentence on the next line saying what you could not tell (it is shown above two buttons: "Build it" and "Just answer").
        {$before}
        ## The message

        {$said}
        MD;
    }

    /** What the agent is handed: how to answer, then what was typed. $mode 'answer' = the person already said it is a question. */
    public static function brief(string $said, string $mode, string $busy, string $before = '', bool $rows = false): string {
        $before = self::beforeSection($before);
        $data = $rows
            ? "You may also look at the app's DATA with `database_query`: one read-only SELECT at a time, 200 rows at most, passwords and keys withheld. Use it when the answer is in the data (how many, which, when, the latest). Count and summarise; name a person or quote an email or phone number only when they asked for exactly that."
            : "You cannot read rows: how much data there is (a count a table) is in the facts file, and that is all you can say about the data — say so plainly if they ask for more, and that an admin of the project can ask the same thing and get it.";
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
        - {$data}
        - Look before you answer. Say what the app really has, naming the page or file (`/game/play`, `controls/Game.php`) — not what an app like it usually has.
        - **Be brief.** The first sentence IS the answer ("Yes — …", "It is at /landing.", "7 so far."). Then only what they need to act on it: at most three short bullets or two more sentences, under about 80 words in all. No preamble, no recap of the question, no list of everything you looked at, no closing summary.
        - Write for the person who owns the app, who may not be a programmer: plain words. Name a page by its address (`/landing`) — a file name only when they asked where something is in the code. No headings. Markdown is shown.
        - If there is honestly more worth knowing, end with one line: "Ask if you want the details." They will ask, and you will have this conversation to go on.
        - If you cannot find it, say what you looked at and that you did not find it.
        - If the honest answer is "that would have to be built", say so, and say in one line what you would ask for.
        - {$now}

        ## One line worth keeping

        After the answer, on a LAST line of its own, write the one thing from it that the next person or agent working on this app should not have to find out again — a fact about THIS app, in one plain sentence under 300 characters, starting with its kind:

        - `NOTE: map: …` — where something lives or how it works ("enquiries are saved by controls/Enquiry.php and also recorded as leads")
        - `NOTE: decision: …` — something that was decided and why
        - `NOTE: lesson: …` — a mistake and how it is avoided

        The person can put that line in the project's notebook with one click; every later build reads the notebook and believes it, so write only what you verified in the code or the facts file. If nothing in the answer is worth keeping (a status, an opinion, "I could not find it"), write `NOTE: none`.
        {$before}
        ## The message

        {$said}
        MD;
    }

    /** The conversation so far as a section of a brief ('' when this is its first message). */
    private static function beforeSection(string $before): string {
        if (trim($before) === '') return '';
        return "\n## The conversation so far\n\nWhat this person said here before, oldest first, and what became of each. The message below is the newest: read it in that light — \"it\", \"that page\", \"and the footer?\", \"do that\" point back at these.\n\n" . trim($before) . "\n";
    }

    /**
     * What one member said in one project's Ask before message #$beforeId, and what became of
     * each — the last $n, oldest first, each cut short: enough to follow "and the footer?", not
     * a transcript (a conversation that grows without limit is paid for on every message).
     */
    public static function history(int $memberId, string $instanceTag, int $beforeId, int $n = 6): string {
        if ($memberId <= 0 || $instanceTag === '') return '';
        return (string) CoreDb::with(function () use ($memberId, $instanceTag, $beforeId, $n) {
            $rows = array_reverse(array_values(Bean::find('promptlog', 'member_id = ? AND instance_tag = ? AND source = ? AND id < ? ORDER BY id DESC LIMIT ' . max(1, $n),
                [$memberId, $instanceTag, PromptLog::SOURCE_DECOMPOSE, $beforeId])));
            $out = [];
            foreach ($rows as $r) {
                $said = trim(GoalBrief::split((string) $r->body)['goal']);
                if (($cut = strpos($said, "\n\n" . PlanExecutor::ATTACHED_HEAD)) !== false) $said = trim(substr($said, 0, $cut)) . ' (with files attached)';
                $said = mb_strlen($said) > 400 ? mb_substr($said, 0, 400) . '…' : $said;
                $state = (string) ($r->askState ?? '');
                if ($state === 'answer') {
                    $a = trim((string) $r->askAnswer);
                    $then = 'You answered: ' . (mb_strlen($a) > 700 ? mb_substr($a, 0, 700) . '…' : $a);
                } elseif ($state === 'unsure') $then = 'It was not clear whether to build or answer; they have not said which.';
                elseif ($state === 'failed')   $then = 'It could not be read (a fault on our side).';
                elseif ($state === 'thinking') $then = 'It is still being read.';
                else $then = (string) $r->planUid !== '' ? 'It was sent to be built' . (trim((string) ($r->planTitle ?? '')) !== '' ? ' as the plan "' . trim((string) $r->planTitle) . '".' : '.')
                                                          : 'It was sent to be built (not planned yet).';
                $out[] = '**They said:** ' . str_replace("\n", "\n  ", $said) . "\n\n**Then:** " . str_replace("\n", "\n  ", $then);
            }
            return implode("\n\n---\n\n", $out);
        }, '');
    }

    /**
     * Does it read like a question — enough to START answering it while the first reading
     * decides? Only that: nothing is routed by this. A request phrased as one ("can you add…?")
     * is left out, since its answer would be thrown away.
     */
    public static function readsAsQuestion(string $said): bool {
        $s = strtolower(trim($said));
        if (preg_match('/^(can|could|would|will|please)\b.{0,12}\b(you|we)\b/', $s) || preg_match('/^(please|add|make|build|create|change|fix|remove|put|move|update|set)\b/', $s)) return false;
        return str_ends_with($s, '?') || (bool) preg_match('/^(what|where|why|how|when|who|which|is|are|does|do|did|has|have)\b/', $s);
    }

    /** The agent's reply, read: ['verdict' => build|answer|unsure, 'text' => …, 'note' => 'kind: one line' or '']; throws when it did not say which. $needAnswer false = the first reading, which only says which. */
    public static function parse(string $reply, bool $needAnswer = true): array {
        $reply = trim($reply);
        if (!preg_match('/^\s*`?VERDICT:\s*(BUILD|ANSWER|UNSURE)`?\s*$/mi', $reply, $m, PREG_OFFSET_CAPTURE)) {
            throw new \RuntimeException('the agent did not say whether this is something to build or a question');
        }
        $text = trim(substr($reply, $m[0][1] + strlen($m[0][0])));
        $verdict = strtolower($m[1][0]);
        // The line worth keeping, taken off the end of the answer: it is offered beside it, not shown in it.
        $note = '';
        if (preg_match('/^\s*`?NOTE:\s*(none|(decision|map|lesson)\s*:\s*(.+?))`?\s*$/mi', $text, $n, PREG_OFFSET_CAPTURE)) {
            if (!empty($n[2][0]) && mb_strlen(trim($n[3][0])) >= 12) $note = strtolower($n[2][0]) . ': ' . mb_substr(trim(preg_replace('/\s+/', ' ', $n[3][0])), 0, 400);
            $text = trim(substr($text, 0, $n[0][1]) . substr($text, $n[0][1] + strlen($n[0][0])));
        }
        if ($verdict === 'answer' && $text === '' && $needAnswer) throw new \RuntimeException('the agent called it a question and gave no answer');
        return ['verdict' => $verdict, 'text' => $verdict === 'build' ? '' : $text, 'note' => $verdict === 'answer' ? $note : ''];
    }

    /** Record where a message stands. */
    public static function mark(int $promptId, string $state, string $answer = '', string $note = ''): void {
        if (!in_array($state, self::STATES, true)) throw new \InvalidArgumentException("not an ask state: {$state}");
        CoreDb::with(function () use ($promptId, $state, $answer, $note) {
            $row = Bean::load('promptlog', $promptId);
            if (!$row->id) throw new \RuntimeException("no prompt #{$promptId}");
            $row->askState  = $state;
            // Sent on to be built ("Build it" under an answer): the answer it had is kept, so that
            // stopping the planner puts the conversation back as it was (restoreAnswer).
            if ($state === 'build') { $row->askAt = date('Y-m-d H:i:s'); Bean::store($row); return null; }
            $row->askAnswer = $answer;
            $row->askNote   = $note;
            $row->askNotedAt = null;
            $row->askAt     = date('Y-m-d H:i:s');
            Bean::store($row);
            return null;
        });
    }

    /**
     * The messages of one member on one project that did not (or not yet) become a plan — for the
     * conversation, oldest first. A thinking one whose run is long over is shown as failed.
     * @return list<array{id:int,asked:string,at:string,state:string,answer:string,note:string,noted:bool}>
     */
    public static function talk(int $memberId, string $instanceTag, int $limit = 20): array {
        if ($memberId <= 0 || $instanceTag === '') return [];
        return (array) CoreDb::with(function () use ($memberId, $instanceTag, $limit) {
            if (!in_array('ask_state', array_keys(Bean::inspect('promptlog')), true)) return [];
            $out = [];
            // …and one sent to be built whose PLANNING failed (no plan, not waiting, an error on
            // it): it used to vanish from the conversation, with only a note elsewhere saying why.
            foreach (Bean::find('promptlog', "member_id = ? AND instance_tag = ? AND (ask_state IN ('thinking', 'answer', 'unsure', 'failed')"
                    . " OR (ask_state = 'build' AND (plan_uid IS NULL OR plan_uid = '') AND queued_at IS NULL AND last_error IS NOT NULL AND last_error != '' AND last_attempt_at >= ask_at))"
                    . ' ORDER BY id DESC LIMIT ' . max(1, $limit), [$memberId, $instanceTag]) as $r) {
                $state = (string) $r->askState; $answer = (string) $r->askAnswer;
                if ($state === 'build') { $state = 'failed'; $answer = 'It could not be planned. ' . trim((string) $r->lastError); }
                if ($state === 'thinking' && time() - (int) strtotime((string) $r->askAt) > self::TIMEOUT + 420) {
                    $state = 'failed'; $answer = 'No answer came back: the run that was reading it ended without one.';
                }
                $out[] = ['id' => (int) $r->id, 'asked' => trim(GoalBrief::split((string) $r->body)['goal']), 'at' => (string) $r->createdAt, 'state' => $state, 'answer' => $answer,
                          'note' => $state === 'answer' ? (string) ($r->askNote ?? '') : '', 'noted' => (string) ($r->askNotedAt ?? '') !== ''];
            }
            return array_reverse($out);
        }, []);
    }

    /**
     * The planner was stopped: a message that had been ANSWERED and then sent to be built ("Build
     * it" under the answer) goes back to being that answer, instead of vanishing from the
     * conversation. Only the newest such message of this member on this project, with no plan and
     * not waiting. Returns its id, or 0.
     */
    public static function restoreAnswer(int $memberId, string $instanceTag): int {
        return (int) CoreDb::with(function () use ($memberId, $instanceTag) {
            $r = Bean::findOne('promptlog', "member_id = ? AND instance_tag = ? AND ask_state = 'build' AND (plan_uid IS NULL OR plan_uid = '') AND queued_at IS NULL ORDER BY id DESC", [$memberId, $instanceTag]);
            if (!$r || !$r->id || trim((string) ($r->askAnswer ?? '')) === '') return 0;
            $r->askState = 'answer';
            Bean::store($r);
            return (int) $r->id;
        }, 0);
    }

    /**
     * The line worth keeping from an answer, as a notebook entry ({kind, doc, text}) — or null
     * when there is none, it is not this member's, or it is already in the notebook.
     */
    public static function noteOf(int $promptId, int $memberId): ?array {
        return CoreDb::with(function () use ($promptId, $memberId) {
            $r = Bean::findOne('promptlog', "id = ? AND member_id = ? AND ask_state = 'answer'", [$promptId, $memberId]);
            if (!$r || !$r->id || (string) ($r->askNotedAt ?? '') !== '') return null;
            if (!preg_match('/^(decision|map|lesson): (.+)$/s', (string) ($r->askNote ?? ''), $m)) return null;
            return ['kind' => $m[1], 'doc' => \app\Notebook::KINDS[$m[1]], 'text' => $m[2], 'tag' => (string) $r->instanceTag];
        }, null);
    }

    /**
     * An answer names pages by their address (`/landing`): each becomes a link that opens THAT
     * page of the project's own app, signed in — the short text stays as written. $open is the
     * link's start, to which the page's path is appended url-encoded (core's
     * /projects/open?id=N&to=). Only an address standing alone in backticks: a path inside a
     * file name (`views/landing/index.php`) or a sentence is left as it is.
     */
    public static function linkPages(string $html, string $open): string {
        if ($open === '') return $html;
        return (string) preg_replace_callback('#<code>(/[A-Za-z0-9][A-Za-z0-9/_-]*)</code>#', function ($m) use ($open) {
            if (preg_match('#^/(srv|var|tmp|home|etc|usr)(/|$)#', $m[1])) return $m[0];   // a place on the machine, not a page
            return '<a href="' . htmlspecialchars($open . rawurlencode($m[1]), ENT_QUOTES) . '" target="_blank" rel="noopener" title="Open this page of your app"><code>' . $m[1] . '</code></a>';
        }, $html);
    }

    /** It is in the notebook now. */
    public static function noted(int $promptId): void {
        CoreDb::with(function () use ($promptId) {
            $r = Bean::load('promptlog', $promptId);
            if ($r->id) { $r->askNotedAt = date('Y-m-d H:i:s'); Bean::store($r); }
            return null;
        });
    }

    /**
     * Read one message and act on it (scripts/ask-triage.php, detached from the request that sent
     * it). $mode: auto — decide; answer — the person said it is a question; build — the person
     * said build it. Returns a line for the log.
     */
    public static function run(int $promptId, string $mode): string {
        $q = CoreDb::with(function () use ($promptId) {
            $r = Bean::load('promptlog', $promptId);
            return $r->id ? ['body' => (string) $r->body, 'tag' => (string) $r->instanceTag, 'plan' => (string) $r->planUid, 'member' => (int) $r->memberId] : null;
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
            $before = self::history($q['member'], $q['tag'], $promptId);
            // Rows only for someone who may see the app's data themselves: its owner, or an admin of a team it is shared with.
            $inst = CoreDb::with(fn() => Bean::findOne('instance', 'slug = ?', [$slug]), null);
            $level = $inst && $inst->id ? CoreDb::with(fn() => AppAccess::level($q['member'], $inst), null) : null;
            $rows = $level !== null && $level <= 50;
            // First the quick reading, on the fast model: which is it? Only a question goes on to
            // be answered — by the builder's own model, which reads the app to do it.
            $got = ['verdict' => 'answer', 'text' => ''];
            // What reads like a question starts being ANSWERED at once, beside the first reading —
            // not after it: starting an agent in the container is most of what a short run costs,
            // and the two starts in a row were six seconds of nothing. The first reading still
            // decides: if it says "build" or "unsure" the answer under way is dropped.
            $ahead = false;
            if ($mode === 'auto') {
                if (self::readsAsQuestion($said)) {
                    TenantHost::askStart($tenant, 'ask-' . $promptId, self::brief($said, 'answer', $busy, $before, $rows), self::TIMEOUT, '', $rows);
                    $ahead = true;
                }
                try {
                    $r = TenantHost::ask($tenant, 'ask-' . $promptId . '-c', self::classifyBrief($said, $before), 90, '', true);
                    if (empty($r['ok'])) throw new \RuntimeException((string) ($r['error'] ?? 'the agent gave no answer'));
                    $got = self::parse((string) $r['text'], false);
                } catch (\Throwable $e) {
                    if ($ahead) TenantHost::askDrop($tenant, 'ask-' . $promptId);
                    throw $e;
                }
                if ($ahead && $got['verdict'] !== 'answer') { TenantHost::askDrop($tenant, 'ask-' . $promptId); $ahead = false; }
            }
            if ($got['verdict'] === 'answer') {
                $r = $ahead ? TenantHost::askWait($tenant, 'ask-' . $promptId, self::TIMEOUT)
                            : TenantHost::ask($tenant, 'ask-' . $promptId, self::brief($said, 'answer', $busy, $before, $rows), self::TIMEOUT, '', false, $rows);
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
        self::mark($promptId, $got['verdict'], $got['text'], (string) ($got['note'] ?? ''));
        return "#{$promptId}: {$got['verdict']}";
    }

    /** It is something to build: down the Builder's road, behind whatever is building. */
    private static function build(int $promptId): string {
        self::mark($promptId, 'build');
        if (!PromptQueue::enqueue($promptId, true)) return "#{$promptId}: build — but it could not be queued";
        return "#{$promptId}: build — " . implode('; ', PromptQueue::drain());
    }
}

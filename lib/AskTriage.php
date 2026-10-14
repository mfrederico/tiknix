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
    public const STATES = ['thinking', 'answer', 'unsure', 'failed', 'build', 'asking', 'action', 'did'];
    /** In a goal: what the planner asked its person and what they answered — settled, at its end. */
    public const QA_HEAD = '## What you asked the owner, and their answer';
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
    public static function classifyBrief(string $said, string $before = '', string $list = ''): string {
        $before = self::beforeSection($before);
        // What is on the project's list that a message could be ABOUT: then it is not a new request
        // but something done to the list — said as which, and to which item.
        $acts = trim($list) === '' ? '' : <<<MD

        ## Or — is it about something already on this project's list?

        {$list}

        If the message is about one of these, reply with that instead (one line, the number from the list):

        - `VERDICT: FOLLOWUP #N` — a change, addition or correction to what request #N built or is building ("make that bold", "also add a phone field to it", "the prices should be per case"). Not a new, separate thing.
        - `VERDICT: RETRY #N` — try stopped task #N again ("try that again", "retry the import").
        - `VERDICT: FIX #N` — fix problem #N that Tiknix found ("fix that", "fix the 500 on the import page").
        - `VERDICT: UNDO` — put the app back to before the newest finished request ("undo that", "take that out again"). Only when the list says one can be undone.
        - `VERDICT: STOP` — stop the request being planned now ("stop", "cancel that"). Only when the list says one is being planned.

        Use these only when it is clearly about that item. A new feature that merely touches the same page is `BUILD`. If it is about an item but you cannot tell which, it is `UNSURE`.
        MD;
        $acts = preg_replace('/^ {8}/m', '', $acts);
        return <<<MD
        # Ask — what kind of message is this?

        Someone typed the message at the end of this file into the Builder of their web app. Do NOT answer it, do not open any file, do not use any tool. Only say what kind of message it is.

        Reply with exactly one line, one of:

        - `VERDICT: BUILD` — they want the app changed: something added, removed, fixed, restyled, connected, imported. A request phrased as a question ("can you add a login page?", "could the header be darker?") is still BUILD. So is a bug report they want fixed.
        - `VERDICT: ANSWER` — they want to know something: how the app works, what a page does, where something is, why something happened, what is possible, what you would suggest, how a build is going. Nothing is to change.
        - `VERDICT: UNSURE` — it could honestly be either, or it is too vague to tell what would be built. Do not guess.

        Only after `VERDICT: UNSURE`, add ONE short sentence on the next line saying what you could not tell (it is shown above two buttons: "Build it" and "Just answer").
        {$acts}{$before}
        ## The message

        {$said}
        MD;
    }

    /** What the agent is handed: how to answer, then what was typed. $mode 'answer' = the person already said it is a question. */
    public static function brief(string $said, string $mode, string $busy, string $before = '', bool $rows = false, string $board = ''): string {
        $before = self::beforeSection($before);
        // The project's work list, as its record has it (BoardDigest): what was asked, what each
        // request became, where every task stands and why. Questions about the WORK are answered from this.
        $board = trim($board) === '' ? '' : "\n## The work list of this project\n\nWhat has been asked for here and what became of it — each request, its plan, every task with where it stands, why a task failed, what its agent last said, what the check afterwards found. Newest last. When they ask what was built, what is happening, why something failed or what a task did, ANSWER FROM THIS (and say the task by its name); go to the code only for how the app works.\n\n" . trim($board) . "\n";
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

        ## What they will likely ask next

        Then one more line: `NEXT: …` — the message this person is most likely to type next, written as THEY would type it into this box (a request to build, or a question), under 15 words, following from this answer ("Add a phone number field to the enquiry form"). It is shown greyed in their box; one key accepts it. Not a question back to them, and not something already done. If nothing obvious follows, write `NEXT: none`.
        {$board}{$before}
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
        $dir = self::dirOfTag($instanceTag);
        $out = [];
        foreach (AskStore::of($dir, $memberId, [], $n, $beforeId) as $r) {
            $said = trim((string) $r['said']);
            if (($cut = strpos($said, "\n\n" . PlanExecutor::ATTACHED_HEAD)) !== false) $said = trim(substr($said, 0, $cut)) . ' (with files attached)';
            $said = mb_strlen($said) > 400 ? mb_substr($said, 0, 400) . '…' : $said;
            $state = (string) $r['state']; $a = trim((string) $r['answer']);
            if ($state === 'answer')        $then = 'You answered: ' . (mb_strlen($a) > 700 ? mb_substr($a, 0, 700) . '…' : $a);
            elseif ($state === 'unsure')    $then = 'It was not clear whether to build or answer; they have not said which.';
            elseif ($state === 'failed')    $then = 'It did not go through' . ($a !== '' ? ': ' . mb_substr($a, 0, 400) : ' (a fault on our side).');
            elseif ($state === 'thinking')  $then = 'It is still being read.';
            elseif ($state === 'asking')    $then = 'Before planning it, the planner asked them: ' . mb_substr($a, 0, 400) . ' (not answered yet)';
            elseif ($state === 'planned')   $then = 'It was sent to be built, as plan #' . (int) $r['plan_ref'] . ' of this project' . ((int) ($r['follows_ref'] ?? 0) > 0 ? ' (a follow-up to #' . (int) $r['follows_ref'] . ')' : '') . '.';
            elseif ($state === 'did')       $then = $a;
            elseif ($state === 'action')    $then = 'It asked for something to be done to the list; they have not confirmed it yet.';
            else                            $then = 'It was sent to be built (not planned yet).';
            foreach ((array) (json_decode((string) $r['qa_json'], true) ?: []) as $qa) {
                $then .= "\n  Before planning it, the planner asked: " . trim((string) ($qa['q'] ?? '')) . ' — they answered: ' . trim((string) ($qa['a'] ?? ''));
            }
            $out[] = '**They said:** ' . str_replace("\n", "\n  ", $said) . "\n\n**Then:** " . str_replace("\n", "\n  ", $then);
        }
        return implode("\n\n---\n\n", $out);
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
        if (!preg_match('/^\s*`?VERDICT:\s*(BUILD|ANSWER|UNSURE|FOLLOWUP|RETRY|FIX|UNDO|STOP)(?:\s+#?(\d+))?`?\s*$/mi', $reply, $m, PREG_OFFSET_CAPTURE)) {
            throw new \RuntimeException('the agent did not say whether this is something to build or a question');
        }
        if (in_array(strtoupper($m[1][0]), ['FOLLOWUP', 'RETRY', 'FIX', 'UNDO', 'STOP'], true)) {
            return ['verdict' => strtolower($m[1][0]), 'text' => '', 'note' => '', 'target' => (int) ($m[2][0] ?? 0)];
        }
        $text = trim(substr($reply, $m[0][1] + strlen($m[0][0])));
        $verdict = strtolower($m[1][0]);
        // The line worth keeping, taken off the end of the answer: it is offered beside it, not shown in it.
        $note = '';
        if (preg_match('/^\s*`?NOTE:\s*(none|(decision|map|lesson)\s*:\s*(.+?))`?\s*$/mi', $text, $n, PREG_OFFSET_CAPTURE)) {
            if (!empty($n[2][0]) && mb_strlen(trim($n[3][0])) >= 12) $note = strtolower($n[2][0]) . ': ' . mb_substr(trim(preg_replace('/\s+/', ' ', $n[3][0])), 0, 400);
            $text = trim(substr($text, 0, $n[0][1]) . substr($text, $n[0][1] + strlen($n[0][0])));
        }
        // …and its guess at the person's next message (shown greyed in their box; Tab takes it).
        $next = '';
        if (preg_match('/^\s*`?NEXT:\s*(.+?)`?\s*$/mi', $text, $x, PREG_OFFSET_CAPTURE)) {
            $g = trim((string) preg_replace('/\s+/', ' ', trim($x[1][0], " \t\"'“”")));
            if (strcasecmp($g, 'none') !== 0 && mb_strlen($g) >= 8) $next = mb_substr($g, 0, 160);
            $text = trim(substr($text, 0, $x[0][1]) . substr($text, $x[0][1] + strlen($x[0][0])));
        }
        if ($verdict === 'answer' && $text === '' && $needAnswer) throw new \RuntimeException('the agent called it a question and gave no answer');
        return ['verdict' => $verdict, 'text' => $verdict === 'build' ? '' : $text, 'note' => $verdict === 'answer' ? $note : '', 'next' => $verdict === 'answer' ? $next : ''];
    }

    /**
     * After a build, what to ask next — from what its check said, no agent asked: the first page it
     * found broken, else the first page it said reads badly. '' when it found nothing (or never ran).
     * $manifest: the check's audit.json, for this plan.
     */
    public static function nextAfterCheck(array $manifest): string {
        foreach ((array) ($manifest['failures'] ?? []) as $f) {
            $what = trim((string) ($f['message'] ?? $f['label'] ?? ''));
            if ($what !== '') return mb_substr('Fix: ' . $what . (!empty($f['url']) ? ' (' . (string) (parse_url((string) $f['url'], PHP_URL_PATH) ?: $f['url']) . ')' : ''), 0, 160);
        }
        foreach ((array) ($manifest['design'] ?? []) as $d) {
            if (strcasecmp((string) ($d['verdict'] ?? ''), 'needs work') !== 0) continue;
            $note = trim((string) (((array) ($d['notes'] ?? []))[0] ?? ''));
            if ($note !== '') return mb_substr('On ' . (string) ($d['page'] ?? 'that page') . ': ' . rtrim($note, '.') . ' — fix that', 0, 160);
        }
        return '';
    }

    /** Record where a message stands. */
    public static function mark(int $promptId, string $state, string $answer = '', string $note = ''): void {
        if (!in_array($state, self::STATES, true)) throw new \InvalidArgumentException("not an ask state: {$state}");
        $p = self::prompt($promptId);
        // The item is made the first time anything is said about it: from then on the project has it.
        AskStore::ensure($p['dir'], $promptId, $p['member'], $p['said'], 'thinking', $p['at']);
        AskStore::mark($p['dir'], $promptId, $state, $answer, $note);
    }

    /**
     * The prompt-log entry of a message, and the project it belongs to: its builder folder (where
     * the project's own record is), who said it, what was said, and when.
     * @return array{dir:string,tag:string,member:int,said:string,body:string,plan:string,at:string,queued:bool}
     */
    private static function prompt(int $promptId): array {
        $miss = new \stdClass();
        $r = CoreDb::with(function () use ($promptId) {
            $r = Bean::load('promptlog', $promptId);
            return $r->id ? ['tag' => (string) $r->instanceTag, 'member' => (int) $r->memberId, 'body' => (string) $r->body, 'plan' => (string) $r->planUid,
                             'at' => (string) $r->createdAt, 'queued' => (string) ($r->queuedAt ?? '') !== ''] : null;
        }, $miss);
        if ($r === $miss) throw new \RuntimeException("the prompt log could not be read for #{$promptId}: " . CoreDb::lastError());
        if (!$r) throw new \RuntimeException("no prompt #{$promptId}");
        $r['said'] = trim(self::splitAsked(trim(GoalBrief::split($r['body'])['goal']))['said']);
        $r['dir']  = self::dirOfTag($r['tag']);
        return $r;
    }

    /** The builder folder of the project a prompt's tag names ("slug.app"): where its own record is kept. */
    public static function dirOfTag(string $instanceTag): string {
        $slug = (string) (strstr($instanceTag, '.', true) ?: $instanceTag);
        if ($slug === '') throw new \RuntimeException('a message with no project');
        $miss = new \stdClass();
        $dir = CoreDb::with(fn() => \Model_Instance::dirForSlug($slug), $miss);
        if ($dir === $miss || !is_string($dir) || $dir === '') throw new \RuntimeException("where {$slug} keeps its record could not be read: " . CoreDb::lastError());
        return $dir;
    }

    /**
     * The messages of one member on one project that did not (or not yet) become a plan — for the
     * conversation, oldest first. A thinking one whose run is long over is shown as failed.
     * @return list<array{id:int,asked:string,at:string,state:string,answer:string,note:string,noted:bool}>
     */
    public static function talk(int $memberId, string $instanceTag, int $limit = 20): array {
        if ($memberId <= 0 || $instanceTag === '') return [];
        $out = [];
        foreach (AskStore::of(self::dirOfTag($instanceTag), $memberId, ['thinking', 'answer', 'unsure', 'failed', 'asking', 'action', 'did'], $limit) as $r) {
            $state = (string) $r['state']; $answer = (string) $r['answer'];
            if ($state === 'thinking' && time() - (int) strtotime((string) $r['updated_at']) > self::TIMEOUT + 420) {
                $state = 'failed'; $answer = 'No answer came back: the run that was reading it ended without one.';
            }
            $qa = [];
            foreach ((array) (json_decode((string) $r['qa_json'], true) ?: []) as $x) $qa[] = ['q' => (string) ($x['q'] ?? ''), 'a' => (string) ($x['a'] ?? '')];
            $out[] = ['id' => (int) $r['prompt_ref'], 'asked' => (string) $r['said'], 'qa' => $qa, 'at' => (string) $r['created_at'], 'state' => $state, 'answer' => $answer,
                      'note' => $state === 'answer' ? (string) $r['note'] : '', 'noted' => (string) ($r['noted_at'] ?? '') !== '',
                      'next' => $state === 'answer' ? (string) ($r['next_hint'] ?? '') : ''];
        }
        return $out;
    }

    /**
     * The PLANNER has a question for its person before it can plan a request (plan-question.md —
     * scripts/plan-question.php): the message waits on it, shown in the conversation as a
     * question to answer. Nothing is planned until they do.
     */
    public static function asks(int $promptId, string $question): void {
        if (trim($question) === '') throw new \InvalidArgumentException('no question to put');
        self::mark($promptId, 'asking', mb_substr(trim($question), 0, 1500));
    }

    /**
     * Their answer: written into the goal itself, at its end, beside the question (QA_HEAD) — the
     * planner reads the goal, so that is where a settled decision belongs — and the message is
     * sent to be planned again. False when the message is not waiting on a question of this member's.
     */
    public static function answered(int $promptId, int $memberId, string $answer): bool {
        $answer = mb_substr(trim($answer), 0, 2000);
        if ($answer === '') return false;
        $p = self::prompt($promptId);
        $row = AskStore::get($p['dir'], $promptId);
        if (!$row || (int) $row['member_ref'] !== $memberId || (string) $row['state'] !== 'asking') return false;
        $q = trim((string) $row['answer']);
        AskStore::addQa($p['dir'], $promptId, $q, $answer);
        // …and into the goal the planner is handed (the prompt log is what its pipeline reads).
        return (bool) CoreDb::with(function () use ($promptId, $q, $answer) {
            $r = Bean::load('promptlog', $promptId);
            if (!$r->id) return false;
            $body = rtrim((string) $r->body);
            if (!str_contains($body, "\n" . self::QA_HEAD . "\n")) $body .= "\n\n" . self::QA_HEAD . "\n";
            $r->body = $body . "\n**You asked:** " . str_replace("\n", "\n  ", $q) . "\n\n**They answered:** " . str_replace("\n", "\n  ", $answer) . "\n";
            $r->lastError = '';
            Bean::store($r);
            return true;
        }, false);
    }

    /**
     * What was typed, apart from what the planner asked and was answered (QA_HEAD):
     * ['said' => the request, 'qa' => [['q' => …, 'a' => …], …]] — for showing each as itself.
     */
    public static function splitAsked(string $goal): array {
        $at = strpos($goal, "\n" . self::QA_HEAD . "\n");
        if ($at === false) return ['said' => $goal, 'qa' => []];
        $qa = [];
        if (preg_match_all('/\*\*You asked:\*\* (.*?)\n\n\*\*They answered:\*\* (.*?)(?=\n\n\*\*You asked:\*\* |\z)/s', substr($goal, $at), $m, PREG_SET_ORDER)) {
            foreach ($m as $x) $qa[] = ['q' => trim(str_replace("\n  ", "\n", $x[1])), 'a' => trim(str_replace("\n  ", "\n", $x[2]))];
        }
        return ['said' => rtrim(substr($goal, 0, $at)), 'qa' => $qa];
    }

    /**
     * A request became a plan (plan-ingest): its item in the project's record says so and names
     * the plan — also for a request that did not come through Ask (the board's own form), which
     * gets its item here. From then on the plan and its tasks are what became of it.
     */
    public static function plannedAs(int $promptId, int $planId): void {
        $p = self::prompt($promptId);
        AskStore::ensure($p['dir'], $promptId, $p['member'], $p['said'], 'build', $p['at']);
        AskStore::planned($p['dir'], $promptId, $planId);
    }

    /** Its planning failed (plan-failed): said on the item, with why — it used to vanish from the conversation. */
    public static function planningFailed(int $promptId, string $why): void {
        $p = self::prompt($promptId);
        $row = AskStore::get($p['dir'], $promptId);
        if (!$row || !in_array((string) $row['state'], ['build', 'thinking'], true)) return;   // not one of Ask's, or already something else
        AskStore::mark($p['dir'], $promptId, 'failed', 'It could not be planned. ' . trim($why));
    }

    /**
     * The planner was stopped: a message that had been ANSWERED and then sent to be built ("Build
     * it" under the answer) goes back to being that answer, instead of vanishing from the
     * conversation. Only the newest such message of this member on this project, with no plan and
     * not waiting. Returns its id, or 0.
     */
    public static function restoreAnswer(int $memberId, string $instanceTag): int {
        $dir = self::dirOfTag($instanceTag);
        $rows = AskStore::of($dir, $memberId, ['build'], 1);
        $r = $rows ? end($rows) : null;
        if (!$r || trim((string) $r['answer']) === '' || !empty($r['plan_ref'])) return 0;
        if (self::prompt((int) $r['prompt_ref'])['queued']) return 0;   // it waits its turn: not stopped
        AskStore::mark($dir, (int) $r['prompt_ref'], 'answer', (string) $r['answer'], (string) $r['note']);
        return (int) $r['prompt_ref'];
    }

    /**
     * The line worth keeping from an answer, as a notebook entry ({kind, doc, text}) — or null
     * when there is none, it is not this member's, or it is already in the notebook.
     */
    public static function noteOf(int $promptId, int $memberId): ?array {
        $p = self::prompt($promptId);
        $r = AskStore::get($p['dir'], $promptId);
        if (!$r || (int) $r['member_ref'] !== $memberId || (string) $r['state'] !== 'answer' || (string) ($r['noted_at'] ?? '') !== '') return null;
        if (!preg_match('/^(decision|map|lesson): (.+)$/s', (string) $r['note'], $m)) return null;
        return ['kind' => $m[1], 'doc' => \app\Notebook::KINDS[$m[1]], 'text' => $m[2], 'tag' => $p['tag']];
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
        AskStore::noted(self::prompt($promptId)['dir'], $promptId);
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
            $dir  = \Model_Instance::dirForSlug($slug);
            $busy = BuildQueue::busyWith($dir, $slug);
            $board = BoardDigest::markdown($dir);
            $targets = BoardDigest::targets($dir);
            $planning = false;
            foreach (TmuxManager::list('tiknix-') as $sess) {
                $name = is_array($sess) ? (string) ($sess['name'] ?? '') : (string) $sess;
                if (preg_match('/^tiknix-\d+-plan-(.+)$/', $name, $pm) && $pm[1] === $slug) $planning = true;
            }
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
                    TenantHost::askStart($tenant, 'ask-' . $promptId, self::brief($said, 'answer', $busy, $before, $rows, $board), self::TIMEOUT, '', $rows);
                    $ahead = true;
                }
                try {
                    $r = TenantHost::ask($tenant, 'ask-' . $promptId . '-c', self::classifyBrief($said, $before, BoardDigest::targetsText($targets, $planning)), 90, '', true);
                    if (empty($r['ok'])) throw new \RuntimeException((string) ($r['error'] ?? 'the agent gave no answer'));
                    $got = self::parse((string) $r['text'], false);
                } catch (\Throwable $e) {
                    if ($ahead) TenantHost::askDrop($tenant, 'ask-' . $promptId);
                    throw $e;
                }
                if ($ahead && $got['verdict'] !== 'answer') { TenantHost::askDrop($tenant, 'ask-' . $promptId); $ahead = false; }
                // It is about something already on the list: done to the list, not planned as new work.
                if (in_array($got['verdict'], ['followup', 'retry', 'fix', 'undo', 'stop'], true)) return self::act($promptId, $dir, $got['verdict'], (int) ($got['target'] ?? 0), $targets, $planning);
            }
            if ($got['verdict'] === 'answer') {
                $r = $ahead ? TenantHost::askWait($tenant, 'ask-' . $promptId, self::TIMEOUT)
                            : TenantHost::ask($tenant, 'ask-' . $promptId, self::brief($said, 'answer', $busy, $before, $rows, $board), self::TIMEOUT, '', false, $rows);
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
        if (($got['next'] ?? '') !== '') { $pp = self::prompt($promptId); AskStore::next($pp['dir'], $promptId, (string) $got['next']); }
        return "#{$promptId}: {$got['verdict']}";
    }

    /**
     * The message is about something already on the project's list (the first reading said which).
     *
     *   followup  a change to what request #N built: it IS a request, planned from that plan's
     *             files and shown with it — started at once, like any build.
     *   retry · fix · undo · stop
     *             something the board itself does. It is not done from here: the message becomes
     *             an `action` naming exactly what and to which item, and the person presses the
     *             same button the board has — so a misread "undo" costs a glance, not their work,
     *             and it runs as them, through the code the button runs.
     *
     * A target the list does not have is not guessed at: the person is asked.
     */
    private static function act(int $promptId, string $dir, string $verb, int $target, array $targets, bool $planning): string {
        $unsure = function (string $why) use ($promptId): string { self::mark($promptId, 'unsure', $why); return "#{$promptId}: unsure — {$why}"; };
        if ($verb === 'followup') {
            if (!isset($targets['plans'][$target])) return $unsure('It read as a change to something already built, but I could not tell to which request.');
            self::mark($promptId, 'build');
            AskStore::follow($dir, $promptId, $target);
            return "#{$promptId}: follow-up to #{$target} — " . substr(self::build($promptId), strlen("#{$promptId}: build — "));
        }
        if ($verb === 'retry') {
            if (!isset($targets['retry'][$target])) return $unsure('It read as "try that again", but I could not tell which task you meant.');
            $act = ['verb' => 'retry', 'id' => $target, 'title' => $targets['retry'][$target]];
        } elseif ($verb === 'fix') {
            if (!isset($targets['fix'][$target])) return $unsure('It read as "fix that", but I could not tell which problem you meant.');
            $act = ['verb' => 'fix', 'id' => $target, 'title' => $targets['fix'][$target]];
        } elseif ($verb === 'undo') {
            if (!$targets['undo']) return $unsure('It read as "undo that", but nothing can be undone right now: only the newest request, once it is finished.');
            $act = ['verb' => 'undo', 'id' => (int) $targets['undo']['id'], 'title' => (string) $targets['undo']['title'], 'checkpoint' => (string) $targets['undo']['checkpoint']];
        } else {
            if (!$planning) return $unsure('It read as "stop", but nothing is being planned right now.');
            $act = ['verb' => 'stop', 'id' => 0, 'title' => ''];
        }
        self::mark($promptId, 'action', (string) json_encode($act, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return "#{$promptId}: {$verb}" . ($act['id'] ? " #{$act['id']}" : '') . ' — waits for the go-ahead';
    }

    /**
     * The action a message asked for was carried out (the person pressed its button): it stays in
     * the conversation as what was done. False when the message is not this member's waiting action.
     */
    public static function did(int $promptId, int $memberId): bool {
        $p = self::prompt($promptId);
        $r = AskStore::get($p['dir'], $promptId);
        if (!$r || (int) $r['member_ref'] !== $memberId || (string) $r['state'] !== 'action') return false;
        $a = json_decode((string) $r['answer'], true) ?: [];
        $t = '“' . mb_strimwidth((string) ($a['title'] ?? ''), 0, 90, '…') . '”';
        $text = ['retry' => "Tried {$t} again.", 'fix' => "Started a fix for {$t}.", 'undo' => "Undid {$t}: your app is back to before it.", 'stop' => 'Stopped what was being planned.'][(string) ($a['verb'] ?? '')] ?? 'Done.';
        AskStore::mark($p['dir'], $promptId, 'did', $text);
        return true;
    }

    /** It is something to build: down the Builder's road, behind whatever is building. */
    private static function build(int $promptId): string {
        self::mark($promptId, 'build');
        if (!PromptQueue::enqueue($promptId, true)) return "#{$promptId}: build — but it could not be queued";
        return "#{$promptId}: build — " . implode('; ', PromptQueue::drain());
    }
}

<?php
/**
 * SiteChanges — a change someone asked for on a project's own site (the runtime's /changes, app\ChangeAsk), here
 * with the people who build it.
 *
 * The app sends it over its broker key (Brokerinfo::change); the key says which project, never the body. It becomes:
 *   - a `changerequest` row — what was asked, where, by whom, the screenshots, and where it is (STATES);
 *   - a conversation in Communications, addressed to the person who asked (their email) and seated with the project's
 *     team (Model_Instance::people): a reply there reaches them by email and on their /changes page, and their email
 *     reply comes back into it — the ordinary email-conversation path (app\Notes, the inbound webhook);
 *   - an email to each of the team, pointing at /changerequests/view.
 * A person on the team decides: Build it (into the project's Ask, to be planned — BuilderMail::queue), Not going ahead
 * (with the reason, which the asker is sent), or Done. A build that came from one and finishes marks it done and tells
 * the asker it is live (scripts/plan-orchestrate.php → built()).
 *
 * The screenshots are kept twice, on purpose: here, as the conversation's attachments (for the team), and on the app
 * (under its data folder), where the Builder's agent reads them — a build runs in the project's container, not here.
 */

namespace app;

use app\services\NotifyService;

final class SiteChanges {

    public const STATES = ['new', 'building', 'done', 'declined'];
    public const LABELS = ['new' => 'New', 'building' => 'Being built', 'done' => 'Done', 'declined' => 'Not going ahead'];
    public const PER_HOUR = 20;
    public const MAX_SHOTS = 6;
    public const MAX_SHOT_BYTES = 1500000;
    private const TYPES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif'];

    /**
     * A request from the app of $inst. Idempotent: the same (site, app_ref) twice is the same request.
     *
     * @throws \InvalidArgumentException it is not one (400)
     * @throws \RuntimeException it cannot be taken now (409/429), with the reason
     */
    public static function receive(object $inst, array $d): int {
        $site = strtolower(trim((string) ($d['site'] ?? '')));
        $ref = (int) ($d['app_ref'] ?? 0);
        $said = trim((string) ($d['said'] ?? ''));
        $email = trim((string) ($d['from_email'] ?? ''));
        $name = mb_substr(trim((string) ($d['from_name'] ?? '')), 0, 120);
        if (!preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $site)) throw new \InvalidArgumentException('site: the address the request was made on is required.');
        if ($ref <= 0) throw new \InvalidArgumentException('app_ref: the app\'s id for the request is required.');
        if (mb_strlen($said) < 10 || mb_strlen($said) > 4000) throw new \InvalidArgumentException('said: what was asked, 10 to 4000 characters.');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new \InvalidArgumentException('from_email: the asker\'s email is required, to answer them.');
        if ($name === '') $name = $email;

        $have = Bean::findOne('changerequest', 'instance_id = ? AND site = ? AND app_ref = ?', [(int) $inst->id, $site, $ref]);
        if ($have && $have->id) return (int) $have->id;
        if (Bean::count('changerequest', 'instance_id = ? AND created_at > ?', [(int) $inst->id, date('Y-m-d H:i:s', time() - 3600)]) >= self::PER_HOUR) {
            throw new \RuntimeException('This site has sent ' . self::PER_HOUR . ' change requests in the last hour; that is the limit. Try again later.');
        }
        $owner = (int) $inst->memberId;
        if ($owner <= 0) throw new \RuntimeException("Project #{$inst->id} has no owner on Tiknix to send the request to.");
        $shots = self::checkShots((array) ($d['shots'] ?? []));

        $now = date('Y-m-d H:i:s');
        $cr = Bean::dispense('changerequest');
        $cr->instanceId = (int) $inst->id;
        $cr->appRef = $ref;
        $cr->site = $site;
        $cr->fromName = $name;
        $cr->fromEmail = $email;
        $cr->levelName = mb_substr(trim((string) ($d['level'] ?? '')), 0, 40);
        $cr->said = $said;
        $cr->page = mb_substr(trim((string) ($d['page'] ?? '')), 0, 500);
        $cr->browser = mb_substr(trim((string) ($d['browser'] ?? '')), 0, 300);
        $cr->errorsJson = json_encode(array_slice(array_map(fn($e) => mb_substr((string) $e, 0, 600), (array) ($d['errors'] ?? [])), 0, 5), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $cr->shotsJson = '[]';
        $files = self::checkFiles((array) ($d['files'] ?? []), PlatformChanges::isPlatform($inst));
        $checked = self::checkNotes((array) ($d['notes'] ?? []), count($shots), count($files));
        foreach ($checked as $ni => $n) foreach ($n['files'] as $fi) $files[$fi]['note'] = $ni;   // each file knows its note
        $cr->notesJson = json_encode($checked, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $cr->filesJson = json_encode($files, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $cr->prompts = '';
        $cr->status = 'new';
        $cr->threadRef = 0;
        $cr->promptRef = 0;
        $cr->decidedRef = 0;
        $cr->decidedAt = '';
        $cr->createdAt = $now;
        $cr->updatedAt = $now;
        $id = (int) Bean::store($cr);

        $project = (string) ($inst->displayName ?: $inst->slug);
        $thread = NotifyService::openInboundThread($owner, $email, $name, 'Change request: ' . self::headline($said) . ' (' . $site . ')',
            self::firstMessage($cr, $project), 'changerequest', $id);
        if (!$thread) {
            Bean::trash($cr);
            throw new \RuntimeException('The request could not be put into the team\'s Communications; nothing was kept. Try again.');
        }
        $people = $inst->box()->people();
        ThreadMembers::ensure((int) $thread, $people);
        $cr->threadRef = (int) $thread;
        try {
            $cr->shotsJson = json_encode(self::keepShots((int) $thread, $shots), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (\RuntimeException $e) {
            // Half a request is not kept: the app says it was not sent, and the person sends it again.
            foreach (Bean::find('messageattachment', 'thread_id = ?', [(int) $thread]) as $a) { @unlink(Host::dataRoot() . '/' . $a->diskPath); Bean::trash($a); }
            foreach (Bean::find('message', 'thread_id = ?', [(int) $thread]) as $m) Bean::trash($m);
            Bean::trash(Bean::load('thread', (int) $thread));
            Bean::trash($cr);
            throw $e;
        }
        Bean::store($cr);

        // the platform's ROOT noting their own platform (PlatformChanges) is not told about their own notes
        if (!(PlatformChanges::isPlatform($inst) && !empty($d['quiet']))) self::tellTeam($people, 'New change request for ' . $project . ': ' . self::headline($said),
            '<p><strong>' . htmlspecialchars($name) . '</strong> (' . htmlspecialchars($cr->levelName ?: 'a member') . ' on ' . htmlspecialchars($site) . ') asked for a change:</p>'
            . '<blockquote>' . nl2br(htmlspecialchars($said)) . '</blockquote>'
            . (count($shots) ? '<p>With ' . count($shots) . ' screenshot' . (count($shots) === 1 ? '' : 's') . '.</p>' : '')
            . '<p><a href="' . htmlspecialchars(self::url($id)) . '">Open the request</a> to answer, build it, or say it is not going ahead.</p>');
        \Flight::get('log')?->info('Change request received', ['id' => $id, 'instance' => (string) $inst->slug, 'site' => $site, 'shots' => count($shots), 'files' => count($files), 'team' => count($people), 'build' => !empty($d['build'])]);
        return $id;
    }

    /**
     * The site's ROOT asked for it to go straight to the Builder: built at once, as the project's owner pressing Build it,
     * when the project's builder is ready (canBuild). Otherwise it waits for the team, and why is said.
     * @return array{built:bool,why:string}
     */
    public static function buildNow(object $inst, int $id): array {
        $ready = self::canBuild($inst);
        if (!$ready['ready']) return ['built' => false, 'why' => $ready['why']];
        try { self::build(Bean::load('changerequest', $id), (int) $inst->memberId); }
        catch (\Throwable $e) { \Flight::get('log')?->error('Change request: straight to build failed', ['id' => $id, 'error' => $e->getMessage()]); return ['built' => false, 'why' => 'the Builder did not take it: ' . $e->getMessage()]; }
        return ['built' => true, 'why' => ''];
    }

    /**
     * Is the project's builder ready to build? What the project last reported about its own agent (the hourly status
     * report, Projectreport::freshReadiness): not ready, or no recent report, is a no with the reason.
     * @return array{ready:bool,why:string}
     */
    public static function canBuild(object $inst): array {
        if (PlatformChanges::isPlatform($inst)) return ['ready' => false, 'why' => PlatformChanges::NOT_BUILT];
        $r = Projectreport::freshReadiness($inst);
        if ($r === null) return ['ready' => false, 'why' => 'Tiknix has not heard from this project in the last half hour, so whether its builder is ready is not known.'];
        if (!$r['agent_ready']) return ['ready' => false, 'why' => 'this project has no builder agent ready' . ($r['agent_problem'] !== '' ? ' (' . $r['agent_problem'] . ')' : '') . '. Set one up on its AI agents page.'];
        return ['ready' => true, 'why' => ''];
    }

    /**
     * What the app shows its asker: each of $refs (the app's ids on $site) with its state; with $detail, the
     * conversation after the request itself.
     */
    public static function forApp(object $inst, string $site, array $refs, bool $detail): array {
        $refs = array_values(array_filter(array_map('intval', $refs), fn($r) => $r > 0));
        if (!$refs) return [];
        $rows = Bean::find('changerequest', 'instance_id = ? AND site = ? AND app_ref IN (' . Bean::genSlots($refs) . ')',
            array_merge([(int) $inst->id, strtolower($site)], $refs));
        $out = [];
        foreach ($rows as $cr) {
            $messages = self::conversation($cr);
            $r = ['ref' => (int) $cr->appRef, 'status' => (string) $cr->status, 'updated_at' => (string) $cr->updatedAt,
                  'answers' => count(array_filter($messages, fn($m) => !$m['mine']))];
            if ($detail) $r['messages'] = $messages;
            $out[] = $r;
        }
        return $out;
    }

    /** The asker adds to their request from the app: on the conversation, as from them. */
    public static function addFromApp(object $inst, string $site, int $ref, string $text): void {
        $text = trim($text);
        if ($text === '' || mb_strlen($text) > 4000) throw new \InvalidArgumentException('text: 1 to 4000 characters.');
        $cr = Bean::findOne('changerequest', 'instance_id = ? AND site = ? AND app_ref = ?', [(int) $inst->id, strtolower($site), $ref]);
        if (!$cr || !$cr->id || (int) $cr->threadRef <= 0) throw new \RuntimeException('There is no such request from this site.');
        $thread = NotifyService::openInboundThread((int) $inst->memberId, (string) $cr->fromEmail, (string) $cr->fromName,
            'Re: Change request: ' . self::headline((string) $cr->said), nl2br(htmlspecialchars($text)), 'changerequest', (int) $cr->id);
        if ((int) $thread !== (int) $cr->threadRef) throw new \RuntimeException("The addition went to conversation #{$thread}, not the request's #{$cr->threadRef}.");
        $cr->updatedAt = date('Y-m-d H:i:s');
        Bean::store($cr);
        self::tellTeam($inst->box()->people(), $cr->fromName . ' added to their change request',
            '<blockquote>' . nl2br(htmlspecialchars($text)) . '</blockquote><p><a href="' . htmlspecialchars(self::url((int) $cr->id)) . '">Open the request</a></p>');
    }

    /** The requests $memberId may see (their projects'; ROOT all), newest first. */
    public static function forMember(int $memberId, int $level, string $status = '', string $q = ''): array {
        $where = $status !== '' ? ' AND status = ?' : '';
        $bind = $status !== '' ? [$status] : [];
        $q = trim($q);
        if ($q !== '') {   // the search box: what was asked, who asked, the site
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr($q, 0, 100)) . '%';
            $where .= " AND (said LIKE ? ESCAPE '\\' OR from_name LIKE ? ESCAPE '\\' OR from_email LIKE ? ESCAPE '\\' OR site LIKE ? ESCAPE '\\')";
            array_push($bind, $like, $like, $like, $like);
        }
        if ($level <= LEVELS['ROOT']) return array_values(Bean::find('changerequest', '1 = 1' . $where . ' ORDER BY id DESC LIMIT 200', $bind));
        $ids = self::projectsOf($memberId);
        if (!$ids) return [];
        return array_values(Bean::find('changerequest', 'instance_id IN (' . Bean::genSlots($ids) . ')' . $where . ' ORDER BY id DESC LIMIT 200', array_merge($ids, $bind)));
    }

    /** Does $memberId have any requests to see (whether the menu shows the page)? */
    public static function any(int $memberId, int $level): bool {
        if (!in_array('changerequest', Bean::inspect(), true)) return false;
        if ($level <= LEVELS['ROOT']) return Bean::count('changerequest') > 0;
        $ids = self::projectsOf($memberId);
        return $ids && Bean::count('changerequest', 'instance_id IN (' . Bean::genSlots($ids) . ')', $ids) > 0;
    }

    /** How many new requests wait for $memberId (the menu's badge). */
    public static function waiting(int $memberId): int {
        $ids = self::projectsOf($memberId);
        if (!$ids || !in_array('changerequest', Bean::inspect(), true)) return 0;
        return (int) Bean::count('changerequest', "status = 'new' AND instance_id IN (" . Bean::genSlots($ids) . ')', $ids);
    }

    public static function may(object $cr, int $memberId, int $level): bool {
        if ($level <= LEVELS['ROOT']) return true;
        $inst = Bean::load('instance', (int) $cr->instanceId);
        return $inst->id && $inst->box()->accessibleBy($memberId);
    }

    /** Build it: into the project's Ask, to be planned; the asker is told. @return int the Ask message's prompt id */
    public static function build(object $cr, int $memberId): int {
        if ((string) $cr->status !== 'new') throw new \RuntimeException('This request is ' . strtolower(self::LABELS[(string) $cr->status] ?? (string) $cr->status) . ' already.');
        $inst = Bean::load('instance', (int) $cr->instanceId);
        if (!$inst->id) throw new \RuntimeException('Its project is gone.');
        if (PlatformChanges::isPlatform($inst)) throw new \RuntimeException(PlatformChanges::NOT_BUILT);
        $promptId = BuilderMail::queue($inst, $memberId, self::brief($cr), 'build');
        $cr->status = 'building'; self::track($cr, $promptId);
        self::decided($cr, $memberId);
        self::answer($cr, $memberId, '<p>This is being built now. You will hear here when it is live on the site.</p>');
        return $promptId;
    }

    /**
     * Open in Ask: the request goes into the project's Ask as a message, which answers it or plans it there (BuilderMail::queue,
     * 'auto'). Nothing is decided for the asker, so they are not told; if a build comes of it, its end marks this done (built()).
     *
     * @return int the Ask message's prompt id
     */
    public static function ask(object $cr, int $memberId): int {
        if (in_array((string) $cr->status, ['done', 'declined'], true)) throw new \RuntimeException('This request is ' . strtolower(self::LABELS[(string) $cr->status]) . ' already.');
        $inst = Bean::load('instance', (int) $cr->instanceId);
        if (!$inst->id) throw new \RuntimeException('Its project is gone.');
        if (PlatformChanges::isPlatform($inst)) throw new \RuntimeException(PlatformChanges::NOT_BUILT);
        $promptId = BuilderMail::queue($inst, $memberId, self::brief($cr), 'auto');
        self::track($cr, $promptId);
        $cr->updatedAt = date('Y-m-d H:i:s');
        Bean::store($cr);
        return $promptId;
    }

    // ---- the dialog with the Builder: what Ask said about this request, answered from its page -------------------

    /**
     * Every Ask message this request became, oldest first, as the project's Ask keeps them (AskStore): who sent it, what
     * was sent, where it is (thinking, asking a question, answered, being planned, planned…), the Builder's words, and the
     * questions already answered. A message whose project record cannot be read says so.
     *
     * @return list<array{prompt:int,member:int,who:string,sent:string,first:bool,state:string,said:string,qa:array,plan:int,at:string}>
     */
    public static function dialog(object $cr): array {
        $ids = self::prompts($cr);
        if (!$ids) return [];
        $inst = Bean::load('instance', (int) $cr->instanceId);
        if (!$inst->id) return [];
        $dir = \Model_Instance::dirForSlug((string) $inst->slug, (string) ($inst->app ?: \Model_Instance::DEFAULT_APP));
        $out = [];
        foreach ($ids as $i => $pid) {
            $m = AskStore::get($dir, $pid);
            if (!$m) { $out[] = ['prompt' => $pid, 'member' => 0, 'who' => '', 'sent' => '', 'first' => $i === 0, 'state' => 'missing', 'said' => 'This message is not in the project\'s Ask.', 'qa' => [], 'plan' => 0, 'at' => '']; continue; }
            $who = Bean::load('member', (int) $m['member_ref']);
            $qa = array_map(fn($x) => ['q' => (string) ($x['q'] ?? ''), 'a' => (string) ($x['a'] ?? '')], (array) (json_decode((string) $m['qa_json'], true) ?: []));
            $out[] = ['prompt' => $pid, 'member' => (int) $m['member_ref'], 'who' => $who->id ? $who->displayName('Someone') : 'Someone',
                      'sent' => $i === 0 ? '' : trim((string) $m['said']),
                      'first' => $i === 0, 'state' => (string) $m['state'], 'said' => (string) $m['answer'], 'qa' => $qa, 'plan' => (int) ($m['plan_ref'] ?? 0), 'at' => (string) $m['created_at']];
        }
        return $out;
    }

    /**
     * Answer the Builder's question about this request (its latest message waits on one): written into that message's
     * goal, and planning goes on with it (AskTriage::answered). Only the person whose message it is may answer it.
     */
    public static function answerBuilder(object $cr, int $memberId, string $text): void {
        $text = trim($text);
        if ($text === '') throw new \InvalidArgumentException('Write your answer.');
        $last = self::lastPrompt($cr);
        if ($last <= 0) throw new \RuntimeException('This request has not been sent to the Builder.');
        if (!AskTriage::answered($last, $memberId, $text)) {
            throw new \RuntimeException('The Builder is not waiting on an answer from you here (it has moved on, or it asked whoever sent this to it).');
        }
        $cr->updatedAt = date('Y-m-d H:i:s'); Bean::store($cr);
    }

    /**
     * Carry on with the Builder about this request: a new message in the project's Ask, read with what came before
     * (BuilderMail::queue, 'auto' — it answers, or plans and builds). If a build comes of it, its end marks this done.
     */
    public static function followUp(object $cr, int $memberId, string $text): int {
        $text = trim($text);
        if ($text === '') throw new \InvalidArgumentException('Write what you want to say to the Builder.');
        if (mb_strlen($text) > 4000) throw new \InvalidArgumentException('That is longer than 4,000 characters.');
        $inst = Bean::load('instance', (int) $cr->instanceId);
        if (!$inst->id) throw new \RuntimeException('Its project is gone.');
        $pid = BuilderMail::queue($inst, $memberId, "About change request #{$cr->id} (\"" . self::headline((string) $cr->said) . "\" on {$cr->site}):\n\n" . $text, 'auto');
        self::track($cr, $pid);
        $cr->updatedAt = date('Y-m-d H:i:s'); Bean::store($cr);
        return $pid;
    }

    /** @return int[] the Ask messages of this request, oldest first */
    public static function prompts(object $cr): array {
        $ids = array_values(array_filter(array_map('intval', explode(',', (string) ($cr->prompts ?? ''))), fn($i) => $i > 0));
        if (!$ids && (int) $cr->promptRef > 0) $ids = [(int) $cr->promptRef];
        return $ids;
    }

    private static function lastPrompt(object $cr): int { $p = self::prompts($cr); return $p ? (int) end($p) : 0; }

    /** Another Ask message of this request: the latest is prompt_ref, all of them are in prompts. */
    private static function track(object $cr, int $promptId): void {
        $ids = self::prompts($cr); $ids[] = $promptId;
        $cr->promptRef = $promptId;
        $cr->prompts = ',' . implode(',', array_values(array_unique($ids))) . ',';
    }

    /** Not going ahead, with the reason (sent to the asker). */
    public static function decline(object $cr, int $memberId, string $reason): void {
        $reason = trim($reason);
        if ($reason === '') throw new \InvalidArgumentException('Say why it is not going ahead; the person who asked is sent it.');
        if ((string) $cr->status === 'declined') throw new \RuntimeException('This request is already marked as not going ahead.');
        $cr->status = 'declined';
        self::decided($cr, $memberId);
        self::answer($cr, $memberId, '<p>' . nl2br(htmlspecialchars($reason)) . '</p>');
    }

    /** Done (made without the Builder, or checked after it); the asker is told. */
    public static function done(object $cr, int $memberId, string $note): void {
        if ((string) $cr->status === 'done') throw new \RuntimeException('This request is already done.');
        $cr->status = 'done';
        self::decided($cr, $memberId);
        $note = trim($note);
        self::answer($cr, $memberId, $note !== '' ? '<p>' . nl2br(htmlspecialchars($note)) . '</p>' : '<p>This is done and live on ' . htmlspecialchars((string) $cr->site) . '.</p>');
    }

    /**
     * A build ended (scripts/plan-orchestrate.php): a change request that became plan $planId of the project whose
     * Builder folder is $dir is done when it built, and its asker is told. Returns what it did, for the run's log.
     */
    public static function built(string $coreDb, string $dir, int $planId, string $status): string {
        if ($status !== 'done') return '';
        $q = AskStore::db($dir)->prepare('SELECT prompt_ref FROM askmessage WHERE plan_ref = ?');
        $q->execute([$planId]);
        $prompts = array_map('intval', $q->fetchAll(\PDO::FETCH_COLUMN));
        if (!$prompts) return '';
        // The orchestrator works in the project's board database; requests and conversations are core's.
        $restore = Bean::hasDatabase('default') ? 'default' : null;
        if (!Bean::hasDatabase('plannotify')) Bean::addDatabase('plannotify', 'sqlite:' . $coreDb);
        Bean::selectDatabase('plannotify');
        $tell = [];
        try {
            if (!in_array('changerequest', Bean::inspect(), true)) return '';
            // 'building' (Build it) or still 'new' (Open in Ask, which planned it there).
            $like = implode(' OR ', array_fill(0, count($prompts), 'prompts LIKE ?'));
            foreach (Bean::find('changerequest', "status IN ('new', 'building') AND (prompt_ref IN (" . Bean::genSlots($prompts) . ") OR {$like})",
                                array_merge($prompts, array_map(fn($p) => '%,' . $p . ',%', $prompts))) as $cr) {
                $now = date('Y-m-d H:i:s');
                $cr->status = 'done';
                $cr->updatedAt = $now;
                Bean::store($cr);
                $by = Bean::load('member', (int) $cr->decidedRef);
                $name = $by->id ? $by->displayName('The team') : 'The team';
                $text = 'This is built and live on ' . $cr->site . ' now. Have a look, and reply if anything is not as you wanted.';
                if ((int) $cr->threadRef > 0) {
                    $m = Bean::dispense('message');
                    $m->threadId = (int) $cr->threadRef; $m->direction = 'out'; $m->notifyType = 'system'; $m->senderMemberId = (int) $by->id;
                    $m->fromName = $name; $m->subject = 'Re: Change request: ' . self::headline((string) $cr->said);
                    $m->content = '<p>' . htmlspecialchars($text) . '</p>'; $m->bodyPlain = $text; $m->status = 'sent'; $m->createdAt = $now; $m->sentAt = $now;
                    Bean::store($m);
                }
                $tell[] = [(string) $cr->fromEmail, (string) $cr->fromName, 'Your change request is live: ' . self::headline((string) $cr->said),
                           '<p>' . htmlspecialchars($text) . '</p><p>See it, and add to it, on https://' . htmlspecialchars((string) $cr->site) . '/changes</p>', (int) $cr->id];
            }
        } finally {
            if ($restore) Bean::selectDatabase($restore);
        }
        $said = [];
        foreach ($tell as [$email, $name, $subject, $html, $id]) {
            $r = PlanNotifier::mailAddress($email, $name, $subject, $html);
            $said[] = "#{$id} done, " . ((str_contains($r, 'FAILED') || str_starts_with($r, 'ERROR')) ? "ERROR its asker was not emailed: {$r}" : 'its asker emailed');
        }
        return $said ? 'change request ' . implode('; ', $said) : '';
    }

    /** The request's own page on Tiknix. */
    public static function url(int $id): string {
        return rtrim((string) \Flight::get('app.baseurl'), '/') . '/changerequests/view?id=' . $id;
    }

    /** One screenshot's file here: [path, type], or null. */
    public static function shot(object $cr, int $n): ?array {
        $s = (json_decode((string) $cr->shotsJson, true) ?: [])[$n - 1] ?? null;
        if (!is_array($s)) return null;
        $base = realpath(Host::dataRoot() . '/secure/uploads/inbound-mail');
        $full = realpath(Host::dataRoot() . '/' . (string) $s['disk']);
        if ($base === false || $full === false || !str_starts_with($full, $base . '/') || !is_file($full)) return null;
        return [$full, (string) $s['type']];
    }

    /** What the Builder is asked: the request in the asker's words, and what it was about. */
    public static function brief(object $cr): string {
        $shots = json_decode((string) $cr->shotsJson, true) ?: [];
        $errors = json_decode((string) $cr->errorsJson, true) ?: [];
        $page = (string) $cr->page;
        $t = "A change asked for on {$cr->site} by {$cr->fromName}" . ($cr->levelName ? " ({$cr->levelName} there)" : '') . ", change request #{$cr->id}:\n\n"
           . trim((string) $cr->said) . "\n\n"
           . ($page !== '' ? 'The page it is about: https://' . $cr->site . (str_starts_with($page, '/') ? $page : '/' . $page) . "\n" : '')
           . ($cr->browser ? "Their browser: {$cr->browser}\n" : '');
        $notes = json_decode((string) ($cr->notesJson ?? '[]'), true) ?: [];
        if ($notes) {
            // Each note points at one thing on one page: what it is, how to find it, a little of its HTML, its picture.
            $t .= "\nTheir notes, each about one thing they pointed at on the site:\n";
            foreach ($notes as $i => $n) {
                $e = $n['element'] ?? null;
                $t .= "\n" . ($i + 1) . '. On ' . $n['page'] . ($e ? ', ' . $e['label'] : ', the whole page') . ': ' . $n['text'] . "\n";
                if ($e) $t .= "   Element: {$e['code']} ({$e['size']} on a {$n['screen']} screen)\n   Selector: {$e['selector']}\n   HTML: {$e['html']}\n";
                if ($n['shot'] !== null && ($p = (string) ($shots[$n['shot']]['app_path'] ?? '')) !== '') $t .= "   Picture of it: {$p}\n";
            }
        }
        $paths = array_values(array_filter(array_map(fn($s) => (string) ($s['app_path'] ?? ''), $shots)));
        if ($paths && !$notes) $t .= "\nScreenshots they sent (pictures on this app's server — look at them before planning):\n" . implode("\n", array_map(fn($p) => "- {$p}", $paths)) . "\n";
        elseif ($paths) $t .= "\nLook at the pictures before planning: they show what each note points at.\n";
        $files = json_decode((string) ($cr->filesJson ?? '[]'), true) ?: [];
        if ($files) {
            // what each kind of file is for, so it is used, not just mentioned
            $t .= "\nFiles they attached (on this app's server — open each one before planning):\n";
            foreach ($files as $f) {
                $ext = strtolower(pathinfo((string) $f['name'], PATHINFO_EXTENSION));
                $how = in_array($ext, ['xls', 'xlsx', 'csv'], true) ? 'a spreadsheet: read its columns and formulas, and make the page work exactly like it (same columns, same results, deterministically)'
                     : (in_array($ext, ['md', 'markdown', 'txt', 'docx'], true) ? 'directions in words: follow them' : (in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'gif'], true) ? 'a picture of a look they like: match its look and feel' : 'read it'));
                $t .= "- {$f['path']} ({$f['name']}" . ($f['note'] !== null ? ', with note ' . ($f['note'] + 1) : '') . "): {$how}\n";
            }
        }
        if ($errors) $t .= "\nErrors the site logged in the half hour before they asked:\n" . implode("\n", array_map(fn($e) => "- {$e}", $errors)) . "\n";
        return $t;
    }

    // ---- inside -------------------------------------------------------------------------------------------------

    /** @return int[] the projects $memberId owns or shares a team with */
    private static function projectsOf(int $memberId): array {
        $ids = array_map('intval', Bean::getCol('SELECT id FROM instance WHERE member_id = ?', [$memberId]));
        if (in_array('instance_team', Bean::inspect(), true)) {
            $ids = array_merge($ids, array_map('intval', Bean::getCol(
                'SELECT DISTINCT it.instance_id FROM instance_team it JOIN teammember tm ON tm.team_id = it.team_id WHERE tm.member_id = ?', [$memberId])));
        }
        return array_values(array_unique($ids));
    }

    /** The conversation after the request itself: who said what, and whether it was the asker. */
    private static function conversation(object $cr): array {
        if ((int) $cr->threadRef <= 0) return [];
        $rows = Bean::getAll('SELECT id, from_name, sender_member_id, direction, body_plain, content, created_at FROM message WHERE thread_id = ? ORDER BY id ASC', [(int) $cr->threadRef]);
        array_shift($rows);   // the request itself
        $out = [];
        foreach ($rows as $m) {
            $text = trim((string) $m['body_plain']) !== '' ? (string) $m['body_plain'] : trim(html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", (string) $m['content']))));
            $mine = (int) $m['sender_member_id'] <= 0 && (string) $m['direction'] === 'in';
            // With its offset: the site shows it in its own time zone, which need not be this one's.
            $out[] = ['who' => $mine ? 'You' : ((string) $m['from_name'] ?: 'The team'), 'mine' => $mine, 'at' => date('c', strtotime((string) $m['created_at'])), 'text' => trim($text)];
        }
        return $out;
    }

    private static function decided(object $cr, int $memberId): void {
        $cr->decidedRef = $memberId;
        $cr->decidedAt = date('Y-m-d H:i:s');
        $cr->updatedAt = $cr->decidedAt;
        Bean::store($cr);
    }

    /** Said on the request's conversation by $memberId: the asker is emailed it (app\Notes). */
    private static function answer(object $cr, int $memberId, string $html): void {
        if ((int) $cr->threadRef <= 0 || $memberId <= 0) {
            error_log("ERROR SiteChanges: request #{$cr->id} has no conversation or no one to say it as; the asker was not told: " . strip_tags($html));
            return;
        }
        $r = Notes::onThread($memberId, (int) $cr->threadRef, $html, 'Re: Change request: ' . self::headline((string) $cr->said));
        if (!in_array($r['email'], ['sent', 'none'], true)) {
            error_log("ERROR SiteChanges: request #{$cr->id}: said on its conversation, but the email to {$cr->fromEmail} was not sent ({$r['email']}): " . (string) ($r['email_error'] ?? ''));
        }
    }

    private static function tellTeam(array $people, string $subject, string $html): void {
        foreach ($people as $id) {
            $m = Bean::load('member', (int) $id);
            if (!$m->id || !filter_var((string) $m->email, FILTER_VALIDATE_EMAIL)) continue;
            $r = PlanNotifier::mailAddress((string) $m->email, $m->displayName('there'), $subject, $html);
            if (str_contains($r, 'FAILED') || str_starts_with($r, 'ERROR')) error_log("ERROR SiteChanges: the team notice to member #{$m->id} was not sent: {$r}");
        }
    }

    private static function headline(string $said): string {
        return mb_strimwidth(trim((string) strtok($said, "\n")), 0, 70, '…');
    }

    private static function firstMessage(object $cr, string $project): string {
        $page = (string) $cr->page;
        return '<p>' . nl2br(htmlspecialchars((string) $cr->said)) . '</p>'
             . '<p class="small">' . htmlspecialchars($project) . ' · ' . htmlspecialchars((string) $cr->site)
             . ($page !== '' ? ' · page ' . htmlspecialchars($page) : '') . '</p>'
             . '<p class="small"><a href="' . htmlspecialchars(self::url((int) $cr->id)) . '">Open the request</a> to build it, or say it is not going ahead. '
             . 'A reply here reaches ' . htmlspecialchars((string) $cr->fromName) . ' by email.</p>';
    }

    /** The notes as the site sent them, kept within bounds (the site checked them; this keeps what reaches a page and a brief small). */
    public static function checkNotes(array $notes, int $shots, int $files = 0): array {
        if (count($notes) > self::MAX_SHOTS) throw new \InvalidArgumentException('notes: at most ' . self::MAX_SHOTS . '.');
        $cut = fn($v, int $n) => mb_substr(trim((string) $v), 0, $n);
        $out = [];
        foreach (array_values($notes) as $i => $n) {
            if (!is_array($n) || $cut($n['text'] ?? '', 1000) === '') throw new \InvalidArgumentException('notes[' . $i . ']: a note says what should change.');
            $shot = isset($n['shot']) && $n['shot'] !== null ? (int) $n['shot'] : null;
            if ($shot !== null && ($shot < 0 || $shot >= $shots)) throw new \InvalidArgumentException('notes[' . $i . ']: its picture is not among the shots.');
            $e = is_array($n['element'] ?? null) ? $n['element'] : null;
            $fs = array_values(array_map('intval', (array) ($n['files'] ?? [])));
            foreach ($fs as $f) if ($f < 0 || $f >= $files) throw new \InvalidArgumentException('notes[' . $i . ']: a file it names is not among the files.');
            $out[] = ['page' => $cut($n['page'] ?? '', 300), 'title' => $cut($n['title'] ?? '', 160), 'text' => $cut($n['text'], 1000), 'screen' => $cut($n['screen'] ?? '', 40), 'shot' => $shot, 'files' => $fs,
                      'element' => $e === null ? null : array_map(fn($k) => $cut($e[$k] ?? '', $k === 'html' ? 1300 : 400), ['label' => 'label', 'code' => 'code', 'selector' => 'selector', 'tag' => 'tag', 'text' => 'text', 'html' => 'html', 'size' => 'size'])];
        }
        return $out;
    }

    /**
     * The files the site kept for the builder (their bytes stay on the app): name, type, size, path on the app — under its
     * data/changes/, which is all /changerequests/file will ever read — and the note they belong to (set below).
     */
    public static function checkFiles(array $files, bool $platform = false): array {
        if (count($files) > 6) throw new \InvalidArgumentException('files: at most 6.');
        $out = [];
        foreach (array_values($files) as $i => $f) {
            $path = (string) ($f['path'] ?? '');
            if (!self::appPathOk($path, $platform)) throw new \InvalidArgumentException('files[' . $i . ']: not a file the site keeps for a request.');
            $out[] = ['name' => mb_substr(basename((string) ($f['name'] ?? 'file')), 0, 120), 'type' => mb_substr((string) ($f['type'] ?? ''), 0, 120),
                      'size' => max(0, (int) ($f['size'] ?? 0)), 'path' => $path, 'note' => null];
        }
        return $out;
    }

    /**
     * A path an app keeps a request's files at: /srv/app/[hosts/<host>/]data/changes/<id>/files/<n>-<name> — or, for the
     * platform's own notes ($platform), the same under this install's root.
     */
    public static function appPathOk(string $path, bool $platform = false): bool {
        $root = $platform ? '(' . preg_quote(rtrim(Paths::root(), '/'), '#') . '/)' : '/srv/app/(hosts/[a-z0-9.-]+/)?';
        return (bool) preg_match('#^' . $root . 'data/changes/\d+/files/\d+-[A-Za-z0-9._-]+$#', $path) && !str_contains($path, '..');
    }

    /** One attached file, fetched from the app's container into a temporary file: [path, name]. @throws \RuntimeException */
    public static function fetchFile(object $cr, int $n): array {
        $f = (json_decode((string) ($cr->filesJson ?? '[]'), true) ?: [])[$n - 1] ?? null;
        $inst = Bean::load('instance', (int) $cr->instanceId);
        if (!$inst->id) throw new \RuntimeException('Its project is gone.');
        $platform = PlatformChanges::isPlatform($inst);
        if (!is_array($f) || !self::appPathOk((string) $f['path'], $platform)) throw new \RuntimeException('No such file on this request.');
        if ($platform) {   // the platform's own notes: the file is on this machine
            $tmp = tempnam(sys_get_temp_dir(), 'crf');
            if (!is_file((string) $f['path']) || !copy((string) $f['path'], $tmp)) { @unlink($tmp); throw new \RuntimeException('The file is no longer here: ' . $f['path']); }
            return [$tmp, (string) $f['name']];
        }
        $tmp = tempnam(sys_get_temp_dir(), 'crf');
        [$code, $err] = TenantHost::ssh($inst, 'app', 'cat ' . escapeshellarg((string) $f['path']), null, 60, null, $tmp);
        if ($code !== 0) { @unlink($tmp); throw new \RuntimeException('The file could not be fetched from the app: ' . trim($err)); }
        return [$tmp, (string) $f['name']];
    }

    /** @return array<int,array{name:string,type:string,bytes:string,app_path:string}> */
    private static function checkShots(array $shots): array {
        if (count($shots) > self::MAX_SHOTS) throw new \InvalidArgumentException('shots: at most ' . self::MAX_SHOTS . '.');
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $out = [];
        foreach (array_values($shots) as $i => $s) {
            $bytes = base64_decode((string) ($s['data'] ?? ''), true);
            if ($bytes === false || $bytes === '') throw new \InvalidArgumentException('shots[' . $i . ']: data is not base64 of a picture.');
            if (strlen($bytes) > self::MAX_SHOT_BYTES) throw new \InvalidArgumentException('shots[' . $i . ']: larger than ' . self::MAX_SHOT_BYTES . ' bytes.');
            $type = (string) $finfo->buffer($bytes);
            if (!isset(self::TYPES[$type])) throw new \InvalidArgumentException('shots[' . $i . ']: not a PNG, JPEG, WebP or GIF.');
            $path = (string) ($s['path'] ?? '');
            $out[] = ['name' => mb_substr(basename((string) ($s['name'] ?? 'screenshot')), 0, 120), 'type' => $type, 'bytes' => $bytes,
                      'app_path' => str_starts_with($path, '/') ? mb_substr($path, 0, 300) : ''];
        }
        return $out;
    }

    /** The screenshots as attachments of the request's first message (the team opens them there and on its page). */
    private static function keepShots(int $threadId, array $shots): array {
        if (!$shots) return [];
        $rel = 'secure/uploads/inbound-mail/' . $threadId;
        $dir = Host::dataRoot() . '/' . $rel;
        if (!is_dir($dir) && !mkdir($dir, 0700, true)) throw new \RuntimeException("could not make {$dir} for the screenshots");
        $first = (int) Bean::getCell('SELECT MIN(id) FROM message WHERE thread_id = ?', [$threadId]);
        $kept = [];
        foreach ($shots as $i => $s) {
            $file = 'change-' . ($i + 1) . '-' . bin2hex(random_bytes(4)) . '.' . self::TYPES[$s['type']];
            if (file_put_contents($dir . '/' . $file, $s['bytes']) === false) throw new \RuntimeException("could not keep screenshot {$s['name']}");
            $att = Bean::dispense('messageattachment');
            $att->threadId = $threadId;
            $att->notifyId = $first;
            $att->filename = $s['name'];
            $att->diskPath = $rel . '/' . $file;
            $att->mimeType = $s['type'];
            $att->size = strlen($s['bytes']);
            $att->createdAt = date('Y-m-d H:i:s');
            Bean::store($att);
            $kept[] = ['name' => $s['name'], 'type' => $s['type'], 'disk' => $rel . '/' . $file, 'app_path' => $s['app_path']];
        }
        return $kept;
    }
}

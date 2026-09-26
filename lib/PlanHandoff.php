<?php
/**
 * PlanHandoff — the door a plan comes through from the Get-started wizard (start.tiknix)
 * into a real project here (START-PLAN.md §8, as re-shaped by §10a).
 *
 * The wizard runs on its own instance for a VISITOR who has no account here. So the
 * hand-off is two-sided:
 *
 *   offer    the wizard's instance POSTs the package — a name, PLAN.md, blueprint.json,
 *            the brief — with its broker key (the same credential every instance→core
 *            call carries). Core keeps it as a `planhandoff` row behind a random token and
 *            answers with the claim URL. The wizard sends the visitor there.
 *   claim    /handoff/claim/<token> on core. Not signed in → sign in or register (the
 *            token waits in the session), then back here. Signed in → "Start a new project
 *            from this plan?": the name, the engine, the plan gate — the same
 *            ProvisionService::create the Projects page uses — and PLAN.md +
 *            .aibuilder/blueprint.json committed into the new project as the member.
 *
 * Nothing here trusts the visitor with anything but the token: the package is what the
 * wizard's instance signed for, the account is whoever signs in, and the project is theirs.
 * A token is single-use — a second claim is told which project it already became.
 */

namespace app;

use \Flight as Flight;

class PlanHandoff {

    public const TOKEN_RE = '/^[a-f0-9]{48}$/';
    public const NAME_MAX = 60;
    public const PLAN_MAX = 200000;
    public const JSON_MAX = 60000;

    /**
     * Keep a package the wizard's instance handed over. Returns the stored row.
     *
     * @throws \InvalidArgumentException naming the field that is missing or malformed
     */
    public static function offer(int $sourceInstanceId, array $in): \RedBeanPHP\OODBBean {
        if ($sourceInstanceId <= 0) throw new \InvalidArgumentException('handoff: the broker key is not bound to an instance');
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > self::NAME_MAX) throw new \InvalidArgumentException('handoff: name is required (1–' . self::NAME_MAX . ' characters)');
        $plan = $in['plan_md'] ?? '';
        if (!is_string($plan) || trim($plan) === '' || strlen($plan) > self::PLAN_MAX) throw new \InvalidArgumentException('handoff: plan_md is required, as text (≤ ' . self::PLAN_MAX . ' bytes)');
        // blueprint_json / brief_json: the JSON object itself, or that object as a string —
        // both are the same value and both are taken. The wizard sends the object inline;
        // the first Build it (2026-09-26) hit "Array to string conversion" here because
        // only the string form was expected, and the contract had said "a JSON object".
        $blueprint = self::jsonObject($in['blueprint_json'] ?? null, 'blueprint_json', true);
        $brief     = self::jsonObject($in['brief_json'] ?? null, 'brief_json', false);
        $resume = trim((string) ($in['resume_url'] ?? ''));
        if ($resume !== '' && !preg_match('#^https://[^\s]+$#', $resume)) throw new \InvalidArgumentException('handoff: resume_url must be an https URL');

        $h = Bean::dispense('planhandoff');
        $h->token             = bin2hex(random_bytes(24));
        $h->status            = 'offered';
        $h->name              = $name;
        $h->planMd            = $plan;
        $h->planSha           = hash('sha256', $plan);
        $h->blueprintJson     = $blueprint;
        $h->briefJson         = $brief;
        $h->resumeUrl         = $resume;
        $h->sourceInstanceRef = $sourceInstanceId;
        $h->memberRef         = 0;
        $h->instanceRef       = 0;
        $h->createdAt         = date('Y-m-d H:i:s');
        $h->claimedAt         = '';
        Bean::store($h);
        return $h;
    }

    /**
     * A JSON object argument, as the encoded string it is stored as. Accepts the decoded
     * object (an array) or its string form; refuses anything else by name.
     *
     * @throws \InvalidArgumentException
     */
    private static function jsonObject($value, string $field, bool $required): string {
        if ($value === null || $value === '') {
            if ($required) throw new \InvalidArgumentException("handoff: {$field} is required (a JSON object, ≤ " . self::JSON_MAX . ' bytes)');
            return '';
        }
        if (is_array($value)) {
            $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($encoded === false) throw new \InvalidArgumentException("handoff: {$field} could not be encoded as JSON");
            $value = $encoded;
        }
        if (!is_string($value) || strlen($value) > self::JSON_MAX || !is_array(json_decode($value, true))) {
            throw new \InvalidArgumentException("handoff: {$field} must be a JSON object (≤ " . self::JSON_MAX . ' bytes)');
        }
        return $value;
    }

    public static function byToken(string $token): ?\RedBeanPHP\OODBBean {
        if (!preg_match(self::TOKEN_RE, $token)) return null;
        $h = Bean::findOne('planhandoff', 'token = ?', [$token]);
        return ($h && $h->id) ? $h : null;
    }

    public static function claimUrl(\RedBeanPHP\OODBBean $h): string {
        return self::baseUrl() . '/handoff/claim/' . $h->token;
    }

    public static function stateUrl(\RedBeanPHP\OODBBean $h): string {
        return self::baseUrl() . '/handoff/state?token=' . $h->token;
    }

    /** What the wizard's status page may know: where the plan stands, never who claimed it. */
    public static function state(\RedBeanPHP\OODBBean $h): array {
        $out = ['status' => (string) $h->status, 'name' => (string) $h->name, 'project_slug' => '', 'project_url' => ''];
        if ((int) $h->instanceRef > 0) {
            $inst = Bean::load('instance', (int) $h->instanceRef);
            if ($inst->id) {
                $out['project_slug'] = (string) $inst->slug;
                $out['project_url']  = 'https://' . $inst->slug . '.' . ($inst->app ?: 'tiknix') . '.com';
            }
        }
        return $out;
    }

    /**
     * Turn a claimed offer into the member's project: provision (the plan gate and the
     * name rules are ProvisionService::create's), commit PLAN.md + blueprint.json into it,
     * select it for the member, mark the offer claimed.
     *
     * @return array ok/slug/id/url, or ok=false/error/code (+action_url from a plan refusal)
     */
    /**
     * The planner's goal for a project that has just received its PLAN.md (§8 step 6):
     * decompose Phase 1 as the plan defines it, nothing more.
     */
    public const PHASE_ONE_GOAL = 'Build Phase 1 of PLAN.md — the plan at the root of this repository, written by the '
        . 'Get-started wizard and committed as the owner\'s contract. Read it first, all of it. Phase 1 is defined in its '
        . '"Phases" section; build exactly that phase: the data model it names (section 4), the pages and routes it names '
        . '(section 5) and the acceptance checks for the phase (section 10), on the primitives section 6 says it is built '
        . 'from. Nothing from a later phase, nothing the plan does not ask for. Every route gets its authcontrol row by seed '
        . '(PermissionCache::seedRule), every public form is protected (Turnstile + honeypot, per CLAUDE.md), every task '
        . 'ships its tests. Decompose into tasks a single agent can finish and prove in one sitting, in dependency order, '
        . 'naming what each reuses from the codebase.';

    /**
     * @param bool $decompose start the planner on Phase 1 right after the commit (§8 step 6).
     *                        The project exists either way; a planner that does not start is
     *                        reported in `planner`, never hidden.
     */
    public static function create(int $memberId, \RedBeanPHP\OODBBean $h, string $name, string $engine, bool $decompose = true): array {
        if ((string) $h->status !== 'offered') {
            return ['ok' => false, 'code' => 409, 'error' => 'This plan has already become a project.'] + self::state($h);
        }
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > self::NAME_MAX) return ['ok' => false, 'code' => 400, 'error' => 'Give the project a name (1–' . self::NAME_MAX . ' characters).'];
        $base = self::slugBase($name);
        if ($base === '') return ['ok' => false, 'code' => 400, 'error' => 'The name needs at least two letters or digits to make a project id from.'];

        $res = (new ProvisionService())->create($memberId, ['slug' => $base, 'name' => $name, 'engine' => $engine, 'plan' => 'project']);
        if (empty($res['ok'])) return $res;

        $inst = Bean::load('instance', (int) $res['id']);
        $dir  = \Model_Instance::dirFrom((string) $inst->slug, (string) ($inst->app ?: 'tiknix'));
        $member = Bean::load('member', $memberId);
        self::commitPlan($dir, (string) $h->planMd, (string) $h->blueprintJson,
            trim((string) (($member->firstName ?? '') . ' ' . ($member->lastName ?? ''))) ?: (string) ($member->username ?? 'member'),
            (string) ($member->email ?? ''), (string) $h->planSha);

        $h->status      = 'claimed';
        $h->memberRef   = $memberId;
        $h->instanceRef = (int) $inst->id;
        $h->claimedAt   = date('Y-m-d H:i:s');
        Bean::store($h);
        ProjectContext::set($memberId, (int) $inst->id);

        // §8 step 6: the plan is decomposed the moment the project exists, so the member
        // lands on a board with Phase 1 already being planned — not on an empty board
        // wondering what to type. The first hand-off (2026-09-26) landed on the empty board.
        $planner = 'not requested';
        if ($decompose) {
            try {
                $runner  = new PlanRunner((string) $inst->slug, $dir, $memberId, (int) ($member->level ?? LEVELS['MEMBER']), $engine);
                $session = $runner->start(self::PHASE_ONE_GOAL);
                $planner = 'started (' . $session . ')';
            } catch (\Throwable $e) {
                Flight::get('log')?->error('handoff: the Phase 1 planner did not start', ['slug' => (string) $inst->slug, 'err' => $e->getMessage()]);
                $planner = 'NOT started: ' . $e->getMessage() . ' — start it from Advanced Builder → Plan with the goal "Build Phase 1 of PLAN.md"';
            }
        }
        return ['ok' => true, 'slug' => (string) $inst->slug, 'id' => (int) $inst->id, 'planner' => $planner,
                'url' => '/sidecar/app/workbench?to=' . rawurlencode('/workbench')];
    }

    /**
     * Write PLAN.md and .aibuilder/blueprint.json into a project and commit them as the
     * member. blueprint.json sits in an ignored directory, so it is force-added — the
     * plan and its blueprint are the project's contract, not build scratch.
     *
     * @throws \RuntimeException with git's own words when the commit does not happen
     */
    public static function commitPlan(string $dir, string $planMd, string $blueprintJson, string $authorName, string $authorEmail, string $planSha = ''): void {
        if (!is_dir($dir . '/.git')) throw new \RuntimeException("handoff: {$dir} is not a git repository");
        if (!is_dir($dir . '/.aibuilder') && !@mkdir($dir . '/.aibuilder', 0775, true)) throw new \RuntimeException("handoff: cannot create {$dir}/.aibuilder");
        if (file_put_contents($dir . '/PLAN.md', $planMd) === false) throw new \RuntimeException("handoff: cannot write {$dir}/PLAN.md");
        if (file_put_contents($dir . '/.aibuilder/blueprint.json', $blueprintJson) === false) throw new \RuntimeException("handoff: cannot write {$dir}/.aibuilder/blueprint.json");
        $git = 'git -C ' . escapeshellarg($dir);
        // --author, not -c user.*: a GIT_AUTHOR_NAME/EMAIL in the environment (a git hook,
        // a CI runner) overrides -c config, and the member's name is the point here.
        $who = $authorEmail !== '' ? ($authorName !== '' ? $authorName : $authorEmail) . ' <' . $authorEmail . '>' : '';
        $author = $who !== '' ? ' --author=' . escapeshellarg($who) : '';
        $msg = 'PLAN.md from the Get-started wizard' . ($planSha !== '' ? ' (plan ' . substr($planSha, 0, 12) . ')' : '');
        $cmd = "$git add PLAN.md && $git add -f .aibuilder/blueprint.json && $git -c user.name=tiknix -c user.email=noreply@tiknix.com commit -q" . $author . ' -m ' . escapeshellarg($msg);
        $out = []; $code = 0;
        exec($cmd . ' 2>&1', $out, $code);
        if ($code !== 0) throw new \RuntimeException('handoff: committing PLAN.md failed — ' . trim(implode(' ', $out)));
    }

    /** "My Shop Portal" → "my-shop-portal"; '' when nothing usable is left. */
    public static function slugBase(string $name): string {
        $s = strtolower(trim($name));
        $s = preg_replace('/[^a-z0-9]+/', '-', $s);
        $s = trim((string) $s, '-');
        $s = preg_replace('/^[^a-z]+/', '', $s);      // must start with a letter
        $s = substr((string) $s, 0, 40);
        $s = rtrim($s, '-');
        return strlen($s) >= 2 ? $s : '';
    }

    /** Where a fresh sign-in or registration should land when a claim is waiting. */
    public static function afterLoginTarget(): ?string {
        $t = (string) ($_SESSION['handoff_token'] ?? '');
        if ($t === '' || !preg_match(self::TOKEN_RE, $t)) return null;
        unset($_SESSION['handoff_token']);
        return '/handoff/claim/' . $t;
    }

    private static function baseUrl(): string {
        $base = rtrim((string) (Flight::get('app.baseurl') ?? ''), '/');
        if ($base === '') throw new \RuntimeException('handoff: [app] baseurl is not set in conf/config.ini');
        return $base;
    }
}

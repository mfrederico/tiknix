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
        $out = ['status' => (string) $h->status, 'name' => (string) $h->name, 'project_slug' => '', 'project_url' => '',
                'progress' => (string) ($h->progress ?? ''), 'note' => (string) ($h->progressNote ?? '')];
        if ((int) $h->instanceRef > 0) {
            $inst = Bean::load('instance', (int) $h->instanceRef);
            // Claimed into a project that has since been deleted: not "claimed" (there is no project
            // to name) and not "offered" (the claim happened). The wizard's expiry purges on this.
            if (!$inst->id && (string) $h->status === 'claimed') {
                $out['status'] = 'gone';
                $out['note'] = 'the project this plan was claimed into has been deleted';
                return $out;
            }
            if ($inst->id) {
                $out['project_slug'] = (string) $inst->slug;
                $out['project_url']  = 'https://' . $inst->slug . '.' . ($inst->app ?: 'tiknix') . '.com';
                // The container's own setup failing is said here too — finish() never runs then.
                if ($out['progress'] === 'setting-up' && \Model_Instance::setupStateFor($inst) === 'failed') {
                    $out['progress'] = 'failed';
                    $out['note'] = 'setting up the project\'s container failed — see its provision log';
                }
            }
        }
        return $out;
    }

    /**
     * The planner's goal for a project that has just received its PLAN.md (§8 step 6):
     * decompose Phase 1 as the plan defines it, nothing more.
     */
    public const PHASE_ONE_GOAL = 'Build Phase 1 of PLAN.md — the plan at the root of this repository, written by the '
        . 'Get-started wizard and committed as the owner\'s contract. Read it first, all of it. Phase 1 is defined in its '
        . '"Phases" section; build exactly that phase: the data model it names (section 4), the pages and routes it names '
        . '(section 5) and the acceptance checks for the phase (section 10), on the primitives section 6 says it is built '
        . 'from. Nothing from a later phase, nothing the plan does not ask for. Every route gets its authcontrol row by seed '
        . '(PermissionCache::seedRule), every public form is protected (Turnstile + honeypot, per AGENTS.md), every task '
        . 'ships its tests. Decompose into tasks a single agent can finish and prove in one sitting, in dependency order, '
        . 'naming what each reuses from the codebase.';

    /**
     * Claim an offer: create the member's project (ProvisionService::create — the plan gate and
     * the name rules are its) and select it. The project lives in its own container, which takes
     * a couple of minutes to set up in the background; the rest — PLAN.md committed into it and
     * Phase 1 planned — is finish(), run right after the setup succeeds (tenant.php
     * --handoff-finish). The claim page follows it through state().
     *
     * @param bool $decompose start the Phase 1 planner once PLAN.md is in (when the app has an
     *                        agent signed in — else the member is asked to connect one first)
     */
    public static function create(int $memberId, \RedBeanPHP\OODBBean $h, string $name, string $engine, bool $decompose = true): array {
        if ((string) $h->status !== 'offered') {
            return ['ok' => false, 'code' => 409, 'error' => 'This plan has already become a project.'] + self::state($h);
        }
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > self::NAME_MAX) return ['ok' => false, 'code' => 400, 'error' => 'Give the project a name (1–' . self::NAME_MAX . ' characters).'];
        $base = self::slugBase($name);
        if ($base === '') return ['ok' => false, 'code' => 400, 'error' => 'The name needs at least two letters or digits to make a project id from.'];

        $res = (new ProvisionService())->create($memberId, ['slug' => $base, 'name' => $name, 'engine' => $engine, 'plan' => 'project',
            'then' => ['--handoff-finish=' . $h->token]]);
        if (empty($res['ok'])) return $res;

        $h->status       = 'claimed';
        $h->memberRef    = $memberId;
        $h->instanceRef  = (int) $res['id'];
        $h->claimedAt    = date('Y-m-d H:i:s');
        $h->progress     = 'setting-up';
        $h->progressNote = $res['warning'] ?? '';
        $h->decompose    = $decompose ? 1 : 0;
        Bean::store($h);
        ProjectContext::set($memberId, (int) $res['id']);
        return ['ok' => true, 'slug' => (string) $res['slug'], 'id' => (int) $res['id'],
                'url' => '/handoff/claim/' . $h->token, 'planner' => 'starts once the project is set up'];
    }

    /**
     * The rest of a claim, once the project's container is up (tenant.php --handoff-finish):
     * PLAN.md and .aibuilder/blueprint.json committed into the app as the member, then Phase 1
     * (startPhaseOne). Safe to run again: PLAN.md is committed once.
     */
    public static function finish(string $token): array {
        $h = self::byToken($token);
        if (!$h) return ['ok' => false, 'error' => 'no such hand-off'];
        if ((string) $h->status !== 'claimed' || (int) $h->instanceRef <= 0) return ['ok' => false, 'error' => "hand-off is '{$h->status}', not a claimed one with a project"];
        $inst = Bean::load('instance', (int) $h->instanceRef);
        if (!$inst->id || !\Model_Instance::tenantRow($inst)) return self::fail($h, 'the project is not running in its own container');
        if (in_array((string) $h->progress, ['setting-up', 'failed', ''], true)) {
            try {
                self::commitPlan($inst, $h, TenantHost::author((int) $h->memberRef));
            } catch (\Throwable $e) {
                return self::fail($h, 'committing PLAN.md into the project failed: ' . $e->getMessage());
            }
            $h->progress = 'plan-committed'; $h->progressNote = ''; $h->finishedAt = date('Y-m-d H:i:s');
            Bean::store($h);
        }
        if (empty($h->decompose)) return ['ok' => true] + self::state($h);
        return self::startPhaseOne($h);
    }

    /**
     * Plan Phase 1 of PLAN.md — on the app's own agent, so only once one is signed in on the
     * app's AI agents page. Without one, the hand-off waits ('waiting-agent') and the member
     * starts it from the claim page after connecting it.
     */
    public static function startPhaseOne(\RedBeanPHP\OODBBean $h): array {
        $inst = Bean::load('instance', (int) $h->instanceRef);
        if (!$inst->id) return self::fail($h, 'the project is gone');
        if (!in_array((string) $h->progress, ['plan-committed', 'waiting-agent', 'planning'], true)) {
            return ['ok' => false, 'error' => "PLAN.md is not in the project yet ({$h->progress})"] + self::state($h);
        }
        [$c, $o] = TenantHost::ssh($inst, 'app', 'cd /srv/app && php scripts/clitool.php --agent-ready 2>&1', null, 60);
        if ($c !== 0) {
            $h->progress = 'waiting-agent';
            $h->progressNote = trim((string) $o) ?: 'no AI agent is signed in on this app yet';
            Bean::store($h);
            return ['ok' => true] + self::state($h);
        }
        $member = Bean::load('member', (int) $h->memberRef);
        try {
            $runner  = new PlanRunner((string) $inst->slug, \Model_Instance::dirOf($inst), (int) $h->memberRef, (int) ($member->level ?? LEVELS['MEMBER']), (string) ($inst->engine ?: 'claude'));
            $session = $runner->start(self::PHASE_ONE_GOAL);
        } catch (\Throwable $e) {
            return self::fail($h, 'the Phase 1 planner did not start: ' . $e->getMessage());
        }
        $h->progress = 'planning'; $h->progressNote = "planner {$session}";
        Bean::store($h);
        return ['ok' => true] + self::state($h);
    }

    /**
     * Every hand-off waiting for its app's agent: start Phase 1 for those whose app has one signed
     * in now (tenant.php --handoff-pending, every minute) — so connecting the agent is all the
     * member does; nobody has to come back and press Start Phase 1.
     *
     * @return array<string,string> token prefix => what happened
     */
    public static function resumeWaiting(): array {
        $out = [];
        foreach (Bean::find('planhandoff', "status = 'claimed' AND progress = 'waiting-agent' AND decompose = 1") as $h) {
            $r = self::startPhaseOne($h);
            $out[substr((string) $h->token, 0, 8)] = (string) ($r['progress'] ?? '') . (empty($r['ok']) ? ' — ' . ($r['error'] ?? '') : '');
        }
        return $out;
    }

    private static function fail(\RedBeanPHP\OODBBean $h, string $why): array {
        Flight::get('log')?->error('handoff: ' . $why, ['token' => substr((string) $h->token, 0, 8) . '…', 'instance' => (int) $h->instanceRef]);
        error_log('ERROR handoff ' . substr((string) $h->token, 0, 8) . '…: ' . $why);
        $h->progress = 'failed'; $h->progressNote = $why;
        Bean::store($h);
        return ['ok' => false, 'error' => $why] + self::state($h);
    }

    /**
     * PLAN.md and .aibuilder/blueprint.json into the app's repository in its container,
     * committed as the member (blueprint.json is force-added: .aibuilder/ is ignored, but the
     * plan and its blueprint are the project's contract). Skips the commit when PLAN.md is
     * already there with this content.
     *
     * @throws \RuntimeException with git's own words when the commit does not happen
     */
    public static function commitPlan(object $inst, \RedBeanPHP\OODBBean $h, array $author): void {
        $git = TenantHost::gitAs($author);
        if ($git === null) throw new \RuntimeException('the member has no name and email to commit as');
        $msg = 'PLAN.md from the Get-started wizard' . ((string) $h->planSha !== '' ? ' (plan ' . substr((string) $h->planSha, 0, 12) . ')' : '');
        $write = '$j = json_decode(stream_get_contents(STDIN), true); if (!is_array($j)) { fwrite(STDERR, "no package on stdin\n"); exit(1); } '
               . '@mkdir(".aibuilder", 0775, true); '
               . 'if (file_put_contents("PLAN.md", $j["plan"]) === false || file_put_contents(".aibuilder/blueprint.json", $j["blueprint"]) === false) { fwrite(STDERR, "could not write the plan files\n"); exit(1); }';
        $script = 'set -e; cd /srv/app; php -r ' . escapeshellarg($write) . '; git add PLAN.md; git add -f .aibuilder/blueprint.json; '
                . 'if git diff --cached --quiet; then echo "PLAN.md already committed"; else ' . $git . ' commit -q -m ' . escapeshellarg($msg) . '; git log -1 --format=%h; fi';
        [$c, $o] = TenantHost::ssh($inst, 'app', $script . ' 2>&1', json_encode(['plan' => (string) $h->planMd, 'blueprint' => (string) $h->blueprintJson]), 120);
        if ($c !== 0) throw new \RuntimeException(trim((string) $o));
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

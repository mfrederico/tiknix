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
        $plan = (string) ($in['plan_md'] ?? '');
        if (trim($plan) === '' || strlen($plan) > self::PLAN_MAX) throw new \InvalidArgumentException('handoff: plan_md is required (≤ ' . self::PLAN_MAX . ' bytes)');
        $blueprint = (string) ($in['blueprint_json'] ?? '');
        if ($blueprint === '' || strlen($blueprint) > self::JSON_MAX || !is_array(json_decode($blueprint, true))) {
            throw new \InvalidArgumentException('handoff: blueprint_json must be a JSON object (≤ ' . self::JSON_MAX . ' bytes)');
        }
        $brief = (string) ($in['brief_json'] ?? '');
        if ($brief !== '' && (strlen($brief) > self::JSON_MAX || !is_array(json_decode($brief, true)))) {
            throw new \InvalidArgumentException('handoff: brief_json, when given, must be a JSON object');
        }
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
    public static function create(int $memberId, \RedBeanPHP\OODBBean $h, string $name, string $engine): array {
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
        return ['ok' => true, 'slug' => (string) $inst->slug, 'id' => (int) $inst->id,
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

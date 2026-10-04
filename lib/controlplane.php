<?php
/**
 * controlplane.php — wires the CONTROL PLANE's knowledge into the runtime's extension points
 * (RUNTIME-SPLIT-MAP.md step 2). Loaded by core's composer.json "files" (never by an app
 * built on the runtime): the runtime names a slot, the control plane fills it here, and no
 * runtime file ever names a control-plane class.
 */

// AgentState: adopt a Claude login from another project of the same member.
// Read with a plain PDO against core's registry on purpose — called from CLIs whose RedBean
// connection points at an instance's tasks db (see AgentState::adoptableFrom).
\app\AgentState::$otherProjectDirs = static function (int $memberId): array {
    $dirs = [];
    try {
        $pdo = new \PDO('sqlite:' . \app\Paths::root() . '/database/tiknix.db');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $st = $pdo->prepare('SELECT slug, app FROM instance WHERE member_id = ?');
        $st->execute([$memberId]);
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $dirs[] = \Model_Instance::dirForSlug((string) $row['slug'], (string) ($row['app'] ?: 'tiknix'));
        }
    } catch (\Throwable $e) {
        // "Nothing to adopt" and "could not look" are different answers; this one means the
        // member will be asked to /login again, so say why.
        error_log('ERROR AgentState: could not read the instance registry to adopt an existing login (' . \app\Paths::root() . '/database/tiknix.db): ' . $e->getMessage());
    }
    return $dirs;
};

// ProjectTarget: on the control plane, "the project these pages act on" is the member's
// SELECTED project (ProjectContext), null when none is chosen.
\app\ProjectTarget::$resolver = static function (int $memberId): ?array {
    $inst = \app\ProjectContext::current($memberId);
    if ($inst === null) return null;
    $m = $inst->box();
    return [
        'id'   => (int) $inst->id,
        'slug' => (string) $inst->slug,
        'name' => (string) ($inst->displayName ?? '') !== '' ? (string) $inst->displayName : (string) $inst->slug,
        'dir'  => $m->dir(),
        'url'  => $m->url(),
        'here' => false,
    ];
};

// Member::closeaccount — deprovision every project the member owns (reports, never aborts).
\app\Member::$onClose = static fn(int $memberId): array => (new \app\ProvisionService())->deleteAllForMember($memberId);

// Dashboard — the plan/billing tile (project count against the tier's cap).
\app\Dashboard::$billingCard = static fn(int $memberId): ?array => \app\ProjectQuota::snapshot($memberId);

// Contact — on the platform a signed-in member's "contact" is the support desk (Helpdesk).
\app\Contact::$memberDesk = '/helpdesk';

// Mcp — the gateway's X-Tiknix-Project scope, and the broker's read of an instance's connection.
\app\Mcp::$projectScope = static function (string $slug, int $memberId, bool $withApiKey): array {
        if (!$withApiKey || $memberId <= 0) throw new \RuntimeException('a project scope needs an API-key caller.');
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/D', $slug)) throw new \RuntimeException("'{$slug}' is not a project slug.");
        $inst = \app\Bean::findOne('instance', 'slug = ? AND status = ?', [$slug, 'active']);
        if (!$inst || !$inst->id) throw new \RuntimeException("no active project '{$slug}'.");
        if (!\app\ProjectContext::canAccess($memberId, $inst)) throw new \RuntimeException("member {$memberId} cannot access project '{$slug}'.");
        $dir = $inst->box()->dir();
        if (!is_dir($dir)) throw new \RuntimeException("project '{$slug}' has no directory at {$dir}.");
        return ['id' => (int) $inst->id, 'slug' => $slug, 'dir' => $dir];
    };
\app\Mcp::$brokerConnection = static fn(int $instanceId, string $type, string $env, ?string $account) => \app\InstanceConnections::forInstall($instanceId, $type, $env, $account);

// Leads — the lead list shows each address's sign-up invitation state and offers an invite.
\app\Leads::$inviteStates = static fn(): array => \app\Invites::leadStates();

// Auth — after login, a Get-started plan waiting to be claimed; card-on-file sign-up.
\app\Auth::$afterLogin = static fn(): ?string => \app\PlanHandoff::afterLoginTarget();
\app\Auth::$registerVia = static function (string $email, string $password, string $first, string $last, string $username): ?array {
    if (!\app\SignupFlow::enabled()) return null;
    $started = \app\SignupFlow::start($email, $password, $first, $last, $username);
    if (!$started['ok']) return ['error' => $started['error']];
    // The token identifies which signup came back; it authorises nothing on its own,
    // because completion still turns on what the billing service says.
    return ['redirect' => '/signup/complete?token=' . urlencode($started['token'])];
};

// Google sign-in — a new member is a billing subject like any other: register their tenant.
\app\plugins\GoogleAuth::$onNewMember = static fn(int $memberId) => \app\SignupFlow::ensureTenantFor($memberId);

// Tiknix's own agents (the AI agents page here, ROOT only — seed 34) run Tiknix's own tooling:
// QA Testing, and pipelines of this install. Their keys are the platform's money, so no builder
// verb may ever run on them — a task, plan, audit, terminal or merge asked of this install is
// refused before a credential is looked at. Projects build in their containers, on their agents.
\app\AgentTask::$platformOnly = "tiknix.com's agents are for Tiknix's own tooling and never build a project: "
    . 'a project is planned and built in its own container, on the agents of its own AI agents page.';

// Admin — quotas, free-project grants, plan tiers, billing tenant/lifecycle on the member screens.
\app\Admin::$memberExtension = new \app\PlatformMemberAdmin();

// The page shell — projects, the project bar, the sidebar's project panel, Teams and Billing.
// The platform's pages beside the app (Chrome::$links), its shell, and its own parts of the
// dashboard and footer — what the runtime used to decide with is_core_install().
\app\Chrome::$links = [
    'projects'   => '/projects',
    'docs'       => 'https://docs.tiknix.com',
    'help'       => '/help',
    'fleet'      => '/fleet',
    'reports'    => '/projectreport',   // what every hosted app reports about itself
];
\app\Chrome::$omit = ['/connections', '/integrations'];   // the project panel offers them (chrome_navtop)
// The sidebar shows two things' pages: the selected project's (the panel) and Tiknix's own. The
// menu and the admin group say they are Tiknix's, or "Data" and "AI agents" read as the project's.
\app\Chrome::$menuHeading = 'Tiknix';
\app\Chrome::$adminHeading = 'Tiknix admin';
\app\Chrome::$copyright = 'ClickSimple LLC';
\app\Chrome::$mark = true;
\app\Member::$builderPrefs = true;
\app\Chrome::add('navTop', 'platform/chrome_navtop');
foreach (['prepare', 'bar', 'account', 'footer', 'dashboard'] as $__slot) \app\Chrome::add($__slot, "platform/chrome_{$__slot}");
unset($__slot);

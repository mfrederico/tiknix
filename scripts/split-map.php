<?php
// Runtime/control-plane split map for tiknix core. Read-only analysis.
$root = dirname(__DIR__);
chdir($root);
$files = [];
$add = function (string $glob) use (&$files) { foreach (glob($glob) ?: [] as $f) $files[] = $f; };
foreach (['controls/*.php', 'controls/BaseControls/*.php', 'models/*.php', 'mcptools/*.php', 'mcptools/workbench/*.php', 'lib/*.php', 'lib/Pipeline/*.php', 'lib/Pipeline/Steps/*.php', 'lib/Publish/*.php', 'lib/plugins/*.php', 'lib/Scaffold/*.php', 'lib/Scaffold/Commands/*.php', 'lib/Scaffold/Generators/*.php', 'services/*.php', 'services/*/*.php', 'services/Schema/Seeds/*.php', 'scripts/*.php'] as $g) $add($g);
$files = array_values(array_unique($files));

// ---- anchors: what is control-plane by PURPOSE ----
$cpClasses = ['PlanExecutor','PlanHandoff','PlanIngestor','PlanNotifier','PlanOrchestrator','PlanRemediator','PlanRunner','AuditReporter','AuditRunner',
  'ProvisionService','ProxmoxDeploy','ProxmoxService','HostedDeploy','GitHttp','InstanceRepo','ConnectorPush','BillingLifecycle','ProjectQuota','ProjectContext',
  'ProjectTarget','TaskAccessControl','WorkspaceManager','PortManager','TmuxManager','ClaudeRunner','PromptBuilder','PromptQueue','PromptLog','SignupFlow','Invite',
  'EngineRegistry','MemberEnginePrefs','AgentLimit','CoreDb','IsolatedPool','Model_Instance','Model_Workbenchtask','Model_Taskcomment','Model_Tasklog',
  'Model_Tasksnapshot','Model_Showcase','GitHubPublisher','PublishRegistry','PublishDriver','GithubPrDriver','RsyncDriver','SshDriver','SshTargetDriver','TiknixHostedDriver',
  'Snapshot','SshKey','ClaudeBinary','AgentState','AgentContext','BrokerService','ConceptLint'];
$cpTables = ['instance','instance_team','instanceaudit','workbenchtask','workbenchtaskcomment','workbenchtasklog','taskcomment','tasklog','tasksnapshot','plan','planhandoff',
  'promptlog','detectederror','invite','pendingsignup','showcase','shopsubscription','socialpage','modelconnection','modelcall','agent'];
$cpControllers = ['About','Agentsetup','Brokerinfo','Concepthub','Firehose','Git','Handoff','Invites','Neosaas','Pricing','Provision','Publish','Sidecar','Social','Projects','Stories','Billing'];

// ---- index classes ----
$defs = []; $info = [];
foreach ($files as $f) {
    $src = file_get_contents($f);
    $toks = @token_get_all($src);
    $ns = ''; $classes = [];
    for ($i = 0; $i < count($toks); $i++) {
        $t = $toks[$i];
        if (!is_array($t)) continue;
        if ($t[0] === T_NAMESPACE) { $j = $i + 1; $n = ''; while (isset($toks[$j]) && $toks[$j] !== ';' && $toks[$j] !== '{') { if (is_array($toks[$j]) && in_array($toks[$j][0], [T_STRING, T_NAME_QUALIFIED], true)) $n .= $toks[$j][1]; $j++; } $ns = $n; }
        if (in_array($t[0], [T_CLASS, T_TRAIT, T_INTERFACE], true)) {
            $prev = $toks[$i - 1] ?? null; $pp = $toks[$i - 2] ?? null;
            if ((is_array($prev) && $prev[0] === T_DOUBLE_COLON) || (is_array($pp) && $pp[0] === T_NEW)) continue;
            $j = $i + 1; while (isset($toks[$j]) && is_array($toks[$j]) && $toks[$j][0] === T_WHITESPACE) $j++;
            if (isset($toks[$j]) && is_array($toks[$j]) && $toks[$j][0] === T_STRING) $classes[] = $toks[$j][1];
        }
    }
    $info[$f] = ['ns' => $ns, 'classes' => $classes, 'src' => $src, 'toks' => $toks];
    foreach ($classes as $c) $defs[$c][] = $f;
}
$known = array_keys($defs);

$out = [];
foreach ($info as $f => $d) {
    $refs = [];
    foreach ($d['toks'] as $t) {
        if (!is_array($t)) continue;
        if (in_array($t[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
            $last = substr(strrchr('\\' . $t[1], '\\'), 1);
            if (isset($defs[$last]) && !in_array($last, $d['classes'], true)) $refs[$last] = true;
        }
    }
    $refs = array_keys($refs);
    preg_match_all("/(?:Bean|R)::(?:find|findOne|findAll|load|dispense|count|trash|findOneOrFail)\(\s*'(\w+)'/", $d['src'], $m1);
    preg_match_all('/\b(?:FROM|JOIN|INTO|UPDATE)\s+`?(\w+)`?/i', $d['src'], $m2);
    $tables = array_values(array_unique(array_map('strtolower', array_merge($m1[1], $m2[1]))));
    $cpT = array_values(array_intersect($tables, $cpTables));
    $dual = (bool) preg_match('/\b(builder_tools_enabled|is_control_plane|is_core_install)\s*\(/', $d['src']);
    $selfCp = (bool) array_intersect($d['classes'], $cpClasses) || (str_starts_with($f, 'controls/') && in_array(basename($f, '.php'), $cpControllers, true));
    $cpRefs = array_values(array_intersect($refs, $cpClasses));
    $out[$f] = ['classes' => $d['classes'], 'refs' => $refs, 'cpRefs' => $cpRefs, 'cpTables' => $cpT, 'dual' => $dual, 'selfCp' => $selfCp, 'lines' => substr_count($d['src'], "\n")];
}
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

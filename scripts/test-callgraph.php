<?php
/**
 * test-callgraph.php — acceptance tests for mcptools/CallGraph against THIS repo.
 *
 * Asserts the golden-fixture edges from explorer.tiknix/CALLGRAPH-DESIGN.md §8.
 * Line numbers drift as the repo moves, so we assert the SEMANTIC relationships
 * (from→to→kind→confidence) and node classifications, not exact evidence lines.
 *
 *   php scripts/test-callgraph.php
 */

require_once __DIR__ . '/../bootstrap.php';
new app\Bootstrap('conf/config.ini');

use app\mcptools\Introspector;
use app\mcptools\CallGraph;

$root = dirname(__DIR__);
$t0 = microtime(true);
$g = (new CallGraph(new Introspector($root)))->build();
$ms = round((microtime(true) - $t0) * 1000, 1);

$nodes = [];
foreach ($g['nodes'] as $n) $nodes[$n['id']] = $n;
$edges = $g['edges'];

// ---- assertion helpers -----------------------------------------------------
$pass = 0; $fail = 0; $fails = [];
function ok(&$pass, &$fail, &$fails, string $label, bool $cond) {
    if ($cond) { $pass++; echo "  PASS  $label\n"; }
    else { $fail++; $fails[] = $label; echo "  FAIL  $label\n"; }
}
$hasEdge = function (string $from, string $to, ?string $kind = null, ?string $conf = null) use ($edges): bool {
    foreach ($edges as $e) {
        if ($e['from'] !== $from || $e['to'] !== $to) continue;
        if ($kind !== null && $e['kind'] !== $kind) continue;
        if ($conf !== null && $e['confidence'] !== $conf) continue;
        return true;
    }
    return false;
};
$edgesTo = function (string $to, ?string $kind = null) use ($edges): array {
    return array_values(array_filter($edges, fn($e) => $e['to'] === $to && ($kind === null || $e['kind'] === $kind)));
};
$node = fn(string $id) => $nodes[$id] ?? null;

echo "CallGraph built: {$g['meta']['nodeCount']} nodes, {$g['meta']['edgeCount']} edges, "
   . "{$g['meta']['broken']} broken, {$g['meta']['dynamicSites']} dynamic, "
   . count($g['meta']['orphans']) . " orphans — {$ms}ms\n\n";

// ---- §8 golden fixtures (semantic form) ------------------------------------

// 3-4: render edges
ok($pass,$fail,$fails, "connections::setup renders views/connections/setup.php [exact]",
   $hasEdge('route:connections::setup','view:views/connections/setup.php','renders','exact'));
ok($pass,$fail,$fails, "connections::index renders views/connections/index.php [exact]",
   $hasEdge('route:connections::index','view:views/connections/index.php','renders','exact'));

// 5: status is json-shaped, no render edge
$status = $node('route:connections::status');
ok($pass,$fail,$fails, "connections::status shape=json",
   $status && ($status['shape'] ?? '') === 'json');
ok($pass,$fail,$fails, "connections::status has no render edge",
   !array_filter($edges, fn($e)=>$e['from']==='route:connections::status' && $e['kind']==='renders'));

// 6-8: view→route edges
ok($pass,$fail,$fails, "views/connections/setup.php view-call connections::repos [exact]",
   $hasEdge('view:views/connections/setup.php','route:connections::repos','view-call','exact'));
ok($pass,$fail,$fails, "views/teams/members.php view-call teams::updaterole [exact]",
   $hasEdge('view:views/teams/members.php','route:teams::updaterole','view-call','exact'));
ok($pass,$fail,$fails, "views/layouts/_notify-bell.php view-call communications::unreadjson [exact]",
   $hasEdge('view:views/layouts/_notify-bell.php','route:communications::unreadjson','view-call','exact'));

// 9: write edge via dispense
ok($pass,$fail,$fails, "connections::add writes bean:connections [exact]",
   $hasEdge('route:connections::add','bean:connections','writes','exact'));

// 10: store($var) inferred write
ok($pass,$fail,$fails, "connections::test writes bean:connections [inferred] (store(\$var) inference)",
   $hasEdge('route:connections::test','bean:connections','writes','inferred'));

// 11-13: script-only classification
$rep = $node('libmethod:AuditReporter::report');
ok($pass,$fail,$fails, "AuditReporter::report reach=script-only",
   $rep && ($rep['reach'] ?? '') === 'script-only');
$pc = $node('libmethod:PermissionCache::clear');
ok($pass,$fail,$fails, "PermissionCache::clear NOT script-only (mixed callers)",
   $pc && ($pc['reach'] ?? '') !== 'script-only' && ($pc['reach'] ?? '') !== 'orphan');

// 14: broken hyphenated link with suggestion
$broken = null;
foreach ($nodes as $id => $nn) {
    if (($nn['kind'] ?? '') === 'broken-link' && strpos($id, 'agent-setup') !== false) { $broken = $nn; break; }
}
ok($pass,$fail,$fails, "broken-link node for /agent-setup/* with a suggestion",
   $broken && !empty($broken['suggest']) && strpos($broken['suggest'],'agentsetup') === 0);

// 16: missing-view node
$missing = array_filter($nodes, fn($n)=>($n['kind']??'')==='missing-view');
ok($pass,$fail,$fails, "at least one missing-view node exists (render target absent)",
   count($missing) > 0);

// 17: dynamic-redirect edge somewhere from connections
ok($pass,$fail,$fails, "a dynamic-redirect edge exists (Flight::redirect(\$var))",
   (bool)array_filter($edges, fn($e)=>$e['kind']==='dynamic-redirect'));

// 18: /shop/<x> resolves to shop::_fallback [inferred]
$shopFb = $node('route:shop::_fallback');
ok($pass,$fail,$fails, "shop::_fallback route node exists (hasFallback controller)",
   $shopFb !== null || isset($nodes['route:shop::_fallback']));

// 19: custom route node for /mcp/message
$mcpCustom = array_filter($nodes, fn($n)=>($n['kind']??'')==='custom-route' && strpos($n['label']??'','/mcp/message')!==false);
ok($pass,$fail,$fails, "custom-route node for /mcp/message exists",
   count($mcpCustom) > 0);

// 21: workbench::view has multiple inbound view-call edges
$wbView = $edgesTo('route:workbench::view','view-call');
ok($pass,$fail,$fails, "workbench::view has >=3 inbound view-call edges (got ".count($wbView).")",
   count($wbView) >= 3);

// 22: a cascade rel-own edge to a bean via xown...List
ok($pass,$fail,$fails, "a rel-own cascade edge exists (xown...List)",
   (bool)array_filter($edges, fn($e)=>$e['kind']==='rel-own' && !empty($e['cascade'])));

// span sanity: at least one exact render + span attribution worked at all
ok($pass,$fail,$fails, "graph has route nodes",
   (bool)array_filter($nodes, fn($n)=>($n['kind']??'')==='route'));

echo "\n==== $pass passed, $fail failed ====\n";
if ($fail) { echo "FAILURES:\n"; foreach ($fails as $f) echo "  - $f\n"; exit(1); }
echo "ALL GREEN — {$ms}ms build\n";

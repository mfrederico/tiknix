<?php
/**
 * Prints (engine, model, stateDir) for every runner that emits a launch script.
 *
 * Read from the GENERATED BASH, not from re-implemented logic — the point is to observe
 * what each runner actually emits, so a refactor can be proved to change nothing. A probe
 * that recomputed the values would only prove the probe agrees with itself.
 *
 * Usage: php tests/agent/resolve-probe.php > baseline.txt
 */
require '/var/www/html/default/tiknix/vendor/autoload.php';
$cfg = parse_ini_file('/var/www/html/default/tiknix/conf/config.ini', true);
foreach ($cfg as $sec => $vals) foreach ((array)$vals as $k => $v) Flight::set("$sec.$k", $v);

$INST = '/var/www/html/default/partsdna-74a225.tiknix';
$WS   = '/var/www/html/default/tiknix/projects/1/partsdna-74a225.tiknix/110';

/** Pull the three observable values out of a generated script. */
function facts(string $script): array {
    $g = function (string $re) use ($script) {
        return preg_match($re, $script, $m) ? $m[1] : '-';
    };
    return [
        'engine'   => $g("/ENGINE='([^']*)'/"),
        'model'    => $g("/--model '([^']*)'/"),
        'stateDir' => $g("/TIKNIX_AGENT_STATE='([^']*)'/"),
    ];
}

function show(string $label, array $f): void {
    printf("%-34s engine=%-8s model=%-22s state=%s\n", $label, $f['engine'], $f['model'], $f['stateDir']);
}

function scriptOf(object $o, string $method, array $args = []): string {
    $m = new ReflectionMethod($o, $method); $m->setAccessible(true);
    return (string) $m->invoke($o, ...$args);
}

// --- PlanRunner: engine from the caller, planner tier -----------------------
foreach ([['zai', 1], ['claude', 1], ['', 1], ['zai', 12]] as [$eng, $mid]) {
    $r = new \app\PlanRunner('partsdna-74a225', $INST, $mid, 50, $eng);
    show(sprintf('PlanRunner(eng=%s,m=%d)', $eng ?: 'none', $mid), facts(scriptOf($r, 'buildRunnerScript')));
}

// --- AuditRunner: engine from the instance file, auditor tier ---------------
foreach ([1, 12] as $mid) {
    $a = new \app\AuditRunner('partsdna-74a225', $INST, 'https://x', $mid, 50);
    show("AuditRunner(m={$mid})", facts(scriptOf($a, 'buildRunnerScript')));
}

// --- ClaudeRunner: engine from the task row, model from the task ------------
putenv('TIKNIX_WORKBENCH_DB=' . $INST . '/data/workbench.db');
\RedBeanPHP\R::setup('sqlite:' . $INST . '/data/workbench.db');
foreach ([110, 96] as $tid) {
    $c = new \app\ClaudeRunner($tid, 1, null, $WS, 50);
    show("ClaudeRunner(task={$tid})", facts(scriptOf($c, 'buildInvocationScript', ['claude --debug', $WS, '/home/ubuntu/capricorn/bin/jail-run.sh'])));
}

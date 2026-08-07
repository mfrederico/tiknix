#!/usr/bin/env php
<?php
/**
 * Phase 0 of the Model_Instance consolidation: a record of what the CURRENT code answers,
 * so a later phase can prove it changed nothing.
 *
 * Instance logic is spread across ProvisionService, TaskAccessControl, ProjectContext,
 * GitHubPublisher and Integrations, with two independent implementations of "may this
 * member touch this instance" and four of "where does it live". Those answers govern
 * whose code an agent may edit and which directory gets archived, so this is the one part
 * of the sweep where "it looked fine afterwards" is not good enough.
 *
 *   php scripts/instance-oracle.php --save      # record the current answers
 *   php scripts/instance-oracle.php --check     # compare now against the record
 *
 * --check exits non-zero on any difference, so it can gate a commit.
 *
 * Deliberately calls the ORIGINAL implementations by reflection rather than reimplementing
 * them: a hand-copied oracle only proves the copy agrees with itself.
 */

if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line\n");
}

require_once __DIR__ . '/../bootstrap.php';
new app\Bootstrap('conf/config.ini');

use app\Bean;

use \RedBeanPHP\R;
use \app\TaskAccessControl;
use \app\ProvisionService;

$file = __DIR__ . '/../data/instance-oracle.json';
$save = in_array('--save', $argv, true);
$check = in_array('--check', $argv, true);
if (!$save && !$check) {
    echo "Usage: instance-oracle.php --save | --check\n";
    exit(1);
}

/** Call a private/protected method on an object, to test the real thing rather than a copy. */
$call = function (object $obj, string $method, array $args) {
    $m = new ReflectionMethod($obj, $method);
    $m->setAccessible(true);
    return $m->invokeArgs($obj, $args);
};

$tac  = new TaskAccessControl();
$prov = new ProvisionService();

$instances = array_map('intval', Bean::getCol('SELECT id FROM instance ORDER BY id'));
$members   = array_map('intval', Bean::getCol('SELECT id FROM member ORDER BY id'));
$slugs     = array_map('strval', Bean::getCol('SELECT slug FROM instance ORDER BY id'));

$snapshot = [
    'taken_at'  => date('c'),
    'instances' => count($instances),
    'members'   => count($members),
    'access'    => [],
    'paths'     => [],
    'sets'      => [],
];

// --- access control: every (member, instance) pair, through BOTH implementations -------
foreach ($members as $m) {
    foreach ($instances as $i) {
        $snapshot['access']["$m:$i"] = [
            'tac_owns'   => $tac->ownsInstance($m, $i),
            'tac_access' => $tac->canAccessInstance($m, $i),
            'ps_owns'    => $call($prov, 'ownsInstance', [$m, $i]),
            'ps_access'  => $call($prov, 'canAccessInstance', [$m, $i]),
        ];
    }
}

// --- paths: every implementation, per instance ----------------------------------------
foreach (Bean::findAll('instance', 'ORDER BY id') as $inst) {
    $slug = (string) $inst->slug;
    $snapshot['paths'][$slug] = [
        'model_dir'  => $inst->dir(),
        'prov_dir'   => $call($prov, 'instanceDir', [$slug]),
        'model_url'  => $inst->url(),
        'provisioned'=> $inst->isProvisioned(),
        // The real filesystem, not just the string — a path that agrees but points at
        // nothing is still wrong.
        'is_dir'     => is_dir($inst->dir()),
    ];
}

// --- id sets: the list-shaped answers -------------------------------------------------
foreach ($members as $m) {
    $snapshot['sets']["$m"] = [
        'accessible' => array_values($tac->getAccessibleInstanceIds($m)),
        'shared'     => array_values($tac->getSharedInstanceIds($m)),
        // NOT ProjectContext::current(): that is live user state, not behaviour. It
        // changes the moment anyone clicks a project — it moved 14 -> 17 -> 62 during
        // one test run — so recording it made the oracle cry wolf about a session
        // write. An oracle that reports differences nobody caused gets ignored, which
        // is worse than not having one.
        'accessible_count' => count($tac->getAccessibleInstanceIds($m)),
    ];
}

if ($save) {
    @mkdir(dirname($file), 0775, true);
    file_put_contents($file, json_encode($snapshot, JSON_PRETTY_PRINT));
    printf("Recorded %d access pairs, %d paths, %d member sets\n  -> %s\n",
        count($snapshot['access']), count($snapshot['paths']), count($snapshot['sets']), $file);
    exit(0);
}

// --- check ----------------------------------------------------------------------------
if (!is_file($file)) {
    echo "No oracle recorded yet. Run --save first.\n";
    exit(1);
}
$was = json_decode(file_get_contents($file), true);

$diffs = [];
foreach (['access', 'paths', 'sets'] as $section) {
    foreach ($snapshot[$section] as $key => $now) {
        $before = $was[$section][$key] ?? null;
        if ($before === null) { $diffs[] = "$section/$key: NEW (was not recorded)"; continue; }
        foreach ($now as $field => $value) {
            $old = $before[$field] ?? null;
            if ($old !== $value) {
                $diffs[] = sprintf('%s/%s %s: %s -> %s', $section, $key, $field,
                    json_encode($old), json_encode($value));
            }
        }
    }
    foreach (array_keys($was[$section] ?? []) as $key) {
        if (!isset($snapshot[$section][$key])) $diffs[] = "$section/$key: GONE";
    }
}

printf("Recorded %s · %d access pairs, %d paths, %d member sets\n",
    $was['taken_at'] ?? '?', count($was['access'] ?? []), count($was['paths'] ?? []), count($was['sets'] ?? []));

if (!$diffs) {
    echo "\nNo differences. Behaviour is unchanged.\n";
    exit(0);
}
echo "\n" . count($diffs) . " DIFFERENCE(S):\n";
foreach (array_slice($diffs, 0, 40) as $d) echo "  $d\n";
if (count($diffs) > 40) printf("  ... and %d more\n", count($diffs) - 40);
echo "\nEvery one is either an intended change you can explain, or a regression.\n";
exit(1);

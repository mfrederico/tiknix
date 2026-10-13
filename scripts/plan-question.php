<?php
/**
 * plan-question.php — the planner asked its person a question instead of planning
 * (.aibuilder/plan-question.md, written by TenantBuilder::unpackPlan). Put it to them: the
 * message it was planning waits on it in the Builder's conversation (app\AskTriage::asks).
 *
 *   php scripts/plan-question.php --dir=<the project's builder folder> --prompt=N
 */
if (php_sapi_name() !== 'cli') { die("cli only\n"); }
require __DIR__ . '/../vendor/autoload.php';

use RedBeanPHP\R;
use app\Bean;

$o = getopt('', ['dir:', 'prompt::']);
$file = rtrim((string) ($o['dir'] ?? ''), '/') . '/.aibuilder/plan-question.md';
$id = (int) ($o['prompt'] ?? 0);
$q = is_file($file) ? trim((string) file_get_contents($file)) : '';
if ($q === '') { fwrite(STDERR, "[plan-question] ERROR no question in {$file}\n"); exit(1); }
if ($id <= 0) { echo "[plan-question] ERROR the planner asked a question, but this run names no prompt to put it on — it is in {$file}: {$q}\n"; exit(1); }

R::setup('sqlite:' . dirname(__DIR__) . '/database/tiknix.db');
Bean::freeze(false);
if (!R::testConnection()) { fwrite(STDERR, "cannot open core db\n"); exit(1); }
\app\AskTriage::asks($id, $q);
unlink($file);
echo "[plan-question] prompt #{$id} waits on its person: " . mb_substr(str_replace("\n", ' ', $q), 0, 200) . "\n";
R::close();

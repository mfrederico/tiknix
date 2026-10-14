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
// …and its person is told, by email too: a reply is the answer (app\BuilderMail).
try {
    $pl = Bean::load('promptlog', $id);
    $ptag = (string) ($pl->instanceTag ?? '');
    echo '[plan-question] notify: ' . \app\PlanNotifier::planQuestion(dirname(__DIR__) . '/database/tiknix.db', $id, (int) $pl->memberId, (string) (strstr($ptag, '.', true) ?: $ptag),
        (string) (\app\AskStore::get(\app\AskTriage::dirOfTag($ptag), $id)['said'] ?? $pl->title), $q) . "\n";
} catch (\Throwable $e) { echo '[plan-question] ERROR its person was not told of the question: ' . $e->getMessage() . "\n"; }
// The app is told (question.asked), for its own pipelines: someone may want to hear of it elsewhere.
try {
    $tag = (string) (Bean::load('promptlog', $id)->instanceTag ?? '');
    $ct = \app\TenantBuilder::bySlug((string) (strstr($tag, '.', true) ?: $tag));
    if ($ct) \app\TenantHost::builderEvent($ct, 'question.asked', ['by' => 'planner', 'question' => $q, 'asked' => (string) (\app\AskStore::get(\app\AskTriage::dirOfTag($tag), $id)['said'] ?? '')]);
} catch (\Throwable $e) { echo '[plan-question] ERROR the app was not told of the question: ' . $e->getMessage() . "\n"; }
echo "[plan-question] prompt #{$id} waits on its person: " . mb_substr(str_replace("\n", ' ', $q), 0, 200) . "\n";
R::close();

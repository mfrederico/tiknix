<?php
/**
 * ask-triage.php — read one message typed into Ask and act on it (app\AskTriage::run):
 * answer it, ask which was meant, or send it to be built. Started detached by the Builder when
 * the message is sent, so the page does not wait on the agent.
 *
 *   php scripts/ask-triage.php --prompt=N [--mode=auto|answer|build]
 */
if (php_sapi_name() !== 'cli') { die("cli only\n"); }
require __DIR__ . '/../vendor/autoload.php';

use RedBeanPHP\R;
use app\Bean;

$o = getopt('', ['prompt:', 'mode::']);
$id = (int) ($o['prompt'] ?? 0);
$mode = (string) ($o['mode'] ?? 'auto');
if ($id <= 0 || !in_array($mode, ['auto', 'answer', 'build'], true)) { fwrite(STDERR, "usage: ask-triage.php --prompt=N [--mode=auto|answer|build]\n"); exit(2); }

// Core's registry is this script's default connection, as for the prompt queue.
R::setup('sqlite:' . dirname(__DIR__) . '/database/tiknix.db');
Bean::freeze(false);
if (!R::testConnection()) { fwrite(STDERR, "cannot open core db\n"); exit(1); }

echo '[' . date('c') . '] ask ' . \app\AskTriage::run($id, $mode) . "\n";
R::close();

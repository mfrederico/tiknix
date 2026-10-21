<?php
use RedBeanPHP\R;
/**
 * 55_AppWatch.php — what the app watcher (app\AppWatch, scripts/app-watch.php) remembers about each app
 * between runs: whether it is well, since when it is not, whom it has told, and how often it has
 * started it again. One row per app (instance_ref); declared, every column TEXT or INTEGER from the start.
 */
R::exec('CREATE TABLE IF NOT EXISTS appwatch (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    instance_ref INTEGER, state TEXT, since TEXT, told_at TEXT,
    restarts TEXT, note TEXT, updated_at TEXT
)');
R::exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_appwatch_instance ON appwatch(instance_ref)');
echo "  appwatch: declared\n";

<?php
use RedBeanPHP\R;
/**
 * 54_GitSync.php — a project kept in step with a GitHub repository (app\GitSync, Publish\GithubDriver).
 *
 *   deploykey      the sealed SSH deploy key the platform pushes and fetches with (one per project
 *                  per driver), and for GitHub also:
 *                    token           a fine-grained token for that one repository, sealed — opens the PRs
 *                    webhook_secret  the secret GitHub signs its push notices with, sealed
 *   deploytarget   sync_json: where the two last agreed (last pushed / pulled commit, the PRs
 *                  opened, a pull waiting for the project to be free)
 *   githook::push  PUBLIC at the route: GitHub posts here; the controller checks the signature
 *
 * Both tables were made on first use before this seed: they are declared here, every column
 * TEXT or INTEGER from the start (a later widen rebuilds a SQLite table and empties it).
 */
echo '  authcontrol: githook::push => ' . \app\PermissionCache::seedRule('githook', 'push', 101, "GitHub's push notices for a project kept in step with a repository (signature checked)") . "\n";

R::exec('CREATE TABLE IF NOT EXISTS deploykey (id INTEGER PRIMARY KEY AUTOINCREMENT, instance_ref INTEGER, driver TEXT, private_key TEXT, public_key TEXT, fingerprint TEXT, created_at TEXT)');
R::exec('CREATE TABLE IF NOT EXISTS deploytarget (id INTEGER PRIMARY KEY AUTOINCREMENT, instance_ref INTEGER, driver TEXT, label TEXT, config_json TEXT, last_run_at TEXT, last_ok INTEGER, last_message TEXT, created_at TEXT, updated_at TEXT)');
$add = [
    'deploykey'    => ['last_used_at' => 'TEXT', 'last_error' => 'TEXT', 'token' => 'TEXT', 'webhook_secret' => 'TEXT'],
    'deploytarget' => ['sync_json' => 'TEXT'],
];
foreach ($add as $table => $cols) {
    $have = R::inspect($table);
    foreach ($cols as $col => $type) {
        if (isset($have[$col])) continue;
        R::exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $col . ' ' . $type);   // names from the list above, not input
        echo "  {$table}.{$col}: added\n";
    }
}
R::exec('CREATE INDEX IF NOT EXISTS idx_deploykey_instance ON deploykey(instance_ref, driver)');
R::exec('CREATE INDEX IF NOT EXISTS idx_deploytarget_instance ON deploytarget(instance_ref)');
echo "  deploykey, deploytarget: declared\n";

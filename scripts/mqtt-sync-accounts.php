#!/usr/bin/env php
<?php
/**
 * Keep the broker's password file in step with the member table.
 *
 * Mosquitto's password file is static and root-owned, so PHP-FPM cannot add an
 * account when somebody signs up — deliberately, since a web process that can
 * mint broker accounts is a web process that can mint itself one. This runs
 * from root's crontab instead, and the delay costs a new member nothing: until
 * their account exists they simply fall back to polling.
 *
 *   * * * * * /usr/bin/php /var/www/html/default/tiknix/scripts/mqtt-sync-accounts.php --quiet
 *
 * Passwords are DERIVED (app\Mqtt::passwordFor), not stored, so this script and
 * the running application are two readings of one secret rather than two copies
 * of a value. There is nothing here that can drift out of step except the set of
 * usernames, which is exactly what this reconciles.
 *
 * Idempotent, and quiet when there is nothing to do: it adds accounts that are
 * missing and removes accounts whose member is gone, and only signals the broker
 * if something actually changed. It does NOT rewrite existing rows — mosquitto
 * salts each hash, so rewriting every member every minute would churn the file
 * and reload the broker forever. Use --rebuild after rotating [mqtt] secret,
 * which is the one case where existing rows are wrong.
 *
 * Usage:
 *   php scripts/mqtt-sync-accounts.php [--quiet] [--dry-run] [--rebuild]
 *                                      [--file=/path/to/passwd]
 */

if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line\n");
}

require_once __DIR__ . '/../bootstrap.php';
$app = new app\Bootstrap('conf/config.ini');

use app\Bean;
use app\Mqtt;

$opts     = getopt('', ['quiet', 'dry-run', 'rebuild', 'file:']);
$quiet    = isset($opts['quiet']);
$dryRun   = isset($opts['dry-run']);
$rebuild  = isset($opts['rebuild']);
$passwd   = $opts['file'] ?? '/etc/mosquitto/tiknix.passwd';

/** The publisher is not a member and must survive every reconciliation. */
const SERVICE_ACCOUNTS = ['tnx-pub'];

function say(string $s): void { global $quiet; if (!$quiet) echo $s . "\n"; }
function fail(string $s): void { fwrite(STDERR, "mqtt-sync: $s\n"); exit(1); }

// ---- preconditions ------------------------------------------------------------
// Checked loudly and separately: "it silently did nothing" is the failure mode a
// cron job is most likely to hide, and each of these has a different fix.

if (!Flight::get('mqtt.enabled')) {
    say('[mqtt] enabled is false — nothing to sync.');
    exit(0);
}
if (trim((string) Flight::get('mqtt.secret')) === '') {
    fail('[mqtt] secret is empty in conf/config.ini — cannot derive credentials.');
}
if (!is_file($passwd)) {
    fail("password file not found: $passwd\n"
       . "         Run capricorn's configure/install-mosquitto.sh first.");
}
if (!$dryRun && !is_writable($passwd)) {
    fail("cannot write $passwd (run as root)");
}

$mosquittoPasswd = trim((string) shell_exec('command -v mosquitto_passwd 2>/dev/null'));
if ($mosquittoPasswd === '') {
    fail('mosquitto_passwd not found — install the mosquitto-clients package.');
}

// ---- what the broker has ------------------------------------------------------
$existing = [];
foreach (file($passwd, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $name = strstr($line, ':', true);
    if ($name !== false && $name !== '') $existing[$name] = true;
}

// ---- what the member table says it should have --------------------------------
// Active members only. A suspended or deleted account keeping a live channel open
// would be the one door that stayed unlocked after the others were shut.
$wanted = [];
foreach (Bean::getCol("SELECT id FROM member WHERE status = 'active'") as $id) {
    $id = (int) $id;
    if ($id > 0) $wanted[Mqtt::usernameFor($id)] = $id;
}

if (!$wanted) fail('no active members found — refusing to empty the password file.');

// ---- reconcile ----------------------------------------------------------------
$toAdd = $rebuild
    ? $wanted
    : array_diff_key($wanted, $existing);

$toRemove = array_diff_key($existing, $wanted, array_flip(SERVICE_ACCOUNTS));

if (!$toAdd && !$toRemove) {
    say('In step: ' . count($wanted) . ' member accounts, nothing to do.');
    exit(0);
}

say(sprintf('%s%d to add, %d to remove (%d active members)',
    $dryRun ? '[dry run] ' : '', count($toAdd), count($toRemove), count($wanted)));

if ($dryRun) {
    foreach (array_keys($toAdd) as $u)    say("  + $u");
    foreach (array_keys($toRemove) as $u) say("  - $u");
    exit(0);
}

$changed = 0;

foreach ($toAdd as $username => $memberId) {
    // -b takes the password as an argument, and WITHOUT -c it appends to the
    // existing file. Passing -c here would replace the file with a single row and
    // silently cut off everybody, including tnx-pub.
    $cmd = escapeshellcmd($mosquittoPasswd) . ' -b '
         . escapeshellarg($passwd) . ' '
         . escapeshellarg($username) . ' '
         . escapeshellarg(Mqtt::passwordFor($memberId)) . ' 2>&1';

    // exec() APPENDS to its output array rather than replacing it, so without
    // this every account's message carries all the previous ones and the last
    // line of the run is an unreadable pile. Reset before each call.
    $out = [];
    exec($cmd, $out, $rc);
    if ($rc !== 0) {
        fwrite(STDERR, "mqtt-sync: failed to add '$username': " . implode(' ', $out) . "\n");
        continue;
    }
    $changed++;
    say("  + $username");
}

foreach (array_keys($toRemove) as $username) {
    $cmd = escapeshellcmd($mosquittoPasswd) . ' -D '
         . escapeshellarg($passwd) . ' ' . escapeshellarg($username) . ' 2>&1';

    $out = [];
    exec($cmd, $out, $rc);
    if ($rc !== 0) {
        fwrite(STDERR, "mqtt-sync: failed to remove '$username': " . implode(' ', $out) . "\n");
        continue;
    }
    $changed++;
    say("  - $username");
}

if ($changed === 0) {
    fail('every change failed — see the errors above.');
}

// The file is read by the broker after it drops privileges, so ownership has to
// survive mosquitto_passwd rewriting it.
@chown($passwd, 'root');
@chgrp($passwd, 'mosquitto');
@chmod($passwd, 0640);

// Mosquitto re-reads the password file on SIGHUP, so accounts take effect without
// dropping the connections of everyone already online.
$out = [];
exec('systemctl reload mosquitto 2>&1', $out, $rc);
if ($rc !== 0) { $out = []; exec('systemctl kill -s HUP mosquitto 2>&1', $out, $rc); }

say($rc === 0
    ? "Applied $changed change(s); broker reloaded."
    : "Applied $changed change(s), but the broker reload FAILED: " . implode(' ', $out));

exit($rc === 0 ? 0 : 1);

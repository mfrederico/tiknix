<?php
/**
 * pipeline-backfill-trigger-secret.php — one-time migration.
 *
 * New instances get a unique [pipeline] trigger_secret at provision time
 * (scripts/aibuilder-provision.php). Instances provisioned BEFORE that landed
 * predate the [pipeline] section, so they lack a secret and can't serve
 * /pipeline/trigger or be fired by the fake-cron. This backfills one.
 *
 * SAFE + idempotent:
 *   - only touches sibling dirs whose CLAUDE.md marks them a tiknix app
 *     (sidecars like explorer/shop/pipelines.tiknix are skipped — no marker)
 *   - only fills a MISSING or EMPTY trigger_secret; never overwrites a set one
 *   - re-runnable; a filled instance is reported as "ok" next time
 *
 * Usage:  php scripts/pipeline-backfill-trigger-secret.php            (dry run)
 *         php scripts/pipeline-backfill-trigger-secret.php --apply    (write)
 */

$apply = in_array('--apply', $argv, true);
$base  = dirname(__DIR__, 2);                 // /var/www/html/default
$marker = 'Tiknix Development Standards';

$dirs = glob($base . '/*', GLOB_ONLYDIR) ?: [];
$minted = $already = $skipped = 0;

foreach ($dirs as $dir) {
    $name = basename($dir);
    $cfg  = "$dir/conf/config.ini";
    $claude = "$dir/CLAUDE.md";

    if (!is_file($cfg)) continue;
    // tiknix-app marker — excludes other apps AND the Sidecar-Kit sidecars
    if (!is_file($claude) || strpos((string) file_get_contents($claude), $marker) === false) {
        continue;
    }

    $ini = parse_ini_file($cfg, true);
    $secret = $ini['pipeline']['trigger_secret'] ?? null;
    if (is_string($secret) && $secret !== '') { $already++; echo "  ok    $name (already set)\n"; continue; }

    $trigger = bin2hex(random_bytes(32));
    $raw = (string) file_get_contents($cfg);
    $raw = preg_replace('/^\s*trigger_secret\s*=.*$/m', "trigger_secret = \"$trigger\"", $raw, 1, $n);
    if (!$n) $raw = rtrim($raw) . "\n\n[pipeline]\ntrigger_secret = \"$trigger\"\n";

    if ($apply) {
        // verify the patch parses before overwriting the live config
        if (@parse_ini_string($raw, true) === false) { echo "  SKIP  $name (patched ini would not parse)\n"; $skipped++; continue; }
        file_put_contents($cfg, $raw);
        echo "  MINT  $name (wrote trigger_secret)\n";
    } else {
        echo "  WOULD $name (mint trigger_secret)\n";
    }
    $minted++;
}

echo "\n" . ($apply ? "Applied." : "Dry run — pass --apply to write.") .
     "  minted=$minted  already-set=$already  skipped=$skipped\n";

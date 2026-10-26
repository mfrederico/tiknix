<?php
namespace app\concepts\messaging;

/**
 * What runs hourly, started by pipelines/messaging-hourly.json (the app must be booted; see the pipeline's command):
 *   Runner::hourly()       mirror this app into the service (Sync), then feed the digest and the app's own nudges (Digest)
 *   Runner::dryRun()       list who the digest and the nudges would reach now, and with what; sends and records nothing
 *   Runner::send($id)      send one campaign now
 * The service's own clock sends scheduled messages and the nudges it evaluates itself. Each returns the lines to print.
 */
class Runner {

    /** @return string[] */
    public static function hourly(): array {
        $lines = Sync::run();
        foreach (Digest::feed() as $l) $lines[] = $l;
        return $lines ?: ['nothing to do'];
    }

    /** @return string[] */
    public static function send(int $id): array {
        $r = Campaigns::send($id);
        return ["campaign #$id: " . json_encode($r['result'])];
    }

    /** @return string[] */
    public static function dryRun(): array {
        $lines = [];
        foreach (Campaigns::list(['status' => 'active']) as $c) {
            if (!$c['content_from_app']) continue;
            $what = $c['kind'] . ($c['trigger_code'] !== '' ? "/{$c['trigger_code']}" : '');
            $plan = Digest::plan($c, true);
            $lines[] = "campaign #{$c['id']} {$what} \"{$c['subject']}\": " . count($plan) . ' would receive it' . ($c['kind'] === 'digest' ? ' (send time, Sunday morning, ignored)' : '');
            foreach ($plan as $p) $lines[] = "  member #{$p['contact']} — \"{$p['headline']}\"";
        }
        return $lines ?: ['no active digest or nudge is fed by this app'];
    }
}

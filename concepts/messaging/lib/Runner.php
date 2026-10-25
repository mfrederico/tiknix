<?php
namespace app\concepts\messaging;

use app\Bean;

/**
 * What runs the sending, started hourly by pipelines/messaging-hourly.json (the app must be booted; see the pipeline's command).
 *   Runner::hourly()       send scheduled campaigns whose time has come; evaluate active ones
 *   Runner::dryRun()       list who the digest and the nudges would reach now, and with what; sends and records nothing
 *   Runner::send($id)      send one campaign now (a draft, or one still sending)
 * Each returns the lines to print.
 */
class Runner {

    /** @return string[] */
    public static function hourly(): array {
        $done = Campaigns::runDue();
        return $done ?: ['nothing due'];
    }

    /** @return string[] */
    public static function send(int $id): array {
        $c = Bean::load('msgcampaign', $id);
        if (!$c->id) throw new \InvalidArgumentException("no campaign #$id");
        return ["campaign #$id: " . json_encode(Campaigns::send($c))];
    }

    /** @return string[] */
    public static function dryRun(): array {
        $lines = [];
        foreach (Bean::find('msgcampaign', "status = 'active' AND kind IN ('digest', 'trigger') ORDER BY id") as $c) {
            $what = $c->kind . ($c->triggerCode ? "/{$c->triggerCode}" : '');
            $plan = Digest::plan($c, true);
            $lines[] = "campaign #{$c->id} {$what} \"{$c->subject}\": " . count($plan) . ' would receive it' . ($c->kind === 'digest' ? ' (send time, Sunday morning, ignored)' : '')
                . (($c->kind === 'digest' && !Digest::available()) ? ' — no digest provider is registered, so the digest is unavailable' : '');
            foreach ($plan as $p) $lines[] = "  member #{$p['member']} {$p['email']} — \"{$p['headline']}\"";
        }
        return $lines;
    }
}

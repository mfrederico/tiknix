<?php
namespace app\concepts\messaging;

/**
 * Digest — what the platform's own messages SAY, which only the app knows: the weekly digest (the digest provider's
 * content) and the app's own nudges (a trigger's qualifies and content). The service keeps the clock, the switches and
 * the ledger: it answers who is due (GET campaigns/{id}/due) and takes each person's copy (POST deliveries). This feeds
 * it, hourly. inactive_days and joined_days need nothing from the app: the service sends them itself.
 */
class Digest {

    public const CODE_INACTIVE = 'inactive_days';
    public const CODE_JOINED = 'joined_days';

    /** Feed every active app-fed campaign. @return string[] what was done */
    public static function feed(): array {
        $lines = [];
        foreach (Campaigns::list(['status' => 'active']) as $c) {
            if (!$c['content_from_app'] || $c['trigger_code'] === Providers::TRIGGER_PIPELINE) continue;   // a Custom one is its pipeline's to send
            $plan = self::plan($c, false);
            if (!$plan) { $lines[] = "campaign #{$c['id']} ({$c['kind']}" . ($c['trigger_code'] !== '' ? "/{$c['trigger_code']}" : '') . '): nobody due'; continue; }
            $r = Client::post("campaigns/{$c['id']}/deliveries", ['deliveries' => $plan])['result'];
            $lines[] = "campaign #{$c['id']} fed: " . json_encode(array_intersect_key($r, ['recipients' => 1, 'sent' => 1, 'failed' => 1, 'not_sent' => 1]));
            foreach ($r['skipped'] as $s) $lines[] = "  skipped: $s";
        }
        return $lines;
    }

    /**
     * Who gets an app-fed campaign now and what it says to them, as the deliveries the service takes.
     * $ignoreTime (a dry run) skips the digest's Sunday-morning rule.
     * @return array<int,array{contact:string,headline:string,content_html:string,content_text:string}>
     */
    public static function plan(array $c, bool $ignoreTime): array {
        if (!$c['content_from_app']) throw new \InvalidArgumentException("campaign #{$c['id']} is not fed by the app: the service sends it itself");
        $due = Client::get("campaigns/{$c['id']}/due", $ignoreTime ? ['ignore_time' => 1] : [])['due'];
        $out = [];
        foreach ($due as $d) {
            $mid = (int) $d['contact'];
            $content = self::content($c, $mid, (string) $d['since']);
            if ($content === null) continue;   // nothing new, or does not qualify: nothing sent
            $out[] = ['contact' => (string) $d['contact'], 'headline' => (string) ($content['headline'] ?? ''), 'content_html' => (string) ($content['html'] ?? ''), 'content_text' => (string) ($content['text'] ?? '')];
        }
        return $out;
    }

    /** What a campaign says to one member: the digest provider's news since $since; a nudge's own content when they qualify. Null = not this time. */
    public static function content(array $c, int $memberId, string $since): ?array {
        if ($c['kind'] === 'digest') {
            if (!Providers::has('digest')) throw new \RuntimeException("campaign #{$c['id']} is the digest, but this app registered no digest provider (Providers::register(['digest' => …]))");
            return Providers::need('digest')::content($memberId, $since);
        }
        $code = (string) $c['trigger_code'];
        $class = Providers::triggers()[$code] ?? null;
        if ($class === null) throw new \RuntimeException("campaign #{$c['id']} has trigger_code '$code', which this app does not register (Providers::register(['triggers' => …]))");
        if (!$class::qualifies($memberId, (int) $c['trigger_days'])) return null;
        return is_callable([$class, 'content']) ? ($class::content($memberId) ?? ['headline' => '', 'html' => '', 'text' => '']) : ['headline' => '', 'html' => '', 'text' => ''];
    }

    /** What a test or preview fills into a system campaign for one member (Campaigns::test's $extra). */
    public static function previewExtra(array $c, int $memberId): array {
        if (!Campaigns::isSystem($c)) return [];
        $content = null;
        if ($c['content_from_app']) {
            $content = $c['kind'] === 'digest'
                ? (Providers::has('digest') ? Providers::need('digest')::content($memberId, date('Y-m-d H:i:s', time() - 7 * 86400)) : null)
                : ((($cls = Providers::triggers()[$c['trigger_code']] ?? null) !== null && is_callable([$cls, 'content'])) ? $cls::content($memberId) : null);
        }
        return ['vars' => ['headline' => (string) ($content['headline'] ?? '')], 'html' => ['content_html' => (string) ($content['html'] ?? '')], 'text' => ['content_text' => (string) ($content['text'] ?? '')]];
    }
}

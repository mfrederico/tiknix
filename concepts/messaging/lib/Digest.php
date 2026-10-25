<?php
namespace app\concepts\messaging;

use app\Bean;

/**
 * The platform's own messages to members: the weekly digest (campaign kind digest) and the nudges (kind trigger, trigger_code
 * inactive_days | joined_days | a code the app registered). The campaign rows are seeded and edited by the admin on /messaging;
 * this class decides who gets what. Sending goes through Campaigns::deliver, so the ledger and Reach::allows apply as to any campaign.
 *
 * What a message SAYS comes from the app: the digest provider's content() for the digest and the inactivity nudge, a trigger's own
 * content() for its nudge. The template (msgcampaign.body_email / body_text / subject) is filled with {first_name}, {headline},
 * {content_html} and {content_text}. With no digest provider the digest is unavailable and never sends.
 */
class Digest {

    /** The digest goes out on the member's local Sunday from this hour; with no timezone, app time. */
    public const SEND_HOUR = 9;

    public const CODE_INACTIVE = 'inactive_days';
    public const CODE_JOINED = 'joined_days';
    /** Trigger codes the plugin evaluates itself. */
    public const BUILT_IN = [self::CODE_INACTIVE, self::CODE_JOINED];

    /** The digest needs the app's digest provider: without it the digest campaign is shown as unavailable and never sends. */
    public static function available(): bool {
        return Providers::has('digest');
    }

    /** @return array<string,string> every trigger code that can be evaluated here, with the words the admin sees for its number of days */
    public static function triggerLabels(): array {
        $out = [self::CODE_INACTIVE => 'After this many days without signing in', self::CODE_JOINED => 'Days after joining'];
        foreach (Providers::triggers() as $code => $class) $out[$code] = (string) $class::label();
        return $out;
    }

    /** Called by Campaigns::runDue for each active digest campaign. @return array stats */
    public static function run($campaign): array {
        if (!self::available()) return ['skipped' => 'no digest provider is registered (Providers::register([\'digest\' => …]))'];
        return self::deliverAll($campaign, self::plan($campaign, false));
    }

    /** Called by Campaigns::runDue for each active trigger campaign. */
    public static function runTrigger($campaign): array {
        if (!isset(self::triggerLabels()[(string) $campaign->triggerCode])) {
            \Flight::get('log')?->error("ERROR Digest: trigger campaign #{$campaign->id} has trigger_code '{$campaign->triggerCode}', which nothing evaluates (Providers::register(['triggers' => …]))");
            return ['skipped' => "unknown trigger_code '{$campaign->triggerCode}'"];
        }
        return self::deliverAll($campaign, self::plan($campaign, false));
    }

    private static function deliverAll($campaign, array $plan): array {
        $stats = ['recipients' => count($plan), 'sent' => 0, 'failed' => 0, 'not_sent' => 0];
        if (!$plan) return $stats;
        $ready = Campaigns::mailer();
        foreach ($plan as $p) $stats[Campaigns::deliver($campaign, $p['member'], $ready, $p['extra'])]++;
        return $stats;
    }

    /**
     * Who gets this campaign now, and what it says to them. $ignoreTime (dry run) skips the digest's Sunday-morning rule.
     * @return array<int,array{member:int,email:string,content:?array,extra:array,headline:string}>
     */
    public static function plan($campaign, bool $ignoreTime): array {
        $kind = (string) $campaign->kind;
        if ((string) $campaign->status !== 'active') return [];
        if ($kind === 'digest') return self::available() ? self::planDigest($campaign, $ignoreTime) : [];
        if ($kind === 'trigger') return self::planTrigger($campaign);
        throw new \InvalidArgumentException("Digest::plan: campaign #{$campaign->id} ({$kind} / {$campaign->triggerCode}) is not one of the platform's own messages");
    }

    private static function planDigest($campaign, bool $ignoreTime): array {
        $out = [];
        $now = time();
        foreach (Campaigns::audience($campaign) as $id) {
            if (!Reach::digestOn($id)) continue;
            if (!$ignoreTime && !self::isSendTime($id, $now)) continue;
            $content = Providers::need('digest')::content($id, self::sinceLast($campaign, $id, $campaign->cadenceDays ? (int) $campaign->cadenceDays : 7));
            if ($content === null) continue;   // nothing new: nothing sent
            $out[] = self::entry($id, $content);
        }
        return $out;
    }

    private static function planTrigger($campaign): array {
        $code = (string) $campaign->triggerCode;
        $days = (int) $campaign->triggerDays;
        if ($days < 1) throw new \RuntimeException("campaign #{$campaign->id}: $code needs trigger_days of 1 or more");
        $audience = Campaigns::audience($campaign);
        $qualify = self::qualifying($code, $days, $audience);
        $out = [];
        foreach ($audience as $id) {
            if (!isset($qualify[$id])) continue;
            $out[] = self::entry($id, self::triggerContent($code, $id, $days));
        }
        return $out;
    }

    /** @return array<int,true> of the members in $ids who have reached this trigger */
    private static function qualifying(string $code, int $days, array $ids): array {
        $cutoff = date('Y-m-d H:i:s', time() - $days * 86400);
        if ($code === self::CODE_INACTIVE) return array_fill_keys(array_map('intval', Bean::getCol('SELECT id FROM member WHERE COALESCE(last_login, created_at) <= ?', [$cutoff])), true);
        if ($code === self::CODE_JOINED) return array_fill_keys(array_map('intval', Bean::getCol('SELECT id FROM member WHERE created_at <= ?', [$cutoff])), true);
        $class = Providers::triggers()[$code] ?? null;
        if ($class === null) throw new \InvalidArgumentException("Digest: trigger '$code' is not registered");
        $out = [];
        foreach ($ids as $id) if ($class::qualifies($id, $days)) $out[$id] = true;
        return $out;
    }

    /** What a nudge tells one member: the app trigger's own content, else (inactivity) what the digest would, else nothing. */
    private static function triggerContent(string $code, int $memberId, int $days): ?array {
        $class = Providers::triggers()[$code] ?? null;
        if ($class !== null && is_callable([$class, 'content'])) return $class::content($memberId);
        if ($code === self::CODE_INACTIVE && self::available()) return Providers::need('digest')::content($memberId, date('Y-m-d H:i:s', time() - $days * 86400));
        return null;
    }

    /**
     * What the runner would fill into this campaign for one member (the $extra of Campaigns::render): the digest with their own
     * news, a nudge with its content. A campaign of any other kind has none.
     */
    public static function previewExtra($campaign, int $memberId): array {
        $kind = (string) $campaign->kind;
        $code = (string) $campaign->triggerCode;
        if ($kind === 'digest') {
            if (!self::available()) return self::entry($memberId, null)['extra'];
            return self::entry($memberId, Providers::need('digest')::content($memberId, self::sinceLast($campaign, $memberId, $campaign->cadenceDays ? (int) $campaign->cadenceDays : 7)))['extra'];
        }
        if ($kind === 'trigger') {
            if (!isset(self::triggerLabels()[$code])) throw new \InvalidArgumentException("Digest::previewExtra: campaign #{$campaign->id} has trigger_code '{$code}', which nothing evaluates");
            return self::entry($memberId, self::triggerContent($code, $memberId, (int) $campaign->triggerDays))['extra'];
        }
        return [];
    }

    private static function entry(int $id, ?array $content): array {
        $headline = (string) ($content['headline'] ?? '');
        return ['member' => $id, 'email' => (string) Bean::getCell('SELECT email FROM member WHERE id = ?', [$id]), 'content' => $content, 'headline' => $headline,
            'extra' => ['vars' => ['headline' => $headline], 'html' => ['content_html' => (string) ($content['html'] ?? '')], 'text' => ['content_text' => (string) ($content['text'] ?? '')]]];
    }

    /** Has this local time come for the member's digest: their Sunday, from SEND_HOUR. A timezone that does not exist is a fault: the member is skipped, loudly. */
    public static function isSendTime(int $memberId, int $at): bool {
        $name = trim((string) \Flight::getSetting('timezone', $memberId));
        if ($name === '') $name = date_default_timezone_get();
        try { $tz = new \DateTimeZone($name); }
        catch (\Throwable $e) {
            \Flight::get('log')?->error("ERROR Digest: member #{$memberId} has timezone '{$name}', which PHP does not know — no digest until they pick one in their settings");
            return false;
        }
        $local = (new \DateTimeImmutable('@' . $at))->setTimezone($tz);
        return (int) $local->format('N') === 7 && (int) $local->format('G') >= self::SEND_HOUR;
    }

    /** When the member's last copy of this campaign went out; with none, $days ago. */
    private static function sinceLast($campaign, int $memberId, int $days): string {
        $last = Bean::getCell('SELECT MAX(created_at) FROM msgsend WHERE msgcampaign_id = ? AND member_id = ?', [(int) $campaign->id, $memberId]);
        return $last ? (string) $last : date('Y-m-d H:i:s', time() - $days * 86400);
    }
}

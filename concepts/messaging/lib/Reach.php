<?php
namespace app\concepts\messaging;

/**
 * Reach — may this message reach this person: the service answers (its switches, suppression, the audience's switch);
 * this asks. Also the member's preferences panel and the hosted preferences link. A member the service does not know
 * yet (joined since the hour's sync) is mirrored first, once.
 */
class Reach {

    public const TRANSACTIONAL = 'transactional';
    public const REMINDERS = 'reminders';

    /** Transactional is always allowed; the rest is the service's answer. */
    public static function allows(int $memberId, string $categoryCode, string $channel, ?string $scopeKey = null): bool {
        if (!in_array($channel, ['email', 'text'], true)) throw new \InvalidArgumentException("channel: '$channel' is not email or text");
        if ($categoryCode === self::TRANSACTIONAL) return true;
        $q = ['category' => $categoryCode, 'channel' => $channel];
        if ($scopeKey !== null) $q['scope'] = $scopeKey;
        return (bool) self::known($memberId, fn() => Client::get("contacts/{$memberId}/reach", $q))['allowed'];
    }

    /** Why not, in the service's words; null when the message may reach them. */
    public static function why(int $memberId, string $categoryCode, string $channel, ?string $scopeKey = null): ?string {
        if ($categoryCode === self::TRANSACTIONAL) return null;
        $q = ['category' => $categoryCode, 'channel' => $channel];
        if ($scopeKey !== null) $q['scope'] = $scopeKey;
        $r = self::known($memberId, fn() => Client::get("contacts/{$memberId}/reach", $q));
        return $r['allowed'] ? null : (string) $r['why'];
    }

    /** The member's panel: categories with a switch per channel, the digest, their audiences, texts, and prefs_url. */
    public static function panel(int $memberId): array {
        return self::known($memberId, fn() => Client::get("contacts/{$memberId}/preferences"))['preferences'];
    }

    /**
     * Save choices: {categories: {code: {channel: bool}}, digest: bool, audiences: {key: bool}}.
     * @return string[] what the service refused, by name (empty = everything saved)
     */
    public static function save(int $memberId, array $choices): array {
        return self::known($memberId, fn() => Client::put("contacts/{$memberId}/preferences", $choices))['refused'];
    }

    /** The signed hosted page where the member chooses what reaches them (for a footer of the app's own mail). */
    public static function prefsUrl(int $memberId): string {
        return (string) self::panel($memberId)['prefs_url'];
    }

    /** Run a call about a member; when the service has not met them, mirror them and run it once more. */
    private static function known(int $memberId, callable $call): array {
        try { return $call(); }
        catch (ServiceException $e) {
            if ($e->status !== 404) throw $e;
            Sync::contact($memberId);
            return $call();
        }
    }
}

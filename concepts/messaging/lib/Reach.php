<?php
namespace app\concepts\messaging;

use app\Bean;
use app\EncryptionService;

/** May this message reach this person — the one place that answers it — and the signed unsubscribe link that switches a category off. */
class Reach {

    public const TRANSACTIONAL = 'transactional';
    public const REMINDERS = 'reminders';
    private const SIG = 32;
    private const TEXT_SIG = 12;
    /** Tokens issued before the plugin named an audience by key carried a bare community id; it still reads as community:<id>. */
    private const LEGACY_SCOPE_KIND = 'community';

    /**
     * Reads the member's msgpref (else the category's default), the audience's own switch ($scopeKey) and suppression.
     * Transactional is always allowed. A suppressed address gets no email in any other category.
     */
    public static function allows(int $memberId, string $categoryCode, string $channel, ?string $scopeKey = null, bool $knownMember = false): bool {
        $cat = \Model_Msgcategory::byCode($categoryCode);
        if (!in_array($channel, \Model_Msgcategory::CHANNELS, true)) throw new \InvalidArgumentException("channel: '$channel' is not email or text");
        if ($categoryCode === self::TRANSACTIONAL) return true;
        if (!in_array($channel, $cat->box()->channels(), true)) return false;
        if ($channel === 'email') {
            $email = (string) Bean::getCell('SELECT email FROM member WHERE id = ?', [$memberId]);
            if ($email === '') throw new \RuntimeException("Reach::allows: member #$memberId has no email address");
            if (\Model_Msgsuppression::has($email)) return false;
        }
        if (!self::switchedOn($memberId, $cat, $channel)) return false;
        if ($channel === 'text' && self::phone($memberId) === null) return false;
        if ($scopeKey !== null) {
            $audiences = Providers::need('audiences');
            if ($audiences::allowsFor($memberId, $scopeKey) === false) return false;
            // Campaigns::audience() lists an audience's members itself and says so, instead of listing them again for each one
            if (!$knownMember && !in_array($memberId, array_map('intval', $audiences::members($scopeKey)), true)) return false;
        }
        return true;
    }

    /** The member's own switch for a category/channel (no suppression, no audience). */
    public static function switchedOn(int $memberId, $cat, string $channel): bool {
        $stored = \Model_Msgpref::stored($memberId, (string) $cat->code, $channel);
        if ($channel === 'text') return $stored === true;   // a text is never on by default: the member must ask for it
        return $stored ?? (int) $cat->defaultOn === 1;
    }

    /** The member's phone (E.164) when the app's phone provider says they proved it. Null when there is none or no phone provider. */
    public static function phone(int $memberId): ?string {
        if (!Providers::has('phone')) return null;
        $phone = Providers::need('phone')::verified($memberId);
        return $phone === '' ? null : $phone;
    }

    /** The category the weekly digest belongs to (the digest switch is stored under it); null when the install has no digest campaign. */
    public static function digestCategory(): ?string {
        $code = Bean::getCell("SELECT c.code FROM msgcampaign m JOIN msgcategory c ON c.id = m.category_ref WHERE m.kind = 'digest' ORDER BY m.id LIMIT 1");
        return $code === null || $code === false ? null : (string) $code;
    }

    /** Weekly digest switch: off until the member turns it on. */
    public static function digestOn(int $memberId): bool {
        $code = self::digestCategory();
        if ($code === null) throw new \RuntimeException('Reach::digestOn: there is no digest campaign (seed 04_SystemCampaigns or the app\'s move seed makes it)');
        return \Model_Msgpref::stored($memberId, $code, \Model_Msgpref::DIGEST) ?? false;
    }

    private static function key(): string {
        return EncryptionService::deriveKey('unsubscribe');
    }

    public const TEXT_BASE_SETTING = 'text_link_base';

    /**
     * The address the links in a TEXT start with. A text is paid for by the 160 characters and carries a tracked link and the stop link, so a
     * long site address does not fit in one part: the platform admin can name a short one that reaches this app (setting text_link_base,
     * on /messaging). Without it the site's own address is used, and a text that then runs over one part is refused, not cut.
     */
    public static function textBase(): string {
        $v = rtrim(trim((string) \Flight::getSetting(self::TEXT_BASE_SETTING, 0)), '/');
        if ($v === '') return Campaigns::base();
        if (!preg_match('~^https?://[A-Za-z0-9.-]+(:\d+)?$~', $v)) throw new \RuntimeException('The setting ' . self::TEXT_BASE_SETTING . ' is "' . $v . '": it must be a web address with no path, like https://short.example. Fix it on /messaging.');
        return $v;
    }

    /** The address in the footer and List-Unsubscribe header of an email in this category (and audience). Absolute. */
    public static function unsubscribeUrl(int $memberId, string $categoryCode, ?string $scopeKey = null, string $channel = 'email'): string {
        if (!in_array($channel, \Model_Msgcategory::CHANNELS, true)) throw new \InvalidArgumentException("channel: '$channel' is not email or text");
        $cat = \Model_Msgcategory::byCode($categoryCode);
        if ((int) $cat->canOptOut === 0) throw new \InvalidArgumentException("category: '$categoryCode' cannot be unsubscribed from");
        if ($scopeKey !== null && !preg_match(\Model_Msgcampaign::KEY_RE, $scopeKey)) throw new \InvalidArgumentException("scope: '$scopeKey' is not a kind:id key");
        $parts = [$memberId, (int) $cat->id, $scopeKey ?? '0'];
        if ($channel === 'text') $parts[] = 't';
        $body = rtrim(strtr(base64_encode(implode('.', $parts)), '+/', '-_'), '=');
        // a text link is charged by the character, so its signature is the shorter one (48 bits is still not guessable)
        $sig = substr(hash_hmac('sha256', $body, self::key()), 0, $channel === 'text' ? self::TEXT_SIG : self::SIG);
        $base = $channel === 'text' ? self::textBase() : rtrim((string) \Flight::get('app.baseurl'), '/');
        if ($base === '') throw new \RuntimeException('Missing setting [app] baseurl in conf/config.ini: an unsubscribe link needs it.');
        return $base . '/msgpref/unsubscribe?t=' . $body . '.' . $sig;
    }

    /** @return array{member:int,category:string,scope:?string,channel:string}|null null when the token is malformed or its signature does not match */
    public static function readToken(string $token): ?array {
        $parts = explode('.', $token);
        if (count($parts) !== 2) return null;
        [$body, $sig] = $parts;
        if (!in_array(strlen($sig), [self::SIG, self::TEXT_SIG], true)) return null;
        if (!hash_equals(substr(hash_hmac('sha256', $body, self::key()), 0, strlen($sig)), $sig)) return null;
        $raw = base64_decode(strtr($body, '-_', '+/'), true);
        if ($raw === false || !preg_match('/^(\d+)\.(\d+)\.(0|\d+|[a-z][a-z0-9_]*:[A-Za-z0-9_-]+)(\.t)?$/', $raw, $m)) return null;
        // the short signature is only good for a text link, which names its channel
        if ((strlen($sig) === self::TEXT_SIG) !== !empty($m[4])) return null;
        $cat = Bean::load('msgcategory', (int) $m[2]);
        if (!$cat->id || (int) $cat->canOptOut === 0 || !Bean::load('member', (int) $m[1])->id) return null;
        $scope = $m[3] === '0' ? null : (ctype_digit($m[3]) ? self::LEGACY_SCOPE_KIND . ':' . $m[3] : $m[3]);
        return ['member' => (int) $m[1], 'category' => (string) $cat->code, 'scope' => $scope, 'channel' => empty($m[4]) ? 'email' : 'text'];
    }

    /** Is the switch a token names still on? (Its own switch, ignoring suppression.) */
    public static function tokenSwitchOn(array $t): bool {
        $cat = \Model_Msgcategory::byCode($t['category']);
        if ($t['channel'] === 'text') return self::switchedOn($t['member'], $cat, 'text');
        if ($t['scope'] !== null) {
            $audiences = Providers::need('audiences');
            return $audiences::allowsFor($t['member'], $t['scope']) !== false && in_array($t['member'], array_map('intval', $audiences::members($t['scope'])), true);
        }
        return self::switchedOn($t['member'], $cat, 'email');
    }

    /** Turn off the switch a token names. */
    public static function tokenSwitchOff(array $t): void {
        if ($t['channel'] === 'text') { \Model_Msgpref::set($t['member'], $t['category'], 'text', false); return; }
        if ($t['scope'] !== null) { Providers::need('audiences')::allow($t['member'], $t['scope'], false); return; }
        \Model_Msgpref::set($t['member'], $t['category'], 'email', false);
    }
}

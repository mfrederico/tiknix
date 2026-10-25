<?php
namespace app\concepts\messaging;

/**
 * What an app hands the plugin, in code, once at boot (from its lib/app.php). Every key is optional; the plugin says plainly on its
 * admin page which ones it has. A provider is a CLASS NAME whose methods are static. One that does not exist, or lacks a required
 * method, is an error at register(), naming the class and the method: never a silent fallback.
 *
 *   Providers::register([
 *       'audiences' => \app\MessagingAudiences::class,
 *       'digest'    => \app\MessagingDigest::class,
 *       'triggers'  => ['no_friends_days' => \app\MessagingNoFriends::class],
 *       'phone'     => \app\MessagingPhone::class,
 *   ]);
 *
 * audiences — who a message can be for. Keys look like community:5 (kind:id).
 *   scopes(int $forMember): array              [['key' => 'community:5', 'label' => 'Paranormal', 'owner_id' => 7], …] the audiences this member belongs to
 *   members(string $key): int[]                member ids in it
 *   label(string $key): string                 its name; throws \InvalidArgumentException for a key that is none
 *   allowsFor(int $memberId, string $key): ?bool   the member's own switch for this audience; null = it has none
 *   allow(int $memberId, string $key, bool $on): void   set that switch
 *   mayMessage(int $memberId, string $key): bool   may this member send to the audience (its owner, or an admin)
 *   category(): string                         the message category (a code) messages to these audiences belong to; the audiences' switches show under it
 *   optional, for messages ABOUT a thing (a gathering) inside an audience — a method that is absent switches that feature off:
 *   abouts(string $key): array                 ['gathering:12' => 'Picnic — Sat 3 pm'] the things a message to the audience can be about
 *   aboutDraft(string $aboutKey): array        ['subject' => …, 'body' => …] a starting message about it
 *   reminder(string $aboutKey): ?array         ['at' => unix time, 'subject' => …, 'body' => …, 'sms' => …] the reminder to schedule for those in it; null = none
 *   vars(string $key): array                   nested values for {community.name}, {gathering.title}: ['community' => ['name' => …]]
 *   link(string $key): ?string                 an absolute address a text's {link} may point at
 * digest — the weekly digest.
 *   content(int $memberId, string $since): ?array   ['headline' => …, 'html' => …, 'text' => …], or null when they have nothing new (no digest)
 * triggers — extra trigger codes (the plugin ships inactive_days and joined_days). code => class with
 *   qualifies(int $memberId, int $days): bool, label(): string, and optionally content(int $memberId): ?array (as the digest's)
 * phone — the text channel.
 *   verified(int $memberId): string            the member's proved phone in E.164, or ''
 *   optional, to let a member prove it from the preferences page: prove(int $memberId, array $categoryCodes): array ['ok' => bool, 'message' => string],
 *   pending(int $memberId): ?string (what the code was sent to), confirm(int $memberId, string $code): array ['ok' => bool, 'message' => string, 'categories' => string[]],
 *   cancel(int $memberId): void
 */
class Providers {

    private const KEYS = ['audiences', 'digest', 'triggers', 'phone'];
    private const REQUIRED = [
        'audiences' => ['scopes', 'members', 'label', 'allowsFor', 'allow', 'mayMessage', 'category'],
        'digest'    => ['content'],
        'trigger'   => ['qualifies', 'label'],
        'phone'     => ['verified'],
    ];
    private const PHONE_PROVING = ['prove', 'pending', 'confirm', 'cancel'];

    /** @var array<string,mixed> */
    private static array $p = [];

    /** @param array{audiences?:string,digest?:string,triggers?:array<string,string>,phone?:string} $providers */
    public static function register(array $providers): void {
        foreach (array_keys($providers) as $k) {
            if (!in_array($k, self::KEYS, true)) throw new \InvalidArgumentException("Providers::register: '$k' is not a provider. Known: " . implode(', ', self::KEYS) . '.');
        }
        foreach (['audiences', 'digest', 'phone'] as $k) {
            if (isset($providers[$k])) self::check($k, $k, $providers[$k]);
        }
        if (isset($providers['triggers'])) {
            if (!is_array($providers['triggers'])) throw new \InvalidArgumentException("Providers::register: 'triggers' must be a list of code => class");
            foreach ($providers['triggers'] as $code => $class) {
                if (!is_string($code) || !preg_match('/^[a-z][a-z0-9_]*$/D', $code)) throw new \InvalidArgumentException("Providers::register: trigger code '$code' must be lowercase letters, digits and _");
                if (in_array($code, Digest::BUILT_IN, true)) throw new \InvalidArgumentException("Providers::register: trigger '$code' is one the plugin ships");
                self::check("triggers[$code]", 'trigger', $class);
            }
        }
        self::$p = $providers;
    }

    /** Forget every provider (tests). */
    public static function reset(): void { self::$p = []; }

    private static function check(string $what, string $kind, $class): void {
        if (!is_string($class) || !class_exists($class)) throw new \InvalidArgumentException("Providers::register: $what names the class " . json_encode($class) . ', which does not exist.');
        foreach (self::REQUIRED[$kind] as $m) {
            if (!is_callable([$class, $m])) throw new \InvalidArgumentException("Providers::register: $what ($class) has no static method $m().");
        }
        if ($kind === 'phone') {
            $have = array_filter(self::PHONE_PROVING, fn($m) => is_callable([$class, $m]));
            if ($have && count($have) !== count(self::PHONE_PROVING)) {
                throw new \InvalidArgumentException("Providers::register: $what ($class) has some of " . implode(', ', self::PHONE_PROVING) . ' but not all: a member could be asked for a code and never able to enter it.');
            }
        }
    }

    public static function has(string $key): bool { return isset(self::$p[$key]); }

    /** @return string[] the provider keys registered, for the admin page */
    public static function registered(): array { return array_keys(self::$p); }

    /** The class of a provider; throws when the app did not register one, naming it. */
    public static function need(string $key): string {
        if (!isset(self::$p[$key]) || !is_string(self::$p[$key])) throw new \RuntimeException("The '$key' provider is not registered: call Providers::register(['$key' => …]) from the app's lib/app.php.");
        return self::$p[$key];
    }

    /** Does the provider $key have this (optional) static method? */
    public static function can(string $key, string $method): bool {
        return isset(self::$p[$key]) && is_string(self::$p[$key]) && is_callable([self::$p[$key], $method]);
    }

    /** @return array<string,string> trigger code => class, the app's */
    public static function triggers(): array { return self::$p['triggers'] ?? []; }
}

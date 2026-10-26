<?php
namespace app\concepts\messaging;

/**
 * What an app hands the plugin, in code, once at boot (from its lib/app.php). The plugin itself is a client of the
 * messaging service: it mirrors the app's people, kinds of message and audiences into the service every hour (Sync),
 * feeds the digest and the app's own nudges with what they say (Digest), and draws the pages. Every key is optional;
 * the admin page says plainly which ones it has. A provider is a CLASS NAME whose methods are static. One that does
 * not exist, or lacks a required method, is an error at register(), naming the class and the method.
 *
 *   Providers::register([
 *       'audiences'  => \app\MessagingAudiences::class,
 *       'digest'     => \app\MessagingDigest::class,
 *       'triggers'   => ['no_friends_days' => \app\MessagingNoFriends::class],
 *       'phone'      => \app\MessagingPhone::class,
 *       'categories' => [['news', 'News', 'What is new in your groups', true, true, 'email,text']],
 *       'system'     => [['kind' => 'digest', 'category' => 'news', 'subject' => '{headline}', 'body_email' => '…', 'body_text' => '…', 'cadence' => 'every_n_days', 'cadence_days' => 7],
 *                        ['kind' => 'trigger', 'trigger_code' => 'no_friends_days', 'trigger_days' => 7, 'category' => 'reminders', 'subject' => '…', 'body_email' => '…']],
 *   ]);
 *
 * audiences — who a message can be for. Keys look like community:5 (kind:id).
 *   all(): array                               [['key' => 'community:5', 'label' => 'Paranormal', 'owner_id' => 7], …] every audience the app has (mirrored hourly)
 *   scopes(int $forMember): array              the same shape, only the audiences this member belongs to (the preferences page)
 *   members(string $key): int[]                member ids in it
 *   label(string $key): string                 its name; throws \InvalidArgumentException for a key that is none
 *   mayMessage(int $memberId, string $key): bool   may this member send to the audience (its owner, or an admin)
 *   category(): string                         the message category (a code) messages to these audiences belong to
 *   optional:
 *   allowsFor(int $memberId, string $key): ?bool   the app's own switch for a member, used ONCE to seed the service's when the member first appears in the audience;
 *                                              from then on the service's switch (the hosted page, /msgpref) is the truth
 *   abouts(string $key): array                 ['gathering:12' => 'Picnic — Sat 3 pm'] the things a message to the audience can be about (each is an audience too)
 *   aboutDraft(string $aboutKey): array        ['subject' => …, 'body' => …] a starting message about it
 *   reminder(string $aboutKey): ?array         ['at' => unix time, 'subject' => …, 'body' => …, 'sms' => …] the reminder to schedule for those in it; null = none
 *   vars(string $key): array                   nested values for {community.name}, {gathering.title}: ['community' => ['name' => …]]
 *   link(string $key): ?string                 an absolute address a text's {link} may point at
 * digest — the weekly digest.
 *   content(int $memberId, string $since): ?array   ['headline' => …, 'html' => …, 'text' => …], or null when they have nothing new (no digest)
 * triggers — the app's own nudges. code => class with qualifies(int $memberId, int $days): bool, label(): string,
 *   and optionally content(int $memberId): ?array (as the digest's). inactive_days and joined_days the service evaluates itself.
 * phone — the text channel.
 *   verified(int $memberId): string            the member's proved phone in E.164, or ''
 *   optional, to let a member prove it from the preferences page: prove(int $memberId, array $categoryCodes): array ['ok' => bool, 'message' => string],
 *   pending(int $memberId): ?string (what the code was sent to), confirm(int $memberId, string $code): array ['ok' => bool, 'message' => string, 'categories' => string[]],
 *   cancel(int $memberId): void
 * categories — the app's own kinds of message: [code, label, description, default_on, can_opt_out, channels]. Transactional and reminders exist in every tenant.
 * system — the platform's own messages the app wants to exist: a digest and nudges, as campaign specs (Campaigns' fields). Each is MADE ONCE,
 *   paused, if the tenant has none of that kind / trigger_code; the admin reads it on /messaging and switches it on. Never overwritten.
 */
class Providers {

    private const KEYS = ['audiences', 'digest', 'triggers', 'phone', 'categories', 'system'];
    private const REQUIRED = [
        'audiences' => ['all', 'scopes', 'members', 'label', 'mayMessage', 'category'],
        'digest'    => ['content'],
        'trigger'   => ['qualifies', 'label'],
        'phone'     => ['verified'],
    ];
    private const PHONE_PROVING = ['prove', 'pending', 'confirm', 'cancel'];
    public const BUILT_IN_TRIGGERS = ['inactive_days', 'joined_days'];

    /** @var array<string,mixed> */
    private static array $p = [];

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
                if (in_array($code, self::BUILT_IN_TRIGGERS, true)) throw new \InvalidArgumentException("Providers::register: trigger '$code' is one the service evaluates itself");
                self::check("triggers[$code]", 'trigger', $class);
            }
        }
        if (isset($providers['categories'])) {
            if (!is_array($providers['categories'])) throw new \InvalidArgumentException("Providers::register: 'categories' must be a list of [code, label, description, default_on, can_opt_out, channels]");
            foreach ($providers['categories'] as $i => $c) {
                if (!is_array($c) || count($c) < 5 || !is_string($c[0]) || !preg_match('/^[a-z][a-z0-9_]{0,39}$/D', $c[0]) || trim((string) $c[1]) === '') {
                    throw new \InvalidArgumentException("Providers::register: categories[$i] must be [code, label, description, default_on, can_opt_out, channels]");
                }
            }
        }
        if (isset($providers['system'])) {
            if (!is_array($providers['system'])) throw new \InvalidArgumentException("Providers::register: 'system' must be a list of campaign specs");
            foreach ($providers['system'] as $i => $s) {
                if (!is_array($s) || !in_array($s['kind'] ?? '', ['digest', 'trigger'], true) || trim((string) ($s['subject'] ?? '')) === '' || trim((string) ($s['body_email'] ?? '')) === '' || trim((string) ($s['category'] ?? '')) === '') {
                    throw new \InvalidArgumentException("Providers::register: system[$i] needs kind (digest|trigger), category, subject and body_email");
                }
                if ($s['kind'] === 'trigger' && (!preg_match('/^[a-z][a-z0-9_]*$/D', (string) ($s['trigger_code'] ?? '')) || (int) ($s['trigger_days'] ?? 0) < 1)) {
                    throw new \InvalidArgumentException("Providers::register: system[$i]: a trigger needs trigger_code and trigger_days");
                }
                if ($s['kind'] === 'trigger' && !in_array($s['trigger_code'], self::BUILT_IN_TRIGGERS, true) && !isset($providers['triggers'][$s['trigger_code']])) {
                    throw new \InvalidArgumentException("Providers::register: system[$i] names trigger '{$s['trigger_code']}', which 'triggers' does not register");
                }
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
                throw new \InvalidArgumentException("Providers::register: $what ($class) has some of " . implode(', ', self::PHONE_PROVING) . ' but not all: a member could be asked for a code and never be able to enter it.');
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

    /** @return array<int,array> the app's own kinds of message, as given */
    public static function categories(): array { return self::$p['categories'] ?? []; }

    /** @return array<int,array> the campaign specs the app wants to exist */
    public static function system(): array { return self::$p['system'] ?? []; }

    /** @return array<string,string> every trigger code that can be evaluated, with the words the admin sees for its number of days */
    public static function triggerLabels(): array {
        $out = ['inactive_days' => 'After this many days without signing in', 'joined_days' => 'Days after joining'];
        foreach (self::triggers() as $code => $class) $out[$code] = (string) $class::label();
        return $out;
    }
}

<?php
/**
 * The plugin's test base: a scratch SQLite database built by the plugin's own schema seed, three members, the two built-in kinds of message plus
 * one the test host would register, and fake providers (an audience "group:1", a phone book). Nothing here is the app's: the plugin is tested alone.
 */

namespace app\concepts\messaging\tests;

foreach (['Providers', 'Reach', 'Campaigns', 'Digest', 'Webhook', 'Runner'] as $c) require_once dirname(__DIR__) . "/lib/{$c}.php";
foreach (['Msgcategory', 'Msgpref', 'Msgsuppression', 'Msgevent', 'Msgsend', 'Msgcampaign'] as $m) require_once dirname(__DIR__) . "/models/Model_{$m}.php";

use app\Bean;
use app\concepts\messaging\Providers;
use PHPUnit\Framework\TestCase;

/** An audience provider whose groups, owners and switches the test sets. */
class FakeAudiences {
    /** @var array<string,int[]> key => member ids */
    public static array $members = [];
    /** @var array<string,array<int,bool>> key => member id => switch (absent = on) */
    public static array $switch = [];
    public static array $owners = [];
    public static array $names = [];

    public static function reset(): void { self::$members = []; self::$switch = []; self::$owners = []; self::$names = []; }
    public static function category(): string { return 'news'; }
    public static function scopes(int $forMember): array {
        $out = [];
        foreach (self::$members as $key => $ids) if (in_array($forMember, $ids, true)) $out[] = ['key' => $key, 'label' => self::label($key), 'owner_id' => self::$owners[$key] ?? 0];
        return $out;
    }
    public static function members(string $key): array { return self::$members[$key] ?? throw new \InvalidArgumentException("no audience $key"); }
    public static function label(string $key): string { return self::$names[$key] ?? throw new \InvalidArgumentException("no audience $key"); }
    public static function allowsFor(int $memberId, string $key): ?bool { return self::$switch[$key][$memberId] ?? true; }
    public static function allow(int $memberId, string $key, bool $on): void { self::$switch[$key][$memberId] = $on; }
    public static function mayMessage(int $memberId, string $key): bool { return (self::$owners[$key] ?? 0) === $memberId; }
    public static function vars(string $key): array { return str_starts_with($key, 'group:') ? ['group' => ['name' => self::label($key)]] : []; }
    public static function link(string $key): ?string { return 'https://example.test/' . str_replace(':', '/', $key); }
}

/** A phone book: member id => proved phone. */
class FakePhone {
    public static array $book = [];
    public static function verified(int $memberId): string { return self::$book[$memberId] ?? ''; }
}

abstract class MessagingCase extends TestCase {
    protected string $db;
    private ?string $was = null;
    /** @var array<string,string> settings by key */
    protected static array $settings = [];
    /** @var int[] */
    protected array $ids = [];
    protected int $newsId;
    protected int $remindersId;

    protected function setUp(): void {
        $this->db = 'messaging-test-' . bin2hex(random_bytes(4));
        $this->was = Bean::currentDatabaseKey();
        Bean::addDatabase($this->db, 'sqlite::memory:');
        Bean::selectDatabase($this->db);
        \Flight::set('app.baseurl', 'https://example.test');
        \Flight::set('security.app_key', str_repeat('ab', 32));
        $this->hadLog = \Flight::get('log');
        \Flight::set('log', new class { public function __call($m, $a) { return null; } });
        self::$settings = ['messaging_reply_domain' => 'reply.example.test'];
        \Flight::map('getSetting', fn($k, $m = null) => self::$settings[$k] ?? null);
        \Flight::map('setSetting', function ($k, $v, $m = null) { self::$settings[$k] = (string) $v; return 1; });

        foreach (['ann', 'bo', 'cy'] as $name) {
            $m = Bean::dispense('member');
            $m->username = $name; $m->email = "$name@example.test"; $m->firstName = ucfirst($name); $m->level = 100; $m->status = 'active'; $m->createdAt = date('Y-m-d H:i:s');
            Bean::store($m);
            $this->ids[$name] = (int) $m->id;
        }
        $_tableCheck = fn(string $t): bool => in_array(Bean::normalize($t), Bean::inspect(), true);
        $_dispensePadding = function (string $type) {   // as the schema builder does: a bean whose model's rules do not run
            $oodb = \RedBeanPHP\R::getRedBean();
            $helper = $oodb->getBeanHelper();
            $oodb->setBeanHelper(new class extends \RedBeanPHP\BeanHelper\SimpleFacadeBeanHelper {
                public function getModelForBean(\RedBeanPHP\OODBBean $bean) { return null; }
            });
            try { return Bean::dispense($type); } finally { $oodb->setBeanHelper($helper); }
        };
        $_defer = function ($bean): void { $this->padding[] = $bean; };
        $this->padding = [];
        ob_start();
        include dirname(__DIR__) . '/seeds/01_MessagingSchema.php';
        ob_end_clean();
        foreach (array_reverse($this->padding) as $b) Bean::trash($b);   // the build trashes its padding rows the same way
        $this->newsId = (int) \Model_Msgcategory::register('news', 'News', 'News from groups you belong to', true, true)->id;
        $this->remindersId = (int) \Model_Msgcategory::register('reminders', 'Reminders', 'Reminders', true, true)->id;
        \Model_Msgcategory::register('transactional', 'Transactional', 'Always on', true, false);
        FakeAudiences::reset();
        FakePhone::$book = [];
        FakeAudiences::$members['group:1'] = [$this->ids['ann'], $this->ids['bo']];
        FakeAudiences::$names['group:1'] = 'Walkers';
        FakeAudiences::$owners['group:1'] = $this->ids['ann'];
        Providers::reset();
        Providers::register(['audiences' => FakeAudiences::class, 'phone' => FakePhone::class]);
    }

    private array $padding = [];
    private $hadLog = null;

    protected function tearDown(): void {
        Providers::reset();
        \Flight::set('log', $this->hadLog);
        if ($this->was !== null && Bean::hasDatabase($this->was)) Bean::selectDatabase($this->was);
    }

    /** A draft of an owner's message to group:1 (not stored). */
    protected function campaign(array $o = []) {
        $c = Bean::dispense('msgcampaign');
        $c->senderId = $this->ids['ann']; $c->categoryRef = $o['category'] ?? $this->newsId; $c->kind = $o['kind'] ?? 'message';
        $c->scopeKey = array_key_exists('scope', $o) ? $o['scope'] : 'group:1'; $c->aboutKey = $o['about'] ?? null;
        $c->subject = $o['subject'] ?? 'Hi {first_name|you}'; $c->bodyEmail = $o['body'] ?? '<p><a href="https://example.org/x">x</a></p>'; $c->bodyText = $o['text'] ?? '';
        $c->bodySms = $o['sms'] ?? null; $c->cadence = $o['cadence'] ?? 'once'; $c->cadenceDays = $o['days'] ?? null; $c->status = $o['status'] ?? 'draft';
        if (isset($o['trigger'])) { $c->triggerCode = $o['trigger']; $c->triggerDays = $o['triggerDays'] ?? 7; }
        return $c;
    }

    protected function stored(array $o = []) {
        $c = $this->campaign($o);
        Bean::store($c);
        return $c;
    }

    protected function ledger($campaign, int $memberId, string $token, string $channel = 'email') {
        $s = Bean::dispense('msgsend');
        $s->msgcampaignId = (int) $campaign->id; $s->memberId = $memberId; $s->channel = $channel; $s->token = $token; $s->links = '[]'; $s->clicks = 0;
        $s->createdAt = date('Y-m-d H:i:s');
        Bean::store($s);
        return $s;
    }
}

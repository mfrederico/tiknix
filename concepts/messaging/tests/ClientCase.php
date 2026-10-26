<?php
/**
 * The plugin's test base: a scratch SQLite member table, fake providers, and a FAKE SERVICE — Client::$transport records
 * every call and answers from a script of canned replies ($answers, matched by "METHOD path"). Nothing here reaches the
 * service or a database of the app's: the plugin is tested as what it is, a client.
 */

namespace app\concepts\messaging\tests;

foreach (['Client', 'Providers', 'Sync', 'Reach', 'Campaigns', 'Digest', 'Runner'] as $c) require_once dirname(__DIR__) . "/lib/{$c}.php";

use app\Bean;
use app\concepts\messaging\Client;
use app\concepts\messaging\Providers;
use PHPUnit\Framework\TestCase;

class FakeAudiences {
    public static array $members = [];
    public static array $switch = [];
    public static array $owners = [];
    public static array $names = [];
    public static function reset(): void { self::$members = []; self::$switch = []; self::$owners = []; self::$names = []; }
    public static function category(): string { return 'news'; }
    public static function all(): array {
        $out = [];
        foreach (self::$names as $key => $label) $out[] = ['key' => $key, 'label' => $label, 'owner_id' => self::$owners[$key] ?? 0];
        return $out;
    }
    public static function scopes(int $forMember): array {
        $out = [];
        foreach (self::$members as $key => $ids) if (in_array($forMember, $ids, true)) $out[] = ['key' => $key, 'label' => self::label($key), 'owner_id' => self::$owners[$key] ?? 0];
        return $out;
    }
    public static function members(string $key): array { return self::$members[$key] ?? throw new \InvalidArgumentException("no audience $key"); }
    public static function label(string $key): string { return self::$names[$key] ?? throw new \InvalidArgumentException("no audience $key"); }
    public static function allowsFor(int $memberId, string $key): ?bool { return self::$switch[$key][$memberId] ?? null; }
    public static function mayMessage(int $memberId, string $key): bool { return (self::$owners[$key] ?? 0) === $memberId; }
    public static function vars(string $key): array { return ['group' => ['name' => self::label($key)]]; }
    public static function link(string $key): ?string { return 'https://example.test/' . str_replace(':', '/', $key); }
}

class FakeDigest {
    /** @var array<int,?array> member id => content */
    public static array $content = [];
    public static array $asked = [];
    public static function content(int $memberId, string $since): ?array { self::$asked[] = [$memberId, $since]; return self::$content[$memberId] ?? null; }
}

class FakeTrigger {
    public static array $qualify = [];
    public static function label(): string { return 'Days with no friends'; }
    public static function qualifies(int $memberId, int $days): bool { return in_array($memberId, self::$qualify, true); }
    public static function content(int $memberId): ?array { return ['headline' => "Make a friend, #$memberId", 'html' => '<p>go</p>', 'text' => 'go']; }
}

class FakePhone {
    public static array $book = [];
    public static function verified(int $memberId): string { return self::$book[$memberId] ?? ''; }
}

abstract class ClientCase extends TestCase {
    protected string $db;
    private ?string $was = null;
    private $hadLog = null;
    /** @var array<int,array{0:string,1:string,2:?array}> every call the plugin made: [method, path, body] */
    public static array $calls = [];
    /** @var array<string,array|callable> "METHOD path" => answer body (ok added) or a callable(body) => [code, body] */
    public static array $answers = [];
    protected array $ids = [];

    protected function setUp(): void {
        $this->db = 'messaging-test-' . bin2hex(random_bytes(4));
        $this->was = Bean::currentDatabaseKey();
        Bean::addDatabase($this->db, 'sqlite::memory:');
        Bean::selectDatabase($this->db);
        \Flight::set('app.baseurl', 'https://example.test');
        $this->hadLog = \Flight::get('log');
        \Flight::set('log', new class { public array $errors = []; public function __call($m, $a) { if ($m === 'error') $this->errors[] = $a[0]; return null; } });
        $settings = ['timezone' => []];
        \Flight::map('getSetting', function ($k, $m = null) use (&$settings) { return $m ? ($settings[$k][$m] ?? null) : ($settings[$k] ?? null); });
        \Flight::map('setSetting', function ($k, $v, $m = null) use (&$settings) { $settings[$k] = (string) $v; return 1; });
        foreach (['ann', 'bo', 'cy'] as $i => $name) {
            $m = Bean::dispense('member');
            $m->username = $name; $m->email = "$name@example.test"; $m->firstName = ucfirst($name); $m->level = $name === 'ann' ? 50 : 100; $m->status = 'active';
            $m->createdAt = date('Y-m-d H:i:s', time() - (10 + $i) * 86400); $m->lastLogin = $name === 'cy' ? null : date('Y-m-d H:i:s');
            Bean::store($m);
            $this->ids[$name] = (int) $m->id;
        }
        FakeAudiences::reset();
        FakeAudiences::$names['group:1'] = 'Walkers';
        FakeAudiences::$members['group:1'] = [$this->ids['ann'], $this->ids['bo']];
        FakeAudiences::$owners['group:1'] = $this->ids['ann'];
        FakeAudiences::$switch['group:1'][$this->ids['bo']] = false;
        FakeDigest::$content = []; FakeDigest::$asked = [];
        FakeTrigger::$qualify = [];
        FakePhone::$book = [$this->ids['bo'] => '+15551234567'];
        Providers::reset();
        self::$calls = [];
        self::$answers = [];
        Client::$transport = function (string $method, string $path, ?array $body): array {
            self::$calls[] = [$method, $path, $body];
            $key = $method . ' ' . preg_replace('/\?.*$/', '', $path);
            $a = self::$answers[$key] ?? self::$answers[$method . ' ' . $path] ?? null;
            if ($a === null) return ['code' => 200, 'body' => ['ok' => true]];
            if (is_callable($a)) { [$code, $b] = $a($body, $path); return ['code' => $code, 'body' => $b + ($code < 300 ? ['ok' => true] : ['ok' => false])]; }
            return ['code' => 200, 'body' => ['ok' => true] + $a];
        };
    }

    protected function tearDown(): void {
        Providers::reset();
        Client::$transport = null;
        \Flight::set('log', $this->hadLog);
        if ($this->was !== null && Bean::hasDatabase($this->was)) Bean::selectDatabase($this->was);
    }

    /** The calls made to a path (method and path prefix), bodies only. */
    protected function sent(string $method, string $pathPrefix): array {
        $out = [];
        foreach (self::$calls as [$m, $p, $b]) if ($m === $method && str_starts_with($p, $pathPrefix)) $out[] = $b;
        return $out;
    }

    protected function paths(): array { return array_map(fn($c) => $c[0] . ' ' . preg_replace('/\?.*$/', '', $c[1]), self::$calls); }
}

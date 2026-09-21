<?php
/**
 * Builds throwaway installs on disk — a root with a concepts/ directory — so the concept
 * runtime is exercised against real files with no database and no Flight. Each concept gets
 * a unique name per test, because PHP cannot unload a class once a fixture has declared it.
 */

namespace tests\unit;

use app\Concepts;
use PHPUnit\Framework\TestCase;

abstract class ConceptsTestCase extends TestCase {

    protected string $root;
    /** @var string[] concept names the fake install has switched on */
    protected array $on = [];
    protected int $level = 101;
    /** @var array<string,int> bean → row count, for disable() */
    protected array $rows = [];
    /** @var array<int,array> calls made to the seed runner */
    protected array $seedCalls = [];
    protected array $seedResult = [];

    private static int $seq = 0;

    protected function setUp(): void {
        $this->root = sys_get_temp_dir() . '/tiknix-concepts-' . getmypid() . '-' . (++self::$seq);
        mkdir($this->root . '/concepts', 0700, true);
        mkdir($this->root . '/models', 0700, true);
    }

    protected function tearDown(): void {
        foreach ($this->registered as $loader) spl_autoload_unregister($loader);
        $this->registered = [];
        $this->rm($this->root);
    }

    /** @var callable[] autoloaders this test registered, removed again in tearDown */
    private array $registered = [];

    /** A concept name no other test has used: tkt1, tkt2, … */
    protected function uniq(string $stem): string {
        return $stem . (++self::$seq);
    }

    /** A registry over this test's install, with its autoloader live (as Concepts::boot() does). */
    protected function concepts(): Concepts {
        $c = $this->build();
        $this->registered[] = $loader = [$c, 'autoload'];
        spl_autoload_register($loader);
        return $c;
    }

    private function build(): Concepts {
        return new Concepts($this->root, [
            'enabled' => fn(): array => $this->on,
            'level'   => fn(): int => $this->level,
            'setFlag' => function (string $name, bool $on): void {
                $this->on = $on ? array_values(array_unique([...$this->on, $name]))
                                : array_values(array_diff($this->on, [$name]));
            },
            'seed'    => function (string $dir): array { $this->seedCalls[] = $dir; return $this->seedResult; },
            'rows'    => fn(string $bean): int => $this->rows[$bean] ?? 0,
        ]);
    }

    /** Write concepts/<name>/concept.json (name + version filled in) and any extra files. */
    protected function concept(string $name, array $manifest = [], array $files = []): string {
        $dir = "{$this->root}/concepts/{$name}";
        $this->put("{$dir}/concept.json", json_encode(['name' => $name, 'version' => '1.0.0'] + $manifest));
        foreach ($files as $rel => $body) $this->put("{$dir}/{$rel}", $body);
        return $dir;
    }

    protected function rootManifest(array $hosts): void {
        $this->put("{$this->root}/concept.json", json_encode(['name' => 'root', 'version' => '1.0.0', 'hosts' => $hosts]));
    }

    protected function put(string $file, string $body): void {
        if (!is_dir(dirname($file))) mkdir(dirname($file), 0700, true);
        file_put_contents($file, $body);
    }

    /** A class in app\concepts\<name>\ with the given static method bodies. */
    protected function cls(string $name, string $class, array $methods): string {
        $body = '';
        foreach ($methods as $sig => $code) $body .= "    public static function {$sig} { {$code} }\n";
        return "<?php\nnamespace app\\concepts\\{$name};\nclass {$class} {\n{$body}}\n";
    }

    private function rm(string $path): void {
        if (is_link($path) || is_file($path)) { unlink($path); return; }
        if (!is_dir($path)) return;
        foreach (scandir($path) as $e) {
            if ($e !== '.' && $e !== '..') $this->rm("{$path}/{$e}");
        }
        rmdir($path);
    }
}

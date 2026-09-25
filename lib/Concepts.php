<?php
/**
 * Concepts — the runtime for pluggable features (COMPONENTS_PLAN.md).
 *
 * A concept is a self-contained directory that shares the instance's one runtime:
 *
 *   concepts/tickets/
 *     concept.json     the manifest — and the whole registration (pure data)
 *     controls/        app\concepts\tickets\…   routable only while enabled
 *     lib/             app\concepts\tickets\…   incl. Portlets.php
 *     models/          global Model_* (FUSE), loadable only while enabled
 *     views/  seeds/
 *
 * What this class does:
 *   - autoloads an ENABLED concept's classes, and nothing from a disabled one
 *   - tells the router which controller a URL names, and which directories may hold one
 *   - renders slots (markup) and gathers collect points (data) that hosts expose
 *   - verifies a concept before it is switched on, and refuses with every reason named
 *
 * Off means inert: a disabled concept contributes no class, no route, no partial. Absent is
 * fine — an empty slot renders nothing. Broken is a fault — an enabled concept whose
 * manifest, class or view is wrong throws ConceptException naming it.
 */

namespace app;

use RedBeanPHP\OODBBean;

class Concepts {

    public const DIR = 'concepts';

    /** Feature::installKeys() prefix: install.concept.<name> */
    private const FLAG_PREFIX = 'concept';

    /** One namespace/class-name segment. Class names arrive from URLs, so this is a path-traversal guard. */
    private const PART_RE = '/^[A-Za-z_][A-Za-z0-9_]*$/D';
    private const NAME_RE = '/^[a-z][a-z0-9]*$/D';

    private static ?self $instance = null;

    private string $root;

    /**
     * The outside world, injected so the registry can be tested with no database and no
     * Flight: enabled (): string[] · level (): int · setFlag (name, bool) · seed (dir): array
     * · rows (bean): int
     *
     * @var array<string,callable>
     */
    private array $io;

    /** @var array<string,ConceptManifest>|null enabled concepts, this request */
    private ?array $enabled = null;
    private ?ConceptManifest $rootManifest = null;
    private bool $rootLoaded = false;
    /** @var array<string,true> concepts whose classes may load while verify() inspects them */
    private array $verifying = [];

    public function __construct(string $root, array $io) {
        foreach (['enabled', 'level', 'setFlag', 'seed', 'rows'] as $k) {
            if (!isset($io[$k]) || !is_callable($io[$k])) {
                throw new \InvalidArgumentException("Concepts: io['{$k}'] must be callable.");
            }
        }
        $this->root = rtrim($root, '/');
        $this->io = $io;
    }

    /* ---- static facade (the install's own registry) --------------------------------- */

    /**
     * Called once by the bootstrap. Does nothing on an install with no concepts/ directory —
     * no autoloader, no query — so an instance that uses no concepts pays nothing.
     */
    public static function boot(string $root): void {
        if (!is_dir(rtrim($root, '/') . '/' . self::DIR)) return;
        $self = self::instance($root);
        spl_autoload_register([$self, 'autoload']);
    }

    public static function instance(?string $root = null): self {
        if (self::$instance === null) {
            self::$instance = new self($root ?? dirname(__DIR__), [
                'enabled' => fn(): array => Feature::installKeys(self::FLAG_PREFIX),
                'level'   => fn(): int => (int) (\Flight::getMember()->level ?? ConceptManifest::LEVEL_VALUES['PUBLIC']),
                'setFlag' => fn(string $name, bool $on) => Feature::setInstallEnabled(self::FLAG_PREFIX . '.' . $name, $on),
                'seed'    => fn(string $dir): array => (new \app\services\Schema\WorkspaceSchemaBuilder())->build($dir),
                'rows'    => fn(string $bean): int => in_array(Bean::normalize($bean), Bean::inspect(), true) ? (int) Bean::count($bean) : 0,
            ]);
        }
        return self::$instance;
    }

    /** Is this concept installed AND switched on? */
    public static function has(string $name): bool {
        return isset(self::instance()->enabled()[$name]);
    }

    /** Markup from every enabled concept registered in $slot, in order. */
    public static function slot(string $slot, array $ctx = []): string {
        return implode('', array_column(self::instance()->parts($slot, $ctx), 'html'));
    }

    /** The same, one element per registration — for a host that wraps each part itself. */
    public static function slotParts(string $slot, array $ctx = []): array {
        return self::instance()->parts($slot, $ctx);
    }

    /** Data merged from every enabled concept registered at $point (nav is an array, not HTML). */
    public static function collect(string $point, array $ctx = []): array {
        return self::instance()->gather($point, $ctx);
    }

    /** A form slot's other half: hand the submitted input to every matching registration's `save`. */
    public static function saveSlot(string $slot, OODBBean $bean, array $input, array $ctx = []): void {
        self::instance()->save($slot, $bean, $input, $ctx);
    }

    /* ---- what is installed / enabled ------------------------------------------------ */

    public function conceptsDir(): string {
        return $this->root . '/' . self::DIR;
    }

    /**
     * Enabled concepts' manifests. An enabled concept that is missing or malformed throws:
     * "switched on but not there" is a fault, not an empty list.
     *
     * @return array<string,ConceptManifest>
     */
    public function enabled(): array {
        if ($this->enabled === null) {
            $out = [];
            foreach ($this->enabledNames() as $name) {
                $out[$name] = ConceptManifest::load($this->conceptsDir() . '/' . $name, $name);
            }
            $this->enabled = $out;
        }
        return $this->enabled;
    }

    /** @return string[] */
    private function enabledNames(): array {
        if (!is_dir($this->conceptsDir())) return [];
        $names = [];
        foreach (($this->io['enabled'])() as $name) {
            if (!is_string($name) || !preg_match(self::NAME_RE, $name)) {
                throw new ConceptException('Concepts: enabled flag ' . json_encode($name) . ' is not a valid concept name.');
            }
            $names[] = $name;
        }
        return $names;
    }

    /** The instance's own manifest (<root>/concept.json), if it ships one. It hosts; it registers nothing. */
    public function rootManifest(): ?ConceptManifest {
        if (!$this->rootLoaded) {
            $this->rootLoaded = true;
            if (is_file($this->root . '/' . ConceptManifest::FILE)) {
                $this->rootManifest = ConceptManifest::load($this->root, ConceptManifest::ROOT);
            }
        }
        return $this->rootManifest;
    }

    /**
     * Every concept directory on disk, working or not — for listings.
     *
     * @return array<string,array{manifest:?ConceptManifest,error:?string,enabled:bool}>
     */
    public function scan(): array {
        $out = [];
        $on = array_flip($this->enabledNames());
        foreach (glob($this->conceptsDir() . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $name = basename($dir);
            try {
                $out[$name] = ['manifest' => ConceptManifest::load($dir, $name), 'error' => null, 'enabled' => isset($on[$name])];
            } catch (ConceptException $e) {
                $out[$name] = ['manifest' => null, 'error' => $e->getMessage(), 'enabled' => isset($on[$name])];
            }
        }
        ksort($out);
        return $out;
    }

    /**
     * Where an installed concept came from (the catalog's .installed.json), or null when it
     * was authored here. A file that exists but does not parse is a fault, not "authored here".
     */
    public function provenance(string $name): ?array {
        if (!preg_match(self::NAME_RE, $name)) return null;
        $file = $this->conceptsDir() . "/{$name}/" . ConceptCatalog::PROVENANCE_FILE;
        if (!is_file($file)) return null;
        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data)) {
            throw new ConceptException("Concept '{$name}': {$file} exists but is not valid JSON.");
        }
        return $data;
    }

    /**
     * The first program of a requires.commands entry ("google-chrome|chromium") this process
     * can run, as its path, or null.
     */
    public static function commandOnPath(string $entry): ?string {
        foreach (explode('|', $entry) as $bin) {
            // Asked of the shell, not stat()ed: under an isolated install's open_basedir,
            // is_file('/usr/bin/x') is false for everything outside the tree, while running
            // /usr/bin/x is still allowed — the program is found the way proc_open() finds it.
            // Pipes for every descriptor: ['file', '/dev/null'] is refused by open_basedir on
            // an isolated install, and proc_open would fail before asking anything.
            $proc = @proc_open(['sh', '-c', 'command -v -- "$1" 2>/dev/null', 'sh', $bin],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($proc)) continue;
            fclose($pipes[0]);
            $path = trim((string) stream_get_contents($pipes[1]));
            fclose($pipes[1]); fclose($pipes[2]);
            if (proc_close($proc) === 0 && $path !== '') return $path;
        }
        return null;
    }

    /* ---- autoload ------------------------------------------------------------------- */

    /**
     * app\concepts\<name>\<Class> → concepts/<name>/{controls,lib}/<Class>.php
     * Model_<Bean>                → concepts/<name>/models/Model_<Bean>.php
     *
     * Only for enabled concepts. Never throws: an autoloader that throws turns every
     * class_exists() in the application into a crash. A failure is logged as an ERROR and
     * the class stays unloaded, which the caller then reports in its own terms.
     */
    public function autoload(string $class): void {
        try {
            if (strncmp($class, 'app\\concepts\\', 13) === 0) {
                $parts = explode('\\', substr($class, 13));
                $name = array_shift($parts);
                if (!$parts || !$this->loadable((string) $name)) return;
                foreach ($parts as $p) {
                    if (!preg_match(self::PART_RE, $p)) return;
                }
                $rel = implode('/', $parts) . '.php';
                // app\concepts\<n>\mcptools\<Tool> names its directory, as core's app\mcptools\ does.
                if ($parts[0] === 'mcptools') {
                    $file = $this->conceptsDir() . "/{$name}/{$rel}";
                    if (is_file($file)) require_once $file;
                    return;
                }
                foreach (['controls', 'lib'] as $sub) {
                    $file = $this->conceptsDir() . "/{$name}/{$sub}/{$rel}";
                    if (is_file($file)) { require_once $file; return; }
                }
                return;
            }
            if (strncmp($class, 'Model_', 6) === 0 && preg_match(self::PART_RE, $class)) {
                foreach ($this->loadableNames() as $name) {
                    $file = $this->conceptsDir() . "/{$name}/models/{$class}.php";
                    if (is_file($file)) { require_once $file; return; }
                }
            }
        } catch (\Throwable $e) {
            error_log("ERROR Concepts::autoload({$class}): " . $e->getMessage());
        }
    }

    private function loadable(string $name): bool {
        return preg_match(self::NAME_RE, $name) && in_array($name, $this->loadableNames(), true);
    }

    /** @return string[] */
    private function loadableNames(): array {
        return array_values(array_unique(array_merge($this->enabledNames(), array_keys($this->verifying))));
    }

    /* ---- routing -------------------------------------------------------------------- */

    /** The class an enabled concept claims for this URL segment ('tickets' → app\concepts\tickets\Tickets), or null. */
    public function controllerClass(string $urlSegment): ?string {
        if (!preg_match(self::PART_RE, $urlSegment)) return null;
        $want = strtolower($urlSegment);
        foreach ($this->enabled() as $name => $m) {
            foreach ($m->controllers as $c) {
                if (strtolower($c) === $want) return "app\\concepts\\{$name}\\{$c}";
            }
        }
        return null;
    }

    /**
     * Directories a routable controller's file may live in, beyond core's controls/.
     * The router's containment check uses these, so a disabled concept is unroutable by
     * construction rather than by each controller remembering to say so.
     *
     * @return string[] realpaths
     */
    public function controllerRoots(): array {
        $roots = [];
        foreach ($this->enabled() as $name => $m) {
            $dir = realpath($m->dir . '/controls');
            if ($dir !== false) $roots[] = $dir;
        }
        return $roots;
    }

    /* ---- agent guidance ------------------------------------------------------------- */

    /**
     * Regenerate this install's CLAUDE.md managed block from core's sections plus the
     * enabled concepts' guidelines.md (AgentGuidance). Called after a flag flips — by the
     * CLI and by the Plugins screen — and by --agent-sync. Throws RuntimeException when
     * the install has no agent/guidelines/ or the file cannot be migrated: the caller
     * reports that beside the enable it belongs to, and the flag change stands.
     *
     * @return array{changed:bool,path:string,notes:string[],migrated:bool,skills:array{installed:string[],removed:string[],kept:string[]}}
     */
    public function syncGuidance(): array {
        $enabled = [];
        foreach ($this->enabled() as $name => $m) $enabled[$name] = ['version' => $m->version, 'dir' => $m->dir];
        $r = AgentGuidance::sync($this->root, $enabled);
        $r['skills'] = AgentGuidance::syncSkills($this->root, $enabled);
        return $r;
    }

    /* ---- pipeline definitions ------------------------------------------------------- */

    /**
     * Pipelines enabled concepts ship, for Pipeline\Loader's second argument:
     * slug => ['concept' => name, 'file' => path]. THIS install's flags decide; a reader of
     * another install's directory uses pipelineSourcesFor() with that install's flags.
     *
     * @return array<string,array{concept:string,file:string}>
     */
    public function pipelineSources(): array {
        return self::pipelineSourcesFor($this->conceptsDir(), array_keys($this->enabled()), $this->enabled());
    }

    /**
     * The same, from a concepts/ directory and a list of enabled names — pure, no database:
     * InstanceAutomations, Introspector and pipeline-cron read OTHER installs this way.
     *
     * @param string   $conceptsDir  <install>/concepts
     * @param string[] $enabledNames from that install's install.concept.* flags
     * @param array<string,ConceptManifest> $manifests already-loaded manifests, if the caller has them
     */
    public static function pipelineSourcesFor(string $conceptsDir, array $enabledNames, array $manifests = []): array {
        $out = [];
        foreach ($enabledNames as $name) {
            if (!preg_match(self::NAME_RE, (string) $name)) continue;
            $m = $manifests[$name] ?? null;
            if ($m === null) {
                $dir = rtrim($conceptsDir, '/') . '/' . $name;
                if (!is_file($dir . '/' . ConceptManifest::FILE)) continue;   // enabled but gone: enabled() reports that; a reader lists what is there
                $m = ConceptManifest::load($dir, $name);
            }
            foreach ($m->pipelines as $slug) {
                $out[$slug] = ['concept' => $name, 'file' => $m->dir . '/pipelines/' . $slug . '.json'];
            }
        }
        return $out;
    }

    /**
     * Another install's pipeline sources, from ITS settings: opens that install's sqlite
     * read-only and reads its install.concept.* flags. Throws when the install's database
     * cannot be read — "could not tell" and "none enabled" are different answers, and the
     * caller (InstanceAutomations, pipeline-cron) says which one it got.
     *
     * @return array<string,array{concept:string,file:string}>
     */
    public static function pipelineSourcesForInstall(string $installDir): array {
        $installDir = rtrim($installDir, '/');
        if (!is_dir($installDir . '/' . self::DIR)) return [];   // no concepts/ at all: nothing to read
        $ini = @parse_ini_file($installDir . '/conf/config.ini', true);
        if (!is_array($ini)) throw new \RuntimeException("Concepts: {$installDir}/conf/config.ini could not be parsed.");
        $type = (string) ($ini['database']['type'] ?? '');
        $path = (string) ($ini['database']['path'] ?? '');
        if ($type !== 'sqlite' || $path === '') throw new \RuntimeException("Concepts: {$installDir} has no sqlite [database] path to read concept flags from.");
        $abs = $path[0] === '/' ? $path : "{$installDir}/{$path}";
        try {
            $pdo = new \PDO('sqlite:' . $abs, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            $st = $pdo->query("SELECT setting_key FROM settings WHERE setting_key LIKE 'install.concept.%' AND setting_value = '1'");
            $names = [];
            foreach ($st->fetchAll(\PDO::FETCH_COLUMN) as $key) $names[] = substr((string) $key, strlen('install.concept.'));
        } catch (\Throwable $e) {
            throw new \RuntimeException("Concepts: could not read concept flags from {$abs}: " . $e->getMessage());
        }
        return self::pipelineSourcesFor($installDir . '/' . self::DIR, $names);
    }

    /* ---- MCP tools ------------------------------------------------------------------ */

    /**
     * The MCP tools enabled concepts offer, for mcptools\ToolLoader::register(). A disabled
     * concept's tools are not in this list, so they are absent from tools/list rather than
     * refused at tools/call: an agent that cannot see a tool never spends a step on it.
     *
     * @return array<int,array{class:string,level:int,concept:string}>  class is fully qualified
     */
    public function tools(): array {
        $out = [];
        foreach ($this->enabled() as $name => $m) {
            foreach ($m->tools as $t) {
                $out[] = ['class' => "app\\concepts\\{$name}\\mcptools\\{$t['class']}", 'level' => $t['level'], 'concept' => $name];
            }
        }
        return $out;
    }

    /**
     * The name a concept's tool must carry: "<concept>_<something>". Collisions with core
     * tools are then impossible by construction, and an agent reading `tickets_hold` in a
     * tools/list knows where it came from. Enforced here (verify) and by ConceptLint.
     */
    public static function toolNameOk(string $concept, string $toolName): bool {
        return (bool) preg_match('/^' . preg_quote($concept, '/') . '_[a-z][a-z0-9_]*$/D', $toolName);
    }

    /** The views/ directory of the concept that owns $class, or null when it is not a concept class. */
    public function viewsDirFor(string $class): ?string {
        $class = ltrim($class, '\\');
        if (strncmp($class, 'app\\concepts\\', 13) !== 0) return null;
        $name = explode('\\', substr($class, 13))[0];
        $m = $this->enabled()[$name] ?? null;
        if ($m === null) {
            throw new ConceptException("Concept '{$name}': {$class} is rendering a view but the concept is not enabled.");
        }
        return $m->dir . '/views';
    }

    /* ---- slots ---------------------------------------------------------------------- */

    /** @return array<int,array{concept:string,match:array,html:string}> */
    public function parts(string $slot, array $ctx): array {
        $this->hostOf('slots', $slot);
        $out = [];
        foreach ($this->registrations('slots', $slot) as $e) {
            if (!$this->passes($e, $ctx)) continue;
            $vars = $e['provider'] ? $this->call($e['provider'], [$ctx], 'array', $e['concept'], "{$e['where']}.provider") : [];
            $out[] = [
                'concept' => $e['concept'],
                'match'   => $e['match'],
                'html'    => $this->renderView($e, $vars, $ctx),
            ];
        }
        return $out;
    }

    public function gather(string $point, array $ctx): array {
        $this->hostOf('collect', $point);
        $out = [];
        foreach ($this->registrations('collect', $point) as $e) {
            if ($this->passes($e, $ctx)) $out = array_merge_recursive($out, $e['data']);
        }
        return $out;
    }

    public function save(string $slot, OODBBean $bean, array $input, array $ctx): void {
        $host = $this->hostOf('slots', $slot);
        if (!$host['form']) {
            throw new ConceptException("Slot '{$slot}' is not a form slot (hosted by '{$host['host']}'), so it has nothing to save.");
        }
        foreach ($this->registrations('slots', $slot) as $e) {
            if ($this->passes($e, $ctx)) {
                $this->call($e['save'], [$bean, $input], 'void', $e['concept'], "{$e['where']}.save");
            }
        }
    }

    /**
     * Who hosts a slot / collect point. A host calling a name no manifest declares is a bug
     * in the host, and a typo must not become a slot that silently renders nowhere.
     *
     * @return array{host:string,form:bool}
     */
    private function hostOf(string $kind, string $name, ?ConceptManifest $also = null): array {
        $found = null;
        foreach ($this->hostManifests($also) as $m) {
            $hosts = $kind === 'slots' ? isset($m->hostsSlots[$name]) : in_array($name, $m->hostsCollect, true);
            if (!$hosts) continue;
            if ($found !== null) {
                throw new ConceptException("'{$name}' is hosted by both '{$found['host']}' and '{$m->name}'. A name has one host.");
            }
            $found = ['host' => $m->name, 'form' => $kind === 'slots' ? $m->hostsSlots[$name]['form'] : false];
        }
        if ($found === null) {
            $what = $kind === 'slots' ? 'Slot' : 'Collect point';
            throw new ConceptException(
                "{$what} '{$name}' is not declared under \"hosts\" by the root manifest or any enabled concept.");
        }
        return $found;
    }

    /** @return ConceptManifest[] */
    private function hostManifests(?ConceptManifest $also = null): array {
        $all = $this->enabled();
        if ($also !== null) $all[$also->name] = $also;
        $root = $this->rootManifest();
        return $root !== null ? array_merge([$root], array_values($all)) : array_values($all);
    }

    /** Registrations for one slot/collect point across enabled concepts, in a stable order. */
    private function registrations(string $kind, string $name): array {
        $all = [];
        foreach ($this->enabled() as $m) {
            foreach (($kind === 'slots' ? $m->slots : $m->collect)[$name] ?? [] as $e) $all[] = $e;
        }
        usort($all, fn(array $a, array $b) => [$a['order'], $a['concept'], $a['where']] <=> [$b['order'], $b['concept'], $b['where']]);
        return $all;
    }

    /** Level, then `match`, then `when` — all must pass. This is presentation, never access control. */
    private function passes(array $e, array $ctx): bool {
        if (($this->io['level'])() > $e['level']) return false;
        foreach ($e['match'] as $key => $values) {
            if (!array_key_exists($key, $ctx)) {
                throw new ConceptException(
                    "Concept '{$e['concept']}': {$e['where']}.match needs '{$key}', which the host did not pass in its context.");
            }
            if (!is_scalar($ctx[$key]) || !in_array((string) $ctx[$key], $values, true)) return false;
        }
        if ($e['when'] !== null) {
            return $this->call($e['when'], [$ctx], 'bool', $e['concept'], "{$e['where']}.when");
        }
        return true;
    }

    /**
     * Call a manifest-named static method. The name was shape-checked when the manifest
     * loaded; here it is resolved inside app\concepts\<owner>\ and nowhere else.
     */
    private function call(array $ref, array $args, string $returns, string $concept, string $where) {
        [$class, $method] = $this->resolve($ref, $concept, $where);
        $result = $class::$method(...$args);
        $ok = match ($returns) {
            'array' => is_array($result),
            'bool'  => is_bool($result),
            'void'  => $result === null,
        };
        if (!$ok) {
            throw new ConceptException(
                "Concept '{$concept}': {$where} ({$ref['ref']}) must return {$returns}, got " . get_debug_type($result) . '.');
        }
        return $result;
    }

    /** @return array{0:string,1:string} [fully-qualified class, method] — public static, or it throws */
    private function resolve(array $ref, string $concept, string $where): array {
        $class = "app\\concepts\\{$ref['concept']}\\{$ref['class']}";
        if (!class_exists($class)) {
            throw new ConceptException("Concept '{$concept}': {$where} ({$ref['ref']}) — class {$class} does not exist.");
        }
        if (!method_exists($class, $ref['method'])) {
            throw new ConceptException("Concept '{$concept}': {$where} ({$ref['ref']}) — {$class} has no method {$ref['method']}().");
        }
        $rm = new \ReflectionMethod($class, $ref['method']);
        if (!$rm->isPublic() || !$rm->isStatic()) {
            throw new ConceptException("Concept '{$concept}': {$where} ({$ref['ref']}) must be a public static method.");
        }
        return [$class, $ref['method']];
    }

    /** The view sees what its provider returned, plus $ctx. Never the host view's local scope. */
    private function renderView(array $e, array $vars, array $ctx): string {
        $file = $this->viewFile($this->manifestOf($e['concept']), $e['view'], $e['where']);
        foreach (array_keys($vars) as $k) {
            if (!is_string($k) || $k === 'ctx' || !preg_match(self::PART_RE, $k)) {
                throw new ConceptException(
                    "Concept '{$e['concept']}': {$e['where']}.provider returned key " . json_encode($k)
                  . ". Keys become view variables: plain identifiers, and 'ctx' is reserved.");
            }
        }
        $vars['ctx'] = $ctx;
        $render = static function (string $__file, array $__vars): string {
            extract($__vars, EXTR_SKIP);
            ob_start();
            try {
                include $__file;
                return (string) ob_get_clean();
            } catch (\Throwable $e) {
                ob_end_clean();
                throw $e;
            }
        };
        return $render($file, $vars);
    }

    private function viewFile(ConceptManifest $m, string $view, string $where): string {
        $viewsDir = realpath($m->dir . '/views');
        $file = $viewsDir === false ? false : realpath($viewsDir . '/' . $view);
        if ($file === false || strncmp($file, $viewsDir . '/', strlen($viewsDir) + 1) !== 0) {
            throw new ConceptException("Concept '{$m->name}': {$where}.view '{$view}' is not a file inside {$m->dir}/views.");
        }
        return $file;
    }

    private function manifestOf(string $name): ConceptManifest {
        $m = $this->enabled()[$name] ?? null;
        if ($m === null) throw new ConceptException("Concept '{$name}' is not enabled.");
        return $m;
    }

    /* ---- enable / disable ----------------------------------------------------------- */

    /**
     * Everything wrong with switching $name on, as a list of sentences. Empty = ready.
     * Collected rather than thrown one at a time, so a concept is fixed in one pass.
     *
     * @return string[]
     */
    public function verify(string $name): array {
        if (!preg_match(self::NAME_RE, $name)) return ["'{$name}' is not a valid concept name."];
        try {
            $m = ConceptManifest::load($this->conceptsDir() . '/' . $name, $name);
        } catch (ConceptException $e) {
            return [$e->getMessage()];
        }

        $problems = [];
        $others = $this->enabled();
        unset($others[$name]);

        foreach ($m->requiresConcepts as $req) {
            if (!isset($others[$req])) $problems[] = "requires concept '{$req}', which is not enabled.";
        }
        foreach ($m->requiresLib as $lib) {
            if (!class_exists('app\\' . $lib)) $problems[] = "requires.lib names app\\{$lib}, which does not exist.";
        }
        foreach ($m->requiresCommands as $entry) {
            if (self::commandOnPath($entry) === null) {
                $problems[] = "requires.commands needs '{$entry}' on PATH, and this server has none of "
                    . str_replace('|', ', ', $entry) . ". Install it (or one of them) before enabling.";
            }
        }

        $this->verifying[$name] = true;
        try {
            foreach ($m->controllers as $c) {
                // Any app\<Name> shadows the concept: the router tries core first, so even a lib
                // class of that name would turn the concept's URL into a refused 404.
                if (class_exists('app\\' . $c)) {
                    $problems[] = "provides.controllers claims '{$c}', but core already has app\\{$c}.";
                }
                foreach ($others as $o) {
                    if (in_array(strtolower($c), array_map('strtolower', $o->controllers), true)) {
                        $problems[] = "provides.controllers claims '{$c}', already claimed by concept '{$o->name}'.";
                    }
                }
                $file = "{$m->dir}/controls/{$c}.php";
                if (!is_file($file)) {
                    $problems[] = "provides.controllers lists '{$c}', but {$file} does not exist.";
                } elseif (!class_exists("app\\concepts\\{$name}\\{$c}")) {
                    $problems[] = "{$file} does not define app\\concepts\\{$name}\\{$c}.";
                }
            }
            foreach ($m->pipelines as $slug) {
                $file = "{$m->dir}/pipelines/{$slug}.json";
                if (!is_file($file)) { $problems[] = "provides.pipelines lists '{$slug}', but {$file} does not exist."; continue; }
                $def = json_decode((string) file_get_contents($file), true);
                if (!is_array($def)) { $problems[] = "{$file} is not valid JSON."; continue; }
                if (($def['slug'] ?? null) !== $slug) {
                    $problems[] = "{$file} has slug '" . ($def['slug'] ?? '') . "'; the file name and provides.pipelines say '{$slug}'.";
                    continue;
                }
                foreach (\app\Pipeline\Loader::validate($def) as $why) $problems[] = "pipeline '{$slug}': {$why}";
                // The instance's own pipelines/ wins in the Loader, so a clash would make this
                // concept's pipeline silently unreachable: refused here instead.
                if (is_file($this->root . "/pipelines/{$slug}.json")) {
                    $problems[] = "provides.pipelines claims '{$slug}', but this install already has pipelines/{$slug}.json.";
                }
                foreach ($others as $o) {
                    if (in_array($slug, $o->pipelines, true)) $problems[] = "provides.pipelines claims '{$slug}', already claimed by concept '{$o->name}'.";
                }
            }
            foreach ($m->tools as $t) {
                $c = $t['class'];
                $file = "{$m->dir}/mcptools/{$c}.php";
                $fqn  = "app\\concepts\\{$name}\\mcptools\\{$c}";
                if (!is_file($file)) {
                    $problems[] = "provides.tools lists '{$c}', but {$file} does not exist.";
                    continue;
                }
                if (!class_exists($fqn)) {
                    $problems[] = "{$file} does not define {$fqn}.";
                    continue;
                }
                if (!is_subclass_of($fqn, 'app\\mcptools\\BaseTool')) {
                    $problems[] = "{$fqn} must extend app\\mcptools\\BaseTool.";
                    continue;
                }
                $toolName = (string) $fqn::$name;
                if (!self::toolNameOk($name, $toolName)) {
                    $problems[] = "{$fqn}::\$name is '{$toolName}'; a concept's tool must be named '{$name}_<something>' (lowercase).";
                }
            }
            foreach ($m->beans as $bean) {
                // Core's file, not class_exists(): with this concept loadable, its own model
                // would answer for the class and hide the collision.
                if (is_file($this->root . '/models/Model_' . ucfirst($bean) . '.php')) {
                    $problems[] = "provides.beans claims '{$bean}', but core already has models/Model_" . ucfirst($bean) . '.php.';
                }
                foreach ($others as $o) {
                    if (in_array($bean, $o->beans, true)) {
                        $problems[] = "provides.beans claims '{$bean}', already claimed by concept '{$o->name}'.";
                    }
                }
            }
            foreach (array_keys($m->hostsSlots) as $slot)  $this->checkSoleHost('slots', $slot, $m, $problems);
            foreach ($m->hostsCollect as $point)           $this->checkSoleHost('collect', $point, $m, $problems);

            foreach ($m->slots as $slot => $entries) {
                try {
                    $host = $this->hostOf('slots', $slot, $m);
                } catch (ConceptException $e) {
                    $problems[] = $e->getMessage();
                    continue;
                }
                foreach ($entries as $e) {
                    if ($host['form'] && $e['save'] === null) {
                        $problems[] = "{$e['where']}: '{$slot}' is a form slot, so \"save\" is required — fields nobody saves are dropped.";
                    }
                    if (!$host['form'] && $e['save'] !== null) {
                        $problems[] = "{$e['where']}: '{$slot}' is not a form slot, so \"save\" would never be called.";
                    }
                    $this->tryCheck($problems, fn() => $this->viewFile($m, $e['view'], $e['where']));
                    foreach (['provider', 'when', 'save'] as $k) {
                        if ($e[$k] !== null) $this->tryCheck($problems, fn() => $this->resolve($e[$k], $name, "{$e['where']}.{$k}"));
                    }
                }
            }
            foreach ($m->collect as $point => $entries) {
                $this->tryCheck($problems, fn() => $this->hostOf('collect', $point, $m));
                foreach ($entries as $e) {
                    if ($e['when'] !== null) $this->tryCheck($problems, fn() => $this->resolve($e['when'], $name, "{$e['where']}.when"));
                }
            }
        } finally {
            unset($this->verifying[$name]);
        }
        return $problems;
    }

    private function checkSoleHost(string $kind, string $name, ConceptManifest $m, array &$problems): void {
        $this->tryCheck($problems, fn() => $this->hostOf($kind, $name, $m));
    }

    private function tryCheck(array &$problems, callable $check): void {
        try {
            $check();
        } catch (ConceptException $e) {
            $problems[] = $e->getMessage();
        }
    }

    /**
     * Switch a concept on: verify, build its schema and permissions, then set the flag.
     * The flag is last, so a concept whose seeds failed is never left half-on.
     *
     * @return array<string,string> seed file → result
     */
    public function enable(string $name): array {
        $problems = $this->verify($name);
        if ($problems) {
            throw new ConceptException("Concept '{$name}' cannot be enabled:\n  - " . implode("\n  - ", $problems));
        }
        $seeded = [];
        $seedDir = $this->conceptsDir() . "/{$name}/seeds";
        if (is_dir($seedDir)) {
            // Production freezes the schema, so nothing here is created on first store. The
            // builder thaws, runs the seeds, and refreezes — the one place fluid mode belongs.
            $this->verifying[$name] = true;
            try {
                $seeded = ($this->io['seed'])($seedDir);
            } finally {
                unset($this->verifying[$name]);
            }
            $failed = array_filter($seeded, fn($r) => $r !== 'ok');
            if ($failed) {
                $lines = [];
                foreach ($failed as $file => $result) $lines[] = "{$file}: {$result}";
                throw new ConceptException("Concept '{$name}' was NOT enabled — its seeds failed:\n  - " . implode("\n  - ", $lines));
            }
        }
        ($this->io['setFlag'])($name, true);
        $this->enabled = null;
        return $seeded;
    }

    /**
     * Switch a concept off. Refused while another enabled concept requires it, and — unless
     * $force — while its claimed beans still hold rows. Data outlives the code: nothing is
     * ever dropped.
     */
    public function disable(string $name, bool $force = false): void {
        $enabled = $this->enabled();
        if (!isset($enabled[$name])) {
            throw new ConceptException("Concept '{$name}' is not enabled.");
        }
        $dependents = [];
        foreach ($enabled as $o) {
            if (in_array($name, $o->requiresConcepts, true)) $dependents[] = $o->name;
        }
        if ($dependents) {
            throw new ConceptException("Concept '{$name}' cannot be disabled: required by " . implode(', ', $dependents) . '.');
        }
        if (!$force) {
            $live = [];
            foreach ($enabled[$name]->beans as $bean) {
                $n = ($this->io['rows'])($bean);
                if ($n > 0) $live[] = "{$n} {$bean} row(s)";
            }
            if ($live) {
                throw new ConceptException(
                    "Concept '{$name}' cannot be disabled: it still has " . implode(', ', $live)
                  . '. Disabling makes them unreachable (they are kept, never dropped). Pass force to do it anyway.');
            }
        }
        ($this->io['setFlag'])($name, false);
        $this->enabled = null;
    }
}

<?php
/**
 * ConceptCatalog — where published concepts live, and how an install finds and fetches one.
 *
 * One class, two sides of the same door:
 *
 *   LOCAL   the control plane. `[concepts] catalog_dir` names a directory holding one
 *           sub-directory per published concept. Search, read, publish.
 *   REMOTE  an instance. It has no catalog of its own; it asks the control plane over HTTP,
 *           authenticating with its own broker key (conf/broker.ini) — the same capability
 *           it already uses to reach its connections. Search, read, install.
 *
 * An install that is neither is told so, by name. And a catalog that cannot be reached is an
 * ERROR, never an empty result: an outage that looked like "nothing matched" would silently
 * turn every ADOPT into a NEW, and nobody would notice the catalog had stopped answering.
 *
 * A concept travels as a BUNDLE — {name, version, files: {path: base64}} — rather than an
 * archive, so every path is validated as data on the way out and again on the way in. The
 * receiving side never trusts the sender: install() re-checks each path, the size caps and
 * the manifest before a single byte lands under concepts/.
 *
 * See COMPONENTS_PLAN.md.
 */

namespace app;

class ConceptCatalog {

    public const MAX_FILES       = 400;
    public const MAX_FILE_BYTES  = 2_000_000;
    public const MAX_TOTAL_BYTES = 8_000_000;

    /** What a concept may contain. Anything else refuses to publish, naming the file. */
    private const TOP_FILES = ['concept.json', 'README.md', 'guidelines.md', 'screenshot.jpg', 'screenshot.png'];
    private const TOP_DIRS  = ['controls', 'lib', 'models', 'views', 'seeds', 'assets', 'tests', 'mcptools', 'skills', 'pipelines'];
    private const EXTENSIONS = ['php', 'json', 'md', 'txt', 'js', 'css', 'svg', 'png', 'jpg', 'jpeg', 'gif', 'webp'];
    private const PATH_RE = '#^[A-Za-z0-9_][A-Za-z0-9_\-.]*(?:/[A-Za-z0-9_][A-Za-z0-9_\-.]*)*$#D';
    private const NAME_RE = '/^[a-z][a-z0-9]*$/D';

    /** Written into an installed concept: where it came from. Never published back. */
    public const PROVENANCE_FILE = '.installed.json';

    private const STOPWORDS = ['the', 'and', 'for', 'with', 'that', 'this', 'from', 'into', 'can', 'are', 'app', 'add', 'new', 'use'];

    private ?string $dir;
    /** @var array{base:string,key:string}|null */
    private ?array $broker;
    /** @var callable(string $url, array $headers): array{0:int,1:string} */
    private $http;

    /**
     * @param string|null   $dir    a local catalog directory, or null
     * @param array|null    $broker ['base' => 'https://tiknix.com', 'key' => 'brk_…'], or null
     * @param callable|null $http   transport (url, headers, post=false) → [status, body], injectable for tests
     */
    public function __construct(?string $dir, ?array $broker = null, ?callable $http = null) {
        $this->dir = $dir !== null ? rtrim($dir, '/') : null;
        $this->broker = $broker;
        $this->http = $http ?? [self::class, 'httpGet'];
        if ($this->dir === null && $this->broker === null) {
            throw new ConceptException(
                'No concept catalog is configured for this install. On the control plane set '
              . '[concepts] catalog_dir in conf/config.ini; on an instance, conf/broker.ini '
              . '([broker] endpoint + key) is how it reaches the control plane\'s catalog.');
        }
        if ($this->dir !== null && !is_dir($this->dir)) {
            throw new ConceptException("Concept catalog: [concepts] catalog_dir '{$this->dir}' is not a directory.");
        }
    }

    /**
     * The catalog THIS install uses — local if it has one, otherwise the control plane's.
     *
     * Reads conf/config.ini itself rather than asking Flight for the loaded value. The plan
     * orchestrator installs adopted concepts into worktrees, and it boots only the
     * autoloader — no Bootstrap, no Flight config — so a Flight lookup there answered "no
     * catalog configured" on the one machine that has the catalog. Same file Flight loads
     * from; one source, reachable from every process.
     *
     * $root is the install whose config decides, which is the install this CODE belongs to —
     * not the project being built. The executor runs on the control plane, so it reads the
     * catalog directly; the same code on a self-hosted tenant has no catalog_dir and asks
     * the control plane with its broker key.
     */
    public static function forInstall(?string $root = null): self {
        $where = self::locate($root);
        return new self($where['dir'] ?? null, $where['broker'] ?? null);   // neither → throws, naming both settings
    }

    /**
     * Does this install have a catalog to ask at all? "None configured" is ABSENT — a plain
     * install that never adopts anything, and nothing to report. It is decided here, as a
     * value, so it can never be confused with "configured but unreachable", which is a fault
     * and is always reported. (A config that does not parse still throws: that is broken.)
     */
    public static function isConfigured(?string $root = null): bool {
        return self::locate($root) !== [];
    }

    /** @return array{dir?:string,broker?:array{base:string,key:string}} empty = nothing configured */
    private static function locate(?string $root): array {
        $root = rtrim($root ?? dirname(__DIR__), '/');
        $config = is_file("{$root}/conf/config.ini") ? parse_ini_file("{$root}/conf/config.ini", true) : [];
        if ($config === false) {
            throw new ConceptException("Concept catalog: {$root}/conf/config.ini could not be parsed, so [concepts] catalog_dir is unknown.");
        }
        $dir = trim((string) ($config['concepts']['catalog_dir'] ?? ''));
        if ($dir !== '') {
            return ['dir' => $dir[0] === '/' ? $dir : "{$root}/{$dir}"];
        }
        $ini = is_file("{$root}/conf/broker.ini") ? (parse_ini_file("{$root}/conf/broker.ini", true) ?: []) : [];
        $u = parse_url((string) ($ini['broker']['endpoint'] ?? ''));
        $key = (string) ($ini['broker']['key'] ?? '');
        if ($key === '' || empty($u['scheme']) || empty($u['host'])) {
            return [];
        }
        return ['broker' => [
            'base' => $u['scheme'] . '://' . $u['host'] . (isset($u['port']) ? ':' . $u['port'] : ''),
            'key'  => $key,
        ]];
    }

    public function isLocal(): bool {
        return $this->dir !== null;
    }

    public function where(): string {
        return $this->dir ?? ($this->broker['base'] . '/concepthub');
    }

    /* ---- search / get --------------------------------------------------------------- */

    /**
     * @return array{query:string,source:string,results:array<int,array>,broken:array<string,string>}
     */
    public function search(string $query, int $limit = 10): array {
        $limit = max(1, min(50, $limit));
        if (!$this->isLocal()) {
            return $this->remote('search', ['q' => $query, 'limit' => $limit]);
        }
        $results = $broken = [];
        foreach (glob($this->dir . '/*', GLOB_ONLYDIR) ?: [] as $cdir) {
            $name = basename($cdir);
            try {
                $summary = self::summary(ConceptManifest::load($cdir, $name));
            } catch (ConceptException $e) {
                $broken[$name] = $e->getMessage();   // said, not hidden — a broken entry is somebody's bug
                continue;
            }
            $score = self::score($summary, $query);
            if ($score > 0 || trim($query) === '') {
                $results[] = ['score' => $score] + $summary;
            }
        }
        usort($results, fn($a, $b) => [$b['score'], $a['name']] <=> [$a['score'], $b['name']]);
        return ['query' => $query, 'source' => $this->where(), 'results' => array_slice($results, 0, $limit), 'broken' => $broken];
    }

    /** One concept: its summary, the manifest itself, and its file list. */
    public function get(string $name): array {
        self::assertName($name);
        if (!$this->isLocal()) {
            return $this->remote('get', ['name' => $name]);
        }
        $cdir = $this->conceptDir($name);
        $m = ConceptManifest::load($cdir, $name);
        $files = [];
        foreach (self::collect($cdir) as $rel) $files[] = ['path' => $rel, 'bytes' => filesize("{$cdir}/{$rel}")];
        $g = "{$cdir}/" . AgentGuidance::CONCEPT_FILE;
        return ['source' => $this->where()] + self::summary($m)
             + ['manifest' => $m->raw, 'files' => $files, 'guidelines' => is_file($g) ? trim((string) file_get_contents($g)) : ''];
    }

    /** The concept as data: {name, version, files: {path: base64}}. */
    public function bundle(string $name): array {
        self::assertName($name);
        if (!$this->isLocal()) {
            return $this->remote('bundle', ['name' => $name]);
        }
        $cdir = $this->conceptDir($name);
        $m = ConceptManifest::load($cdir, $name);
        $files = [];
        foreach (self::collect($cdir) as $rel) $files[$rel] = base64_encode((string) file_get_contents("{$cdir}/{$rel}"));
        return ['name' => $name, 'version' => $m->version, 'files' => $files];
    }

    /* ---- install as a build ---------------------------------------------------------- */

    /**
     * A plan that installs $name into a project — the shape PlanIngestor::ingest() takes, so
     * an install is approved, run, committed and merged exactly like any other plan. No
     * planner wrote it and no agent runs: its one task is of type 'install', and the executor
     * does the copying (PlanExecutor::installAdopted).
     *
     * Everything $name requires comes with it, resolved from the catalog, in dependency
     * order — except what $projectRoot already has, which is that project's own code and is
     * never reinstalled. A requirement the catalog does not hold is an error naming it: a
     * plan that would install a concept which can never be enabled is not a plan.
     *
     * @return array{title:string,summary:string,subtasks:array<int,array>}
     */
    public function installPlan(string $name, string $projectRoot): array {
        self::assertName($name);
        $projectRoot = rtrim($projectRoot, '/');
        if (is_dir("{$projectRoot}/" . Concepts::DIR . "/{$name}")) {
            throw new ConceptException("Concept '{$name}' is already in this project ({$projectRoot}/" . Concepts::DIR . "/{$name}). It is that project's own code now; there is nothing to install.");
        }

        $order = [];      // names, requirements first
        $detail = [];     // name => get() result
        $visit = function (string $n, array $trail) use (&$visit, &$order, &$detail, $projectRoot) {
            if (in_array($n, $trail, true)) {
                throw new ConceptException('Concept requirements loop: ' . implode(' → ', [...$trail, $n]) . '.');
            }
            if (isset($detail[$n]) || is_dir("{$projectRoot}/" . Concepts::DIR . "/{$n}")) return;
            try {
                $detail[$n] = $this->get($n);
            } catch (ConceptException $e) {
                $why = $trail ? "required by '" . end($trail) . "', but " : '';
                throw new ConceptException("Concept '{$n}' is {$why}not available: " . $e->getMessage());
            }
            foreach ($detail[$n]['requires']['concepts'] as $req) $visit($req, [...$trail, $n]);
            $order[] = $n;
        };
        $visit($name, []);

        $lines = [];
        foreach ($order as $n) {
            $d = $detail[$n];
            $lines[] = "- **{$n}** v{$d['version']}" . ($d['title'] !== '' ? " — {$d['title']}" : '') . ' (' . count($d['files']) . ' files)';
        }
        $main = $detail[$name];
        $also = array_values(array_diff($order, [$name]));
        return [
            'title'   => "Install plugin: {$name}" . ($also ? ' (+ ' . implode(', ', $also) . ')' : ''),
            'summary' => trim("Installs from the concept catalog ({$this->where()}), copied into `concepts/`:\n\n" . implode("\n", $lines)
                       . "\n\n{$main['blurb']}\n\nNo agent runs. The files are committed and merged like any task, "
                       . 'then switched on in the project (seeds, permission rows, agent guidance). It is a COPY — this project\'s own code from then on.'),
            'subtasks' => [[
                'id'          => 't1',
                'title'       => 'Install ' . implode(', ', $order) . ' from the catalog',
                'description' => "Copy into `concepts/`: " . implode(', ', $order) . '. Copied and switched on — nothing is adapted or wired by this task.',
                'task_type'   => 'install',
                'adopts'      => $order,
                'files'       => array_map(fn($n) => Concepts::DIR . "/{$n}/", $order),
                'depends_on'  => [],
                'reuses'      => [],
            ]],
        ];
    }

    /* ---- install (any install) ------------------------------------------------------ */

    /**
     * Copy a catalog concept into <root>/concepts/<name>/. COPY, not link: from here on it
     * is the install's own code. Refuses to overwrite — an installed concept may have been
     * adapted, and replacing it would destroy that work without a word.
     *
     * Installing does NOT enable. Writing code and switching it on are separate decisions.
     *
     * @return array{name:string,version:string,dir:string,files:int}
     */
    public function install(string $name, string $root): array {
        self::assertName($name);
        $root = rtrim($root, '/');
        $target = "{$root}/" . Concepts::DIR . "/{$name}";
        if (file_exists($target)) {
            throw new ConceptException(
                "Concept '{$name}' is already installed at {$target}. It is this install's own code now and may "
              . 'have been adapted, so it is never overwritten. Remove the directory deliberately to reinstall.');
        }

        $bundle = $this->bundle($name);
        $files = self::checkBundle($bundle, $name);

        if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0775, true)) {
            throw new ConceptException("Concept '{$name}': could not create " . dirname($target) . '.');
        }
        // Build beside the target and rename, so a failure never leaves half a concept that
        // the runtime would then try to load. No chmod anywhere: on an isolated instance
        // chmod collapses the ACL mask and locks the pool user out (see CLAUDE.md).
        $tmp = dirname($target) . "/.{$name}.installing-" . bin2hex(random_bytes(4));
        try {
            foreach ($files as $rel => $bytes) {
                $dest = "{$tmp}/{$rel}";
                if (!is_dir(dirname($dest)) && !mkdir(dirname($dest), 0775, true)) {
                    throw new ConceptException("Concept '{$name}': could not create " . dirname($dest) . '.');
                }
                if (file_put_contents($dest, $bytes) !== strlen($bytes)) {
                    throw new ConceptException("Concept '{$name}': could not write {$rel}.");
                }
            }
            $m = ConceptManifest::load($tmp, $name);   // the manifest must still hold once it is on disk
            if ($m->version !== (string) $bundle['version']) {
                throw new ConceptException("Concept '{$name}': bundle says version {$bundle['version']} but its manifest says {$m->version}.");
            }
            // This file is committed into the PROJECT's repository — a client's, eventually. So
            // the source is never the control plane's filesystem path: that would publish the
            // server's layout into every project that adopts anything.
            file_put_contents("{$tmp}/" . self::PROVENANCE_FILE, json_encode([
                'name' => $name, 'version' => $m->version,
                'source' => $this->isLocal() ? 'control-plane catalog' : $this->where(),
                'installed_at' => date('c'), 'bundle_sha256' => hash('sha256', json_encode($bundle['files'])),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
            if (!rename($tmp, $target)) {
                throw new ConceptException("Concept '{$name}': could not move the install into place at {$target}.");
            }
        } catch (\Throwable $e) {
            self::rmTree($tmp);
            throw $e;
        }
        return ['name' => $name, 'version' => $m->version, 'dir' => $target, 'files' => count($files)];
    }

    /* ---- publish (control plane only) ----------------------------------------------- */

    /**
     * Put a concept into the catalog. Refused unless ConceptLint finds no errors, and
     * refused when this version is already published with different contents — a version
     * names one thing forever, or "which calendar 1.0.0 did that instance install?" has no
     * answer.
     *
     * @param string[] $forbid origin-identifying strings for the lint (slug, domain, site name)
     * @return array{name:string,version:string,status:string,files:int,findings:array}
     */
    public function publish(string $conceptDir, array $forbid = []): array {
        if (!$this->isLocal()) {
            throw new ConceptException('Publishing is done on the control plane, which holds the catalog. This install only reads it.');
        }
        $conceptDir = rtrim($conceptDir, '/');
        $name = basename($conceptDir);
        self::assertName($name);

        // Structure first: what the concept may CONTAIN is decided before anything in it is
        // read, so a symlink or a stray file is refused by name rather than linted.
        $files = self::collect($conceptDir);

        $findings = ConceptLint::check($conceptDir, $forbid);
        if (ConceptLint::hasErrors($findings)) {
            $lines = [];
            foreach ($findings as $f) {
                if ($f['severity'] === ConceptLint::ERROR) $lines[] = "{$f['file']}" . ($f['line'] ? ":{$f['line']}" : '') . " — {$f['message']}";
            }
            throw new ConceptException("Concept '{$name}' was NOT published — lint errors:\n  - " . implode("\n  - ", $lines));
        }
        $m = ConceptManifest::load($conceptDir, $name);

        $target = "{$this->dir}/{$name}";
        if (is_dir($target)) {
            $existing = ConceptManifest::load($target, $name);
            $same = self::treeHash($target) === self::treeHash($conceptDir);
            if ($same) {
                return ['name' => $name, 'version' => $m->version, 'status' => 'unchanged', 'files' => count($files), 'findings' => $findings];
            }
            if (version_compare($m->version, $existing->version, '<=')) {
                throw new ConceptException(
                    "Concept '{$name}' was NOT published — the catalog already has version {$existing->version} and this is "
                  . "{$m->version} with different contents. Bump \"version\" in concept.json.");
            }
        }

        $tmp = "{$this->dir}/.{$name}.publishing-" . bin2hex(random_bytes(4));
        $old = "{$this->dir}/.{$name}.replaced-" . bin2hex(random_bytes(4));
        try {
            foreach ($files as $rel) {
                if (!is_dir(dirname("{$tmp}/{$rel}"))) mkdir(dirname("{$tmp}/{$rel}"), 0775, true);
                if (!copy("{$conceptDir}/{$rel}", "{$tmp}/{$rel}")) {
                    throw new ConceptException("Concept '{$name}': could not copy {$rel} into the catalog.");
                }
            }
            $replacing = is_dir($target);
            if ($replacing && !rename($target, $old)) throw new ConceptException("Concept '{$name}': could not set the previous version aside.");
            if (!rename($tmp, $target)) {
                if ($replacing) rename($old, $target);
                throw new ConceptException("Concept '{$name}': could not move the new version into the catalog.");
            }
            if ($replacing) self::rmTree($old);
        } catch (\Throwable $e) {
            self::rmTree($tmp);
            throw $e;
        }
        return ['name' => $name, 'version' => $m->version, 'status' => isset($existing) ? 'updated' : 'published', 'files' => count($files), 'findings' => $findings];
    }

    /* ---- scoring -------------------------------------------------------------------- */

    /**
     * Relevance of one concept to a free-text query. The weights are myctobot's
     * PluginSearchService (exact name 100, name contains 80, description 50, tags 30),
     * applied per query word and summed, because a planner asks in phrases — "staff shift
     * scheduling" — not in concept names.
     */
    public static function score(array $summary, string $query): int {
        $words = self::words($query);
        if (!$words) return 0;
        $name  = strtolower($summary['name']);
        $title = strtolower($summary['title']);
        $blurb = strtolower($summary['blurb']);
        $tags  = array_map('strtolower', array_merge($summary['tags'], $summary['capabilities']));
        $score = 0;
        foreach ($words as $w) {
            if ($name === $w)                     $score += 100;
            elseif (str_contains($name, $w))      $score += 80;
            if (in_array($w, $tags, true))        $score += 60;
            elseif (self::anyContains($tags, $w)) $score += 30;
            if (str_contains($title, $w))         $score += 50;
            if (str_contains($blurb, $w))         $score += 30;
        }
        return $score;
    }

    /** @return string[] */
    private static function words(string $query): array {
        $out = [];
        foreach (preg_split('/[^a-z0-9]+/', strtolower($query), -1, PREG_SPLIT_NO_EMPTY) as $w) {
            if (strlen($w) < 3 || in_array($w, self::STOPWORDS, true)) continue;
            // crude singular: "tickets" finds "ticket", "classes" finds "class"
            $out[] = $w;
            if (strlen($w) > 4 && str_ends_with($w, 'es')) $out[] = substr($w, 0, -2);
            elseif (strlen($w) > 3 && str_ends_with($w, 's')) $out[] = substr($w, 0, -1);
        }
        return array_values(array_unique($out));
    }

    private static function anyContains(array $haystacks, string $needle): bool {
        foreach ($haystacks as $h) if (str_contains($h, $needle)) return true;
        return false;
    }

    /** What a search result and a planner need: enough to decide, not the code. */
    public static function summary(ConceptManifest $m): array {
        return [
            'name'         => $m->name,
            'version'      => $m->version,
            'kind'         => (string) ($m->raw['kind'] ?? 'concept'),
            'title'        => $m->title,
            'blurb'        => $m->blurb,
            'tags'         => $m->tags,
            'capabilities' => $m->capabilities,
            'requires'     => ['concepts' => $m->requiresConcepts, 'lib' => $m->requiresLib],
            'provides'     => ['controllers' => $m->controllers, 'beans' => $m->beans],
            'uses_beans'   => $m->usesBeans,
            'hosts_slots'  => array_keys($m->hostsSlots),
            'fills_slots'  => array_keys($m->slots),
            'screenshot'   => is_file("{$m->dir}/screenshot.jpg") ? 'screenshot.jpg' : (is_file("{$m->dir}/screenshot.png") ? 'screenshot.png' : null),
        ];
    }

    /* ---- what a concept may contain ------------------------------------------------- */

    /**
     * Every publishable file in a concept directory. A file that does not belong is an
     * error naming it — silently leaving it out would publish a concept that is not the one
     * its author tested.
     *
     * @return string[]
     */
    public static function collect(string $conceptDir): array {
        $out = [];
        $total = 0;
        foreach (ConceptLint::files($conceptDir) as $rel) {
            if ($rel === self::PROVENANCE_FILE) continue;   // where an INSTALL came from; not part of the concept
            self::assertPath($rel, basename($conceptDir));
            if (is_link("{$conceptDir}/{$rel}")) {
                throw new ConceptException("Concept '" . basename($conceptDir) . "': {$rel} is a symlink. A concept carries files, not pointers out of itself.");
            }
            $bytes = filesize("{$conceptDir}/{$rel}");
            if ($bytes > self::MAX_FILE_BYTES) throw new ConceptException("Concept '" . basename($conceptDir) . "': {$rel} is {$bytes} bytes; the limit is " . self::MAX_FILE_BYTES . '.');
            $total += $bytes;
            $out[] = $rel;
        }
        if (count($out) > self::MAX_FILES)   throw new ConceptException("Concept '" . basename($conceptDir) . "' has " . count($out) . ' files; the limit is ' . self::MAX_FILES . '.');
        if ($total > self::MAX_TOTAL_BYTES)  throw new ConceptException("Concept '" . basename($conceptDir) . "' is {$total} bytes; the limit is " . self::MAX_TOTAL_BYTES . '.');
        if (!in_array('concept.json', $out, true)) throw new ConceptException("Concept '" . basename($conceptDir) . "' has no concept.json.");
        return $out;
    }

    /** A received bundle, checked as untrusted input. @return array<string,string> path → decoded bytes */
    private static function checkBundle($bundle, string $name): array {
        if (!is_array($bundle) || ($bundle['name'] ?? null) !== $name || !is_string($bundle['version'] ?? null)
            || !is_array($bundle['files'] ?? null) || !$bundle['files']) {
            throw new ConceptException("Concept '{$name}': the catalog returned a malformed bundle.");
        }
        if (count($bundle['files']) > self::MAX_FILES) throw new ConceptException("Concept '{$name}': bundle has too many files.");
        $out = [];
        $total = 0;
        foreach ($bundle['files'] as $rel => $b64) {
            self::assertPath((string) $rel, $name);
            $bytes = is_string($b64) ? base64_decode($b64, true) : false;
            if ($bytes === false) throw new ConceptException("Concept '{$name}': bundle file {$rel} is not valid base64.");
            if (strlen($bytes) > self::MAX_FILE_BYTES) throw new ConceptException("Concept '{$name}': bundle file {$rel} exceeds the size limit.");
            $total += strlen($bytes);
            $out[(string) $rel] = $bytes;
        }
        if ($total > self::MAX_TOTAL_BYTES) throw new ConceptException("Concept '{$name}': bundle exceeds the total size limit.");
        if (!isset($out['concept.json'])) throw new ConceptException("Concept '{$name}': bundle has no concept.json.");
        return $out;
    }

    private static function assertPath(string $rel, string $concept): void {
        $parts = explode('/', $rel);
        $ok = preg_match(self::PATH_RE, $rel) && !in_array('..', $parts, true) && !str_contains($rel, '..');
        if ($ok) {
            $ok = count($parts) === 1 ? in_array($rel, self::TOP_FILES, true) : in_array($parts[0], self::TOP_DIRS, true);
        }
        if ($ok && count($parts) > 1) {
            $ok = in_array(strtolower(pathinfo($rel, PATHINFO_EXTENSION)), self::EXTENSIONS, true);
        }
        if (!$ok) {
            throw new ConceptException(
                "Concept '{$concept}': '{$rel}' is not something a concept may contain. Allowed at the top: "
              . implode(', ', self::TOP_FILES) . '; directories: ' . implode(', ', self::TOP_DIRS)
              . '; extensions: ' . implode(', ', self::EXTENSIONS) . '.');
        }
    }

    private static function assertName(string $name): void {
        if (!preg_match(self::NAME_RE, $name)) {
            throw new ConceptException("'{$name}' is not a valid concept name (lowercase letters and digits, starting with a letter).");
        }
    }

    private function conceptDir(string $name): string {
        $cdir = "{$this->dir}/{$name}";
        if (!is_dir($cdir)) throw new ConceptException("Concept '{$name}' is not in the catalog ({$this->dir}).");
        return $cdir;
    }

    private static function treeHash(string $dir): string {
        $h = hash_init('sha256');
        foreach (self::collect($dir) as $rel) {
            hash_update($h, $rel . "\0" . hash_file('sha256', "{$dir}/{$rel}") . "\n");
        }
        return hash_final($h);
    }

    private static function rmTree(string $path): void {
        if (is_link($path) || is_file($path)) { @unlink($path); return; }
        if (!is_dir($path)) return;
        foreach (scandir($path) ?: [] as $e) {
            if ($e !== '.' && $e !== '..') self::rmTree("{$path}/{$e}");
        }
        @rmdir($path);
    }

    /* ---- installing into a project, as a build ------------------------------------- */

    /**
     * Queue an install of $name into a project, on the control plane. Runs
     * scripts/concept-install.php, which writes the install plan into the project and
     * hands it to plan-ingest.php — an ordinary no-agent build that installs, commits and
     * merges. Never a copy into a live tree: worktrees are cut from the committed base, so
     * a copy is invisible to every agent that runs afterwards.
     *
     * The project's registry row is resolved by plan-ingest in THIS install's database, so
     * this only works where that registry lives: the control plane. An instance asks with
     * requestInstall() instead.
     *
     * @param array{slug:string,dir:string} $project
     * @return array{ok:bool,said:string}  said = the runner's last lines, for the person
     */
    public static function queueInstall(string $name, array $project, int $memberId): array {
        self::assertName($name);
        // 'php', never PHP_BINARY: under php-fpm that constant is php-fpm itself, which
        // answers a script argument with its usage screen ("-R, --allow-to-run-as-root …").
        // --autobuild=1: the approval gate exists for plans that spend agent time; an install
        // plan has no agent and takes seconds, so the request IS the approval.
        $cmd = 'php ' . escapeshellarg(dirname(__DIR__) . '/scripts/concept-install.php')
             . ' --concept=' . escapeshellarg($name)
             . ' --slug='    . escapeshellarg((string) $project['slug'])
             . ' --dir='     . escapeshellarg((string) $project['dir'])
             . ' --member='  . (int) $memberId
             . ' --autobuild=1'
             . ' 2>&1';
        $out = [];
        exec($cmd, $out, $code);
        $said = trim(implode(' ', array_slice(array_filter(array_map('trim', $out)), -2)));
        return ['ok' => $code === 0, 'said' => $said !== '' ? $said : "the runner exited {$code}"];
    }

    /**
     * An instance asking the control plane to install $name into it — the Plugins page's
     * Install button on a project. The plane resolves the project from the broker key,
     * queues the build as its owner, and answers with what it did.
     *
     * @return array{queued:bool,message:string}
     */
    public function requestInstall(string $name): array {
        self::assertName($name);
        if ($this->broker === null) {
            throw new ConceptException("requestInstall('{$name}') is for an instance with a broker: this install serves the catalog itself — use queueInstall() with a project.");
        }
        $data = $this->remote('install', ['name' => $name], true);
        if (!isset($data['queued'])) {
            throw new ConceptException("Concept catalog at {$this->broker['base']} answered 'install' without a queued flag.");
        }
        return ['queued' => (bool) $data['queued'], 'message' => (string) ($data['message'] ?? '')];
    }

    /* ---- remote --------------------------------------------------------------------- */

    private function remote(string $action, array $query, bool $post = false): array {
        $url = $this->broker['base'] . '/concepthub/' . $action . '?' . http_build_query($query);
        [$status, $body] = ($this->http)($url, ['Authorization: Bearer ' . $this->broker['key'], 'Accept: application/json'], $post);
        $data = json_decode($body, true);
        if ($status !== 200 || !is_array($data)) {
            $why = is_array($data) && isset($data['message']) ? (string) $data['message'] : ('HTTP ' . $status);
            throw new ConceptException("Concept catalog at {$this->broker['base']} did not answer '{$action}': {$why}. This is a failure to reach the catalog, NOT an empty result.");
        }
        return $data;
    }

    /** @return array{0:int,1:string} */
    private static function httpGet(string $url, array $headers, bool $post = false): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $post ? 120 : 30,   // an install answers after the build is queued and started
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        if ($post) curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => '']);
        $resp = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($resp === false) {
            return [0, json_encode(['message' => 'connection failed: ' . curl_error($ch)])];
        }
        return [$status, (string) $resp];   // no curl_close(): deprecated in PHP 8.5, and it throws in a web handler
    }
}

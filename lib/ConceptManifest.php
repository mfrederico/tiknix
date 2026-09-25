<?php
/**
 * ConceptManifest — one concept's concept.json, parsed and shape-checked.
 *
 * The manifest IS the registration: it is pure data, and loading it executes nothing the
 * concept supplies. Every entry that will later resolve to code is held to a shape that has
 * no syntax for leaving the concept's own namespace:
 *
 *   "Portlets::teacherSelect"          → app\concepts\<this concept>\Portlets::teacherSelect
 *   "vendors:Access::isVendorCtx"      → app\concepts\vendors\Access::isVendorCtx
 *                                        (and "vendors" must be in requires.concepts)
 *
 * No backslashes, no bare function names. "system" and "\\app\\Bean::exec" fail here, before
 * anything is resolved, so there is no allowlist to maintain and none to get wrong.
 *
 * This class checks SHAPE only — it never touches the database, the autoloader or the
 * filesystem beyond reading the one JSON file. Whether the named classes, methods and views
 * actually exist is Concepts::verify()'s job, at enable time. See COMPONENTS_PLAN.md.
 */

namespace app;

class ConceptManifest {

    public const FILE = 'concept.json';

    /** The instance itself: hosts slots, registers nothing. Its manifest sits at the install root. */
    public const ROOT = 'root';

    /**
     * Level names a manifest may use, as literals rather than the LEVELS constant: LEVELS is
     * defined by the web bootstrap and manifests are also read from CLIs. Mirrors
     * Feature::ADMIN_LEVEL and TwoFactorAuth::REQUIRED_LEVELS, for the same reason.
     */
    public const LEVEL_VALUES = ['ROOT' => 1, 'ADMIN' => 50, 'MEMBER' => 100, 'PUBLIC' => 101];

    public const DEFAULT_ORDER = 100;

    private const NAME_RE     = '/^[a-z][a-z0-9]*$/D';
    private const CLASS_RE    = '/^[A-Z][A-Za-z0-9]*$/D';
    private const BEAN_RE     = '/^[a-z][a-z0-9]*$/D';
    private const SLOT_RE     = '/^[a-z][a-z0-9]*(?:\.[a-z][a-z0-9]*)+$/D';
    /** The same shape Pipeline\Loader::safeSlug() accepts. */
    private const PIPELINE_RE = '/^[a-z0-9][a-z0-9-]{0,63}$/D';
    /** plain program names, alternatives separated by '|': no paths, no arguments, no shell */
    private const COMMAND_RE  = '/^[A-Za-z0-9][A-Za-z0-9._+-]*(?:\|[A-Za-z0-9][A-Za-z0-9._+-]*)*$/D';
    /** extension names as extension_loaded() knows them, alternatives separated by '|' */
    private const EXTENSION_RE = '/^[a-z][a-z0-9_]*(?:\|[a-z][a-z0-9_]*)*$/D';
    private const CALLABLE_RE = '/^(?:([a-z][a-z0-9]*):)?([A-Z][A-Za-z0-9]*)::([a-z][A-Za-z0-9]*)$/D';
    private const VIEW_RE     = '/^[A-Za-z0-9][A-Za-z0-9_\-]*(?:\/[A-Za-z0-9][A-Za-z0-9_\-]*)*\.php$/D';
    private const MATCH_KEY_RE = '/^[a-z][A-Za-z0-9]*$/D';

    public string $name;
    public string $dir;
    public string $version;
    public string $title;
    public string $blurb;
    /** @var string[] */
    public array $tags = [];
    /** @var string[] concept names this one needs enabled first */
    public array $requiresConcepts = [];
    /** @var string[] core class names (under app\) this one calls */
    public array $requiresLib = [];
    /**
     * @var string[] programs that must be on PATH, one entry per need; alternatives joined
     *      with '|' ("google-chrome|chromium") — any one of them satisfies the entry
     */
    public array $requiresCommands = [];
    /** @var string[] PHP extensions that must be loaded; alternatives joined with '|' ("imagick|gd") */
    public array $requiresExtensions = [];
    /** @var string[] controller class names this concept claims */
    public array $controllers = [];
    /** @var string[] bean types this concept claims */
    public array $beans = [];
    /**
     * Pipeline definitions this concept ships: pipelines/<slug>.json each, slug
     * "<concept>-<something>". The runtime stays core; the definitions are the part.
     * @var string[]
     */
    public array $pipelines = [];
    /**
     * MCP tools this concept offers, each {class, level}: mcptools/<class>.php defining
     * app\concepts\<name>\mcptools\<class>. `level` is required for the same reason a slot's
     * is — a tool is a door, and the caller's level decides whether it is even listed.
     * @var array<int,array{class:string,level:int,where:string}>
     */
    public array $tools = [];
    /** @var string[] bean types it reads or writes but does NOT own (a host's `product`, core's `member`) */
    public array $usesBeans = [];
    /** @var string[] free-form capability words the catalog matches on ("ics", "add-to-calendar") */
    public array $capabilities = [];
    /** @var array<string,array{form:bool}> slots this concept hosts */
    public array $hostsSlots = [];
    /** @var string[] collect points this concept hosts */
    public array $hostsCollect = [];
    /** @var array<string,array<int,array>> slot name → registrations */
    public array $slots = [];
    /** @var array<string,array<int,array>> collect name → registrations */
    public array $collect = [];
    /** The decoded manifest, for readers (catalog, storefront) that own keys this class does not. */
    public array $raw = [];

    private function __construct() {}

    /**
     * Read and shape-check <dir>/concept.json.
     *
     * @param string $dir          the concept's directory (or the install root, for ROOT)
     * @param string $expectedName the directory's name — the manifest must agree with it
     */
    public static function load(string $dir, string $expectedName): self {
        $file = rtrim($dir, '/') . '/' . self::FILE;
        if (!is_file($file)) {
            throw new ConceptException("Concept '{$expectedName}': no " . self::FILE . " at {$file}.");
        }
        $json = file_get_contents($file);
        if ($json === false) {
            throw new ConceptException("Concept '{$expectedName}': {$file} could not be read.");
        }
        try {
            $raw = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ConceptException("Concept '{$expectedName}': {$file} is not valid JSON — " . $e->getMessage());
        }
        if (!is_array($raw) || array_is_list($raw)) {
            throw new ConceptException("Concept '{$expectedName}': {$file} must be a JSON object.");
        }
        return self::fromArray($raw, rtrim($dir, '/'), $expectedName);
    }

    /** Build from an already-decoded manifest. Public so the shape rules can be tested without files. */
    public static function fromArray(array $raw, string $dir, string $expectedName): self {
        $m = new self();
        $m->dir = $dir;
        $m->raw = $raw;

        $name = $raw['name'] ?? null;
        if (!is_string($name) || $name === '') {
            throw new ConceptException("Concept '{$expectedName}': manifest has no \"name\".");
        }
        if ($name !== $expectedName) {
            throw new ConceptException(
                "Concept '{$expectedName}': manifest \"name\" is '{$name}'. The name is the directory "
              . "and the namespace segment, so the two must match.");
        }
        if ($name !== self::ROOT && !preg_match(self::NAME_RE, $name)) {
            throw new ConceptException(
                "Concept '{$name}': a name must be lowercase letters and digits, starting with a letter "
              . "(it becomes the namespace app\\concepts\\<name>\\).");
        }
        $m->name = $name;

        $m->version = self::str($raw, 'version', $name, true);
        $m->title   = self::str($raw, 'title', $name, false);
        $m->blurb   = self::str($raw, 'blurb', $name, false);
        $m->tags    = self::stringList($raw['tags'] ?? [], "tags", $name, null);

        $requires = self::obj($raw['requires'] ?? [], 'requires', $name);
        $m->requiresConcepts = self::stringList($requires['concepts'] ?? [], 'requires.concepts', $name, self::NAME_RE);
        $m->requiresLib      = self::stringList($requires['lib'] ?? [], 'requires.lib', $name, self::CLASS_RE);
        $m->requiresCommands = self::stringList($requires['commands'] ?? [], 'requires.commands', $name, self::COMMAND_RE);
        $m->requiresExtensions = self::stringList($requires['extensions'] ?? [], 'requires.extensions', $name, self::EXTENSION_RE);
        if (in_array($name, $m->requiresConcepts, true)) {
            throw new ConceptException("Concept '{$name}': requires.concepts lists the concept itself.");
        }

        $provides = self::obj($raw['provides'] ?? [], 'provides', $name);
        $m->controllers = self::stringList($provides['controllers'] ?? [], 'provides.controllers', $name, self::CLASS_RE);
        $m->beans       = self::stringList($provides['beans'] ?? [], 'provides.beans', $name, self::BEAN_RE);
        $m->capabilities = self::stringList($provides['capabilities'] ?? [], 'provides.capabilities', $name, null);
        $m->pipelines = self::stringList($provides['pipelines'] ?? [], 'provides.pipelines', $name, self::PIPELINE_RE);
        foreach ($m->pipelines as $slug) {
            if (strncmp($slug, $name . '-', strlen($name) + 1) !== 0) {
                throw new ConceptException(
                    "Concept '{$name}': provides.pipelines lists '{$slug}'; a concept's pipeline slug must be "
                  . "'{$name}-<something>' (it is public: /pipeline/trigger/<slug>, tiknix:pipe_<slug>).");
            }
        }
        if (isset($provides['tools'])) {
            foreach (self::entryList($provides['tools'], 'provides.tools', $name) as $i => $entry) {
                $m->tools[] = $m->toolEntry($entry, "provides.tools[{$i}]");
            }
        }
        $seen = [];
        foreach ($m->tools as $t) {
            if (isset($seen[$t['class']])) throw new ConceptException("Concept '{$name}': provides.tools lists '{$t['class']}' twice.");
            $seen[$t['class']] = true;
        }

        $uses = self::obj($raw['uses'] ?? [], 'uses', $name);
        $m->usesBeans = self::stringList($uses['beans'] ?? [], 'uses.beans', $name, self::BEAN_RE);
        if (array_intersect($m->usesBeans, $m->beans)) {
            throw new ConceptException("Concept '{$name}': a bean is listed under both provides.beans and uses.beans — it is owned or it is borrowed.");
        }

        $hosts = self::obj($raw['hosts'] ?? [], 'hosts', $name);
        foreach (self::obj($hosts['slots'] ?? [], 'hosts.slots', $name) as $slot => $opts) {
            self::slotName((string) $slot, "hosts.slots", $name);
            $opts = self::obj($opts, "hosts.slots.{$slot}", $name);
            $form = $opts['form'] ?? false;
            if (!is_bool($form)) {
                throw new ConceptException("Concept '{$name}': hosts.slots.{$slot}.form must be true or false.");
            }
            $m->hostsSlots[(string) $slot] = ['form' => $form];
        }
        $m->hostsCollect = self::stringList($hosts['collect'] ?? [], 'hosts.collect', $name, self::SLOT_RE);

        foreach (self::obj($raw['slots'] ?? [], 'slots', $name) as $slot => $entries) {
            self::slotName((string) $slot, 'slots', $name);
            foreach (self::entryList($entries, "slots.{$slot}", $name) as $i => $entry) {
                $m->slots[(string) $slot][] = $m->slotEntry($entry, "slots.{$slot}[{$i}]");
            }
        }
        foreach (self::obj($raw['collect'] ?? [], 'collect', $name) as $point => $entries) {
            self::slotName((string) $point, 'collect', $name);
            foreach (self::entryList($entries, "collect.{$point}", $name) as $i => $entry) {
                $m->collect[(string) $point][] = $m->collectEntry($entry, "collect.{$point}[{$i}]");
            }
        }

        // The instance hosts; it does not register. Its namespace is app\ itself, so letting
        // it name callables would reopen exactly the reach the relative-name rule closes.
        if ($name === self::ROOT && ($m->slots || $m->collect || $m->controllers || $m->beans || $m->tools || $m->pipelines || $m->requiresConcepts)) {
            throw new ConceptException(
                "Concept 'root': the root manifest may only declare \"hosts\". Core's own controllers, "
              . "beans, tools and views are not registered through a manifest.");
        }
        return $m;
    }

    /** "Class::method" or "concept:Class::method" → where it points. Shape only. */
    public function callable(string $ref, string $where): array {
        if (!preg_match(self::CALLABLE_RE, $ref, $x)) {
            throw new ConceptException(
                "Concept '{$this->name}': {$where} is '{$ref}'. It must be Class::method, or "
              . "concept:Class::method for a required concept — no namespaces, no bare functions.");
        }
        $owner = $x[1] !== '' ? $x[1] : $this->name;
        if ($owner !== $this->name && !in_array($owner, $this->requiresConcepts, true)) {
            throw new ConceptException(
                "Concept '{$this->name}': {$where} points into concept '{$owner}', which is not in "
              . "requires.concepts.");
        }
        return ['concept' => $owner, 'class' => $x[2], 'method' => $x[3], 'ref' => $ref];
    }

    private function slotEntry(array $e, string $where): array {
        $known = ['view', 'level', 'provider', 'when', 'save', 'match', 'order'];
        $this->noUnknownKeys($e, $known, $where);

        $view = $e['view'] ?? null;
        if (!is_string($view) || !preg_match(self::VIEW_RE, $view)) {
            throw new ConceptException(
                "Concept '{$this->name}': {$where}.view must be a .php filename relative to the concept's "
              . "views/ (letters, digits, - and _; no '..').");
        }
        $out = [
            'concept' => $this->name,
            'where'   => $where,
            'view'    => $view,
            'level'   => $this->level($e, $where),
            'match'   => $this->match($e['match'] ?? [], $where),
            'order'   => $this->order($e, $where),
        ];
        foreach (['provider', 'when', 'save'] as $k) {
            $out[$k] = isset($e[$k]) ? $this->callable($this->strVal($e[$k], "{$where}.{$k}"), "{$where}.{$k}") : null;
        }
        return $out;
    }

    private function collectEntry(array $e, string $where): array {
        $this->noUnknownKeys($e, ['level', 'when', 'match', 'order', 'data'], $where);
        $data = $e['data'] ?? null;
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new ConceptException("Concept '{$this->name}': {$where}.data must be a JSON object.");
        }
        return [
            'concept' => $this->name,
            'where'   => $where,
            'level'   => $this->level($e, $where),
            'when'    => isset($e['when']) ? $this->callable($this->strVal($e['when'], "{$where}.when"), "{$where}.when") : null,
            'match'   => $this->match($e['match'] ?? [], $where),
            'order'   => $this->order($e, $where),
            'data'    => $data,
        ];
    }

    /** {"class": "TicketsListTool", "level": "MEMBER"} — the file and the class are checked by Concepts::verify(). */
    private function toolEntry(array $e, string $where): array {
        $this->noUnknownKeys($e, ['class', 'level'], $where);
        $class = $e['class'] ?? null;
        if (!is_string($class) || !preg_match(self::CLASS_RE, $class)) {
            throw new ConceptException(
                "Concept '{$this->name}': {$where}.class must be a class name (mcptools/<Class>.php, "
              . "defining app\\concepts\\{$this->name}\\mcptools\\<Class>).");
        }
        return ['concept' => $this->name, 'where' => $where, 'class' => $class, 'level' => $this->level($e, $where)];
    }

    /** `level` is REQUIRED: a default of PUBLIC leaks an admin panel, a default of ADMIN hides a guest feature. */
    private function level(array $e, string $where): int {
        $lvl = $e['level'] ?? null;
        if (!is_string($lvl) || !isset(self::LEVEL_VALUES[$lvl])) {
            throw new ConceptException(
                "Concept '{$this->name}': {$where}.level is required and must be one of "
              . implode(', ', array_keys(self::LEVEL_VALUES)) . '.');
        }
        return self::LEVEL_VALUES[$lvl];
    }

    /** `match` narrows a registration by the host's context: {"offerType": ["class","session"]}. */
    private function match($match, string $where): array {
        $match = self::obj($match, "{$where}.match", $this->name);
        $out = [];
        foreach ($match as $key => $values) {
            if (!preg_match(self::MATCH_KEY_RE, (string) $key)) {
                throw new ConceptException("Concept '{$this->name}': {$where}.match key '{$key}' is not a plain identifier.");
            }
            $values = self::stringList($values, "{$where}.match.{$key}", $this->name, null);
            if ($values === []) {
                throw new ConceptException("Concept '{$this->name}': {$where}.match.{$key} is empty, so it can never match.");
            }
            $out[(string) $key] = $values;
        }
        return $out;
    }

    private function order(array $e, string $where): int {
        if (!isset($e['order'])) return self::DEFAULT_ORDER;
        if (!is_int($e['order'])) {
            throw new ConceptException("Concept '{$this->name}': {$where}.order must be an integer.");
        }
        return $e['order'];
    }

    /** A typo'd key ("provder") must not become a registration that silently does less. */
    private function noUnknownKeys(array $e, array $known, string $where): void {
        $extra = array_diff(array_keys($e), $known);
        if ($extra) {
            throw new ConceptException(
                "Concept '{$this->name}': {$where} has unknown key(s) " . implode(', ', $extra)
              . '. Known: ' . implode(', ', $known) . '.');
        }
    }

    private function strVal($v, string $where): string {
        if (!is_string($v)) {
            throw new ConceptException("Concept '{$this->name}': {$where} must be a string.");
        }
        return $v;
    }

    private static function slotName(string $slot, string $where, string $concept): void {
        if (!preg_match(self::SLOT_RE, $slot)) {
            throw new ConceptException(
                "Concept '{$concept}': {$where} name '{$slot}' must be dotted lowercase, e.g. catalog.edit.fields.");
        }
    }

    private static function str(array $raw, string $key, string $concept, bool $required): string {
        $v = $raw[$key] ?? null;
        if ($v === null && !$required) return '';
        if (!is_string($v) || $v === '') {
            throw new ConceptException("Concept '{$concept}': \"{$key}\" must be a non-empty string.");
        }
        return $v;
    }

    private static function obj($v, string $where, string $concept): array {
        if (!is_array($v) || ($v !== [] && array_is_list($v))) {
            throw new ConceptException("Concept '{$concept}': {$where} must be a JSON object.");
        }
        return $v;
    }

    /** One registration or a list of them, normalised to a list. */
    private static function entryList($v, string $where, string $concept): array {
        if (!is_array($v) || $v === []) {
            throw new ConceptException("Concept '{$concept}': {$where} must be an object or a list of objects.");
        }
        $list = array_is_list($v) ? $v : [$v];
        foreach ($list as $i => $entry) {
            if (!is_array($entry) || $entry === [] || array_is_list($entry)) {
                throw new ConceptException("Concept '{$concept}': {$where}[{$i}] must be a JSON object.");
            }
        }
        return $list;
    }

    private static function stringList($v, string $where, string $concept, ?string $re): array {
        if (!is_array($v) || ($v !== [] && !array_is_list($v))) {
            throw new ConceptException("Concept '{$concept}': {$where} must be a JSON list.");
        }
        foreach ($v as $item) {
            if (!is_string($item) || $item === '' || ($re !== null && !preg_match($re, $item))) {
                throw new ConceptException(
                    "Concept '{$concept}': {$where} has an invalid entry " . json_encode($item) . '.');
            }
        }
        if (count(array_unique($v)) !== count($v)) {
            throw new ConceptException("Concept '{$concept}': {$where} lists the same entry twice.");
        }
        return $v;
    }
}

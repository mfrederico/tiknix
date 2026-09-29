<?php
/**
 * CallGraph — static call/render cross-reference scanner for a tiknix instance.
 *
 * Builds the directed graph the Architecture Explorer draws: nodes are
 * {route, view, libmethod, bean, script, custom-route, broken-link, missing-view}
 * and edges answer "what renders/calls/reads what", each with `path:line` evidence
 * and a confidence class (exact | inferred | dynamic). Zero-dependency and
 * jail-safe (token_get_all + targeted literal extraction; no AST lib, no network) —
 * it lands in CORE so every instance clone inherits it and the design's precision
 * numbers (see explorer.tiknix/CALLGRAPH-DESIGN.md) hold.
 *
 * Consumes Introspector's inventories (controllers/libs/authcontrol/tables); it
 * never re-derives signatures. It DOES read method bodies (Introspector explicitly
 * does not) via token_get_all.
 */

namespace app\mcptools;

final class CallGraph {

    /** Bump when the extraction changes so cached graphs invalidate (folded into codeHash). */
    public const VERSION = 1;

    private string $root;
    private array $controllers;   // lc name => {name, path, methods:[m=>line], hasFallback}
    private array $libClasses;    // ClassName => true (resolvable static-call targets)
    private array $nodes = [];    // id => node
    private array $edges = [];
    private array $meta  = [];

    // Bean read/write API sets (RedBean facade + Bean wrapper).
    private const BEAN_READ  = ['find','findone','findall','findlike','load','loadall','count','findcollection'];
    private const BEAN_WRITE = ['dispense','store','storeall','trash','trashall','wipe'];
    private const BEAN_SQL   = ['getall','getrow','getcol','getcell','exec','getcell'];

    public function __construct(private Introspector $intro) {
        $this->root        = $intro->rootPath();
        $this->controllers = $intro->controllerList();
        $this->libClasses  = [];
        foreach (array_keys($intro->libList()) as $cls) $this->libClasses[$cls] = true;
        foreach ($this->controllers as $c) $this->libClasses[$c['name']] = true;   // controllers callable too
    }

    /** Build the full graph: ['nodes'=>[...], 'edges'=>[...], 'meta'=>[...]]. */
    public function build(): array {
        $this->nodes = $this->edges = [];
        // 1) Route nodes from the controller inventory (+ their authcontrol levels).
        $this->seedRouteNodes();
        // 2) PHP body scan: render/redirect/json + bean + caller edges, per file.
        foreach ($this->phpFiles() as $file) $this->scanPhpFile($file);
        // 3) View scan: view→route + view→view includes + dynamic-call sites.
        foreach ($this->viewFiles() as $file) $this->scanViewFile($file);
        // 4) Custom routes from bootstrap-loaded routes files.
        $this->seedCustomRoutes();
        // 5) Post-pass: shape, reach classification, orphans.
        $this->classify();

        return [
            'nodes' => array_values($this->nodes),
            'edges' => $this->edges,
            'meta'  => [
                'version'    => self::VERSION,
                'nodeCount'  => count($this->nodes),
                'edgeCount'  => count($this->edges),
                'orphans'    => $this->meta['orphans'] ?? [],
                'broken'     => $this->meta['broken'] ?? 0,
                'dynamicSites' => $this->meta['dynamic'] ?? 0,
            ],
        ];
    }

    // === node helpers ========================================================

    private function node(string $id, array $data): void {
        if (!isset($this->nodes[$id])) $this->nodes[$id] = ['id' => $id] + $data;
        else $this->nodes[$id] = array_merge($this->nodes[$id], $data);
    }

    private function edge(string $from, string $to, string $kind, string $evidence, string $confidence, array $extra = []): void {
        $this->edges[] = ['from' => $from, 'to' => $to, 'kind' => $kind,
            'evidence' => $evidence, 'confidence' => $confidence] + $extra;
    }

    private function seedRouteNodes(): void {
        foreach ($this->controllers as $lc => $c) {
            foreach ($c['methods'] as $method => $line) {
                $id = "route:{$lc}::{$method}";
                $this->node($id, [
                    'kind'        => 'route',
                    'label'       => "{$lc}::{$method}",
                    'path'        => $c['path'],
                    'line'        => $line,
                    'shape'       => 'page',
                    'hasFallback' => $c['hasFallback'],
                ]);
            }
        }
    }

    // === PHP body scan =======================================================

    /** All PHP files whose bodies carry edges. */
    private function phpFiles(): array {
        $out = [];
        foreach (['controls', 'lib', 'models', 'scripts', 'cli', 'services', 'mcptools', 'routes'] as $dir) {
            $base = "{$this->root}/{$dir}";
            if (!is_dir($base)) continue;
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isFile() && substr($f->getFilename(), -4) === '.php') $out[] = $f->getPathname();
            }
        }
        return $out;
    }

    private function rel(string $abs): string {
        return ltrim(substr($abs, strlen($this->root)), '/');
    }

    private function scanPhpFile(string $abs): void {
        $src = @file_get_contents($abs);
        if ($src === false) return;
        $rel = $this->rel($abs);
        $toks  = $this->tokenize($src);
        $spans = $this->methodSpans($toks);
        $owner = $this->ownerResolver($spans, $rel);

        // Per-span accumulation for store($var) inference: exact bean types seen per span.
        $spanBeans = [];  // ownerId => [beanName => true]

        $n = count($toks);
        for ($i = 0; $i < $n; $i++) {
            $t = $toks[$i];
            $line = $t['line'];
            $own  = $owner($line);

            // ---- $this -> render / json (controller-only helpers) ----
            if ($t['id'] === T_VARIABLE && $t['text'] === '$this') {
                $a = $this->sig($toks, $i + 1);
                if ($a !== null && $toks[$a]['id'] === T_OBJECT_OPERATOR) {
                    $b = $this->sig($toks, $a + 1);
                    if ($b !== null && $toks[$b]['id'] === T_STRING) {
                        $name = $toks[$b]['text'];
                        $c = $this->sig($toks, $b + 1);
                        $isCall = $c !== null && $toks[$c]['text'] === '(';
                        if ($isCall && $name === 'render') {
                            $this->renderEdge($own, $toks, $c, $t['line']);
                        } elseif ($isCall && in_array(strtolower($name), ['jsonsuccess', 'jsonerror', 'json'], true)) {
                            $this->markJson($own);
                        }
                    }
                }
            }

            // ---- -> own/xown/sharedXList relation-list read (on ANY variable) ----
            if ($t['id'] === T_OBJECT_OPERATOR) {
                $b = $this->sig($toks, $i + 1);
                if ($b !== null && $toks[$b]['id'] === T_STRING
                    && preg_match('/^(x?own|shared)([A-Z]\w*?)List$/', $toks[$b]['text'], $m)) {
                    $c = $this->sig($toks, $b + 1);
                    if ($c === null || $toks[$c]['text'] !== '(') {   // a property read, not a method call
                        $bean = strtolower($m[2]);
                        $kind = $m[1] === 'shared' ? 'rel-shared' : 'rel-own';
                        $extra = $m[1] === 'xown' ? ['cascade' => true] : [];
                        $this->beanNode($bean);
                        $this->edge($own, "bean:{$bean}", $kind, "{$rel}:{$line}", 'exact', $extra);
                    }
                }
            }

            // ---- Name :: method (  /  new Name (  ----
            if ($t['id'] === T_STRING || $t['id'] === T_NAME_QUALIFIED || $t['id'] === T_NAME_FULLY_QUALIFIED) {
                $cls = $this->lastSegment($t['text']);
                $a = $this->sig($toks, $i + 1);
                if ($a !== null && $toks[$a]['id'] === T_DOUBLE_COLON) {
                    $b = $this->sig($toks, $a + 1);
                    if ($b !== null && $toks[$b]['id'] === T_STRING) {
                        $method = $toks[$b]['text'];
                        $c = $this->sig($toks, $b + 1);
                        if ($c !== null && $toks[$c]['text'] === '(') {
                            $this->staticCallEdge($own, $cls, $method, $toks, $c, $rel, $line, $spanBeans);
                        }
                    }
                }
            }
            if ($t['id'] === T_NEW) {
                $a = $this->sig($toks, $i + 1);
                if ($a !== null && in_array($toks[$a]['id'], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                    $cls = $this->lastSegment($toks[$a]['text']);
                    if (isset($this->libClasses[$cls])) {
                        $this->node("lib:{$cls}", ['kind' => 'lib', 'label' => $cls]);
                        $this->edge($own, "lib:{$cls}", 'instantiates', "{$rel}:{$line}", 'exact');
                    }
                }
            }
        }

        // store($var)/trash($var) inference: a write with no literal bean, in a span
        // whose only exact bean type is B, is an inferred write to B.
        foreach ($this->deferredWrites ?? [] as $w) {
            $beans = array_keys($spanBeans[$w['owner']] ?? []);
            if (count($beans) === 1) {
                $this->beanNode($beans[0]);
                $this->edge($w['owner'], "bean:{$beans[0]}", 'writes', $w['evidence'], 'inferred', ['via' => $w['via']]);
            } else {
                $this->beanNode('(dynamic)');
                $this->edge($w['owner'], 'bean:(dynamic)', 'writes', $w['evidence'], 'dynamic', ['via' => $w['via']]);
            }
        }
        $this->deferredWrites = [];
        $this->spanBeans = $spanBeans;
    }

    private array $deferredWrites = [];
    private array $spanBeans = [];

    /** $this->render('x/y'[, ..., false]) → renders edge (+ implicit layout edges unless false). */
    private function renderEdge(string $own, array $toks, int $paren, int $line): void {
        $rel = $this->rel_current;
        $arg = $this->sig($toks, $paren + 1);
        if ($arg === null || $toks[$arg]['id'] !== T_CONSTANT_ENCAPSED_STRING) return;
        $view = $this->unquote($toks[$arg]['text']);
        if ($view === '') return;
        $viewId = "view:views/{$view}.php";
        $exists = is_file("{$this->root}/views/{$view}.php");
        $this->node($viewId, ['kind' => $exists ? 'view' : 'missing-view', 'label' => "views/{$view}.php",
            'path' => "views/{$view}.php"] + ($exists ? [] : ['broken' => true]));
        $this->edge($own, $viewId, 'renders', "{$rel}:{$line}", 'exact');
        // layout sandwich unless a literal `false` appears in the call args
        if (!$this->argsContainFalse($toks, $paren)) {
            foreach (['layouts/header', 'layouts/footer', 'layouts/layout'] as $lay) {
                $lid = "view:views/{$lay}.php";
                if (is_file("{$this->root}/views/{$lay}.php")) {
                    $this->node($lid, ['kind' => 'view', 'label' => "views/{$lay}.php", 'path' => "views/{$lay}.php"]);
                    $this->edge($own, $lid, 'renders', "{$rel}:{$line}", 'inferred', ['implicit' => true]);
                }
            }
        }
    }

    private function markJson(string $own): void {
        if (isset($this->nodes[$own]) && $this->nodes[$own]['kind'] === 'route') {
            $cur = $this->nodes[$own]['shape'] ?? 'page';
            $this->nodes[$own]['jsonSeen'] = true;
            $this->nodes[$own]['shape'] = ($cur === 'page') ? 'json' : $cur;
        }
    }

    /** Static call: Flight::redirect, R/Bean::*, or a lib/controller call edge. */
    private function staticCallEdge(string $own, string $cls, string $method, array $toks, int $paren, string $rel, int $line, array &$spanBeans): void {
        $mlc = strtolower($method);

        if ($cls === 'Flight' && $mlc === 'redirect') {
            $arg = $this->sig($toks, $paren + 1);
            if ($arg !== null && $toks[$arg]['id'] === T_CONSTANT_ENCAPSED_STRING) {
                $target = $this->unquote($toks[$arg]['text']);
                if (strncmp($target, 'http', 4) === 0) {
                    $this->node("external:{$target}", ['kind' => 'external', 'label' => $target]);
                    $this->edge($own, "external:{$target}", 'redirects', "{$rel}:{$line}", 'exact', ['external' => true]);
                } elseif ($target !== '' && $target[0] === '/') {
                    [$rid, $conf] = $this->resolveUrl($target);
                    if ($rid) $this->edge($own, $rid, 'redirects', "{$rel}:{$line}", $conf);
                }
            } else {
                $this->edge($own, 'dynamic:redirect', 'dynamic-redirect', "{$rel}:{$line}", 'dynamic');
                $this->node('dynamic:redirect', ['kind' => 'dynamic', 'label' => '(computed redirects)']);
                $this->meta['dynamic'] = ($this->meta['dynamic'] ?? 0) + 1;
            }
            return;
        }
        if ($cls === 'Flight' && in_array($mlc, ['jsonsuccess', 'jsonerror'], true)) { $this->markJson($own); return; }
        if ($cls === 'Flight' && in_array($mlc, ['render', 'renderview'], true)) {
            $arg = $this->sig($toks, $paren + 1);
            if ($arg !== null && $toks[$arg]['id'] === T_CONSTANT_ENCAPSED_STRING) {
                $view = $this->unquote($toks[$arg]['text']);
                $viewId = "view:views/{$view}.php";
                $exists = is_file("{$this->root}/views/{$view}.php");
                $this->node($viewId, ['kind' => $exists ? 'view' : 'missing-view', 'label' => "views/{$view}.php", 'path' => "views/{$view}.php"] + ($exists ? [] : ['broken' => true]));
                $this->edge($own, $viewId, 'renders', "{$rel}:{$line}", 'exact', ['via' => "Flight::{$method}"]);
            }
            return;
        }

        if ($cls === 'R' || $cls === 'Bean') {
            $this->beanEdge($own, $mlc, $toks, $paren, $rel, $line, $spanBeans);
            return;
        }

        // Lib / controller call edge.
        if (isset($this->libClasses[$cls])) {
            $this->node("lib:{$cls}", ['kind' => 'lib', 'label' => $cls]);
            $this->edge($own, "libmethod:{$cls}::{$method}", 'calls', "{$rel}:{$line}", 'exact');
            $this->node("libmethod:{$cls}::{$method}", ['kind' => 'libmethod', 'label' => "{$cls}::{$method}", 'parent' => "lib:{$cls}"]);
        }
    }

    private function beanEdge(string $own, string $mlc, array $toks, int $paren, string $rel, int $line, array &$spanBeans): void {
        $isRead  = in_array($mlc, self::BEAN_READ, true);
        $isWrite = in_array($mlc, self::BEAN_WRITE, true);
        $isSql   = in_array($mlc, self::BEAN_SQL, true);
        if (!$isRead && !$isWrite && !$isSql) return;

        if ($isSql) {
            $this->beanNode('(sql)');
            $this->edge($own, 'bean:(sql)', 'reads', "{$rel}:{$line}", 'dynamic', ['via' => "R::{$mlc}"]);
            return;
        }
        $arg = $this->sig($toks, $paren + 1);
        $literal = $arg !== null && $toks[$arg]['id'] === T_CONSTANT_ENCAPSED_STRING;
        if ($literal) {
            $bean = $this->normBean($this->unquote($toks[$arg]['text']));
            if ($bean === '') return;
            $this->beanNode($bean);
            $this->edge($own, "bean:{$bean}", $isWrite ? 'writes' : 'reads', "{$rel}:{$line}", 'exact', ['via' => ($mlc)]);
            if ($isRead || $mlc === 'dispense') $spanBeans[$own][$bean] = true;   // for store($var) inference
            return;
        }
        // Non-literal first arg on a write → defer for same-span single-bean inference.
        if ($isWrite) {
            $this->deferredWrites[] = ['owner' => $own, 'evidence' => "{$rel}:{$line}", 'via' => $mlc];
        }
    }

    private function beanNode(string $bean): void {
        $this->node("bean:{$bean}", ['kind' => 'bean', 'label' => $bean]);
    }

    // === view scan ===========================================================

    private function viewFiles(): array {
        $out = [];
        $base = "{$this->root}/views";
        if (!is_dir($base)) return $out;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && substr($f->getFilename(), -4) === '.php') $out[] = $f->getPathname();
        }
        return $out;
    }

    private function scanViewFile(string $abs): void {
        $src = @file_get_contents($abs);
        if ($src === false) return;
        $rel = $this->rel($abs);
        $viewId = "view:{$rel}";
        $this->node($viewId, ['kind' => 'view', 'label' => $rel, 'path' => $rel]);
        $lines = explode("\n", $src);
        $hasDynamic = false;
        foreach ($lines as $li => $ln) {
            $lineNo = $li + 1;
            // include/require of another view/partial → includes edge
            if (preg_match('#\b(include|require)(_once)?\b#', $ln)
                && preg_match('#[\'"](/?(?:partials|components|views)/[a-zA-Z0-9_/\-]+\.php)[\'"]#', $ln, $im)) {
                $inc = 'views/' . ltrim(preg_replace('#^/?views/#', '', $im[1]), '/');
                $incId = "view:{$inc}";
                $this->node($incId, ['kind' => 'view', 'label' => $inc, 'path' => $inc]);
                $this->edge($viewId, $incId, 'includes', "{$rel}:{$lineNo}", 'exact');
            }
            // URL literals
            if (preg_match_all('#["\'](/[a-z][a-zA-Z0-9_/.\-]*(?:\?[^"\']*)?)["\'<?]#', $ln, $mm)) {
                foreach ($mm[1] as $url) {
                    [$rid, $conf] = $this->resolveUrl($url);
                    if ($rid) $this->edge($viewId, $rid, 'view-call', "{$rel}:{$lineNo}", $conf);
                }
            }
            // dynamic fetch/location with a non-literal (variable) base
            if (preg_match('#\bfetch\s*\(\s*[a-zA-Z_$]#', $ln) || preg_match('#location(\.href)?\s*=\s*[a-zA-Z_$`]#', $ln)) {
                $hasDynamic = true;
            }
        }
        if ($hasDynamic) {
            $did = "dynamic:{$rel}";
            $this->node($did, ['kind' => 'dynamic', 'label' => "computed request(s) in {$rel}", 'path' => $rel]);
            $this->edge($viewId, $did, 'dynamic-call', "{$rel}", 'dynamic');
            $this->meta['dynamic'] = ($this->meta['dynamic'] ?? 0) + 1;
        }
    }

    // === URL → route resolution (§3.3 validator) =============================

    /** Returns [nodeId|null, confidence]. Creates broken-link/missing nodes as needed. */
    private function resolveUrl(string $url): array {
        $path = parse_url($url, PHP_URL_PATH) ?: $url;
        $segs = array_values(array_filter(explode('/', trim($path, '/')), fn($s) => $s !== ''));
        if (!$segs) return [null, 'exact'];
        $seg1 = strtolower($segs[0]);
        $seg2 = strtolower($segs[1] ?? 'index');

        // custom routes take precedence (checked after seedCustomRoutes populates them)
        if (isset($this->customRoutePaths[$path])) return [$this->customRoutePaths[$path], 'exact'];

        if (isset($this->controllers[$seg1])) {
            $c = $this->controllers[$seg1];
            $mlc = $this->methodLc($c, $seg2);
            if ($mlc !== null) return ["route:{$seg1}::{$mlc}", 'exact'];
            if ($c['hasFallback']) {
                $id = "route:{$seg1}::_fallback";
                return [$id, 'inferred'];
            }
            // control exists, method doesn't, no fallback → broken link
            $bid = "broken:/{$seg1}/{$seg2}";
            $this->node($bid, ['kind' => 'broken-link', 'label' => "/{$seg1}/{$seg2}", 'broken' => true]);
            $this->meta['broken'] = ($this->meta['broken'] ?? 0) + 1;
            return [$bid, 'inferred'];
        }
        // seg1 no controller — fuzzy hyphen-stripped match → broken-link w/ suggestion
        $stripped = str_replace('-', '', $seg1);
        if ($stripped !== $seg1 && isset($this->controllers[$stripped])) {
            $c = $this->controllers[$stripped];
            $mlc = $this->methodLc($c, str_replace('-', '', $seg2));
            $suggest = "{$stripped}::" . ($mlc ?? 'index');
            $bid = "broken:{$path}";
            $this->node($bid, ['kind' => 'broken-link', 'label' => $path, 'broken' => true, 'suggest' => $suggest]);
            $this->meta['broken'] = ($this->meta['broken'] ?? 0) + 1;
            return [$bid, 'inferred'];
        }
        return [null, 'exact'];   // asset / placeholder / off-app → dropped
    }

    /** A controller's method matching $seg2 case-insensitively → its real (cased) name lc, or null. */
    private function methodLc(array $c, string $seg2): ?string {
        foreach ($c['methods'] as $m => $_) {
            if (strtolower($m) === $seg2) return strtolower($m);
        }
        return null;
    }

    // === custom routes =======================================================

    private array $customRoutePaths = [];   // static path prefix => custom node id

    private function seedCustomRoutes(): void {
        foreach ($this->intro->routeLiterals() as $r) {
            $pat = $r['pattern'];
            $id  = "custom:{$r['verbs']} {$pat}";
            $this->node($id, ['kind' => 'custom-route', 'label' => "{$r['verbs']} {$pat}",
                'path' => $r['file'], 'line' => $r['line']] + ($r['live'] ? [] : ['broken' => true, 'reason' => 'routes file not loaded by bootstrap']));
            // index by static prefix (up to first param) for view URL matching
            $static = preg_replace('#[/(].*$#', '', $pat) === '' ? $pat : $pat;
            $this->customRoutePaths[$pat] = $id;
            if (!$r['live']) $this->meta['broken'] = ($this->meta['broken'] ?? 0) + 1;
        }
    }

    // === classification ======================================================

    private function classify(): void {
        // inbound counts per node
        $inbound = [];
        foreach ($this->edges as $e) {
            $inbound[$e['to']][$e['kind']][] = $e['from'];
        }
        $orphans = [];
        foreach ($this->nodes as $id => &$node) {
            if ($node['kind'] !== 'route' && $node['kind'] !== 'libmethod') continue;
            $in = $inbound[$id] ?? [];
            $viewCalls = $in['view-call'] ?? [];
            $callers   = array_merge($in['calls'] ?? [], $in['instantiates'] ?? [], $in['redirects'] ?? []);
            if ($viewCalls) {
                $node['reach'] = 'endpoint';
            } elseif ($callers) {
                // script-only iff every caller is a scripts/ or cli/ file owner
                $onlyScripts = true;
                foreach ($callers as $c) {
                    if (!preg_match('#^(file):(scripts|cli)/#', $c)) { $onlyScripts = false; break; }
                }
                $node['reach'] = $onlyScripts ? 'script-only' : 'internal';
            } else {
                $node['reach'] = 'orphan';
                if ($node['kind'] === 'route') $orphans[] = $id;
            }
        }
        unset($node);
        $this->meta['orphans'] = $orphans;
    }

    // === tokenizer + span extractor (validated, CALLGRAPH-DESIGN §2) =========

    /** Normalize token_get_all into [{id:int|null, text, line}], with the line-tracking fix. */
    private function tokenize(string $src): array {
        $raw = @token_get_all($src);
        $out = [];
        $line = 1;
        foreach ($raw as $t) {
            if (is_array($t)) {
                $out[] = ['id' => $t[0], 'text' => $t[1], 'line' => $t[2]];
                $line = $t[2] + substr_count($t[1], "\n");   // the bug: advance past newlines
            } else {
                $out[] = ['id' => null, 'text' => $t, 'line' => $line];
            }
        }
        return $out;
    }

    /** Next significant token index (skips whitespace/comments), or null. */
    private function sig(array $toks, int $i): ?int {
        $n = count($toks);
        for (; $i < $n; $i++) {
            $id = $toks[$i]['id'];
            if ($id === T_WHITESPACE || $id === T_COMMENT || $id === T_DOC_COMMENT) continue;
            return $i;
        }
        return null;
    }

    /** Previous significant token index, or null. */
    private function sigPrev(array $toks, int $i): ?int {
        for (; $i >= 0; $i--) {
            $id = $toks[$i]['id'];
            if ($id === T_WHITESPACE || $id === T_COMMENT || $id === T_DOC_COMMENT) continue;
            return $i;
        }
        return null;
    }

    /**
     * Method spans via a typed brace stack. Returns list of
     * ['owner'=>'Class::method', 'class'=>, 'method'=>, 'start'=>, 'end'=>, 'stub'=>bool].
     */
    private function methodSpans(array $toks): array {
        $spans = [];
        $stack = [];                 // each: ['type'=>class|method|interp|other, 'class'=>, 'method'=>, 'start'=>]
        $pendingClass = null;        // class name (or '(anon)') awaiting its '{'
        $pendingMethod = null;       // ['name'=>, 'line'=>] awaiting '{' or ';'
        $classCtx = null;            // current class name (innermost class context)
        $n = count($toks);

        for ($i = 0; $i < $n; $i++) {
            $t = $toks[$i]; $id = $t['id']; $txt = $t['text']; $line = $t['line'];

            if ($id === T_CLASS || $id === T_TRAIT || $id === T_INTERFACE || $id === T_ENUM) {
                $prev = $this->sigPrev($toks, $i - 1);
                if ($prev !== null && $toks[$prev]['id'] === T_DOUBLE_COLON) continue;   // Foo::class
                if ($prev !== null && $toks[$prev]['id'] === T_NEW) { $pendingClass = '(anon)'; continue; }
                $nx = $this->sig($toks, $i + 1);
                $pendingClass = ($nx !== null && $toks[$nx]['id'] === T_STRING) ? $toks[$nx]['text'] : '(anon)';
                continue;
            }
            if ($id === T_FUNCTION) {
                // Only a method if the innermost open scope is a class context.
                $top = end($stack);
                if ($top && $top['type'] === 'class') {
                    $nx = $this->sig($toks, $i + 1);
                    if ($nx !== null && $toks[$nx]['id'] === T_STRING) {
                        $pendingMethod = ['name' => $toks[$nx]['text'], 'line' => $line, 'class' => $top['class']];
                    }
                }
                continue;
            }
            if ($id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
                $stack[] = ['type' => 'interp'];
                continue;
            }
            if ($id === null && $txt === '{') {
                if ($pendingMethod !== null) {
                    $stack[] = ['type' => 'method', 'class' => $pendingMethod['class'], 'method' => $pendingMethod['name'], 'start' => $pendingMethod['line']];
                    $pendingMethod = null;
                } elseif ($pendingClass !== null) {
                    $stack[] = ['type' => 'class', 'class' => $pendingClass];
                    $classCtx = $pendingClass;
                    $pendingClass = null;
                } else {
                    $stack[] = ['type' => 'other'];
                }
                continue;
            }
            if ($id === null && $txt === '}') {
                $popped = array_pop($stack);
                if ($popped && $popped['type'] === 'method') {
                    $spans[] = ['owner' => "{$popped['class']}::{$popped['method']}", 'class' => $popped['class'],
                        'method' => $popped['method'], 'start' => $popped['start'], 'end' => $line, 'stub' => false];
                }
                continue;
            }
            if ($id === null && $txt === ';' && $pendingMethod !== null) {
                // abstract/interface stub: `;` before `{`
                $spans[] = ['owner' => "{$pendingMethod['class']}::{$pendingMethod['name']}", 'class' => $pendingMethod['class'],
                    'method' => $pendingMethod['name'], 'start' => $pendingMethod['line'], 'end' => $pendingMethod['line'], 'stub' => true];
                $pendingMethod = null;
                continue;
            }
        }
        usort($spans, fn($a, $b) => $a['start'] <=> $b['start']);
        return $spans;
    }

    private string $rel_current = '';

    /** Returns a closure line→ownerId. Owner is route:/libmethod: for a span, else file:<rel>. */
    private function ownerResolver(array $spans, string $rel): callable {
        $this->rel_current = $rel;
        $isController = strncmp($rel, 'controls/', 9) === 0;
        return function (int $line) use ($spans, $rel, $isController): string {
            foreach ($spans as $s) {
                if ($line >= $s['start'] && $line <= $s['end']) {
                    $cls = $s['class']; $m = $s['method'];
                    if ($isController) {
                        $lc = strtolower(basename($rel, '.php'));
                        return "route:{$lc}::{$m}";
                    }
                    return "libmethod:{$cls}::{$m}";
                }
            }
            return "file:{$rel}";
        };
    }

    // === small utils =========================================================

    private function lastSegment(string $name): string {
        $name = ltrim($name, '\\');
        $p = strrpos($name, '\\');
        return $p === false ? $name : substr($name, $p + 1);
    }

    private function unquote(string $lit): string {
        $s = trim($lit);
        if (strlen($s) >= 2 && ($s[0] === "'" || $s[0] === '"')) $s = substr($s, 1, -1);
        return stripcslashes($s);
    }

    private function normBean(string $name): string {
        return strtolower(str_replace('_', '', $name));   // Bean wrapper contract (lib/Bean.php)
    }

    /** Does the call arg list starting at `(` contain a literal `false` (layout flag)? */
    private function argsContainFalse(array $toks, int $paren): bool {
        $depth = 0; $n = count($toks);
        for ($i = $paren; $i < $n; $i++) {
            $txt = $toks[$i]['text'];
            if ($txt === '(') $depth++;
            elseif ($txt === ')') { $depth--; if ($depth === 0) return false; }
            elseif ($toks[$i]['id'] === T_STRING && strtolower($txt) === 'false' && $depth === 1) return true;
        }
        return false;
    }
}

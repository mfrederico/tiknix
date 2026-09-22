<?php
/**
 * ConceptLint — is this concept fit to leave the instance it was built in?
 *
 * Concepts::verify() asks "will it switch on HERE". This asks the other question, the one
 * that matters before a concept enters a catalog other installs adopt from: does it carry
 * anything of its origin with it, and does its manifest tell the truth about what it needs?
 *
 * A concept is extracted from a real client's instance, so the failure modes are concrete:
 *   - the client's name, slug or domain baked into a string (serenity's ICS PRODID was
 *     'Serenity Gemstones and More')
 *   - a credential or an absolute server path
 *   - a core class or a bean it quietly depends on and never declared — which installs
 *     cleanly and then fatals on a install that lacks it
 *
 * Every finding is an ERROR or a WARNING. Publishing is refused on any error; nothing is
 * skipped and nothing is auto-fixed. Pure file reading: no database, no Flight.
 */

namespace app;

class ConceptLint {

    public const ERROR = 'error';
    public const WARN  = 'warn';

    /** Core classes every install has and no manifest needs to declare. */
    private const ALWAYS_AVAILABLE = ['Bean', 'Concepts', 'ConceptException', 'PermissionCache', 'mcptools'];

    private const SECRET_PATTERNS = [
        'Stripe key'        => '/\b[sr]k_(?:live|test)_[A-Za-z0-9]{8,}/',
        'broker key'        => '/\bbrk_[A-Za-z0-9]{12,}/',
        'API key'           => '/\btk_[A-Za-z0-9]{16,}/',
        'private key block' => '/-----BEGIN [A-Z ]*PRIVATE KEY-----/',
        'AWS access key'    => '/\bAKIA[0-9A-Z]{16}\b/',
        'Slack token'       => '/\bxox[abp]-[A-Za-z0-9-]{10,}/',
    ];

    private const BEAN_CALL_RE = '/\bBean::(?:dispense|load|find|findOne|findAll|findOneOrFail|count|trash|trashAll)\(\s*[\'"]([A-Za-z_]+)[\'"]/';
    private const RAW_SQL_RE   = '/\bBean::(?:exec|getAll|getRow|getCol|getCell)\(/';

    /**
     * @param string   $dir     the concept directory
     * @param string[] $forbid  strings that identify the ORIGIN install (slug, domain, site
     *                          name) and must not appear anywhere in the concept
     * @return array<int,array{severity:string,file:string,line:int,message:string}>
     */
    public static function check(string $dir, array $forbid = []): array {
        $dir = rtrim($dir, '/');
        $name = basename($dir);
        try {
            $m = ConceptManifest::load($dir, $name);
        } catch (ConceptException $e) {
            return [self::finding(self::ERROR, ConceptManifest::FILE, 0, $e->getMessage())];
        }

        $forbid = array_values(array_filter(array_map('trim', $forbid), fn($s) => strlen($s) >= 3));
        $out = [];
        $sawRawSql = [];

        // Agent guidance (AgentGuidance): the catalog exists so agents learn what they can
        // adopt, and a concept without guidelines.md is one they will misuse. Short, because
        // it is loaded every session on every install that enables the concept.
        $g = "{$dir}/" . AgentGuidance::CONCEPT_FILE;
        if (!is_file($g) || is_link($g)) {
            $out[] = self::finding(self::ERROR, AgentGuidance::CONCEPT_FILE, 0,
                'is missing. Every concept ships guidelines.md: what it is, its beans, slots and tools, how to extend it, what not to do (≤ ' . AgentGuidance::MAX_LINES . ' lines; the long form is a skill).');
        } else {
            $text = trim((string) file_get_contents($g));
            $n = $text === '' ? 0 : substr_count($text, "\n") + 1;
            if ($n === 0) $out[] = self::finding(self::ERROR, AgentGuidance::CONCEPT_FILE, 0, 'is empty.');
            if ($n > AgentGuidance::MAX_LINES) $out[] = self::finding(self::ERROR, AgentGuidance::CONCEPT_FILE, AgentGuidance::MAX_LINES + 1, "is {$n} lines; the limit is " . AgentGuidance::MAX_LINES . '. Move the long form to skills/<skill>/SKILL.md.');
            foreach (self::linesMatching($text, '/^## /m') as $ln) $out[] = self::finding(self::ERROR, AgentGuidance::CONCEPT_FILE, $ln, "opens a '## ' heading; the concept's guidance is one section (use ### inside it).");
        }
        // Skills: skills/<skill>/SKILL.md, Agent Skills format — frontmatter with name + description.
        foreach (glob("{$dir}/skills/*", GLOB_ONLYDIR) ?: [] as $sd) {
            $skill = basename($sd);
            $rel = "skills/{$skill}/SKILL.md";
            if (!preg_match('/^[a-z][a-z0-9-]*$/D', $skill)) {
                $out[] = self::finding(self::ERROR, "skills/{$skill}", 0, 'skill directory names are lowercase letters, digits and dashes.');
            }
            if (!is_file("{$dir}/{$rel}")) { $out[] = self::finding(self::ERROR, $rel, 0, 'is missing; a skill is a directory with a SKILL.md.'); continue; }
            $body = (string) file_get_contents("{$dir}/{$rel}");
            if (!preg_match('/\A---\n(.*?)\n---\n/s', $body, $fm)
                || !preg_match('/^name:\s*\S/m', $fm[1]) || !preg_match('/^description:\s*\S/m', $fm[1])) {
                $out[] = self::finding(self::ERROR, $rel, 1, 'must start with YAML frontmatter carrying name: and description: (Agent Skills format).');
            }
        }

        foreach (self::files($dir) as $rel) {
            // Where an INSTALL came from — written by the installer, never published, and not
            // the concept author's text. Linting it faulted every installed concept.
            if ($rel === ConceptCatalog::PROVENANCE_FILE) continue;
            // Never read THROUGH a symlink: it points out of the concept, and linting it would
            // mean opening whatever it names (it was /etc/hostname in the test that found this).
            if (is_link("{$dir}/{$rel}")) {
                $out[] = self::finding(self::ERROR, $rel, 0, 'is a symlink. A concept carries files, not pointers out of itself.');
                continue;
            }
            $raw = (string) file_get_contents("{$dir}/{$rel}");
            $isPhp = str_ends_with($rel, '.php');
            $lines = explode("\n", $raw);

            // Origin leakage and secrets are searched in the RAW text: a client's name in a
            // comment has still left the building.
            foreach ($lines as $i => $line) {
                foreach ($forbid as $needle) {
                    if (stripos($line, $needle) !== false) {
                        $out[] = self::finding(self::ERROR, $rel, $i + 1, "names the origin install (\"{$needle}\"). Use Flight::siteName() or a concept setting.");
                    }
                }
                foreach (self::SECRET_PATTERNS as $what => $re) {
                    if (preg_match($re, $line)) $out[] = self::finding(self::ERROR, $rel, $i + 1, "contains what looks like a {$what}.");
                }
                if (preg_match('#(?<![A-Za-z0-9_.])/(?:var/www|home/[a-z])#', $line)) {
                    $out[] = self::finding(self::ERROR, $rel, $i + 1, 'contains an absolute server path. Build paths from __DIR__.');
                }
            }
            if (!$isPhp) continue;

            // Everything below reads CODE only, so a docblock that mentions app\Mailer or
            // quotes "R::exec" is not mistaken for a dependency or a database call.
            $code = self::stripComments($raw);
            $top = explode('/', $rel)[0];

            if (in_array($top, ['controls', 'lib', 'mcptools'], true)) {
                $want = 'app\\concepts\\' . $name;
                if (!preg_match('/^\s*namespace\s+([A-Za-z0-9_\\\\]+)\s*;/m', $code, $ns)
                    || ($ns[1] !== $want && strncmp($ns[1], $want . '\\', strlen($want) + 1) !== 0)) {
                    $out[] = self::finding(self::ERROR, $rel, 1, "must declare namespace {$want} (found " . ($ns[1] ?? 'none') . ').');
                }
            }
            if ($top === 'models' && preg_match('/^\s*namespace\s+/m', $code)) {
                $out[] = self::finding(self::ERROR, $rel, 1, 'a FUSE model must be a global-namespace Model_* class — RedBean resolves it by that name.');
            }
            if ($top === 'mcptools') {
                // An MCP tool's name is its identity on every tools/list: "<concept>_<x>", so
                // it can never collide with a core tool and always says where it came from.
                foreach (self::matches($code, '/static\s+string\s+\$name\s*=\s*([\'"])([^\'"]*)\1/') as [$ln, $tn]) {
                    if (!Concepts::toolNameOk($name, $tn[2])) {
                        $out[] = self::finding(self::ERROR, $rel, $ln, "tool name '{$tn[2]}' must be '{$name}_<something>' (lowercase).");
                    }
                }
                $declared = array_column($m->tools, 'class');
                $cls = basename($rel, '.php');
                if (!in_array($cls, $declared, true)) {
                    $out[] = self::finding(self::ERROR, $rel, 1, "is in mcptools/ but provides.tools does not declare '{$cls}'. A tool is declared, never discovered.");
                }
            }

            foreach (self::linesMatching($code, '/(?<![A-Za-z0-9_\\\\])R::[a-zA-Z]+\s*\(/') as $ln) {
                $out[] = self::finding(self::ERROR, $rel, $ln, 'calls R:: directly. Use the Bean:: wrapper — concept seeds included.');
            }

            foreach (self::matches($code, '/(?<![A-Za-z0-9_])\\\\?app\\\\([A-Za-z_][A-Za-z0-9_]*)((?:\\\\[A-Za-z_][A-Za-z0-9_]*)*)/') as [$ln, $x]) {
                $first = $x[1];
                if ($first === 'concepts') {
                    $other = ltrim(explode('\\', ltrim($x[2], '\\'))[0] ?? '', '\\');
                    if ($other !== '' && $other !== $name && !in_array($other, $m->requiresConcepts, true)) {
                        $out[] = self::finding(self::ERROR, $rel, $ln, "reaches into concept '{$other}', which is not in requires.concepts.");
                    }
                    continue;
                }
                if ($first === 'BaseControls' || in_array($first, self::ALWAYS_AVAILABLE, true)) continue;
                if (!in_array($first, $m->requiresLib, true)) {
                    $out[] = self::finding(self::ERROR, $rel, $ln, "uses app\\{$first}, which requires.lib does not list. An install without it would fatal.");
                }
            }

            foreach (self::matches($code, self::BEAN_CALL_RE) as [$ln, $x]) {
                $bean = strtolower(str_replace('_', '', $x[1]));
                if (!in_array($bean, $m->beans, true) && !in_array($bean, $m->usesBeans, true)) {
                    $out[] = self::finding(self::ERROR, $rel, $ln,
                        "touches bean '{$bean}', which it neither owns (provides.beans) nor declares (uses.beans).");
                }
            }
            if (preg_match(self::RAW_SQL_RE, $code) && !isset($sawRawSql[$rel])) {
                $sawRawSql[$rel] = true;
                $ln = self::linesMatching($code, self::RAW_SQL_RE)[0] ?? 0;
                $out[] = self::finding(self::WARN, $rel, $ln, 'runs raw SQL — table names in it are not checked here; confirm each is under provides.beans or uses.beans.');
            }
        }

        if ($m->blurb === '') $out[] = self::finding(self::WARN, ConceptManifest::FILE, 0, 'has no "blurb" — that is the text a planner searches.');
        if (!$m->tags)        $out[] = self::finding(self::WARN, ConceptManifest::FILE, 0, 'has no "tags" — the catalog search weights them.');
        if (!is_dir("{$dir}/tests")) $out[] = self::finding(self::WARN, 'tests/', 0, 'ships no tests, so an install has nothing to prove it works.');

        return $out;
    }

    /** @return string[] every file in the concept, relative, sorted */
    public static function files(string $dir): array {
        $dir = rtrim($dir, '/');
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() || $f->isLink()) $out[] = substr($f->getPathname(), strlen($dir) + 1);
        }
        sort($out);
        return $out;
    }

    public static function hasErrors(array $findings): bool {
        foreach ($findings as $f) if ($f['severity'] === self::ERROR) return true;
        return false;
    }

    /** Comments out, strings and line numbers kept. Lexed, not pattern-matched. */
    private static function stripComments(string $src): string {
        $out = '';
        foreach (token_get_all($src) as $tok) {
            if (is_array($tok) && ($tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT)) {
                $out .= str_repeat("\n", substr_count($tok[1], "\n"));
            } else {
                $out .= is_array($tok) ? $tok[1] : $tok;
            }
        }
        return $out;
    }

    /** @return array<int,array{0:int,1:array}> [line, match groups] for every match */
    private static function matches(string $code, string $re): array {
        $out = [];
        if (preg_match_all($re, $code, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($all as $set) {
                $out[] = [substr_count(substr($code, 0, $set[0][1]), "\n") + 1, array_column($set, 0)];
            }
        }
        return $out;
    }

    /** @return int[] */
    private static function linesMatching(string $code, string $re): array {
        return array_column(self::matches($code, $re), 0);
    }

    private static function finding(string $severity, string $file, int $line, string $message): array {
        return ['severity' => $severity, 'file' => $file, 'line' => $line, 'message' => $message];
    }
}

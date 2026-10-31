<?php
/**
 * Choice — what this visitor said. Kept in a cookie of its own (needed to remember the answer, so it needs no consent)
 * and, as proof, in a consentrecord row: the groups, when, and the version of the list they answered. No name, no
 * address. The cookie reads "<version>.<id>.<group>-<group>"; an answer to an older version counts as no answer.
 */

namespace app\concepts\consent;

use app\Bean;

class Choice {

    public const COOKIE = 'tiknix_consent';
    /** How long an answer lasts before the visitor is asked again. */
    public const DAYS = 182;
    /** A proof is kept for the answer's life plus a year. */
    public const KEEP_DAYS = 547;

    /** Cookies an outside service leaves on this site's own address, by group: removed when that group is refused. */
    public const LEFTOVERS = [
        'analytics' => '/^(_ga|_ga_.*|_gid|_gat.*|_hj.*|_clck|_clsk|ph_.*|mp_.*|ajs_.*)$/',
        'marketing' => '/^(_fbp|_fbc|_gcl_.*|_ttp|li_.*|_uet.*|hubspotutk|__hs.*)$/',
    ];

    /** Parse a cookie value. @return array{version:int,id:string,groups:string[]}|null null when it is not one */
    public static function parse(string $value): ?array {
        if (!preg_match('/^(\d{1,9})\.([a-f0-9]{16})\.([a-z-]*)$/', $value, $m)) return null;
        $groups = array_values(array_intersect(array_filter(explode('-', $m[3])), Groups::asked()));
        return ['version' => (int) $m[1], 'id' => $m[2], 'groups' => $groups];
    }

    /** This visitor's answer to the current list, or null when they have not given one. */
    public static function current(?string $cookie = null, ?int $version = null): ?array {
        $c = self::parse($cookie ?? (string) ($_COOKIE[self::COOKIE] ?? ''));
        return $c !== null && $c['version'] === ($version ?? Settings::version()) ? $c : null;
    }

    /** The answer they last gave, even to an older list: what the form starts from. */
    public static function last(): array { return self::parse((string) ($_COOKIE[self::COOKIE] ?? ''))['groups'] ?? []; }

    /** May this group run for this visitor? Needed always; the rest only on a yes. */
    public static function allows(string $group): bool {
        if ($group === Groups::NECESSARY) return true;
        if (!Groups::exists($group)) throw new \InvalidArgumentException("no consent group called '{$group}'");
        return in_array($group, self::current()['groups'] ?? [], true);
    }

    /** Record an answer. @param string[] $groups @param string $how all|none|pick @return string the cookie's value */
    public static function save(array $groups, string $how, int $memberId = 0): string {
        foreach ($groups as $g) if (!in_array($g, Groups::asked(), true)) throw new \InvalidArgumentException("'{$g}' is not a group a visitor is asked about");
        $id = bin2hex(random_bytes(8));
        $version = Settings::version();
        $r = Bean::dispense('consentrecord');
        $r->rid = $id; $r->choices = implode(',', $groups); $r->how = $how; $r->version = $version; $r->memberRef = $memberId; $r->at = date('Y-m-d H:i:s');
        Bean::store($r);
        if (random_int(1, 100) === 1) Bean::exec('DELETE FROM consentrecord WHERE at < ?', [date('Y-m-d H:i:s', time() - self::KEEP_DAYS * 86400)]);
        return "{$version}.{$id}." . implode('-', $groups);
    }

    /** The cookies left behind by a group this answer does not allow. @return string[] names, of those this request carries */
    public static function leftovers(array $groups, array $cookies): array {
        $out = [];
        foreach (self::LEFTOVERS as $group => $re) {
            if (in_array($group, $groups, true)) continue;
            foreach (array_keys($cookies) as $name) if (preg_match($re, (string) $name)) $out[] = (string) $name;
        }
        return $out;
    }

    /** Send the answer's cookie, and expire what a refused group left: on this host and each parent domain a script may have used. */
    public static function send(string $value, array $groups, string $host, bool $https): void {
        setcookie(self::COOKIE, $value, ['expires' => time() + self::DAYS * 86400, 'path' => '/', 'secure' => $https, 'samesite' => 'Lax']);
        $names = self::leftovers($groups, $_COOKIE);
        if (!$names) return;
        $labels = explode('.', $host);
        $domains = [''];
        for ($i = 0; $i < count($labels) - 1; $i++) $domains[] = '.' . implode('.', array_slice($labels, $i));
        foreach ($names as $n) foreach ($domains as $d) setcookie($n, '', ['expires' => 1, 'path' => '/', 'domain' => $d, 'secure' => $https, 'samesite' => 'Lax']);
    }
}

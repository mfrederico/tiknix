<?php
/**
 * AgentState — where an agent's credentials live.
 *
 * They belong to the PERSON running the agent, not to the project. Keyed per project
 * (the original design) every new project demanded its own `/login` before it could plan
 * or build anything, and — worse — a teammate's build would have spent whichever account
 * that project happened to hold, which nobody agreed to. Keyed per member, you sign in
 * once and every project you can reach runs as you.
 *
 * The store lives OUTSIDE the instance directories: a project's folder is archived on
 * delete, published by the rsync/github drivers, and readable by that project's agent.
 * Credentials have no business in any of those.
 *
 * One deliberate widening to be aware of: an agent inside a jail can read the credential
 * bound into it, so an agent in any of your projects can now see the token that opens all
 * of them. They are all yours and the jail is the boundary either way — but it is a real
 * change from "this agent can only ever see this project's token", and it is the reason
 * a SHARED project still runs as whoever triggered it rather than as its owner.
 */
namespace app;

class AgentState {

    /** Where per-member stores live. Outside the web root and outside any instance. */
    private const DEFAULT_BASE = '/home/ubuntu/.tiknix/agent-state';

    private static function base(): string {
        $cfg = @parse_ini_file(dirname(__DIR__) . '/conf/aibuilder.ini', true) ?: [];
        return rtrim((string) ($cfg['agent']['state_base'] ?? self::DEFAULT_BASE), '/');
    }

    private static function engine(string $engine): string {
        return preg_replace('/[^a-z0-9_-]/i', '', $engine) ?: 'claude';
    }

    /** The per-PROJECT store — the old location, still the fallback. */
    public static function projectDir(string $instanceDir, string $engine): string {
        return rtrim($instanceDir, '/') . '/.aibuilder/state/' . self::engine($engine);
    }

    /** The per-MEMBER store. */
    public static function memberDir(int $memberId, string $engine): string {
        return self::base() . '/' . max(0, $memberId) . '/' . self::engine($engine);
    }

    /**
     * The directory to bind as the agent's config/creds home, creating it if needed.
     *
     * Migrates a project that is ALREADY signed in: its credential is moved into the
     * member's store the first time that member runs an agent, so nobody has to log in
     * again to keep working. One-time, and only ever from a project the member can
     * already run agents in.
     */
    public static function resolve(int $memberId, string $engine, string $instanceDir): string {
        if ($memberId <= 0) return self::projectDir($instanceDir, $engine);   // no member: old behaviour

        $mine = self::memberDir($memberId, $engine);
        if (!is_dir($mine) && !@mkdir($mine, 0700, true) && !is_dir($mine)) {
            return self::projectDir($instanceDir, $engine);   // cannot create it; do not break the run
        }
        @chmod($mine, 0700);

        if (!is_file($mine . '/.credentials.json')) {
            // Adopt an existing login rather than demanding a new one — from THIS project
            // if it has one, otherwise from any other project this member OWNS. That
            // second case is the one that matters: you signed in once, in some project,
            // and every project of yours should already work. Owned only, read from
            // core's registry, so this can never pick up someone else's credential.
            $from = self::adoptableFrom($memberId, $engine, $instanceDir);
            if ($from !== '' && @copy($from . '/.credentials.json', $mine . '/.credentials.json')) {
                @chmod($mine . '/.credentials.json', 0600);
                foreach (['.claude.json', 'settings.json'] as $also) {
                    if (is_file($from . '/' . $also) && !is_file($mine . '/' . $also)) {
                        @copy($from . '/' . $also, $mine . '/' . $also);
                    }
                }
            }
        }

        return $mine;
    }

    /**
     * A directory holding a credential this member may adopt: this project first, then
     * the most recently used of their OWN other projects.
     *
     * Read with a plain PDO against core's registry on purpose — this is called from
     * CLIs whose RedBean connection points at an instance's tasks db, and switching the
     * ORM's database underneath a running build to answer a lookup is the habit that put
     * plans in the wrong database twice.
     */
    private static function adoptableFrom(int $memberId, string $engine, string $instanceDir): string {
        $here = self::projectDir($instanceDir, $engine);
        if (is_file($here . '/.credentials.json')) return $here;

        $best = ''; $bestAt = 0;
        try {
            $pdo = new \PDO('sqlite:' . dirname(__DIR__) . '/database/tiknix.db');
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $st = $pdo->prepare('SELECT slug, app FROM instance WHERE member_id = ?');
            $st->execute([$memberId]);
            foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $dir  = \Model_Instance::dirForSlug((string) $row['slug'], (string) ($row['app'] ?: 'tiknix'));
                $cand = self::projectDir($dir, $engine);
                $file = $cand . '/.credentials.json';
                if (!is_file($file)) continue;
                $at = (int) @filemtime($file);
                if ($at > $bestAt) { $best = $cand; $bestAt = $at; }
            }
        } catch (\Throwable $e) { /* no registry reachable: nothing to adopt */ }

        return $best;
    }

    /** Is this member signed in for this engine (after migration would have run)? */
    public static function signedIn(int $memberId, string $engine, string $instanceDir): bool {
        if ($memberId > 0 && is_file(self::memberDir($memberId, $engine) . '/.credentials.json')) return true;
        if (is_file(self::projectDir($instanceDir, $engine) . '/.credentials.json')) return true;
        // Not signed in HERE, but adoptable from another of their projects — which
        // resolve() will do on the next run, so the honest answer is yes.
        return $memberId > 0 && self::adoptableFrom($memberId, $engine, $instanceDir) !== '';
    }
}

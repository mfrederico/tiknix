<?php
/**
 * SchemaAuditWriter — makes RedBean's automatic schema changes LOUD.
 *
 * In fluid mode RedBean treats an unknown bean property as a schema it should build, not
 * as a mistake, and creates the column silently. That is the correct contract for a seed
 * building a table; it is a trap everywhere else, because a typo becomes a real column.
 *
 * It happened here: something once stored an `authcontrol` bean carrying `controller` and
 * `operation` properties. Both columns were created, stayed NULL in all 171 rows, and made
 * a WRONG query VALID —
 *
 *     SELECT controller FROM authcontrol WHERE controller = 'api'
 *
 * runs clean and returns nothing, because every value is NULL. That reads as "no such
 * permission" when the row is right there under `control`, and it cost a real detour before
 * anyone thought to compare the column list.
 *
 * This writer does not PREVENT the change — preventing it means freezing, which would also
 * stop the seeds and the generated features that legitimately grow the schema. It makes the
 * change impossible to miss: every automatic CREATE TABLE / ADD COLUMN / widen is logged at
 * ERROR with the call site that caused it. A schema change from a seed is expected and its
 * log line is a receipt. A schema change from a controller during a web request is a bug,
 * and now it leaves evidence naming the file and line.
 *
 * Wired in bootstrap.php via the same ToolBox swap the query cache uses.
 */

namespace app;

use RedBeanPHP\QueryWriter\SQLiteT;

class SchemaAuditWriter extends SQLiteT {

    /**
     * Where the schema change came from, skipping RedBean's own frames.
     *
     * The vendor frames are never the answer — every one of these calls arrives through
     * OODB::store — so they are dropped and the first application frame is reported.
     */
    private static function origin(): string {
        /* PASS-THROUGH frames are skipped, not just vendor ones.
         *
         * Every bean operation in this codebase goes through the Bean:: wrapper by mandate,
         * so the first non-vendor frame is ALWAYS lib/Bean.php — which reported the wrapper
         * as the origin of every schema change and named nothing useful. The caller of the
         * wrapper is the code that actually misspelled the property. */
        $passThrough = ['/lib/SchemaAuditWriter.php', '/lib/Bean.php', '/vendor/gabordemooij/'];
        $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 32);
        foreach ($frames as $f) {
            $file = $f['file'] ?? '';
            if ($file === '') continue;
            foreach ($passThrough as $skip) {
                if (str_contains($file, $skip)) continue 2;
            }
            return $file . ':' . ($f['line'] ?? 0);
        }
        // Only the wrapper on the stack — better to say so than to name it and mislead.
        return 'unknown (wrapper-only stack)';
    }

    /**
     * ERROR level on purpose, even though nothing failed.
     *
     * Severity here is about "a human must look at this", not about whether the request
     * survived. A silent schema change survives fine; that is exactly the problem. Written
     * through error_log so it lands wherever the app's log goes without this class needing
     * to know how logging is configured.
     */
    private static function note(string $what): void {
        error_log(sprintf(
            'ERROR RedBean fluid schema change: %s — from %s. '
          . 'Expected from a seed or migration; from a request it is almost certainly a '
          . 'misspelled bean property, which becomes a real always-NULL column.',
            $what, self::origin()
        ));
    }

    public function createTable( $type )
    {
        self::note("CREATE TABLE {$type}");
        return parent::createTable( $type );
    }

    public function addColumn( $table, $column, $type )
    {
        self::note("ADD COLUMN {$table}.{$column}");
        return parent::addColumn( $table, $column, $type );
    }

    /**
     * Widening is logged too, and it is the most dangerous of the three: on SQLite a widen
     * rebuilds the table, and a rebuild that goes wrong takes every row with it.
     */
    public function widenColumn( $type, $column, $datatype )
    {
        self::note("WIDEN COLUMN {$type}.{$column}");
        return parent::widenColumn( $type, $column, $datatype );
    }
}

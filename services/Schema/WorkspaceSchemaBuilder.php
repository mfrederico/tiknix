<?php
/**
 * WorkspaceSchemaBuilder — runs numbered bean seeds to build the DB schema.
 *
 * Seeds live in services/Schema/Seeds/ and are numbered (01, 02, …). Each seed
 * uses RedBeanPHP bean operations (dispense/store) rather than raw DDL, so
 * RedBean emits dialect-correct schema for whatever the connection is — SQLite
 * locally, MySQL/Postgres on a deploy (see DB_DSN). That's why this works
 * unchanged across backends: RedBean owns the DDL dialectisms.
 *
 * Each seed's "pass 1" stores a padded sample bean (str_repeat('x', N)) to size
 * columns, then defers it; after all seeds run, the deferred padding beans are
 * trashed. "Pass 2" plants the real bootstrap data idempotently. Indexes use
 * CREATE ... IF NOT EXISTS (portable across SQLite/MySQL).
 *
 * Idempotent: safe to run repeatedly (each seed checks before creating).
 */

namespace app\services\Schema;

use \app\Bean;
use \Flight as Flight;

class WorkspaceSchemaBuilder {

    private array $deferred = [];
    private array $results = [];

    /**
     * @param string|null $seedDir numbered seeds to run; defaults to core's own. A concept
     *                             passes concepts/<name>/seeds so its schema is built by the
     *                             same thaw / run / refreeze, with the same helpers in scope.
     */
    public function build(?string $seedDir = null): array {
        $seedDir = $seedDir ?? __DIR__ . '/Seeds';
        if (!is_dir($seedDir)) {
            return ['error' => 'Seeds directory not found'];
        }

        $files = glob("{$seedDir}/*.php");
        sort($files); // numbered order

        // Helper closures made available to each seed file's scope.
        $_tableCheck = function (string $table): bool {
            try {
                return in_array(Bean::normalize($table), Bean::inspect(), true);
            } catch (\Exception $e) {
                return false;
            }
        };

        $_defer = function ($bean): void {
            if ($bean && $bean->id) {
                $this->deferred[] = $bean;
            }
        };

        /* Dispense a padding bean with FUSE switched off.
         *
         * A model whose update() hook validates (Model_Interview's phase, Model_Handoffstep's
         * step) rejects str_repeat('x', N) padding, so the seed throws and the table is never
         * created; a hook that stamps dates would also retype a padded TEXT column NUMERIC.
         * The model is resolved once, at dispense, from OODB's bean helper — so dispensing
         * through a helper that resolves no model gives a bean whose store() runs no hooks.
         */
        $_dispensePadding = function (string $type) {
            $oodb = \RedBeanPHP\R::getRedBean();
            $helper = $oodb->getBeanHelper();
            $oodb->setBeanHelper(new class extends \RedBeanPHP\BeanHelper\SimpleFacadeBeanHelper {
                public function getModelForBean(\RedBeanPHP\OODBBean $bean) { return null; }
            });
            try {
                return Bean::dispense($type);
            } finally {
                $oodb->setBeanHelper($helper);
            }
        };

        $logger = Flight::get('log');

        /* THAW for the duration of the build, and only here.
         *
         * Building the schema is the one job that legitimately creates tables and columns,
         * so it is the one place fluid mode belongs. Everywhere else it is a trap: an
         * unknown bean property is treated as schema to build rather than a mistake, which
         * is how `authcontrol` grew ghost `controller`/`operation` columns that stayed NULL
         * in all 171 rows and made a wrong query merely empty instead of an error.
         *
         * The previous state is captured and restored rather than assumed, because this
         * runs both from the CLI (frozen) and from a request, and leaving the connection
         * thawed afterwards would hand every later query the behaviour we are removing.
         */
        $wasFrozen = Bean::isFrozen();
        Bean::freeze(false);
        try {

        foreach ($files as $file) {
            $name = basename($file);
            try {
                $logger?->debug("SchemaBuilder: running seed {$name}");
                include $file;
                $this->results[$name] = 'ok';
            } catch (\Throwable $e) {
                $logger?->error("SchemaBuilder: seed {$name} failed", ['error' => $e->getMessage()]);
                // A failed seed is caught here, so it is not an uncaught error and never
                // reached the Firehose on its own — start.tiknix's interview table went
                // unbuilt with the failure sitting in a log nobody read (2026-09-25).
                // Reported with the same reporter, self-gated on this install's config.
                \app\ErrorReporter::capture($e, 'seed_failure', ['seed' => $name, 'seed_dir' => basename(dirname($file))]);
                $this->results[$name] = 'error: ' . $e->getMessage();
            }
        }

        // Trash the schema-priming padding beans. Reverse order so children
        // (deferred after parents) trash first and FK constraints don't reject.
        foreach (array_reverse($this->deferred) as $bean) {
            try { Bean::trash($bean); } catch (\Exception $e) { /* ignore */ }
        }

        $logger?->info('SchemaBuilder: build complete', ['results' => $this->results]);

        } finally {
            // finally, not a trailing call: a seed that throws must not leave the
            // connection thawed for whatever runs next.
            Bean::freeze($wasFrozen);
        }
        return $this->results;
    }
}

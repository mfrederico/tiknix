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
use \RedBeanPHP\R as R;
use \Flight as Flight;

class WorkspaceSchemaBuilder {

    private array $deferred = [];
    private array $results = [];

    public function build(): array {
        $seedDir = __DIR__ . '/Seeds';
        if (!is_dir($seedDir)) {
            return ['error' => 'Seeds directory not found'];
        }

        $files = glob("{$seedDir}/*.php");
        sort($files); // numbered order

        // Helper closures made available to each seed file's scope.
        $_tableCheck = function (string $table): bool {
            try {
                return in_array(Bean::normalize($table), R::inspect(), true);
            } catch (\Exception $e) {
                return false;
            }
        };

        $_defer = function ($bean): void {
            if ($bean && $bean->id) {
                $this->deferred[] = $bean;
            }
        };

        $logger = Flight::get('log');

        foreach ($files as $file) {
            $name = basename($file);
            try {
                $logger?->debug("SchemaBuilder: running seed {$name}");
                include $file;
                $this->results[$name] = 'ok';
            } catch (\Throwable $e) {
                $logger?->error("SchemaBuilder: seed {$name} failed", ['error' => $e->getMessage()]);
                $this->results[$name] = 'error: ' . $e->getMessage();
            }
        }

        // Trash the schema-priming padding beans. Reverse order so children
        // (deferred after parents) trash first and FK constraints don't reject.
        foreach (array_reverse($this->deferred) as $bean) {
            try { R::trash($bean); } catch (\Exception $e) { /* ignore */ }
        }

        $logger?->info('SchemaBuilder: build complete', ['results' => $this->results]);
        return $this->results;
    }
}

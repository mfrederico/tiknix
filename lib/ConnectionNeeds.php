<?php
/**
 * ConnectionNeeds — a build said the app needs a connection (AgentTask::NEEDS_FILE, kept on the task as needs_json by
 * PlanExecutor). When the project's owner connects that connector here on tiknix.com (Connections: by key, or by signing in),
 * the need is met: marked on every task that named it, so the Builder's Ask turns its card to "connected". Set only from the
 * connect itself, never from a page saying so.
 */

namespace app;

class ConnectionNeeds {

    /** @return int how many tasks had the need */
    public static function met(object $inst, string $connector): int {
        $connector = strtolower(trim($connector));
        if ($connector === '' || !$inst->id) return 0;
        $n = self::metIn(\Model_Instance::dirOf($inst), $connector);
        if ($n > 0) \Flight::get('log')->info('ConnectionNeeds: a connection a build asked for is connected', ['slug' => $inst->slug, 'connector' => $connector, 'tasks' => $n]);
        return $n;
    }

    /** met() for a project's folder: marks the need on its board's tasks. @return int how many tasks had the need */
    public static function metIn(string $dir, string $connector): int {
        if ($connector === '' || !is_file($dir . '/data/workbench.db')) return 0;
        return (int) BuilderMail::inBoard($dir, function () use ($connector) {
            if (!Bean::getCell("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'workbenchtask'")
                || !array_key_exists('needs_json', Bean::inspect('workbenchtask'))) return 0;   // no build has asked for anything here
            $n = 0;
            foreach (Bean::find('workbenchtask', 'needs_json LIKE ?', ['%"' . $connector . '"%']) as $t) {
                $needs = json_decode((string) $t->needsJson, true) ?: [];
                if (!isset($needs[$connector]) || ($needs[$connector]['connected_at'] ?? '') !== '') continue;
                $needs[$connector]['connected_at'] = date('Y-m-d H:i:s');
                $t->needsJson = json_encode($needs, JSON_UNESCAPED_SLASHES);
                Bean::store($t); $n++;
            }
            return $n;
        });
    }
}

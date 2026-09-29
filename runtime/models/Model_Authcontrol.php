<?php
/**
 * Authcontrol FUSE Model
 *
 * Automatically invalidates the PermissionCache whenever an authcontrol
 * record is created, updated, or deleted.
 *
 * This ensures APCu cache consistency across all processes (CLI, web, tmux sessions).
 */

class Model_Authcontrol extends \RedBeanPHP\SimpleModel {

    /**
     * Called after a bean is stored (insert or update)
     */
    public function after_update() {
        $this->clearPermissionCache();
    }

    /**
     * Called after a bean is deleted
     */
    public function after_delete() {
        $this->clearPermissionCache();
    }

    /**
     * Clear the permission cache
     */
    private function clearPermissionCache() {
        if (class_exists('\app\PermissionCache')) {
            \app\PermissionCache::clear();

            // Log if logger available
            if (class_exists('\Flight') && \Flight::has('log')) {
                \Flight::get('log')->info('Model_Authcontrol: Cache cleared after bean update/delete', [
                    'control' => $this->bean->control ?? 'unknown',
                    'method' => $this->bean->method ?? 'unknown'
                ]);
            }
        }
    }
}

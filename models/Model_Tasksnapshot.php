<?php
/**
 * Tasksnapshot FUSE Model
 *
 * Enables RedBeanPHP associations for the tasksnapshot bean:
 * - workbenchtask: The task this snapshot belongs to
 *
 * Snapshot Types: output, files_changed, current_action
 */

class Model_Tasksnapshot extends \RedBeanPHP\SimpleModel {
    // Associations are automatic - this class enables FUSE discovery
}

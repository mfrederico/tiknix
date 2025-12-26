<?php
/**
 * Tasklog FUSE Model
 *
 * Enables RedBeanPHP associations for the tasklog bean:
 * - workbenchtask: The task this log entry belongs to
 * - member: The member who created this log (null = system/Claude)
 *
 * Log Levels: debug, info, warning, error
 * Log Types: system, claude, user, validation
 */

class Model_Tasklog extends \RedBeanPHP\SimpleModel {
    // Associations are automatic - this class enables FUSE discovery
}

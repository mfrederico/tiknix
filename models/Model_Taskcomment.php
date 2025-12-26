<?php
/**
 * Taskcomment FUSE Model
 *
 * Enables RedBeanPHP associations for the taskcomment bean:
 * - workbenchtask: The task this comment belongs to
 * - member: The member who wrote this comment
 */

class Model_Taskcomment extends \RedBeanPHP\SimpleModel {
    // Associations are automatic - this class enables FUSE discovery
}

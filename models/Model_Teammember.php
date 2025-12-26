<?php
/**
 * Teammember FUSE Model
 *
 * Enables RedBeanPHP associations for the teammember bean:
 * - team: The team this membership belongs to
 * - member: The member in this team
 *
 * Roles:
 * - owner: Full control, can delete team
 * - admin: Can manage members, run/edit/delete tasks
 * - member: Can create, edit, run tasks (default)
 * - viewer: Read-only access to team tasks
 */

class Model_Teammember extends \RedBeanPHP\SimpleModel {
    // Associations are automatic - this class enables FUSE discovery
}

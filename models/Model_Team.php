<?php
/**
 * Team FUSE Model
 *
 * Enables RedBeanPHP associations for the team bean:
 * - ownTeammemberList: Members of this team
 * - ownTeaminvitationList: Pending invitations
 * - ownWorkbenchtaskList: Tasks belonging to this team
 *
 * Relations:
 * - owner: The member who owns this team (via owner_id)
 *
 * Use xownTeammemberList for cascade delete
 * Use xownTeaminvitationList for cascade delete
 * Use xownWorkbenchtaskList for cascade delete (careful!)
 */

class Model_Team extends \RedBeanPHP\SimpleModel {
    // Associations are automatic - this class enables FUSE discovery
}

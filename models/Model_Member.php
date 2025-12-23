<?php
/**
 * Member FUSE Model
 *
 * Enables RedBeanPHP associations for the member bean:
 * - ownApikeyList: API keys belonging to this member
 * - ownContactList: Contact submissions by this member
 * - ownSettingsList: Settings for this member
 */

class Model_Member extends \RedBeanPHP\SimpleModel {
    // Associations are automatic - this class enables FUSE discovery
}

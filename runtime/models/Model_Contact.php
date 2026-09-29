<?php
/**
 * Contact FUSE Model
 *
 * Enables RedBeanPHP associations for the contact bean:
 * - ownContactresponseList: Responses to this contact message
 *
 * Use xownContactresponseList for cascade delete (deletes responses when contact deleted)
 */

class Model_Contact extends \RedBeanPHP\SimpleModel {
    // Associations are automatic - this class enables FUSE discovery
}

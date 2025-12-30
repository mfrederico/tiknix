<?php
/**
 * Grocery Item FUSE Model
 *
 * Enables RedBeanPHP associations for the groceryitem bean:
 * - Belongs to member (member_id)
 * - sortOrder for drag/drop reordering
 * - isChecked for strike-through functionality
 */

class Model_Groceryitem extends \RedBeanPHP\SimpleModel {

    /**
     * Called before storing the bean
     */
    public function update() {
        // Set default sort order if not set
        if (!$this->bean->sortOrder) {
            $this->bean->sortOrder = 0;
        }

        // Set timestamps
        if (!$this->bean->id) {
            $this->bean->createdAt = date('Y-m-d H:i:s');
        }
        $this->bean->updatedAt = date('Y-m-d H:i:s');
    }
}

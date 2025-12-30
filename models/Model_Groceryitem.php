<?php
/**
 * Grocery Item FUSE Model
 *
 * Enables RedBeanPHP associations for the groceryitem bean:
 * - Belongs to member (member_id)
 * - Optionally belongs to grocerylist (grocerylist_id) for saved lists
 * - sortOrder for drag/drop reordering
 * - isChecked for strike-through functionality
 * - quantity for tracking how many of an item to get
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

        // Set default quantity if not set
        if (!$this->bean->quantity) {
            $this->bean->quantity = 1;
        }

        // Set timestamps
        if (!$this->bean->id) {
            $this->bean->createdAt = date('Y-m-d H:i:s');
        }
        $this->bean->updatedAt = date('Y-m-d H:i:s');
    }
}

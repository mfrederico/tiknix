<?php
/**
 * Grocery List FUSE Model
 *
 * Represents a saved grocery shopping trip with:
 * - Belongs to member (member_id)
 * - listDate for when shopping was done
 * - storeName where items were purchased
 * - totalCost for receipt total
 * - Has many groceryitems (ownGroceryitemList)
 */

class Model_Grocerylist extends \RedBeanPHP\SimpleModel {

    /**
     * Called before storing the bean
     */
    public function update() {
        // Set timestamps
        if (!$this->bean->id) {
            $this->bean->createdAt = date('Y-m-d H:i:s');
        }
        $this->bean->updatedAt = date('Y-m-d H:i:s');

        // Default list date to today if not set
        if (!$this->bean->listDate) {
            $this->bean->listDate = date('Y-m-d');
        }
    }
}

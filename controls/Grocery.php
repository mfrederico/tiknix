<?php
/**
 * Grocery List Controller
 *
 * Mobile-friendly grocery list with:
 * - CRUD operations
 * - Checkbox toggle with strike-through
 * - Drag/drop reordering
 * - Quantity tracking with decrement on check
 * - Save lists by date with store name and receipt total
 * - View history of saved lists
 */

namespace app;

use \Flight as Flight;
use \app\Bean;
use \Exception as Exception;
use app\BaseControls\Control;

class Grocery extends Control {

    public function __construct() {
        parent::__construct();

        // Require login for all actions
        if (!Flight::isLoggedIn()) {
            if (Flight::request()->ajax) {
                Flight::jsonError('Login required', 401);
                exit;
            }
            Flight::redirect('/auth/login?redirect=' . urlencode(Flight::request()->url));
            exit;
        }
    }

    /**
     * List grocery items (active list - not saved to a grocerylist yet)
     */
    public function index($params = []) {
        $this->viewData['title'] = 'Grocery List';

        // Get active grocery items (not yet saved to a list)
        $items = Bean::find('groceryitem',
            'member_id = ? AND grocerylist_id IS NULL ORDER BY is_checked ASC, sort_order ASC, created_at DESC',
            [$this->member->id]
        );

        // Get saved lists count for display
        $savedListsCount = Bean::count('grocerylist', 'member_id = ?', [$this->member->id]);

        $this->viewData['items'] = $items;
        $this->viewData['savedListsCount'] = $savedListsCount;

        $this->render('grocery/index', $this->viewData);
    }

    /**
     * View saved grocery lists history
     */
    public function history($params = []) {
        $this->viewData['title'] = 'Grocery History';

        // Get all saved lists ordered by date
        $lists = Bean::find('grocerylist',
            'member_id = ? ORDER BY list_date DESC, created_at DESC',
            [$this->member->id]
        );

        $this->viewData['lists'] = $lists;

        $this->render('grocery/history', $this->viewData);
    }

    /**
     * View a specific saved grocery list
     */
    public function view($params = []) {
        $listId = $params['id'] ?? null;

        if (!$listId) {
            Flight::redirect('/grocery/history');
            return;
        }

        $list = Bean::load('grocerylist', $listId);

        if (!$list->id || $list->memberId != $this->member->id) {
            Flight::redirect('/grocery/history');
            return;
        }

        $this->viewData['title'] = 'Grocery List - ' . date('M j, Y', strtotime($list->listDate));
        $this->viewData['list'] = $list;
        $this->viewData['items'] = $list->with(' ORDER BY sort_order ASC ')->ownGroceryitemList;

        $this->render('grocery/view', $this->viewData);
    }

    /**
     * Save current list (AJAX)
     */
    public function saveList($params = []) {
        $request = Flight::request();

        if ($request->method !== 'POST') {
            Flight::jsonError('Method not allowed', 405);
            return;
        }

        if (!Flight::csrf()->validateRequest()) {
            Flight::jsonError('Invalid CSRF token', 403);
            return;
        }

        $listDate = trim($request->data->listDate ?? date('Y-m-d'));
        $storeName = trim($request->data->storeName ?? '');
        $totalCost = floatval($request->data->totalCost ?? 0);

        // Validate date format
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $listDate)) {
            $listDate = date('Y-m-d');
        }

        // Validate store name length
        if (strlen($storeName) > 255) {
            Flight::jsonError('Store name is too long (max 255 characters)', 400);
            return;
        }

        try {
            $this->beginTransaction();

            // Get checked items (items to save)
            $checkedItems = Bean::find('groceryitem',
                'member_id = ? AND grocerylist_id IS NULL AND is_checked = 1',
                [$this->member->id]
            );

            if (empty($checkedItems)) {
                Flight::jsonError('No checked items to save', 400);
                return;
            }

            // Create the grocery list
            $list = Bean::dispense('grocerylist');
            $list->memberId = $this->member->id;
            $list->listDate = $listDate;
            $list->storeName = $storeName;
            $list->totalCost = $totalCost;
            Bean::store($list);

            // Move checked items to this list
            foreach ($checkedItems as $item) {
                $item->grocerylistId = $list->id;
                Bean::store($item);
            }

            $this->commit();

            $this->logger->info('Grocery list saved', [
                'list_id' => $list->id,
                'items_count' => count($checkedItems),
                'member_id' => $this->member->id
            ]);

            Flight::json([
                'success' => true,
                'list' => [
                    'id' => $list->id,
                    'listDate' => $list->listDate,
                    'storeName' => $list->storeName,
                    'totalCost' => $list->totalCost,
                    'itemsCount' => count($checkedItems)
                ]
            ]);
        } catch (Exception $e) {
            $this->rollback();
            $this->logger->error('Failed to save grocery list', [
                'error' => $e->getMessage()
            ]);
            Flight::jsonError('Error saving list', 500);
        }
    }

    /**
     * Delete a saved list (AJAX)
     */
    public function deleteList($params = []) {
        $request = Flight::request();

        if ($request->method !== 'POST') {
            Flight::jsonError('Method not allowed', 405);
            return;
        }

        if (!Flight::csrf()->validateRequest()) {
            Flight::jsonError('Invalid CSRF token', 403);
            return;
        }

        $listId = $request->data->id ?? null;

        if (!$listId) {
            Flight::jsonError('List ID is required', 400);
            return;
        }

        try {
            $list = Bean::load('grocerylist', $listId);

            if (!$list->id || $list->memberId != $this->member->id) {
                Flight::jsonError('List not found', 404);
                return;
            }

            $this->beginTransaction();

            // Delete all items in this list first
            $items = $list->ownGroceryitemList;
            foreach ($items as $item) {
                Bean::trash($item);
            }

            // Delete the list
            Bean::trash($list);

            $this->commit();

            $this->logger->info('Grocery list deleted', [
                'list_id' => $listId,
                'member_id' => $this->member->id
            ]);

            Flight::json(['success' => true]);
        } catch (Exception $e) {
            $this->rollback();
            $this->logger->error('Failed to delete grocery list', [
                'error' => $e->getMessage()
            ]);
            Flight::jsonError('Error deleting list', 500);
        }
    }

    /**
     * Add new item (AJAX)
     */
    public function add($params = []) {
        $request = Flight::request();

        if ($request->method !== 'POST') {
            Flight::jsonError('Method not allowed', 405);
            return;
        }

        if (!Flight::csrf()->validateRequest()) {
            Flight::jsonError('Invalid CSRF token', 403);
            return;
        }

        $name = trim($request->data->name ?? '');
        $quantity = intval($request->data->quantity ?? 1);

        if (empty($name)) {
            Flight::jsonError('Item name is required', 400);
            return;
        }

        if (strlen($name) > 255) {
            Flight::jsonError('Item name is too long (max 255 characters)', 400);
            return;
        }

        // Validate quantity (1-999)
        if ($quantity < 1) {
            $quantity = 1;
        }
        if ($quantity > 999) {
            $quantity = 999;
        }

        try {
            // Get max sort order for this member's active items
            $maxSort = Bean::getCell(
                'SELECT MAX(sort_order) FROM groceryitem WHERE member_id = ? AND grocerylist_id IS NULL',
                [$this->member->id]
            ) ?? 0;

            $item = Bean::dispense('groceryitem');
            $item->memberId = $this->member->id;
            $item->name = $name;
            $item->quantity = $quantity;
            $item->isChecked = 0;
            $item->sortOrder = $maxSort + 1;
            $item->createdAt = date('Y-m-d H:i:s');

            Bean::store($item);

            $this->logger->info('Grocery item added', [
                'item_id' => $item->id,
                'quantity' => $quantity,
                'member_id' => $this->member->id
            ]);

            Flight::json([
                'success' => true,
                'item' => [
                    'id' => $item->id,
                    'name' => $item->name,
                    'quantity' => (int)$item->quantity,
                    'isChecked' => (bool)$item->isChecked,
                    'sortOrder' => (int)$item->sortOrder
                ]
            ]);
        } catch (Exception $e) {
            $this->logger->error('Failed to add grocery item', [
                'error' => $e->getMessage()
            ]);
            Flight::jsonError('Error adding item', 500);
        }
    }

    /**
     * Edit item (AJAX)
     */
    public function edit($params = []) {
        $request = Flight::request();

        if ($request->method !== 'POST') {
            Flight::jsonError('Method not allowed', 405);
            return;
        }

        if (!Flight::csrf()->validateRequest()) {
            Flight::jsonError('Invalid CSRF token', 403);
            return;
        }

        $itemId = $request->data->id ?? null;
        $name = trim($request->data->name ?? '');
        $quantity = $request->data->quantity ?? null;

        if (!$itemId) {
            Flight::jsonError('Item ID is required', 400);
            return;
        }

        if (empty($name)) {
            Flight::jsonError('Item name is required', 400);
            return;
        }

        if (strlen($name) > 255) {
            Flight::jsonError('Item name is too long (max 255 characters)', 400);
            return;
        }

        try {
            $item = Bean::load('groceryitem', $itemId);

            if (!$item->id || $item->memberId != $this->member->id) {
                Flight::jsonError('Item not found', 404);
                return;
            }

            $item->name = $name;

            // Update quantity if provided
            if ($quantity !== null) {
                $qty = intval($quantity);
                if ($qty < 1) $qty = 1;
                if ($qty > 999) $qty = 999;
                $item->quantity = $qty;

                // If item was checked and now has quantity, uncheck it
                if ($item->isChecked && $qty > 0) {
                    $item->isChecked = 0;
                }
            }

            $item->updatedAt = date('Y-m-d H:i:s');
            Bean::store($item);

            Flight::json([
                'success' => true,
                'item' => [
                    'id' => $item->id,
                    'name' => $item->name,
                    'quantity' => (int)$item->quantity,
                    'isChecked' => (bool)$item->isChecked
                ]
            ]);
        } catch (Exception $e) {
            $this->logger->error('Failed to edit grocery item', [
                'error' => $e->getMessage()
            ]);
            Flight::jsonError('Error editing item', 500);
        }
    }

    /**
     * Toggle item checked status (AJAX)
     * If quantity > 1, decrement quantity instead of checking
     * When quantity reaches 0, item is checked off
     */
    public function toggle($params = []) {
        $request = Flight::request();

        if ($request->method !== 'POST') {
            Flight::jsonError('Method not allowed', 405);
            return;
        }

        if (!Flight::csrf()->validateRequest()) {
            Flight::jsonError('Invalid CSRF token', 403);
            return;
        }

        $itemId = $request->data->id ?? null;

        if (!$itemId) {
            Flight::jsonError('Item ID is required', 400);
            return;
        }

        try {
            $item = Bean::load('groceryitem', $itemId);

            if (!$item->id || $item->memberId != $this->member->id) {
                Flight::jsonError('Item not found', 404);
                return;
            }

            // Ensure quantity is at least 1 for unchecked items
            $quantity = max(1, (int)$item->quantity);

            if ($item->isChecked) {
                // Unchecking - restore to quantity 1
                $item->isChecked = 0;
                $item->quantity = 1;
            } else {
                // Checking - decrement quantity
                $quantity--;
                $item->quantity = $quantity;

                if ($quantity <= 0) {
                    // Quantity depleted, check off the item
                    $item->isChecked = 1;
                    $item->quantity = 0;
                }
            }

            $item->updatedAt = date('Y-m-d H:i:s');
            Bean::store($item);

            Flight::json([
                'success' => true,
                'isChecked' => (bool)$item->isChecked,
                'quantity' => (int)$item->quantity
            ]);
        } catch (Exception $e) {
            $this->logger->error('Failed to toggle grocery item', [
                'error' => $e->getMessage()
            ]);
            Flight::jsonError('Error toggling item', 500);
        }
    }

    /**
     * Delete item (AJAX)
     */
    public function delete($params = []) {
        $request = Flight::request();

        if ($request->method !== 'POST') {
            Flight::jsonError('Method not allowed', 405);
            return;
        }

        if (!Flight::csrf()->validateRequest()) {
            Flight::jsonError('Invalid CSRF token', 403);
            return;
        }

        $itemId = $request->data->id ?? null;

        if (!$itemId) {
            Flight::jsonError('Item ID is required', 400);
            return;
        }

        try {
            $item = Bean::load('groceryitem', $itemId);

            if (!$item->id || $item->memberId != $this->member->id) {
                Flight::jsonError('Item not found', 404);
                return;
            }

            $this->logger->info('Grocery item deleted', [
                'item_id' => $item->id,
                'item_name' => $item->name,
                'member_id' => $this->member->id
            ]);

            Bean::trash($item);

            Flight::json(['success' => true]);
        } catch (Exception $e) {
            $this->logger->error('Failed to delete grocery item', [
                'error' => $e->getMessage()
            ]);
            Flight::jsonError('Error deleting item', 500);
        }
    }

    /**
     * Reorder items (AJAX) - for drag/drop
     */
    public function reorder($params = []) {
        $request = Flight::request();

        if ($request->method !== 'POST') {
            Flight::jsonError('Method not allowed', 405);
            return;
        }

        if (!Flight::csrf()->validateRequest()) {
            Flight::jsonError('Invalid CSRF token', 403);
            return;
        }

        $order = $request->data->order ?? [];

        if (empty($order) || !is_array($order)) {
            Flight::jsonError('Order array is required', 400);
            return;
        }

        try {
            $this->beginTransaction();

            foreach ($order as $position => $itemId) {
                $item = Bean::load('groceryitem', (int)$itemId);

                if ($item->id && $item->memberId == $this->member->id) {
                    $item->sortOrder = $position;
                    Bean::store($item);
                }
            }

            $this->commit();

            Flight::json(['success' => true]);
        } catch (Exception $e) {
            $this->rollback();
            $this->logger->error('Failed to reorder grocery items', [
                'error' => $e->getMessage()
            ]);
            Flight::jsonError('Error reordering items', 500);
        }
    }

    /**
     * Clear checked items from active list (AJAX)
     */
    public function clearChecked($params = []) {
        $request = Flight::request();

        if ($request->method !== 'POST') {
            Flight::jsonError('Method not allowed', 405);
            return;
        }

        if (!Flight::csrf()->validateRequest()) {
            Flight::jsonError('Invalid CSRF token', 403);
            return;
        }

        try {
            // Only clear checked items from active list (not saved to a grocerylist)
            $checkedItems = Bean::find('groceryitem',
                'member_id = ? AND grocerylist_id IS NULL AND is_checked = 1',
                [$this->member->id]
            );

            $count = 0;
            foreach ($checkedItems as $item) {
                Bean::trash($item);
                $count++;
            }

            $this->logger->info('Cleared checked grocery items', [
                'count' => $count,
                'member_id' => $this->member->id
            ]);

            Flight::json([
                'success' => true,
                'cleared' => $count
            ]);
        } catch (Exception $e) {
            $this->logger->error('Failed to clear checked items', [
                'error' => $e->getMessage()
            ]);
            Flight::jsonError('Error clearing items', 500);
        }
    }

    /**
     * Clear all items from active list (AJAX)
     */
    public function clearAll($params = []) {
        $request = Flight::request();

        if ($request->method !== 'POST') {
            Flight::jsonError('Method not allowed', 405);
            return;
        }

        if (!Flight::csrf()->validateRequest()) {
            Flight::jsonError('Invalid CSRF token', 403);
            return;
        }

        try {
            // Only clear items from active list (not saved to a grocerylist)
            $allItems = Bean::find('groceryitem',
                'member_id = ? AND grocerylist_id IS NULL',
                [$this->member->id]
            );

            $count = 0;
            foreach ($allItems as $item) {
                Bean::trash($item);
                $count++;
            }

            $this->logger->info('Cleared all grocery items from active list', [
                'count' => $count,
                'member_id' => $this->member->id
            ]);

            Flight::json([
                'success' => true,
                'cleared' => $count
            ]);
        } catch (Exception $e) {
            $this->logger->error('Failed to clear all items', [
                'error' => $e->getMessage()
            ]);
            Flight::jsonError('Error clearing items', 500);
        }
    }
}

<?php
/**
 * Grocery List Controller
 *
 * Mobile-friendly grocery list with:
 * - CRUD operations
 * - Checkbox toggle with strike-through
 * - Drag/drop reordering
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
     * List grocery items
     */
    public function index($params = []) {
        $this->viewData['title'] = 'Grocery List';

        // Get grocery items via association with ordering
        $items = $this->member->with(' ORDER BY is_checked ASC, sort_order ASC, created_at DESC ')->ownGroceryitemList;

        $this->viewData['items'] = $items;

        $this->render('grocery/index', $this->viewData);
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

        if (empty($name)) {
            Flight::jsonError('Item name is required', 400);
            return;
        }

        if (strlen($name) > 255) {
            Flight::jsonError('Item name is too long (max 255 characters)', 400);
            return;
        }

        try {
            // Get max sort order for this member's items
            $maxSort = Bean::getCell(
                'SELECT MAX(sort_order) FROM groceryitem WHERE member_id = ?',
                [$this->member->id]
            ) ?? 0;

            $item = Bean::dispense('groceryitem');
            $item->memberId = $this->member->id;
            $item->name = $name;
            $item->isChecked = 0;
            $item->sortOrder = $maxSort + 1;
            $item->createdAt = date('Y-m-d H:i:s');

            Bean::store($item);

            $this->logger->info('Grocery item added', [
                'item_id' => $item->id,
                'member_id' => $this->member->id
            ]);

            Flight::json([
                'success' => true,
                'item' => [
                    'id' => $item->id,
                    'name' => $item->name,
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
            $item->updatedAt = date('Y-m-d H:i:s');
            Bean::store($item);

            Flight::json([
                'success' => true,
                'item' => [
                    'id' => $item->id,
                    'name' => $item->name,
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

            $item->isChecked = $item->isChecked ? 0 : 1;
            $item->updatedAt = date('Y-m-d H:i:s');
            Bean::store($item);

            Flight::json([
                'success' => true,
                'isChecked' => (bool)$item->isChecked
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
     * Clear checked items (AJAX)
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
            $checkedItems = Bean::find('groceryitem', 'member_id = ? AND is_checked = 1', [$this->member->id]);

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
     * Clear all items (AJAX)
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
            $allItems = $this->member->ownGroceryitemList;

            $count = 0;
            foreach ($allItems as $item) {
                Bean::trash($item);
                $count++;
            }

            $this->logger->info('Cleared all grocery items', [
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

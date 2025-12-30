<style>
    /* Grocery List Styles - Mobile First */
    .grocery-container {
        max-width: 600px;
        margin: 0 auto;
    }

    .grocery-header {
        background: linear-gradient(135deg, #198754 0%, #157347 100%);
        border-radius: 1rem;
        padding: 1.5rem;
        margin-bottom: 1rem;
        color: white;
    }

    .grocery-header h1 {
        font-size: 1.5rem;
        margin: 0;
    }

    .grocery-stats {
        font-size: 0.9rem;
        opacity: 0.9;
    }

    .add-item-form {
        background: var(--bs-body-bg);
        border-radius: 1rem;
        padding: 1rem;
        margin-bottom: 1rem;
        border: 1px solid var(--bs-border-color);
    }

    .add-item-form .input-group {
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        border-radius: 0.75rem;
        overflow: hidden;
    }

    .add-item-form input {
        border: none;
        padding: 1rem;
        font-size: 1rem;
    }

    .add-item-form button {
        border: none;
        padding: 1rem 1.5rem;
    }

    .quantity-input {
        width: 60px !important;
        text-align: center;
        border-left: 1px solid var(--bs-border-color) !important;
    }

    .grocery-list {
        list-style: none;
        padding: 0;
        margin: 0;
    }

    .grocery-item {
        background: var(--bs-body-bg);
        border: 1px solid var(--bs-border-color);
        border-radius: 0.75rem;
        margin-bottom: 0.5rem;
        padding: 0.75rem 1rem;
        display: flex;
        align-items: center;
        gap: 0.75rem;
        transition: all 0.2s ease;
        cursor: grab;
    }

    .grocery-item:active {
        cursor: grabbing;
    }

    .grocery-item.sortable-ghost {
        opacity: 0.4;
        background: var(--bs-primary);
    }

    .grocery-item.sortable-chosen {
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    }

    .grocery-item.checked {
        opacity: 0.6;
        background: var(--bs-secondary-bg);
    }

    .grocery-item.checked .item-name {
        text-decoration: line-through;
        color: var(--bs-secondary-color);
    }

    .item-checkbox {
        width: 24px;
        height: 24px;
        cursor: pointer;
        accent-color: #198754;
    }

    .item-name {
        flex: 1;
        font-size: 1rem;
        word-break: break-word;
    }

    .item-quantity {
        background: var(--bs-primary);
        color: white;
        border-radius: 50%;
        min-width: 28px;
        height: 28px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.85rem;
        font-weight: bold;
    }

    .item-quantity.depleted {
        background: var(--bs-secondary);
    }

    .item-name-input {
        flex: 1;
        border: none;
        background: transparent;
        font-size: 1rem;
        padding: 0;
        outline: none;
    }

    .item-actions {
        display: flex;
        gap: 0.25rem;
    }

    .item-actions .btn {
        padding: 0.25rem 0.5rem;
        font-size: 0.9rem;
    }

    .drag-handle {
        color: var(--bs-secondary-color);
        cursor: grab;
        padding: 0 0.25rem;
    }

    .drag-handle:active {
        cursor: grabbing;
    }

    .empty-state {
        text-align: center;
        padding: 3rem 1rem;
        color: var(--bs-secondary-color);
    }

    .empty-state i {
        font-size: 4rem;
        margin-bottom: 1rem;
        opacity: 0.5;
    }

    .action-buttons {
        display: flex;
        gap: 0.5rem;
        flex-wrap: wrap;
        margin-top: 1rem;
    }

    .save-list-section {
        background: var(--bs-body-bg);
        border: 2px dashed var(--bs-success);
        border-radius: 1rem;
        padding: 1rem;
        margin-top: 1rem;
    }

    .save-list-section h5 {
        color: var(--bs-success);
        margin-bottom: 1rem;
    }

    /* Touch-friendly on mobile */
    @media (max-width: 576px) {
        .grocery-container {
            padding: 0 0.5rem;
        }

        .grocery-header {
            border-radius: 0;
            margin: -1rem -0.5rem 1rem;
            padding: 1rem;
        }

        .grocery-item {
            padding: 1rem;
        }

        .item-checkbox {
            width: 28px;
            height: 28px;
        }

        .item-name {
            font-size: 1.1rem;
        }

        .quantity-input {
            width: 50px !important;
        }
    }

    /* Animation for adding items */
    @keyframes slideIn {
        from {
            opacity: 0;
            transform: translateY(-10px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .grocery-item.new-item {
        animation: slideIn 0.3s ease;
    }

    /* Animation for removing items */
    @keyframes slideOut {
        from {
            opacity: 1;
            transform: translateX(0);
        }
        to {
            opacity: 0;
            transform: translateX(100%);
        }
    }

    .grocery-item.removing {
        animation: slideOut 0.3s ease forwards;
    }
</style>

<div class="container py-4">
    <div class="grocery-container">
        <!-- Header -->
        <div class="grocery-header">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1><i class="bi bi-cart3"></i> Grocery List</h1>
                    <div class="grocery-stats">
                        <span id="itemCount"><?= count($items) ?></span> items
                        <span class="mx-1">|</span>
                        <span id="checkedCount"><?= count(array_filter($items, fn($i) => $i->isChecked)) ?></span> checked
                    </div>
                </div>
                <div class="d-flex gap-2">
                    <?php if ($savedListsCount > 0): ?>
                    <a href="/grocery/history" class="btn btn-outline-light btn-sm" title="View History">
                        <i class="bi bi-clock-history"></i>
                        <span class="badge bg-light text-dark"><?= $savedListsCount ?></span>
                    </a>
                    <?php endif; ?>
                    <a href="/dashboard" class="btn btn-outline-light btn-sm">
                        <i class="bi bi-arrow-left"></i>
                    </a>
                </div>
            </div>
        </div>

        <!-- Load Saved List Dropdown -->
        <div class="mb-2" id="savedListsDropdownContainer" style="display: none;">
            <div class="input-group input-group-sm">
                <span class="input-group-text"><i class="bi bi-clock-history"></i></span>
                <select class="form-select" id="savedListsDropdown" onchange="loadSavedList(this.value)">
                    <option value="">Load a saved list...</option>
                </select>
                <button type="button" class="btn btn-outline-danger" onclick="clearSavedLists()" title="Clear all saved lists">
                    <i class="bi bi-trash"></i>
                </button>
            </div>
        </div>

        <!-- Add Item Form -->
        <div class="add-item-form">
            <form id="addItemForm" onsubmit="return addItem(event)">
                <div class="input-group">
                    <input type="text"
                           id="newItemName"
                           class="form-control"
                           placeholder="Add an item..."
                           autocomplete="off"
                           maxlength="255"
                           required>
                    <input type="number"
                           id="newItemQty"
                           class="form-control quantity-input"
                           value="1"
                           min="1"
                           max="999"
                           title="Quantity">
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-plus-lg"></i>
                    </button>
                </div>
            </form>
        </div>

        <!-- Grocery List -->
        <div id="groceryListContainer">
            <?php if (empty($items)): ?>
                <div class="empty-state" id="emptyState">
                    <i class="bi bi-basket"></i>
                    <p>Your grocery list is empty</p>
                    <p class="text-muted">Add items above to get started</p>
                </div>
            <?php else: ?>
                <ul class="grocery-list" id="groceryList">
                    <?php foreach ($items as $item): ?>
                        <li class="grocery-item <?= $item->isChecked ? 'checked' : '' ?>"
                            data-id="<?= $item->id ?>"
                            data-quantity="<?= (int)($item->quantity ?? 1) ?>">
                            <span class="drag-handle">
                                <i class="bi bi-grip-vertical"></i>
                            </span>
                            <input type="checkbox"
                                   class="item-checkbox"
                                   <?= $item->isChecked ? 'checked' : '' ?>
                                   onchange="toggleItem(<?= $item->id ?>)">
                            <span class="item-name"><?= htmlspecialchars($item->name) ?></span>
                            <?php $qty = (int)($item->quantity ?? 1); ?>
                            <span class="item-quantity <?= $qty <= 0 ? 'depleted' : '' ?>"
                                  title="<?= $qty > 0 ? "Click {$qty} more time(s) to check off" : 'Checked off' ?>">
                                <?= $qty ?>
                            </span>
                            <div class="item-actions">
                                <button type="button"
                                        class="btn btn-sm btn-outline-secondary"
                                        onclick="editItem(<?= $item->id ?>, this)"
                                        title="Edit">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <button type="button"
                                        class="btn btn-sm btn-outline-danger"
                                        onclick="deleteItem(<?= $item->id ?>)"
                                        title="Delete">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <!-- Action Buttons -->
        <div class="action-buttons">
            <button type="button"
                    class="btn btn-outline-warning btn-sm"
                    onclick="clearChecked()"
                    id="clearCheckedBtn"
                    <?= count(array_filter($items, fn($i) => $i->isChecked)) == 0 ? 'disabled' : '' ?>>
                <i class="bi bi-check2-square"></i> Clear Checked
            </button>
            <button type="button"
                    class="btn btn-outline-danger btn-sm"
                    onclick="clearAll()"
                    id="clearAllBtn"
                    <?= empty($items) ? 'disabled' : '' ?>>
                <i class="bi bi-trash"></i> Clear All
            </button>
            <button type="button"
                    class="btn btn-success btn-sm"
                    onclick="showSaveModal()"
                    id="saveListBtn"
                    <?= count(array_filter($items, fn($i) => $i->isChecked)) == 0 ? 'disabled' : '' ?>>
                <i class="bi bi-save"></i> Save Checked Items
            </button>
        </div>

        <!-- History Link -->
        <?php if ($savedListsCount > 0): ?>
        <div class="text-center mt-3">
            <a href="/grocery/history" class="text-muted">
                <i class="bi bi-clock-history"></i> View <?= $savedListsCount ?> saved list<?= $savedListsCount > 1 ? 's' : '' ?>
            </a>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Save List Modal -->
<div class="modal fade" id="saveListModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="bi bi-save"></i> Save Shopping Trip</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="saveListForm">
                    <div class="mb-3">
                        <label class="form-label">Date</label>
                        <input type="date" class="form-control" id="saveListDate" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Store Name (optional)</label>
                        <input type="text" class="form-control" id="saveStoreName" placeholder="e.g., Walmart, Kroger..." maxlength="255">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Receipt Total (optional)</label>
                        <div class="input-group">
                            <span class="input-group-text">$</span>
                            <input type="number" class="form-control" id="saveTotalCost" step="0.01" min="0" placeholder="0.00">
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success" onclick="saveList()">
                    <i class="bi bi-check-lg"></i> Save List
                </button>
            </div>
        </div>
    </div>
</div>

<!-- SortableJS for drag/drop -->
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>

<script>
// CSRF token for AJAX requests
const csrfToken = <?= json_encode($csrf) ?>;

// Initialize Sortable for drag/drop
let sortable = null;
let saveListModal = null;

// LocalStorage keys
const LS_ACTIVE_LIST = 'grocery_active_list';
const LS_SAVED_LISTS = 'grocery_saved_lists';

function initSortable() {
    const list = document.getElementById('groceryList');
    if (!list) return;

    sortable = Sortable.create(list, {
        handle: '.drag-handle',
        animation: 150,
        ghostClass: 'sortable-ghost',
        chosenClass: 'sortable-chosen',
        onEnd: function(evt) {
            saveOrder();
            saveActiveListToLocalStorage();
        }
    });
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    initSortable();
    document.getElementById('newItemName').focus();
    saveListModal = new bootstrap.Modal(document.getElementById('saveListModal'));

    // Initialize localStorage UI (completed lists dropdown)
    initLocalStorageUI();

    // Check if we should restore from localStorage
    restoreActiveListFromLocalStorage();
});

// ============================================
// LocalStorage Functions
// ============================================

// Get current items from DOM as array
function getActiveItemsFromDOM() {
    const items = [];
    document.querySelectorAll('.grocery-item').forEach(li => {
        items.push({
            id: li.dataset.id,
            name: li.querySelector('.item-name')?.textContent || '',
            quantity: parseInt(li.dataset.quantity) || 1,
            isChecked: li.classList.contains('checked')
        });
    });
    return items;
}

// Save active list to localStorage
function saveActiveListToLocalStorage() {
    try {
        const items = getActiveItemsFromDOM();
        localStorage.setItem(LS_ACTIVE_LIST, JSON.stringify(items));
    } catch (e) {
        console.warn('Failed to save to localStorage:', e);
    }
}

// Get active list from localStorage
function getActiveListFromLocalStorage() {
    try {
        const data = localStorage.getItem(LS_ACTIVE_LIST);
        return data ? JSON.parse(data) : [];
    } catch (e) {
        console.warn('Failed to read active list from localStorage:', e);
        return [];
    }
}

// Restore active list from localStorage on page load
function restoreActiveListFromLocalStorage() {
    const localItems = getActiveListFromLocalStorage();
    const domItems = getActiveItemsFromDOM();

    // If localStorage has items and DOM is empty, restore from localStorage
    if (localItems.length > 0 && domItems.length === 0) {
        console.log('Restoring', localItems.length, 'items from localStorage');
        restoreItemsToList(localItems);
        return;
    }

    // If both have items, check if localStorage has items not in DOM (by name)
    if (localItems.length > 0 && domItems.length > 0) {
        const domNames = new Set(domItems.map(i => i.name.toLowerCase()));
        const missingItems = localItems.filter(i => !domNames.has(i.name.toLowerCase()));

        if (missingItems.length > 0) {
            console.log('Found', missingItems.length, 'items in localStorage not in server list');
            // Restore missing items
            restoreItemsToList(missingItems);
        }
    }

    // If DOM has items but localStorage is empty, save DOM to localStorage
    if (domItems.length > 0 && localItems.length === 0) {
        saveActiveListToLocalStorage();
    }
}

// Restore items to the list (add via server to get proper IDs)
async function restoreItemsToList(items) {
    for (const item of items) {
        await addItemFromLocalStorage(item.name, item.quantity || 1);
    }
}

// Get saved lists from localStorage
function getSavedLists() {
    try {
        const data = localStorage.getItem(LS_SAVED_LISTS);
        return data ? JSON.parse(data) : {};
    } catch (e) {
        console.warn('Failed to read saved lists:', e);
        return {};
    }
}

// Save completed list to localStorage by date
function saveCompletedListToLocalStorage(listDate, storeName, totalCost, items) {
    try {
        const savedLists = getSavedLists();
        const key = listDate; // Use date as key

        savedLists[key] = {
            date: listDate,
            storeName: storeName,
            totalCost: parseFloat(totalCost) || 0,
            items: items,
            savedAt: new Date().toISOString()
        };

        localStorage.setItem(LS_SAVED_LISTS, JSON.stringify(savedLists));
        updateSavedListsDropdown();
    } catch (e) {
        console.warn('Failed to save completed list:', e);
    }
}

// Initialize localStorage UI (dropdown)
function initLocalStorageUI() {
    updateSavedListsDropdown();
}

// Update the saved lists dropdown
function updateSavedListsDropdown() {
    const savedLists = getSavedLists();
    const container = document.getElementById('savedListsDropdownContainer');
    const dropdown = document.getElementById('savedListsDropdown');

    // Get keys sorted by date descending
    const keys = Object.keys(savedLists).sort((a, b) => b.localeCompare(a));

    if (keys.length === 0) {
        container.style.display = 'none';
        return;
    }

    container.style.display = 'block';
    dropdown.innerHTML = '<option value="">Load a saved list...</option>';

    keys.forEach(key => {
        const list = savedLists[key];
        const date = new Date(list.date + 'T12:00:00');
        const dateStr = date.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' });
        const store = list.storeName ? ` @ ${list.storeName}` : '';
        const cost = list.totalCost > 0 ? ` ($${list.totalCost.toFixed(2)})` : '';
        const itemCount = list.items ? list.items.length : 0;

        const option = document.createElement('option');
        option.value = key;
        option.textContent = `${dateStr}${store} - ${itemCount} items${cost}`;
        dropdown.appendChild(option);
    });
}

// Load a saved list from localStorage
function loadSavedList(key) {
    if (!key) return;

    const savedLists = getSavedLists();
    const list = savedLists[key];

    if (!list || !list.items || list.items.length === 0) {
        showToast('error', 'No items in this saved list');
        document.getElementById('savedListsDropdown').value = '';
        return;
    }

    if (!confirm(`Load ${list.items.length} items from ${key}? This will add them to your current list.`)) {
        document.getElementById('savedListsDropdown').value = '';
        return;
    }

    // Add each item from the saved list
    list.items.forEach(item => {
        addItemFromLocalStorage(item.name, item.quantity);
    });

    document.getElementById('savedListsDropdown').value = '';
    showToast('success', `Loaded ${list.items.length} items from saved list`);
}

// Add item from localStorage (without server call, just to DOM and then sync)
async function addItemFromLocalStorage(name, quantity) {
    try {
        const formData = new FormData();
        formData.append('name', name);
        formData.append('quantity', quantity || 1);
        Object.entries(csrfToken).forEach(([key, value]) => {
            formData.append(key, value);
        });

        const response = await fetch('/grocery/add', {
            method: 'POST',
            body: formData
        });

        const data = await response.json();

        if (data.success) {
            // Remove empty state if present
            const emptyState = document.getElementById('emptyState');
            if (emptyState) {
                document.getElementById('groceryListContainer').innerHTML = '<ul class="grocery-list" id="groceryList"></ul>';
                initSortable();
            }

            // Add new item to list
            const list = document.getElementById('groceryList');
            const li = document.createElement('li');
            li.className = 'grocery-item new-item';
            li.dataset.id = data.item.id;
            li.dataset.quantity = data.item.quantity;
            li.innerHTML = `
                <span class="drag-handle">
                    <i class="bi bi-grip-vertical"></i>
                </span>
                <input type="checkbox"
                       class="item-checkbox"
                       onchange="toggleItem(${data.item.id})">
                <span class="item-name">${escapeHtml(data.item.name)}</span>
                <span class="item-quantity" title="Click ${data.item.quantity} more time(s) to check off">
                    ${data.item.quantity}
                </span>
                <div class="item-actions">
                    <button type="button"
                            class="btn btn-sm btn-outline-secondary"
                            onclick="editItem(${data.item.id}, this)"
                            title="Edit">
                        <i class="bi bi-pencil"></i>
                    </button>
                    <button type="button"
                            class="btn btn-sm btn-outline-danger"
                            onclick="deleteItem(${data.item.id})"
                            title="Delete">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
            `;

            // Add at top of unchecked items
            const firstChecked = list.querySelector('.grocery-item.checked');
            if (firstChecked) {
                list.insertBefore(li, firstChecked);
            } else {
                list.appendChild(li);
            }

            updateStats();
            saveActiveListToLocalStorage();
        }
    } catch (error) {
        console.error('Error adding item from localStorage:', error);
    }
}

// Clear all saved lists from localStorage
function clearSavedLists() {
    if (!confirm('Delete ALL saved lists from this device? This cannot be undone.')) return;

    try {
        localStorage.removeItem(LS_SAVED_LISTS);
        updateSavedListsDropdown();
        showToast('success', 'All saved lists cleared');
    } catch (e) {
        console.warn('Failed to clear saved lists:', e);
    }
}

// Update stats display
function updateStats() {
    const items = document.querySelectorAll('.grocery-item');
    const checked = document.querySelectorAll('.grocery-item.checked');

    document.getElementById('itemCount').textContent = items.length;
    document.getElementById('checkedCount').textContent = checked.length;

    // Update button states
    document.getElementById('clearCheckedBtn').disabled = checked.length === 0;
    document.getElementById('clearAllBtn').disabled = items.length === 0;
    document.getElementById('saveListBtn').disabled = checked.length === 0;

    // Save to localStorage whenever stats update
    saveActiveListToLocalStorage();
}

// Show/hide empty state
function checkEmptyState() {
    const list = document.getElementById('groceryList');
    const container = document.getElementById('groceryListContainer');

    if (!list || list.children.length === 0) {
        container.innerHTML = `
            <div class="empty-state" id="emptyState">
                <i class="bi bi-basket"></i>
                <p>Your grocery list is empty</p>
                <p class="text-muted">Add items above to get started</p>
            </div>
        `;
        sortable = null;
        // Clear localStorage active list when list is actually empty
        saveActiveListToLocalStorage();
    }
}

// Add new item
async function addItem(event) {
    event.preventDefault();

    const input = document.getElementById('newItemName');
    const qtyInput = document.getElementById('newItemQty');
    const name = input.value.trim();
    const quantity = parseInt(qtyInput.value) || 1;

    if (!name) return false;

    try {
        const formData = new FormData();
        formData.append('name', name);
        formData.append('quantity', quantity);
        Object.entries(csrfToken).forEach(([key, value]) => {
            formData.append(key, value);
        });

        const response = await fetch('/grocery/add', {
            method: 'POST',
            body: formData
        });

        const data = await response.json();

        if (data.success) {
            // Remove empty state if present
            const emptyState = document.getElementById('emptyState');
            if (emptyState) {
                document.getElementById('groceryListContainer').innerHTML = '<ul class="grocery-list" id="groceryList"></ul>';
                initSortable();
            }

            // Add new item to list
            const list = document.getElementById('groceryList');
            const li = document.createElement('li');
            li.className = 'grocery-item new-item';
            li.dataset.id = data.item.id;
            li.dataset.quantity = data.item.quantity;
            li.innerHTML = `
                <span class="drag-handle">
                    <i class="bi bi-grip-vertical"></i>
                </span>
                <input type="checkbox"
                       class="item-checkbox"
                       onchange="toggleItem(${data.item.id})">
                <span class="item-name">${escapeHtml(data.item.name)}</span>
                <span class="item-quantity" title="Click ${data.item.quantity} more time(s) to check off">
                    ${data.item.quantity}
                </span>
                <div class="item-actions">
                    <button type="button"
                            class="btn btn-sm btn-outline-secondary"
                            onclick="editItem(${data.item.id}, this)"
                            title="Edit">
                        <i class="bi bi-pencil"></i>
                    </button>
                    <button type="button"
                            class="btn btn-sm btn-outline-danger"
                            onclick="deleteItem(${data.item.id})"
                            title="Delete">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
            `;

            // Add at top of unchecked items
            const firstChecked = list.querySelector('.grocery-item.checked');
            if (firstChecked) {
                list.insertBefore(li, firstChecked);
            } else {
                list.appendChild(li);
            }

            input.value = '';
            qtyInput.value = 1;
            input.focus();
            updateStats();
        } else {
            showToast('error', data.message || 'Failed to add item');
        }
    } catch (error) {
        console.error('Error adding item:', error);
        showToast('error', 'Failed to add item');
    }

    return false;
}

// Toggle item checked status (with quantity decrement)
async function toggleItem(itemId) {
    const li = document.querySelector(`.grocery-item[data-id="${itemId}"]`);
    const checkbox = li.querySelector('.item-checkbox');
    const qtyBadge = li.querySelector('.item-quantity');

    try {
        const formData = new FormData();
        formData.append('id', itemId);
        Object.entries(csrfToken).forEach(([key, value]) => {
            formData.append(key, value);
        });

        const response = await fetch('/grocery/toggle', {
            method: 'POST',
            body: formData
        });

        const data = await response.json();

        if (data.success) {
            // Update quantity badge
            li.dataset.quantity = data.quantity;
            qtyBadge.textContent = data.quantity;

            if (data.isChecked) {
                li.classList.add('checked');
                checkbox.checked = true;
                qtyBadge.classList.add('depleted');
                qtyBadge.title = 'Checked off';
                // Move to bottom of list
                const list = document.getElementById('groceryList');
                list.appendChild(li);
            } else {
                li.classList.remove('checked');
                checkbox.checked = false;
                qtyBadge.classList.remove('depleted');
                qtyBadge.title = `Click ${data.quantity} more time(s) to check off`;
                // Move to top of unchecked items
                const list = document.getElementById('groceryList');
                const firstChecked = list.querySelector('.grocery-item.checked');
                if (firstChecked) {
                    list.insertBefore(li, firstChecked);
                } else {
                    list.insertBefore(li, list.firstChild);
                }
            }
            updateStats();
        } else {
            // Revert checkbox
            checkbox.checked = !checkbox.checked;
            showToast('error', data.message || 'Failed to update item');
        }
    } catch (error) {
        console.error('Error toggling item:', error);
        checkbox.checked = !checkbox.checked;
        showToast('error', 'Failed to update item');
    }
}

// Edit item name and quantity
function editItem(itemId, button) {
    const li = document.querySelector(`.grocery-item[data-id="${itemId}"]`);
    const nameSpan = li.querySelector('.item-name');
    const qtyBadge = li.querySelector('.item-quantity');
    const currentName = nameSpan.textContent;
    const currentQty = parseInt(li.dataset.quantity) || 1;

    // Replace span with inputs
    const nameInput = document.createElement('input');
    nameInput.type = 'text';
    nameInput.className = 'item-name-input form-control form-control-sm';
    nameInput.value = currentName;
    nameInput.maxLength = 255;

    const qtyInput = document.createElement('input');
    qtyInput.type = 'number';
    qtyInput.className = 'form-control form-control-sm';
    qtyInput.style.width = '60px';
    qtyInput.value = currentQty;
    qtyInput.min = 1;
    qtyInput.max = 999;

    nameSpan.replaceWith(nameInput);
    qtyBadge.replaceWith(qtyInput);
    nameInput.focus();
    nameInput.select();

    // Change edit button to save button
    const originalHtml = button.innerHTML;
    button.innerHTML = '<i class="bi bi-check"></i>';
    button.className = 'btn btn-sm btn-success';

    // Save function
    async function saveEdit() {
        const newName = nameInput.value.trim();
        const newQty = parseInt(qtyInput.value) || 1;

        if (!newName) {
            cancelEdit();
            return;
        }

        try {
            const formData = new FormData();
            formData.append('id', itemId);
            formData.append('name', newName);
            formData.append('quantity', newQty);
            Object.entries(csrfToken).forEach(([key, value]) => {
                formData.append(key, value);
            });

            const response = await fetch('/grocery/edit', {
                method: 'POST',
                body: formData
            });

            const data = await response.json();

            if (data.success) {
                const newSpan = document.createElement('span');
                newSpan.className = 'item-name';
                newSpan.textContent = data.item.name;

                const newBadge = document.createElement('span');
                newBadge.className = 'item-quantity' + (data.item.quantity <= 0 ? ' depleted' : '');
                newBadge.textContent = data.item.quantity;
                newBadge.title = data.item.quantity > 0 ? `Click ${data.item.quantity} more time(s) to check off` : 'Checked off';

                nameInput.replaceWith(newSpan);
                qtyInput.replaceWith(newBadge);
                button.innerHTML = originalHtml;
                button.className = 'btn btn-sm btn-outline-secondary';

                li.dataset.quantity = data.item.quantity;

                // Update checked state if changed
                if (data.item.isChecked) {
                    li.classList.add('checked');
                    li.querySelector('.item-checkbox').checked = true;
                } else {
                    li.classList.remove('checked');
                    li.querySelector('.item-checkbox').checked = false;
                }

                updateStats();
            } else {
                showToast('error', data.message || 'Failed to update item');
                cancelEdit();
            }
        } catch (error) {
            console.error('Error editing item:', error);
            showToast('error', 'Failed to update item');
            cancelEdit();
        }
    }

    // Cancel function
    function cancelEdit() {
        const newSpan = document.createElement('span');
        newSpan.className = 'item-name';
        newSpan.textContent = currentName;

        const newBadge = document.createElement('span');
        newBadge.className = 'item-quantity' + (currentQty <= 0 ? ' depleted' : '');
        newBadge.textContent = currentQty;
        newBadge.title = currentQty > 0 ? `Click ${currentQty} more time(s) to check off` : 'Checked off';

        nameInput.replaceWith(newSpan);
        qtyInput.replaceWith(newBadge);
        button.innerHTML = originalHtml;
        button.className = 'btn btn-sm btn-outline-secondary';
    }

    // Handle save button click
    button.onclick = function(e) {
        e.preventDefault();
        saveEdit();
    };

    // Handle enter key and escape
    nameInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            saveEdit();
        } else if (e.key === 'Escape') {
            cancelEdit();
        }
    });

    qtyInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            saveEdit();
        } else if (e.key === 'Escape') {
            cancelEdit();
        }
    });

    // Handle blur
    let blurTimeout;
    function handleBlur() {
        blurTimeout = setTimeout(() => {
            if (document.activeElement !== button &&
                document.activeElement !== nameInput &&
                document.activeElement !== qtyInput) {
                cancelEdit();
            }
        }, 100);
    }

    nameInput.addEventListener('blur', handleBlur);
    qtyInput.addEventListener('blur', handleBlur);
    nameInput.addEventListener('focus', () => clearTimeout(blurTimeout));
    qtyInput.addEventListener('focus', () => clearTimeout(blurTimeout));
}

// Delete item
async function deleteItem(itemId) {
    const li = document.querySelector(`.grocery-item[data-id="${itemId}"]`);

    try {
        const formData = new FormData();
        formData.append('id', itemId);
        Object.entries(csrfToken).forEach(([key, value]) => {
            formData.append(key, value);
        });

        // Animate removal
        li.classList.add('removing');

        const response = await fetch('/grocery/delete', {
            method: 'POST',
            body: formData
        });

        const data = await response.json();

        if (data.success) {
            setTimeout(() => {
                li.remove();
                updateStats();
                checkEmptyState();
            }, 300);
        } else {
            li.classList.remove('removing');
            showToast('error', data.message || 'Failed to delete item');
        }
    } catch (error) {
        console.error('Error deleting item:', error);
        li.classList.remove('removing');
        showToast('error', 'Failed to delete item');
    }
}

// Save order after drag/drop
async function saveOrder() {
    const list = document.getElementById('groceryList');
    if (!list) return;

    const items = list.querySelectorAll('.grocery-item');
    const order = Array.from(items).map(li => li.dataset.id);

    try {
        const formData = new FormData();
        order.forEach((id, index) => {
            formData.append('order[]', id);
        });
        Object.entries(csrfToken).forEach(([key, value]) => {
            formData.append(key, value);
        });

        await fetch('/grocery/reorder', {
            method: 'POST',
            body: formData
        });
    } catch (error) {
        console.error('Error saving order:', error);
    }
}

// Clear checked items
async function clearChecked() {
    if (!confirm('Remove all checked items?')) return;

    try {
        const formData = new FormData();
        Object.entries(csrfToken).forEach(([key, value]) => {
            formData.append(key, value);
        });

        const response = await fetch('/grocery/clearChecked', {
            method: 'POST',
            body: formData
        });

        const data = await response.json();

        if (data.success) {
            document.querySelectorAll('.grocery-item.checked').forEach(li => {
                li.classList.add('removing');
                setTimeout(() => li.remove(), 300);
            });

            setTimeout(() => {
                updateStats();
                checkEmptyState();
            }, 350);

            if (data.cleared > 0) {
                showToast('success', `Removed ${data.cleared} item${data.cleared > 1 ? 's' : ''}`);
            }
        } else {
            showToast('error', data.message || 'Failed to clear items');
        }
    } catch (error) {
        console.error('Error clearing checked items:', error);
        showToast('error', 'Failed to clear items');
    }
}

// Clear all items
async function clearAll() {
    if (!confirm('Remove ALL items from your grocery list?')) return;

    try {
        const formData = new FormData();
        Object.entries(csrfToken).forEach(([key, value]) => {
            formData.append(key, value);
        });

        const response = await fetch('/grocery/clearAll', {
            method: 'POST',
            body: formData
        });

        const data = await response.json();

        if (data.success) {
            document.querySelectorAll('.grocery-item').forEach(li => {
                li.classList.add('removing');
            });

            setTimeout(() => {
                checkEmptyState();
                updateStats();
            }, 350);

            if (data.cleared > 0) {
                showToast('success', `Removed ${data.cleared} item${data.cleared > 1 ? 's' : ''}`);
            }
        } else {
            showToast('error', data.message || 'Failed to clear items');
        }
    } catch (error) {
        console.error('Error clearing all items:', error);
        showToast('error', 'Failed to clear items');
    }
}

// Show save list modal
function showSaveModal() {
    document.getElementById('saveListDate').value = new Date().toISOString().split('T')[0];
    document.getElementById('saveStoreName').value = '';
    document.getElementById('saveTotalCost').value = '';
    saveListModal.show();
}

// Save list
async function saveList() {
    const listDate = document.getElementById('saveListDate').value;
    const storeName = document.getElementById('saveStoreName').value.trim();
    const totalCost = document.getElementById('saveTotalCost').value;

    // Get checked items before saving (for localStorage)
    const checkedItems = [];
    document.querySelectorAll('.grocery-item.checked').forEach(li => {
        checkedItems.push({
            name: li.querySelector('.item-name')?.textContent || '',
            quantity: parseInt(li.dataset.quantity) || 1
        });
    });

    try {
        const formData = new FormData();
        formData.append('listDate', listDate);
        formData.append('storeName', storeName);
        formData.append('totalCost', totalCost || 0);
        Object.entries(csrfToken).forEach(([key, value]) => {
            formData.append(key, value);
        });

        const response = await fetch('/grocery/saveList', {
            method: 'POST',
            body: formData
        });

        const data = await response.json();

        if (data.success) {
            saveListModal.hide();

            // Save to localStorage for quick reload later
            saveCompletedListToLocalStorage(listDate, storeName, totalCost, checkedItems);

            // Remove checked items from view
            document.querySelectorAll('.grocery-item.checked').forEach(li => {
                li.classList.add('removing');
                setTimeout(() => li.remove(), 300);
            });

            setTimeout(() => {
                updateStats();
                checkEmptyState();
            }, 350);

            showToast('success', `Saved ${data.list.itemsCount} item${data.list.itemsCount > 1 ? 's' : ''} to your history`);
        } else {
            showToast('error', data.message || 'Failed to save list');
        }
    } catch (error) {
        console.error('Error saving list:', error);
        showToast('error', 'Failed to save list');
    }
}

// Escape HTML helper
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
</script>

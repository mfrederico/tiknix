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
                <a href="/dashboard" class="btn btn-outline-light btn-sm">
                    <i class="bi bi-arrow-left"></i>
                </a>
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
                            data-id="<?= $item->id ?>">
                            <span class="drag-handle">
                                <i class="bi bi-grip-vertical"></i>
                            </span>
                            <input type="checkbox"
                                   class="item-checkbox"
                                   <?= $item->isChecked ? 'checked' : '' ?>
                                   onchange="toggleItem(<?= $item->id ?>)">
                            <span class="item-name"><?= htmlspecialchars($item->name) ?></span>
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
        }
    });
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    initSortable();
    document.getElementById('newItemName').focus();
});

// Update stats display
function updateStats() {
    const items = document.querySelectorAll('.grocery-item');
    const checked = document.querySelectorAll('.grocery-item.checked');

    document.getElementById('itemCount').textContent = items.length;
    document.getElementById('checkedCount').textContent = checked.length;

    // Update button states
    document.getElementById('clearCheckedBtn').disabled = checked.length === 0;
    document.getElementById('clearAllBtn').disabled = items.length === 0;
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
    }
}

// Add new item
async function addItem(event) {
    event.preventDefault();

    const input = document.getElementById('newItemName');
    const name = input.value.trim();

    if (!name) return false;

    try {
        const formData = new FormData();
        formData.append('name', name);
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
            li.innerHTML = `
                <span class="drag-handle">
                    <i class="bi bi-grip-vertical"></i>
                </span>
                <input type="checkbox"
                       class="item-checkbox"
                       onchange="toggleItem(${data.item.id})">
                <span class="item-name">${escapeHtml(data.item.name)}</span>
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

// Toggle item checked status
async function toggleItem(itemId) {
    const li = document.querySelector(`.grocery-item[data-id="${itemId}"]`);
    const checkbox = li.querySelector('.item-checkbox');

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
            if (data.isChecked) {
                li.classList.add('checked');
                checkbox.checked = true;
                // Move to bottom of list
                const list = document.getElementById('groceryList');
                list.appendChild(li);
            } else {
                li.classList.remove('checked');
                checkbox.checked = false;
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

// Edit item name
function editItem(itemId, button) {
    const li = document.querySelector(`.grocery-item[data-id="${itemId}"]`);
    const nameSpan = li.querySelector('.item-name');
    const currentName = nameSpan.textContent;

    // Replace span with input
    const input = document.createElement('input');
    input.type = 'text';
    input.className = 'item-name-input form-control form-control-sm';
    input.value = currentName;
    input.maxLength = 255;

    nameSpan.replaceWith(input);
    input.focus();
    input.select();

    // Change edit button to save button
    const originalHtml = button.innerHTML;
    button.innerHTML = '<i class="bi bi-check"></i>';
    button.className = 'btn btn-sm btn-success';

    // Save function
    async function saveEdit() {
        const newName = input.value.trim();

        if (!newName) {
            cancelEdit();
            return;
        }

        if (newName === currentName) {
            cancelEdit();
            return;
        }

        try {
            const formData = new FormData();
            formData.append('id', itemId);
            formData.append('name', newName);
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
                input.replaceWith(newSpan);
                button.innerHTML = originalHtml;
                button.className = 'btn btn-sm btn-outline-secondary';
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
        input.replaceWith(newSpan);
        button.innerHTML = originalHtml;
        button.className = 'btn btn-sm btn-outline-secondary';
    }

    // Handle save button click
    button.onclick = function(e) {
        e.preventDefault();
        saveEdit();
    };

    // Handle enter key and escape
    input.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            saveEdit();
        } else if (e.key === 'Escape') {
            cancelEdit();
        }
    });

    // Handle blur
    input.addEventListener('blur', function() {
        // Small delay to allow button click to register
        setTimeout(() => {
            if (document.activeElement !== button) {
                cancelEdit();
            }
        }, 100);
    });
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

// Escape HTML helper
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
</script>

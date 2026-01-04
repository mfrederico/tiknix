<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>Grocery List</title>

    <!-- PWA Meta Tags -->
    <link rel="manifest" href="/grocery/manifest">
    <meta name="theme-color" content="#198754">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Grocery">

    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">

    <!-- Custom Tiknix CSS -->
    <link href="/css/app.css" rel="stylesheet">

    <style>
        /* PWA Standalone Styles - Uses CSS variables from app.css for theme support */
        .grocery-app {
            max-width: 600px;
            margin: 0 auto;
            padding: 1rem;
            min-height: 100vh;
        }

        .grocery-header {
            background: linear-gradient(135deg, var(--success-color, #198754) 0%, #157347 100%);
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

        .offline-badge {
            background: rgba(255,255,255,0.2);
            padding: 0.25rem 0.5rem;
            border-radius: 0.5rem;
            font-size: 0.75rem;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
        }

        .offline-badge.online { background: rgba(25, 135, 84, 0.3); }
        .offline-badge.offline { background: rgba(220, 53, 69, 0.3); }

        /* Tabs */
        .grocery-tabs {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }

        .grocery-tabs .tab-btn {
            flex: 1;
            padding: 0.75rem;
            border: 2px solid var(--sidebar-border, #dee2e6);
            background: var(--card-bg, white);
            color: var(--bs-body-color);
            border-radius: 0.75rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }

        .grocery-tabs .tab-btn.active {
            border-color: var(--success-color, #198754);
            background: rgba(25, 135, 84, 0.1);
            color: var(--success-color, #198754);
        }

        .grocery-tabs .tab-btn .badge {
            margin-left: 0.25rem;
        }

        .tab-content { display: none; }
        .tab-content.active { display: block; }

        .add-item-form {
            background: var(--card-bg, white);
            border-radius: 1rem;
            padding: 1rem;
            margin-bottom: 1rem;
            border: 1px solid var(--sidebar-border, #dee2e6);
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
            border-left: 1px solid var(--sidebar-border, #dee2e6) !important;
        }

        .grocery-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .grocery-item {
            background: var(--card-bg, white);
            border: 1px solid var(--sidebar-border, #dee2e6);
            border-radius: 0.75rem;
            margin-bottom: 0.5rem;
            padding: 0.75rem 1rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            transition: all 0.2s ease;
            cursor: grab;
        }

        .grocery-item:active { cursor: grabbing; }
        .grocery-item.sortable-ghost { opacity: 0.4; background: var(--success-color, #198754); }
        .grocery-item.sortable-chosen { box-shadow: 0 4px 12px rgba(0,0,0,0.15); }

        .grocery-item.checked {
            opacity: 0.6;
            background: var(--sidebar-bg, #f8f9fa);
        }

        .grocery-item.checked .item-name {
            text-decoration: line-through;
            color: var(--muted-color, #6c757d);
        }

        .item-checkbox {
            width: 24px;
            height: 24px;
            cursor: pointer;
            accent-color: var(--success-color, #198754);
        }

        .item-name {
            flex: 1;
            font-size: 1rem;
            word-break: break-word;
            cursor: pointer;
        }

        .item-name:hover {
            color: var(--accent-color, #0d6efd);
        }

        .item-quantity {
            background: var(--accent-color, #0d6efd);
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

        .item-quantity.depleted { background: var(--muted-color, #6c757d); }

        .item-name-input {
            flex: 1;
            border: none;
            background: transparent;
            font-size: 1rem;
            padding: 0;
            outline: none;
            color: var(--bs-body-color);
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
            color: var(--muted-color, #6c757d);
            cursor: grab;
            padding: 0 0.25rem;
        }

        .drag-handle:active { cursor: grabbing; }

        .grocery-empty-state {
            text-align: center;
            padding: 3rem 1rem;
            color: var(--muted-color, #6c757d);
        }

        .grocery-empty-state i {
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

        /* History styles */
        .history-card {
            background: var(--card-bg, white);
            border: 1px solid var(--sidebar-border, #dee2e6);
            border-radius: 0.75rem;
            margin-bottom: 0.75rem;
            padding: 1rem;
            cursor: pointer;
            transition: all 0.2s;
        }

        .history-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
        .history-card.expanded { border-color: var(--success-color, #198754); }

        .history-card .date { font-size: 1.1rem; font-weight: 600; }
        .history-card .store { color: var(--muted-color, #6c757d); font-size: 0.9rem; }
        .history-card .meta {
            display: flex;
            gap: 1rem;
            margin-top: 0.5rem;
            font-size: 0.85rem;
            color: var(--muted-color, #6c757d);
        }
        .history-card .total { font-weight: 600; color: var(--success-color, #198754); }

        .history-items {
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid var(--sidebar-border, #dee2e6);
            display: none;
        }

        .history-card.expanded .history-items { display: block; }

        .history-item {
            padding: 0.5rem 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        /* Toast notification */
        .toast-container {
            position: fixed;
            top: 1rem;
            right: 1rem;
            left: 1rem;
            z-index: 9999;
            pointer-events: none;
        }

        .toast-notification {
            background: var(--sidebar-bg, #333);
            color: white;
            padding: 0.75rem 1rem;
            border-radius: 0.5rem;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
            animation: slideIn 0.3s ease;
            pointer-events: auto;
        }

        .toast-notification.success { background: var(--success-color, #198754); }
        .toast-notification.error { background: var(--danger-color, #dc3545); }

        /* Touch-friendly on mobile */
        @media (max-width: 576px) {
            .grocery-app { padding: 0.5rem; }
            .grocery-header {
                border-radius: 0;
                margin: -0.5rem -0.5rem 1rem;
                padding: 1rem;
            }
            .grocery-item { padding: 1rem; }
            .item-checkbox { width: 28px; height: 28px; }
            .item-name { font-size: 1.1rem; }
            .quantity-input { width: 50px !important; }
        }

        /* Animations */
        @keyframes slideIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .grocery-item.new-item { animation: slideIn 0.3s ease; }

        @keyframes slideOut {
            from { opacity: 1; transform: translateX(0); }
            to { opacity: 0; transform: translateX(100%); }
        }

        .grocery-item.removing, .history-card.removing { animation: slideOut 0.3s ease forwards; }
    </style>
</head>
<body>
    <!-- Toast Container -->
    <div class="toast-container" id="toastContainer"></div>

    <div class="grocery-app">
        <!-- Header -->
        <div class="grocery-header">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1><i class="bi bi-cart3"></i> Grocery List</h1>
                    <div class="grocery-stats">
                        <span id="itemCount">0</span> items |
                        <span id="checkedCount">0</span> checked
                        <span id="offlineBadge" class="offline-badge online ms-2">
                            <i class="bi bi-wifi"></i> <span>Online</span>
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tabs -->
        <div class="grocery-tabs">
            <button class="tab-btn active" onclick="switchTab('list')" id="tabList">
                <i class="bi bi-list-check"></i> List
            </button>
            <button class="tab-btn" onclick="switchTab('history')" id="tabHistory">
                <i class="bi bi-clock-history"></i> History
                <span class="badge bg-secondary" id="historyCount">0</span>
            </button>
        </div>

        <!-- Active List Tab -->
        <div class="tab-content active" id="contentList">
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
                <div class="grocery-empty-state" id="emptyState">
                    <i class="bi bi-basket"></i>
                    <p>Your grocery list is empty</p>
                    <p class="text-muted">Add items above to get started</p>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="action-buttons">
                <button type="button" class="btn btn-outline-warning btn-sm" onclick="clearChecked()" id="clearCheckedBtn" disabled>
                    <i class="bi bi-check2-square"></i> Clear Checked
                </button>
                <button type="button" class="btn btn-outline-danger btn-sm" onclick="clearAll()" id="clearAllBtn" disabled>
                    <i class="bi bi-trash"></i> Clear All
                </button>
                <button type="button" class="btn btn-success btn-sm" onclick="showSaveModal()" id="saveListBtn" disabled>
                    <i class="bi bi-save"></i> Save Checked
                </button>
            </div>
        </div>

        <!-- History Tab -->
        <div class="tab-content" id="contentHistory">
            <div id="historyContainer">
                <div class="grocery-empty-state" id="emptyHistory">
                    <i class="bi bi-inbox"></i>
                    <p>No saved grocery lists yet</p>
                    <p class="text-muted">Save your checked items to build history</p>
                </div>
            </div>
            <div class="text-center mt-3">
                <button type="button" class="btn btn-outline-danger btn-sm" onclick="clearAllHistory()" id="clearHistoryBtn" style="display:none;">
                    <i class="bi bi-trash"></i> Clear All History
                </button>
            </div>
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
                            <input type="date" class="form-control" id="saveListDate" required>
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

    <!-- Bootstrap JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <!-- SortableJS for drag/drop -->
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
    <!-- Custom Tiknix JS (for tooltips, etc) -->
    <script src="/js/app.js"></script>

    <script>
    // ============================================
    // LocalStorage Keys & State
    // ============================================
    const LS_ACTIVE_LIST = 'grocery_pwa_items';
    const LS_SAVED_LISTS = 'grocery_pwa_history';

    let sortable = null;
    let saveListModal = null;
    let nextItemId = 1;

    // ============================================
    // Initialization
    // ============================================
    document.addEventListener('DOMContentLoaded', function() {
        // Initialize Bootstrap modal
        saveListModal = new bootstrap.Modal(document.getElementById('saveListModal'));

        // Set default date
        document.getElementById('saveListDate').value = new Date().toISOString().split('T')[0];

        // Load data from localStorage
        loadItems();
        loadHistory();

        // Focus input
        document.getElementById('newItemName').focus();

        // Register service worker for offline support
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('/grocery/sw')
                .then(reg => console.log('Service Worker registered'))
                .catch(err => console.log('Service Worker registration failed:', err));
        }

        // Online/offline status
        updateOnlineStatus();
        window.addEventListener('online', updateOnlineStatus);
        window.addEventListener('offline', updateOnlineStatus);
    });

    function updateOnlineStatus() {
        const badge = document.getElementById('offlineBadge');
        if (navigator.onLine) {
            badge.className = 'offline-badge online ms-2';
            badge.innerHTML = '<i class="bi bi-wifi"></i> <span>Online</span>';
        } else {
            badge.className = 'offline-badge offline ms-2';
            badge.innerHTML = '<i class="bi bi-wifi-off"></i> <span>Offline</span>';
        }
    }

    // ============================================
    // Toast Notifications
    // ============================================
    function showToast(type, message) {
        const container = document.getElementById('toastContainer');
        const toast = document.createElement('div');
        toast.className = `toast-notification ${type}`;
        toast.innerHTML = `
            <i class="bi bi-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i>
            <span>${message}</span>
        `;
        container.appendChild(toast);

        setTimeout(() => {
            toast.style.animation = 'slideOut 0.3s ease forwards';
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }

    // ============================================
    // Tab Switching
    // ============================================
    function switchTab(tab) {
        document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
        document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));

        if (tab === 'list') {
            document.getElementById('tabList').classList.add('active');
            document.getElementById('contentList').classList.add('active');
        } else {
            document.getElementById('tabHistory').classList.add('active');
            document.getElementById('contentHistory').classList.add('active');
        }
    }

    // ============================================
    // LocalStorage Operations
    // ============================================
    function getItems() {
        try {
            const data = localStorage.getItem(LS_ACTIVE_LIST);
            const items = data ? JSON.parse(data) : [];
            // Update nextItemId
            items.forEach(item => {
                if (item.id >= nextItemId) nextItemId = item.id + 1;
            });
            return items;
        } catch (e) {
            console.error('Error loading items:', e);
            return [];
        }
    }

    function saveItems(items) {
        try {
            localStorage.setItem(LS_ACTIVE_LIST, JSON.stringify(items));
        } catch (e) {
            console.error('Error saving items:', e);
            showToast('error', 'Failed to save - storage may be full');
        }
    }

    function getHistory() {
        try {
            const data = localStorage.getItem(LS_SAVED_LISTS);
            return data ? JSON.parse(data) : [];
        } catch (e) {
            console.error('Error loading history:', e);
            return [];
        }
    }

    function saveHistory(history) {
        try {
            localStorage.setItem(LS_SAVED_LISTS, JSON.stringify(history));
        } catch (e) {
            console.error('Error saving history:', e);
            showToast('error', 'Failed to save - storage may be full');
        }
    }

    // ============================================
    // Render Functions
    // ============================================
    function loadItems() {
        const items = getItems();
        renderItems(items);
    }

    function renderItems(items) {
        const container = document.getElementById('groceryListContainer');

        if (items.length === 0) {
            container.innerHTML = `
                <div class="grocery-empty-state" id="emptyState">
                    <i class="bi bi-basket"></i>
                    <p>Your grocery list is empty</p>
                    <p class="text-muted">Add items above to get started</p>
                </div>
            `;
            sortable = null;
        } else {
            // Sort: unchecked first, then by sortOrder
            items.sort((a, b) => {
                if (a.isChecked !== b.isChecked) return a.isChecked ? 1 : -1;
                return (a.sortOrder || 0) - (b.sortOrder || 0);
            });

            let html = '<ul class="grocery-list" id="groceryList">';
            items.forEach(item => {
                html += renderItemHtml(item);
            });
            html += '</ul>';
            container.innerHTML = html;
            initSortable();
        }

        updateStats();
    }

    function renderItemHtml(item) {
        const checkedClass = item.isChecked ? 'checked' : '';
        const depletedClass = item.quantity <= 0 ? 'depleted' : '';
        const title = item.quantity > 0 ? `Click ${item.quantity} more time(s) to check off` : 'Checked off';

        return `
            <li class="grocery-item ${checkedClass}" data-id="${item.id}" data-quantity="${item.quantity}">
                <span class="drag-handle"><i class="bi bi-grip-vertical"></i></span>
                <input type="checkbox" class="item-checkbox" ${item.isChecked ? 'checked' : ''} onchange="toggleItem(${item.id})">
                <span class="item-name" onclick="editItemByName(${item.id})" title="Tap to edit">${escapeHtml(item.name)}</span>
                <span class="item-quantity ${depletedClass}" title="${title}">${item.quantity}</span>
                <button type="button" class="btn btn-sm btn-outline-danger" onclick="confirmDelete(${item.id})" title="Delete">
                    <i class="bi bi-trash"></i>
                </button>
            </li>
        `;
    }

    function loadHistory() {
        const history = getHistory();
        renderHistory(history);
    }

    function renderHistory(history) {
        const container = document.getElementById('historyContainer');
        const clearBtn = document.getElementById('clearHistoryBtn');

        document.getElementById('historyCount').textContent = history.length;

        if (history.length === 0) {
            container.innerHTML = `
                <div class="grocery-empty-state" id="emptyHistory">
                    <i class="bi bi-inbox"></i>
                    <p>No saved grocery lists yet</p>
                    <p class="text-muted">Save your checked items to build history</p>
                </div>
            `;
            clearBtn.style.display = 'none';
        } else {
            // Sort by date descending
            history.sort((a, b) => new Date(b.date) - new Date(a.date));

            let html = '';
            history.forEach((list, index) => {
                const date = new Date(list.date + 'T12:00:00');
                const dateStr = date.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });

                html += `
                    <div class="history-card" data-index="${index}" onclick="toggleHistoryCard(this)">
                        <div class="d-flex justify-content-between align-items-start">
                            <div class="flex-grow-1">
                                <div class="date">${dateStr}</div>
                                ${list.storeName ? `<div class="store"><i class="bi bi-shop"></i> ${escapeHtml(list.storeName)}</div>` : ''}
                                <div class="meta">
                                    <span><i class="bi bi-basket"></i> ${list.items.length} item${list.items.length !== 1 ? 's' : ''}</span>
                                    ${list.totalCost > 0 ? `<span class="total">$${parseFloat(list.totalCost).toFixed(2)}</span>` : ''}
                                </div>
                            </div>
                            <div class="d-flex gap-1" onclick="event.stopPropagation()">
                                <button type="button" class="btn btn-sm btn-outline-success" onclick="reloadHistoryList(${index})" title="Add to list">
                                    <i class="bi bi-arrow-repeat"></i>
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteHistoryItem(${index})" title="Delete">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </div>
                        <div class="history-items">
                            ${list.items.map(item => `
                                <div class="history-item">
                                    <i class="bi bi-check-circle-fill text-success"></i>
                                    <span>${escapeHtml(item.name)}</span>
                                    ${item.quantity > 1 ? `<span class="badge bg-secondary">${item.quantity}</span>` : ''}
                                </div>
                            `).join('')}
                        </div>
                    </div>
                `;
            });

            container.innerHTML = html;
            clearBtn.style.display = 'inline-block';
        }
    }

    function toggleHistoryCard(card) {
        card.classList.toggle('expanded');
    }

    // ============================================
    // Item Operations
    // ============================================
    function addItem(event) {
        event.preventDefault();

        const input = document.getElementById('newItemName');
        const qtyInput = document.getElementById('newItemQty');
        const name = input.value.trim();
        const quantity = Math.max(1, Math.min(999, parseInt(qtyInput.value) || 1));

        if (!name) return false;

        const items = getItems();
        const maxSort = items.reduce((max, item) => Math.max(max, item.sortOrder || 0), 0);

        const newItem = {
            id: nextItemId++,
            name: name,
            quantity: quantity,
            isChecked: false,
            sortOrder: maxSort + 1,
            createdAt: new Date().toISOString()
        };

        items.push(newItem);
        saveItems(items);

        // Clear inputs
        input.value = '';
        qtyInput.value = 1;
        input.focus();

        // Re-render
        renderItems(items);
        showToast('success', `Added "${name}"`);

        return false;
    }

    function toggleItem(itemId) {
        const items = getItems();
        const item = items.find(i => i.id === itemId);

        if (!item) return;

        if (item.isChecked) {
            // Unchecking - restore quantity to 1
            item.isChecked = false;
            item.quantity = 1;
        } else {
            // Checking - decrement quantity
            item.quantity = Math.max(0, item.quantity - 1);
            if (item.quantity <= 0) {
                item.isChecked = true;
            }
        }

        saveItems(items);
        renderItems(items);
    }

    function deleteItem(itemId) {
        const items = getItems();
        const index = items.findIndex(i => i.id === itemId);

        if (index === -1) return;

        const li = document.querySelector(`.grocery-item[data-id="${itemId}"]`);
        if (li) {
            li.classList.add('removing');
            setTimeout(() => {
                items.splice(index, 1);
                saveItems(items);
                renderItems(items);
            }, 300);
        }
    }

    function editItemByName(itemId) {
        const li = document.querySelector(`.grocery-item[data-id="${itemId}"]`);
        if (!li) return;

        const nameSpan = li.querySelector('.item-name');
        const qtyBadge = li.querySelector('.item-quantity');
        const deleteBtn = li.querySelector('.btn-outline-danger');
        const currentName = nameSpan.textContent;
        const currentQty = parseInt(li.dataset.quantity) || 1;

        // Create inputs
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

        // Create save button
        const saveBtn = document.createElement('button');
        saveBtn.type = 'button';
        saveBtn.className = 'btn btn-sm btn-success';
        saveBtn.innerHTML = '<i class="bi bi-check"></i>';

        nameSpan.replaceWith(nameInput);
        qtyBadge.replaceWith(qtyInput);
        deleteBtn.replaceWith(saveBtn);
        nameInput.focus();
        nameInput.select();

        function saveEdit() {
            const newName = nameInput.value.trim();
            const newQty = Math.max(1, Math.min(999, parseInt(qtyInput.value) || 1));

            if (!newName) {
                cancelEdit();
                return;
            }

            const items = getItems();
            const item = items.find(i => i.id === itemId);

            if (item) {
                item.name = newName;
                item.quantity = newQty;
                if (newQty > 0 && item.isChecked) {
                    item.isChecked = false;
                }
                saveItems(items);
                renderItems(items);
            }
        }

        function cancelEdit() {
            renderItems(getItems());
        }

        saveBtn.onclick = (e) => { e.preventDefault(); saveEdit(); };

        [nameInput, qtyInput].forEach(input => {
            input.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') { e.preventDefault(); saveEdit(); }
                else if (e.key === 'Escape') { cancelEdit(); }
            });
            input.addEventListener('blur', (e) => {
                // Delay to allow click on save button
                setTimeout(() => {
                    if (!document.activeElement.classList.contains('form-control') &&
                        !document.activeElement.classList.contains('btn-success')) {
                        saveEdit();
                    }
                }, 150);
            });
        });
    }

    function confirmDelete(itemId) {
        if (confirm('Delete this item?')) {
            deleteItem(itemId);
        }
    }

    function clearChecked() {
        if (!confirm('Remove all checked items?')) return;

        let items = getItems();
        items = items.filter(i => !i.isChecked);
        saveItems(items);
        renderItems(items);
        showToast('success', 'Cleared checked items');
    }

    function clearAll() {
        if (!confirm('Remove ALL items from your grocery list?')) return;

        saveItems([]);
        renderItems([]);
        showToast('success', 'List cleared');
    }

    // ============================================
    // Sorting
    // ============================================
    function initSortable() {
        const list = document.getElementById('groceryList');
        if (!list) return;

        sortable = Sortable.create(list, {
            handle: '.drag-handle',
            animation: 150,
            ghostClass: 'sortable-ghost',
            chosenClass: 'sortable-chosen',
            onEnd: function() {
                saveOrder();
            }
        });
    }

    function saveOrder() {
        const list = document.getElementById('groceryList');
        if (!list) return;

        const items = getItems();
        const itemElements = list.querySelectorAll('.grocery-item');

        itemElements.forEach((li, index) => {
            const id = parseInt(li.dataset.id);
            const item = items.find(i => i.id === id);
            if (item) {
                item.sortOrder = index;
            }
        });

        saveItems(items);
    }

    // ============================================
    // Save to History
    // ============================================
    function showSaveModal() {
        document.getElementById('saveListDate').value = new Date().toISOString().split('T')[0];
        document.getElementById('saveStoreName').value = '';
        document.getElementById('saveTotalCost').value = '';
        saveListModal.show();
    }

    function saveList() {
        const listDate = document.getElementById('saveListDate').value;
        const storeName = document.getElementById('saveStoreName').value.trim();
        const totalCost = parseFloat(document.getElementById('saveTotalCost').value) || 0;

        const items = getItems();
        const checkedItems = items.filter(i => i.isChecked);

        if (checkedItems.length === 0) {
            showToast('error', 'No checked items to save');
            return;
        }

        // Create history entry
        const historyEntry = {
            id: Date.now(),
            date: listDate,
            storeName: storeName,
            totalCost: totalCost,
            items: checkedItems.map(i => ({ name: i.name, quantity: i.quantity })),
            savedAt: new Date().toISOString()
        };

        // Add to history
        const history = getHistory();
        history.push(historyEntry);
        saveHistory(history);

        // Remove checked items from active list
        const remaining = items.filter(i => !i.isChecked);
        saveItems(remaining);

        saveListModal.hide();
        renderItems(remaining);
        loadHistory();

        showToast('success', `Saved ${checkedItems.length} item${checkedItems.length !== 1 ? 's' : ''} to history`);
    }

    // ============================================
    // History Operations
    // ============================================
    function reloadHistoryList(index) {
        const history = getHistory();
        const list = history[index];

        if (!list || !list.items.length) {
            showToast('error', 'No items in this list');
            return;
        }

        if (!confirm(`Add ${list.items.length} items to your current list?`)) return;

        const items = getItems();
        const maxSort = items.reduce((max, item) => Math.max(max, item.sortOrder || 0), 0);

        list.items.forEach((historyItem, i) => {
            items.push({
                id: nextItemId++,
                name: historyItem.name,
                quantity: Math.max(1, historyItem.quantity || 1),
                isChecked: false,
                sortOrder: maxSort + i + 1,
                createdAt: new Date().toISOString()
            });
        });

        saveItems(items);
        renderItems(items);
        switchTab('list');
        showToast('success', `Added ${list.items.length} items to your list`);
    }

    function deleteHistoryItem(index) {
        if (!confirm('Delete this saved list?')) return;

        const history = getHistory();
        const card = document.querySelector(`.history-card[data-index="${index}"]`);

        if (card) {
            card.classList.add('removing');
            setTimeout(() => {
                history.splice(index, 1);
                saveHistory(history);
                renderHistory(history);
            }, 300);
        }
    }

    function clearAllHistory() {
        if (!confirm('Delete ALL saved lists? This cannot be undone.')) return;

        saveHistory([]);
        renderHistory([]);
        showToast('success', 'History cleared');
    }

    // ============================================
    // Stats & Helpers
    // ============================================
    function updateStats() {
        const items = getItems();
        const checked = items.filter(i => i.isChecked);

        document.getElementById('itemCount').textContent = items.length;
        document.getElementById('checkedCount').textContent = checked.length;

        document.getElementById('clearCheckedBtn').disabled = checked.length === 0;
        document.getElementById('clearAllBtn').disabled = items.length === 0;
        document.getElementById('saveListBtn').disabled = checked.length === 0;
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    </script>
</body>
</html>

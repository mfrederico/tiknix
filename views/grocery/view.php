<style>
    .grocery-container {
        max-width: 600px;
        margin: 0 auto;
    }

    .grocery-header {
        background: linear-gradient(135deg, #0d6efd 0%, #0a58ca 100%);
        border-radius: 1rem;
        padding: 1.5rem;
        margin-bottom: 1rem;
        color: white;
    }

    .grocery-header h1 {
        font-size: 1.3rem;
        margin: 0;
    }

    .grocery-meta {
        font-size: 0.9rem;
        opacity: 0.9;
        margin-top: 0.5rem;
    }

    .grocery-meta .store {
        margin-right: 1rem;
    }

    .grocery-meta .total {
        background: rgba(255,255,255,0.2);
        padding: 0.25rem 0.75rem;
        border-radius: 1rem;
        font-weight: 600;
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
    }

    .item-name {
        flex: 1;
        font-size: 1rem;
        word-break: break-word;
    }

    .item-quantity {
        background: var(--bs-secondary);
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

    .summary-card {
        background: var(--bs-body-bg);
        border: 1px solid var(--bs-border-color);
        border-radius: 0.75rem;
        padding: 1rem;
        margin-top: 1rem;
    }

    .summary-card .row {
        display: flex;
        justify-content: space-between;
        padding: 0.25rem 0;
    }

    .summary-card .total-row {
        border-top: 1px solid var(--bs-border-color);
        padding-top: 0.5rem;
        margin-top: 0.5rem;
        font-weight: 600;
        font-size: 1.1rem;
    }

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

        .item-name {
            font-size: 1.1rem;
        }
    }
</style>

<div class="container py-4">
    <div class="grocery-container">
        <!-- Header -->
        <div class="grocery-header">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <h1><i class="bi bi-receipt"></i> <?= date('l, M j, Y', strtotime($list->listDate)) ?></h1>
                    <div class="grocery-meta">
                        <?php if ($list->storeName): ?>
                            <span class="store"><i class="bi bi-shop"></i> <?= htmlspecialchars($list->storeName) ?></span>
                        <?php endif; ?>
                        <?php if ($list->totalCost > 0): ?>
                            <span class="total">$<?= number_format($list->totalCost, 2) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <a href="/grocery/history" class="btn btn-outline-light btn-sm">
                    <i class="bi bi-arrow-left"></i> Back
                </a>
            </div>
        </div>

        <!-- Items -->
        <div id="itemsContainer">
            <?php if (empty($items)): ?>
                <div class="empty-state">
                    <i class="bi bi-basket"></i>
                    <p>No items in this list</p>
                </div>
            <?php else: ?>
                <ul class="grocery-list">
                    <?php foreach ($items as $item): ?>
                        <li class="grocery-item">
                            <i class="bi bi-check-circle-fill text-success"></i>
                            <span class="item-name"><?= htmlspecialchars($item->name) ?></span>
                            <?php if ((int)($item->quantity ?? 1) > 0): ?>
                                <span class="item-quantity"><?= (int)($item->quantity ?? 1) ?></span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>

                <!-- Summary -->
                <div class="summary-card">
                    <div class="row">
                        <span>Items</span>
                        <span><?= count($items) ?></span>
                    </div>
                    <?php if ($list->storeName): ?>
                    <div class="row">
                        <span>Store</span>
                        <span><?= htmlspecialchars($list->storeName) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($list->totalCost > 0): ?>
                    <div class="row total-row text-success">
                        <span>Total Paid</span>
                        <span>$<?= number_format($list->totalCost, 2) ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Actions -->
        <div class="d-flex gap-2 mt-3 flex-wrap">
            <a href="/grocery/history" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Back to History
            </a>
            <?php if (!empty($items)): ?>
            <button type="button" class="btn btn-primary" onclick="reloadList(<?= $list->id ?>)">
                <i class="bi bi-arrow-repeat"></i> Reload as Template
            </button>
            <?php endif; ?>
            <a href="/grocery" class="btn btn-success">
                <i class="bi bi-cart3"></i> Current List
            </a>
        </div>
    </div>
</div>

<script>
const csrfToken = <?= json_encode($csrf) ?>;

async function reloadList(listId) {
    if (!confirm('Add these items to your current grocery list?')) return;

    try {
        const formData = new FormData();
        formData.append('id', listId);
        Object.entries(csrfToken).forEach(([key, value]) => {
            formData.append(key, value);
        });

        const response = await fetch('/grocery/reloadList', {
            method: 'POST',
            body: formData
        });

        const data = await response.json();

        if (data.success) {
            showToast('success', data.message);
            // Redirect to grocery list after short delay
            setTimeout(() => {
                window.location.href = '/grocery';
            }, 1000);
        } else {
            showToast('error', data.message || 'Failed to reload list');
        }
    } catch (error) {
        console.error('Error reloading list:', error);
        showToast('error', 'Failed to reload list');
    }
}
</script>

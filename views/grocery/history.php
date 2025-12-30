<style>
    .grocery-container {
        max-width: 600px;
        margin: 0 auto;
    }

    .grocery-header {
        background: linear-gradient(135deg, #6c757d 0%, #495057 100%);
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

    .list-card {
        background: var(--bs-body-bg);
        border: 1px solid var(--bs-border-color);
        border-radius: 0.75rem;
        margin-bottom: 0.75rem;
        padding: 1rem;
        transition: all 0.2s ease;
    }

    .list-card:hover {
        box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    }

    .list-card .date {
        font-size: 1.1rem;
        font-weight: 600;
    }

    .list-card .store {
        color: var(--bs-secondary-color);
        font-size: 0.9rem;
    }

    .list-card .meta {
        display: flex;
        gap: 1rem;
        margin-top: 0.5rem;
        font-size: 0.85rem;
        color: var(--bs-secondary-color);
    }

    .list-card .total {
        font-weight: 600;
        color: var(--bs-success);
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

    @media (max-width: 576px) {
        .grocery-container {
            padding: 0 0.5rem;
        }

        .grocery-header {
            border-radius: 0;
            margin: -1rem -0.5rem 1rem;
            padding: 1rem;
        }
    }

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

    .list-card.removing {
        animation: slideOut 0.3s ease forwards;
    }
</style>

<div class="container py-4">
    <div class="grocery-container">
        <!-- Header -->
        <div class="grocery-header">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1><i class="bi bi-clock-history"></i> Grocery History</h1>
                    <div class="grocery-stats">
                        <?= count($lists) ?> saved list<?= count($lists) != 1 ? 's' : '' ?>
                    </div>
                </div>
                <a href="/grocery" class="btn btn-outline-light btn-sm">
                    <i class="bi bi-arrow-left"></i> Back
                </a>
            </div>
        </div>

        <!-- Lists -->
        <div id="listsContainer">
            <?php if (empty($lists)): ?>
                <div class="empty-state">
                    <i class="bi bi-inbox"></i>
                    <p>No saved grocery lists yet</p>
                    <p class="text-muted">Save your checked items to build history</p>
                    <a href="/grocery" class="btn btn-success mt-2">
                        <i class="bi bi-cart3"></i> Go to Grocery List
                    </a>
                </div>
            <?php else: ?>
                <?php foreach ($lists as $list): ?>
                    <?php
                    $itemCount = count($list->ownGroceryitemList);
                    $listDate = date('l, M j, Y', strtotime($list->listDate));
                    ?>
                    <div class="list-card" data-id="<?= $list->id ?>">
                        <div class="d-flex justify-content-between align-items-start">
                            <div class="flex-grow-1">
                                <a href="/grocery/view/<?= $list->id ?>" class="text-decoration-none">
                                    <div class="date text-body"><?= $listDate ?></div>
                                    <?php if ($list->storeName): ?>
                                        <div class="store"><i class="bi bi-shop"></i> <?= htmlspecialchars($list->storeName) ?></div>
                                    <?php endif; ?>
                                </a>
                                <div class="meta">
                                    <span><i class="bi bi-basket"></i> <?= $itemCount ?> item<?= $itemCount != 1 ? 's' : '' ?></span>
                                    <?php if ($list->totalCost > 0): ?>
                                        <span class="total">$<?= number_format($list->totalCost, 2) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="d-flex gap-1">
                                <a href="/grocery/view/<?= $list->id ?>" class="btn btn-sm btn-outline-primary" title="View">
                                    <i class="bi bi-eye"></i>
                                </a>
                                <button type="button"
                                        class="btn btn-sm btn-outline-danger"
                                        onclick="deleteList(<?= $list->id ?>)"
                                        title="Delete">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
const csrfToken = <?= json_encode($csrf) ?>;

async function deleteList(listId) {
    if (!confirm('Delete this saved list? This cannot be undone.')) return;

    const card = document.querySelector(`.list-card[data-id="${listId}"]`);

    try {
        const formData = new FormData();
        formData.append('id', listId);
        Object.entries(csrfToken).forEach(([key, value]) => {
            formData.append(key, value);
        });

        card.classList.add('removing');

        const response = await fetch('/grocery/deleteList', {
            method: 'POST',
            body: formData
        });

        const data = await response.json();

        if (data.success) {
            setTimeout(() => {
                card.remove();
                // Check if list is empty
                const remaining = document.querySelectorAll('.list-card');
                if (remaining.length === 0) {
                    document.getElementById('listsContainer').innerHTML = `
                        <div class="empty-state">
                            <i class="bi bi-inbox"></i>
                            <p>No saved grocery lists yet</p>
                            <p class="text-muted">Save your checked items to build history</p>
                            <a href="/grocery" class="btn btn-success mt-2">
                                <i class="bi bi-cart3"></i> Go to Grocery List
                            </a>
                        </div>
                    `;
                }
            }, 300);
            showToast('success', 'List deleted');
        } else {
            card.classList.remove('removing');
            showToast('error', data.message || 'Failed to delete list');
        }
    } catch (error) {
        console.error('Error deleting list:', error);
        card.classList.remove('removing');
        showToast('error', 'Failed to delete list');
    }
}
</script>

<?php
/**
 * Communications hub — thread-list rail + empty detail pane.
 *
 * @var array  $threads
 * @var int    $activeId  (0 here)
 * @var string $search
 * @var bool   $isAdmin
 * @var int    $unreadTotal
 */
?>
<?php include __DIR__ . '/_styles.php'; ?>

<div class="comms-hub container-fluid py-4">

    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
            <h1 class="h3 fw-bold mb-0">
                <i class="bi bi-chat-left-dots"></i> Communications
                <?php if (!empty($unreadTotal)): ?>
                    <span class="badge bg-danger align-middle"><?= (int)$unreadTotal ?> unread</span>
                <?php endif; ?>
            </h1>
            <span class="text-muted small">
                Email conversations
                <?= !empty($isAdmin) ? '— all conversations (root)' : '— your conversations' ?>
            </span>
        </div>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#comms-compose-modal">
            <i class="bi bi-pencil-square me-1"></i>New Conversation
        </button>
    </div>

    <div class="row g-3">
        <?php include __DIR__ . '/_thread-list.php'; ?>

        <div class="col-lg-8 d-none d-lg-block">
            <div class="card border-0 shadow-sm comms-panel align-items-center justify-content-center">
                <div class="text-center text-body-secondary p-4">
                    <i class="bi bi-chat-left-text" style="font-size:2.5rem;"></i>
                    <div class="mt-2">Select a conversation to view messages.</div>
                    <div class="small mt-1">
                        Threads start automatically from any <code>NotifyService</code> send;
                        recipient replies arrive here via the Mailgun webhook.
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>

<?php include __DIR__ . '/_compose-modal.php'; ?>

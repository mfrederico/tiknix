<?php
/**
 * "New Conversation" compose modal. Posts to /communications/create, which
 * starts a thread via NotifyService and redirects to it.
 */
?>
<div class="modal fade" id="comms-compose-modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" action="/communications/create">
                <?= csrf_field() ?>
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-pencil-square me-1"></i>New Conversation</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-2 mb-2">
                        <div class="col-sm-7">
                            <label class="form-label small mb-1">Recipient email</label>
                            <input type="email" name="to" class="form-control" placeholder="name@example.com" required>
                        </div>
                        <div class="col-sm-5">
                            <label class="form-label small mb-1">Name <span class="text-muted">(optional)</span></label>
                            <input type="text" name="to_name" class="form-control" placeholder="Jane Smith">
                        </div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small mb-1">Subject</label>
                        <input type="text" name="subject" class="form-control" placeholder="Subject" required>
                    </div>
                    <div class="mb-1">
                        <label class="form-label small mb-1">Message</label>
                        <textarea name="body" class="form-control" rows="5" placeholder="Write your message…" required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-send me-1"></i>Send</button>
                </div>
            </form>
        </div>
    </div>
</div>

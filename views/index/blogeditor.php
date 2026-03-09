<style>
    .post-list-item {
        background: var(--bs-dark, #1e293b);
        border: 1px solid #334155;
        border-radius: 8px;
        padding: 1rem 1.25rem;
        margin-bottom: 0.75rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
        transition: border-color 0.2s;
    }
    .post-list-item:hover { border-color: #ff6b35; }
    .post-list-item h6 { color: #fff; margin: 0 0 4px; font-weight: 600; }
    .post-list-item small { color: #8b949e; }
    .md-editor {
        width: 100%;
        min-height: 500px;
        background: #0d1117;
        border: 1px solid #334155;
        border-radius: 8px;
        color: #c9d1d9;
        font-family: 'JetBrains Mono', 'Courier New', monospace;
        font-size: 0.9rem;
        padding: 1.25rem;
        resize: vertical;
        line-height: 1.7;
        tab-size: 4;
    }
    .md-editor:focus { border-color: #ff6b35; outline: none; box-shadow: 0 0 0 2px rgba(255,107,53,0.15); }
    .slug-input {
        background: #0d1117;
        border: 1px solid #334155;
        color: #c9d1d9;
        border-radius: 8px;
        padding: 10px 14px;
    }
    .slug-input:focus { background: #0d1117; color: #c9d1d9; border-color: #ff6b35; box-shadow: 0 0 0 2px rgba(255,107,53,0.15); }
    .template-hint {
        background: #161b22;
        border: 1px solid #334155;
        border-radius: 8px;
        padding: 1rem;
        font-size: 0.85rem;
        color: #8b949e;
    }
    .template-hint code {
        color: #ff6b35;
        background: #0d1117;
        padding: 1px 5px;
        border-radius: 3px;
        font-size: 0.8rem;
    }
</style>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1><i class="bi bi-pencil-square text-primary"></i> Blog Editor</h1>
        <div class="d-flex gap-2">
            <a href="/blog" class="btn btn-sm btn-outline-secondary" target="_blank"><i class="bi bi-box-arrow-up-right"></i> View Blog</a>
            <a href="/index/blogeditor" class="btn btn-sm btn-outline-secondary"><i class="bi bi-list"></i> All Posts</a>
            <a href="/dashboard" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i> Dashboard</a>
        </div>
    </div>

    <?php if (!empty($message)): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="bi bi-check-circle"></i> <?= htmlspecialchars($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($action === 'list'): ?>
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="mb-0">Posts (<?= count($posts) ?>)</h5>
            <a href="/index/blogeditor?action=new" class="btn btn-primary btn-sm">
                <i class="bi bi-plus-lg"></i> New Post
            </a>
        </div>

        <?php if (empty($posts)): ?>
            <div class="text-center py-5 text-muted">
                <p>No posts yet. Create your first one!</p>
            </div>
        <?php else: ?>
            <?php foreach ($posts as $post): ?>
                <div class="post-list-item">
                    <div>
                        <h6><?= htmlspecialchars($post['title'] ?? $post['slug']) ?></h6>
                        <small>
                            <?= htmlspecialchars($post['slug']) ?>.md
                            <?php if (!empty($post['date'])): ?>
                                &middot; <?= htmlspecialchars($post['date']) ?>
                            <?php endif; ?>
                            <?php if (!empty($post['draft']) && $post['draft'] === 'true'): ?>
                                &middot; <span class="text-warning">DRAFT</span>
                            <?php endif; ?>
                        </small>
                    </div>
                    <div class="d-flex gap-2">
                        <a href="/blog/<?= htmlspecialchars($post['slug']) ?>" class="btn btn-sm btn-outline-secondary" target="_blank"><i class="bi bi-eye"></i></a>
                        <a href="/index/blogeditor?edit=<?= htmlspecialchars($post['slug']) ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                        <form method="post" style="display:inline;" onsubmit="return confirm('Delete this post?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="slug" value="<?= htmlspecialchars($post['slug']) ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

    <?php elseif ($action === 'new' || $action === 'edit'): ?>
        <h5 class="mb-3"><?= $action === 'edit' ? 'Edit Post' : 'New Post' ?></h5>

        <?php if ($action === 'new'): ?>
        <div class="template-hint mb-3">
            <strong>Front matter template</strong> — included at the top of new posts:<br>
            <code>---</code><br>
            <code>title: "Your Post Title Here"</code><br>
            <code>date: "<?= date('Y-m-d') ?>"</code><br>
            <code>description: "A short summary for SEO and social cards"</code><br>
            <code>author: "CannonWMS Team"</code><br>
            <code>tags: "WMS, eCommerce, Inventory"</code><br>
            <code>draft: "false"</code><br>
            <code>---</code>
        </div>
        <?php endif; ?>

        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">

            <div class="mb-3">
                <label class="form-label fw-bold">Slug (URL-safe filename, lowercase, hyphens only)</label>
                <input type="text" name="slug" class="form-control slug-input"
                    value="<?= htmlspecialchars($editSlug) ?>"
                    placeholder="e.g., how-to-fix-inventory-sync"
                    pattern="[a-z0-9\-]+"
                    <?= $action === 'edit' ? 'readonly style="opacity:0.6;"' : '' ?>
                    required>
            </div>

            <div class="mb-3">
                <label class="form-label fw-bold">Content (Markdown with YAML front matter)</label>
                <textarea name="content" class="md-editor" id="mdEditor"><?= htmlspecialchars($editContent ?: "---\ntitle: \"\"\ndate: \"" . date('Y-m-d') . "\"\ndescription: \"\"\nauthor: \"CannonWMS Team\"\ntags: \"\"\ndraft: \"true\"\n---\n\n# Your Title Here\n\nStart writing your post...\n") ?></textarea>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Save Post</button>
                <a href="/index/blogeditor" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>

        <script>
            document.getElementById('mdEditor').addEventListener('keydown', function(e) {
                if (e.key === 'Tab') {
                    e.preventDefault();
                    var start = this.selectionStart;
                    var end = this.selectionEnd;
                    this.value = this.value.substring(0, start) + '    ' + this.value.substring(end);
                    this.selectionStart = this.selectionEnd = start + 4;
                }
            });
        </script>
    <?php endif; ?>
</div>

<?php
// Scan posts directory for .md files and parse front matter
$postsDir = __DIR__ . '/posts';
$posts = [];

if (is_dir($postsDir)) {
    foreach (glob($postsDir . '/*.md') as $file) {
        $raw = file_get_contents($file);
        $meta = [];
        if (preg_match('/^---\s*\n(.*?)\n---\s*\n/s', $raw, $m)) {
            foreach (explode("\n", $m[1]) as $line) {
                if (preg_match('/^(\w[\w_]*)\s*:\s*(.+)$/', trim($line), $kv)) {
                    $val = trim($kv[2]);
                    if (preg_match('/^["\'](.*)["\']\s*$/', $val, $q)) {
                        $val = $q[1];
                    }
                    $meta[$kv[1]] = $val;
                }
            }
        }
        $meta['slug'] = basename($file, '.md');
        $meta['file'] = $file;
        if (!empty($meta['draft']) && $meta['draft'] === 'true') continue;
        $posts[] = $meta;
    }
}

// Sort by date descending
usort($posts, function($a, $b) {
    return strcmp($b['date'] ?? '0000-00-00', $a['date'] ?? '0000-00-00');
});
?>

<style>
    .blog-list-hero {
        background: linear-gradient(135deg, var(--dark-color) 0%, #1a1f2e 50%, var(--dark-color) 100%);
        color: white;
        padding: 120px 0 60px;
        text-align: center;
    }
    .blog-card {
        background: var(--dark-surface);
        border: 1px solid var(--dark-border);
        border-radius: 16px;
        padding: 2rem;
        transition: all 0.3s ease;
        height: 100%;
        display: flex;
        flex-direction: column;
    }
    .blog-card:hover {
        border-color: var(--primary-color);
        transform: translateY(-3px);
        box-shadow: 0 10px 30px rgba(255,107,53,0.1);
    }
    .blog-card h3 {
        color: #fff;
        font-weight: 700;
        font-size: 1.25rem;
        margin-bottom: 0.75rem;
    }
    .blog-card h3 a {
        color: #fff;
        text-decoration: none;
        transition: color 0.3s;
    }
    .blog-card h3 a:hover { color: var(--primary-color); }
    .blog-card p {
        color: var(--text-secondary);
        font-size: 0.95rem;
        line-height: 1.6;
        flex-grow: 1;
    }
    .blog-card-meta {
        color: var(--text-secondary);
        font-size: 0.8rem;
        margin-bottom: 0.75rem;
    }
    .blog-card-meta i { color: var(--primary-color); margin-right: 4px; }
    .blog-tag-sm {
        display: inline-block;
        background: rgba(255,107,53,0.1);
        color: var(--primary-color);
        padding: 2px 8px;
        border-radius: 10px;
        font-size: 0.75rem;
        font-weight: 600;
        margin-right: 4px;
    }
</style>

<section class="blog-list-hero">
    <div class="container">
        <h1 style="font-size: 3rem; font-weight: 900;">CannonWMS Blog</h1>
        <p style="font-size: 1.2rem; color: var(--text-secondary); max-width: 600px; margin: 1rem auto 0;">Warehouse management insights, product updates, and strategies for eCommerce brands.</p>
    </div>
</section>

<section class="section-dark" style="padding: 60px 0;">
    <div class="container">
        <?php if (empty($posts)): ?>
            <div class="text-center" style="padding: 60px 0;">
                <i class="fas fa-pen-nib" style="font-size: 3rem; color: var(--text-secondary); margin-bottom: 1rem; display: block;"></i>
                <h3 style="color: #fff;">Coming Soon</h3>
                <p style="color: var(--text-secondary);">We're working on our first posts. Check back soon!</p>
            </div>
        <?php else: ?>
            <div class="row g-4">
                <?php foreach ($posts as $post): ?>
                    <div class="col-md-6 col-lg-4">
                        <div class="blog-card">
                            <div class="blog-card-meta">
                                <?php if (!empty($post['date'])): ?>
                                    <i class="fas fa-calendar"></i> <?php echo htmlspecialchars($post['date']); ?>
                                <?php endif; ?>
                            </div>
                            <h3><a href="/blog/<?php echo htmlspecialchars($post['slug']); ?>"><?php echo htmlspecialchars($post['title'] ?? $post['slug']); ?></a></h3>
                            <p><?php echo htmlspecialchars($post['description'] ?? ''); ?></p>
                            <div>
                                <?php if (!empty($post['tags'])): ?>
                                    <?php foreach (explode(',', $post['tags']) as $tag): ?>
                                        <span class="blog-tag-sm"><?php echo htmlspecialchars(trim($tag)); ?></span>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php
/**
 * Blog post renderer
 * - Serves HTML to browsers with full site template
 * - Serves raw Markdown to AI bots and .md requests
 *
 * Variables provided by controller:
 *   $slug, $meta, $markdown, $htmlContent
 */

$title = $meta['title'] ?? $slug;
$description = $meta['description'] ?? '';
$date = $meta['date'] ?? '';
$author = $meta['author'] ?? 'CannonWMS Team';
$tags = $meta['tags'] ?? '';
?>

<style>
    .blog-hero {
        background: linear-gradient(135deg, var(--dark-color) 0%, #1a1f2e 50%, var(--dark-color) 100%);
        color: white;
        padding: 120px 0 60px;
        position: relative;
        overflow: hidden;
    }
    .blog-hero::before {
        content: '';
        position: absolute;
        top: -50%;
        right: -20%;
        width: 80%;
        height: 200%;
        background: radial-gradient(ellipse, rgba(255,107,53,0.06) 0%, transparent 70%);
        pointer-events: none;
    }
    .blog-post-content {
        background: var(--dark-surface);
        border: 1px solid var(--dark-border);
        border-radius: 16px;
        padding: 3rem;
        margin-top: -30px;
        position: relative;
        z-index: 10;
    }
    .blog-post-content h2 {
        color: #fff;
        font-weight: 700;
        font-size: 1.75rem;
        margin-top: 2.5rem;
        margin-bottom: 1rem;
        padding-bottom: 0.5rem;
        border-bottom: 1px solid var(--dark-border);
    }
    .blog-post-content h3 {
        color: #fff;
        font-weight: 600;
        font-size: 1.35rem;
        margin-top: 2rem;
        margin-bottom: 0.75rem;
    }
    .blog-post-content h4 {
        color: var(--text-primary);
        font-weight: 600;
        font-size: 1.1rem;
        margin-top: 1.5rem;
        margin-bottom: 0.5rem;
    }
    .blog-post-content p {
        color: var(--text-primary);
        line-height: 1.8;
        margin-bottom: 1.25rem;
        font-size: 1.05rem;
    }
    .blog-post-content ul, .blog-post-content ol {
        color: var(--text-primary);
        margin-bottom: 1.25rem;
        padding-left: 1.5rem;
    }
    .blog-post-content li {
        margin-bottom: 0.5rem;
        line-height: 1.7;
    }
    .blog-post-content blockquote {
        border-left: 4px solid var(--primary-color);
        padding: 1rem 1.5rem;
        margin: 1.5rem 0;
        background: rgba(255,107,53,0.05);
        border-radius: 0 8px 8px 0;
    }
    .blog-post-content blockquote p {
        color: var(--text-secondary);
        font-style: italic;
        margin-bottom: 0;
    }
    .blog-post-content pre {
        background: var(--dark-color);
        border: 1px solid var(--dark-border);
        border-radius: 8px;
        padding: 1.25rem;
        overflow-x: auto;
        margin: 1.5rem 0;
    }
    .blog-post-content pre code {
        background: none;
        padding: 0;
        color: var(--text-primary);
        font-size: 0.9rem;
    }
    .blog-post-content .table-responsive {
        overflow-x: auto;
        margin: 1.5rem 0;
        border-radius: 8px;
        border: 1px solid var(--dark-border);
    }
    .blog-post-content .blog-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.95rem;
    }
    .blog-post-content .blog-table th {
        background: var(--dark-color);
        color: #fff;
        font-weight: 700;
        padding: 0.75rem 1rem;
        text-align: left;
        border-bottom: 2px solid var(--primary-color);
        white-space: nowrap;
    }
    .blog-post-content .blog-table td {
        padding: 0.65rem 1rem;
        color: var(--text-primary);
        border-bottom: 1px solid var(--dark-border);
    }
    .blog-post-content .blog-table tbody tr:hover {
        background: rgba(255,107,53,0.04);
    }
    .blog-post-content .blog-table tbody tr:last-child td {
        border-bottom: none;
    }
    .blog-post-content hr {
        border: none;
        border-top: 1px solid var(--dark-border);
        margin: 2.5rem 0;
    }
    .blog-post-content strong {
        color: #fff;
    }
    .blog-meta {
        display: flex;
        align-items: center;
        gap: 1.5rem;
        color: var(--text-secondary);
        font-size: 0.9rem;
        flex-wrap: wrap;
    }
    .blog-meta i {
        color: var(--primary-color);
        margin-right: 4px;
    }
    .blog-tag {
        display: inline-block;
        background: rgba(255,107,53,0.1);
        color: var(--primary-color);
        padding: 3px 10px;
        border-radius: 12px;
        font-size: 0.8rem;
        font-weight: 600;
    }
    .blog-cta {
        background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        border-radius: 12px;
        padding: 2.5rem;
        text-align: center;
        margin-top: 3rem;
    }
    .blog-cta h3 { color: #fff; font-weight: 800; margin-bottom: 0.75rem; }
    .blog-cta p { color: rgba(255,255,255,0.9); margin-bottom: 1.5rem; }
    @media (max-width: 768px) {
        .blog-post-content { padding: 1.5rem; }
        .blog-hero { padding: 80px 0 40px; }
    }
</style>

<!-- Alternate markdown version for AI crawlers -->
<link rel="alternate" type="text/markdown" href="/blog/<?php echo htmlspecialchars($slug); ?>.md">

<!-- Article structured data -->
<script type="application/ld+json">
{
    "@context": "https://schema.org",
    "@type": "Article",
    "headline": <?php echo json_encode($title); ?>,
    "description": <?php echo json_encode($description); ?>,
    "author": {
        "@type": "Organization",
        "name": "ShipCannon"
    },
    "publisher": {
        "@type": "Organization",
        "name": "ShipCannon",
        "logo": {
            "@type": "ImageObject",
            "url": "https://shipcannon.com/images/NEW-ShipCannon_header_footer.webp"
        }
    },
    "datePublished": <?php echo json_encode($date); ?>,
    "mainEntityOfPage": "https://shipcannon.com/blog/<?php echo htmlspecialchars($slug); ?>"
}
</script>

<section class="blog-hero">
    <div class="container position-relative">
        <div class="row justify-content-center">
            <div class="col-lg-8 text-center">
                <a href="/blog" style="color: var(--primary-color); text-decoration: none; font-weight: 600; font-size: 0.9rem;">
                    <i class="fas fa-arrow-left"></i> Back to Blog
                </a>
                <h1 style="font-size: 2.75rem; font-weight: 900; margin: 1rem 0; color: #fff;"><?php echo htmlspecialchars($title); ?></h1>
                <div class="blog-meta justify-content-center">
                    <?php if ($date): ?>
                        <span><i class="fas fa-calendar"></i> <?php echo htmlspecialchars($date); ?></span>
                    <?php endif; ?>
                    <span><i class="fas fa-user"></i> <?php echo htmlspecialchars($author); ?></span>
                    <?php if ($tags): ?>
                        <span>
                            <?php foreach (explode(',', $tags) as $tag): ?>
                                <span class="blog-tag"><?php echo htmlspecialchars(trim($tag)); ?></span>
                            <?php endforeach; ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="section-dark" style="padding: 40px 0 80px;">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <article class="blog-post-content">
                    <?php echo $htmlContent; ?>

                    <div class="blog-cta">
                        <h3>Ready to Fix This for Your Warehouse?</h3>
                        <p>Start your 60-day free trial of CannonWMS. No credit card required.</p>
                        <a href="/contact-us" class="btn btn-light btn-lg" style="font-weight:700;color:var(--primary-color);">
                            <i class="fas fa-rocket"></i> Start Free Trial
                        </a>
                    </div>
                </article>
            </div>
        </div>
    </div>
</section>

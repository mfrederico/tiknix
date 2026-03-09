<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title ?? 'CannonWMS by ShipCannon') ?></title>
    <meta name="description" content="<?= htmlspecialchars($description ?? 'CannonWMS is a modern, multi-warehouse management system built for eCommerce brands.') ?>">

    <?php
    $canonicalPath = $canonical ?? strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
    $canonicalPath = rtrim($canonicalPath, '/');
    if ($canonicalPath === '' || $canonicalPath === '/index') $canonicalPath = '/';
    $canonicalUrl = 'https://shipcannon.com' . ($canonicalPath === '/' ? '' : $canonicalPath);
    ?>
    <link rel="canonical" href="<?= $canonicalUrl ?>">

    <meta property="og:type" content="website">
    <meta property="og:site_name" content="CannonWMS by ShipCannon">
    <meta property="og:title" content="<?= htmlspecialchars($title ?? 'CannonWMS by ShipCannon') ?>">
    <meta property="og:description" content="<?= htmlspecialchars($description ?? 'CannonWMS is a modern, multi-warehouse management system built for eCommerce brands.') ?>">
    <meta property="og:url" content="<?= $canonicalUrl ?>">
    <meta property="og:image" content="https://shipcannon.com/images/NEW-ShipCannon_header_footer.webp">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= htmlspecialchars($title ?? 'CannonWMS by ShipCannon') ?>">
    <meta name="twitter:description" content="<?= htmlspecialchars($description ?? 'CannonWMS is a modern, multi-warehouse management system built for eCommerce brands.') ?>">
    <meta name="twitter:image" content="https://shipcannon.com/images/NEW-ShipCannon_header_footer.webp">

    <!-- Google Analytics -->
    <script async src="https://www.googletagmanager.com/gtag/js?id=G-2EVST7MT9R"></script>
    <script>
        window.dataLayer = window.dataLayer || [];
        function gtag(){dataLayer.push(arguments);}
        gtag('js', new Date());
        gtag('config', 'G-2EVST7MT9R');
        gtag('config', 'AW-1060720857');
    </script>

    <script type="application/ld+json">
    {
        "@context": "https://schema.org",
        "@type": "Organization",
        "name": "ShipCannon",
        "url": "https://shipcannon.com",
        "logo": "https://shipcannon.com/images/NEW-ShipCannon_header_footer.webp",
        "telephone": "+1-866-845-7447",
        "email": "info@shipcannon.com"
    }
    </script>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link href="/css/shipcannon.css" rel="stylesheet">

    <?php if (isset($additional_css)): ?>
        <?php foreach ($additional_css as $css): ?>
            <link href="<?= $css ?>" rel="stylesheet">
        <?php endforeach; ?>
    <?php endif; ?>
</head>
<body>
    <?= $header_content ?>

    <main><?= $body_content ?></main>

    <?= $footer_content ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        $(window).scroll(function() {
            $('.sticky-cta').toggleClass('show', $(window).scrollTop() > 500);
        });
        $('a[href*="#"]').on('click', function(e) {
            var target = $(this.hash);
            if (target.length) {
                e.preventDefault();
                $('html, body').animate({ scrollTop: target.offset().top - 80 }, 600);
            }
        });
    </script>

    <?php if (isset($additional_js)): ?>
        <?php foreach ($additional_js as $js): ?>
            <script src="<?= $js ?>"></script>
        <?php endforeach; ?>
    <?php endif; ?>
</body>
</html>

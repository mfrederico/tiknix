<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title><?= htmlspecialchars($title ?? 'App') ?></title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    
    <!-- Custom CSS -->
    <link href="/css/app.css" rel="stylesheet">
    
    <!-- Additional CSS -->
    <?php if (isset($additional_css)): ?>
        <?php foreach ($additional_css as $css): ?>
            <link href="<?= $css ?>" rel="stylesheet">
        <?php endforeach; ?>
    <?php endif; ?>
</head>
<body>
    <!-- Header -->
    <?= $header_content ?>
    
    <!-- Main Content -->
    <main class="flex-shrink-0">
        <?= $body_content ?>
    </main>
    
    <!-- Footer -->
    <?= $footer_content ?>
    
    <!-- Bootstrap 5 JS Bundle (includes Popper) -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- jQuery (optional, but useful) -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    
    <!-- Custom JS -->
    <script src="/js/app.js"></script>
    
    <!-- Flash Messages -->
    <?php if (isset($_SESSION['flash'])): ?>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            <?php foreach ($_SESSION['flash'] as $flash): ?>
                showToast('<?= $flash['type'] ?>', '<?= addslashes($flash['message']) ?>');
            <?php endforeach; ?>
            <?php unset($_SESSION['flash']); ?>
        });
    </script>
    <?php endif; ?>
    
    <!-- Additional JS -->
    <?php if (isset($additional_js)): ?>
        <?php foreach ($additional_js as $js): ?>
            <script src="<?= $js ?>"></script>
        <?php endforeach; ?>
    <?php endif; ?>
</body>
</html>
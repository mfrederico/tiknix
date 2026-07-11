<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrf ?? '', ENT_QUOTES, 'UTF-8') ?>">
    <title><?= htmlspecialchars($title ?? 'App') ?></title>

    <!-- Restore saved theme before paint to avoid a flash -->
    <script>(function(){try{var t=localStorage.getItem('ui-theme');if(t)document.documentElement.setAttribute('data-bs-theme',t);}catch(e){}})();</script>

    <!-- Bootstrap 5.3 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <!-- Custom CSS (tiknix-specific; loaded before the design system so tokens win) -->
    <link href="/css/app.css" rel="stylesheet">
    <!-- Shared design system — MUST load last so its :root overrides win -->
    <?php include __DIR__ . '/../components/design-system.php'; ?>

    <!-- Additional CSS -->
    <?php if (isset($additional_css)): ?>
        <?php foreach ($additional_css as $css): ?>
            <link href="<?= $css ?>" rel="stylesheet">
        <?php endforeach; ?>
    <?php endif; ?>
</head>
<body>
    <!-- Shell: sidebar + topbar open in header, page body, shell closes in footer -->
    <?= $header_content ?>
    <?= $body_content ?>
    <?= $footer_content ?>

    <!-- Bootstrap 5 JS Bundle (includes Popper) -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <!-- jQuery (optional, but useful) -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <!-- Custom JS -->
    <script src="/js/app.js"></script>

    <!-- Flash Messages -->
    <?php if (isset($_SESSION['flash'])): ?>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            <?php foreach ($_SESSION['flash'] as $flash): ?>
                <?php $isLogoutMessage = stripos($flash['message'], 'logged out') !== false; ?>
                <?php if ($isLogoutMessage): ?>
                clearToastHistory();
                <?php endif; ?>
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

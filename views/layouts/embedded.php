<?php /* A page shown inside another page of Tiknix's own (the Builder's Ask opens a connector's panel in a frame): the site's
   styles and scripts, no menus. Used as render(..., 'layouts/embedded'). */ ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars((string) ($title ?? 'Tiknix')) ?></title>
    <script>(function(){try{var t=localStorage.getItem('ui-theme');if(t)document.documentElement.setAttribute('data-bs-theme',t);}catch(e){}})();</script>
    <link href="/rt/vendor/bootstrap-5.3.3/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/rt/vendor/bootstrap-icons-1.11.3/bootstrap-icons.min.css">
    <link href="/rt/css/app.css" rel="stylesheet">
    <?php include \Flight::view()->getTemplate('components/design-system'); ?>
</head>
<body class="bg-body">
    <?= $body_content ?>
    <script src="/rt/vendor/bootstrap-5.3.3/bootstrap.bundle.min.js"></script>
    <script src="/rt/js/app.js"></script>
    <script src="/rt/js/dialogs.js"></script>
</body>
</html>

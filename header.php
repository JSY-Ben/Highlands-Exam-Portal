<?php

$pageTitle = $pageTitle ?? 'Exams Submission Portal';
$brandHref = $brandHref ?? 'index.php';
$brandText = $brandText ?? 'Exams Submission Portal';
$logoPath = $logoPath ?? 'logo.png';
$cssPath = $cssPath ?? 'style.css';
$navActions = $navActions ?? '';
$pageScripts = $pageScripts ?? '';

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo e($pageTitle); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootswatch@5.3.2/dist/lumen/bootstrap.min.css" rel="stylesheet">
    <link href="<?php echo e($cssPath); ?>" rel="stylesheet">
</head>
<body class="bg-light">
<nav class="navbar navbar-expand-lg bg-white border-bottom">
    <div class="container">
        <a class="navbar-brand fw-semibold d-flex align-items-center" href="<?php echo e($brandHref); ?>">
            <img src="<?php echo e($logoPath); ?>" alt="Portal logo" class="brand-logo me-2">
            <?php echo e($brandText); ?>
        </a>
        <?php if ($navActions !== ''): ?>
            <div class="ms-auto d-flex gap-2 align-items-center">
                <?php echo $navActions; ?>
            </div>
        <?php endif; ?>
    </div>
</nav>
<?php if ($pageScripts !== ''): ?>
    <?php echo $pageScripts; ?>
<?php endif; ?>

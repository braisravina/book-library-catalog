<?php
/** @var \DigitalLibrary\View\View $view */
/** @var string $content */
$user = $current_user ?? null;
$authorizer = $authorizer ?? null;
$title = isset($page_title) && $page_title !== null ? $view->t($page_title) . ' · ' : '';
?>
<!doctype html>
<html lang="<?= $view->e($view->locale()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $title ?><?= $view->t('app.name') ?></title>
    <link rel="stylesheet" href="/assets/css/app.css">
    <link rel="icon" href="/assets/img/favicon.svg" type="image/svg+xml">
    <script src="/assets/js/app.js" defer></script>
</head>
<body>
<a class="skip-link" href="#main"><?= $view->t('nav.skip') ?></a>
<header class="site-header">
    <div class="container header-inner">
        <a class="brand" href="/">
            <span class="brand-mark" aria-hidden="true">❦</span>
            <span><?= $view->t('app.name') ?></span>
        </a>
        <?php if ($user !== null): ?>
            <button class="nav-toggle" type="button" aria-expanded="false" aria-controls="primary-nav" data-nav-toggle>
                <span class="visually-hidden"><?= $view->t('nav.menu') ?></span>
                <span aria-hidden="true">☰</span>
            </button>
            <nav id="primary-nav" class="primary-nav" aria-label="<?= $view->t('nav.primary') ?>">
                <ul>
                    <li><a href="/"><?= $view->t('nav.dashboard') ?></a></li>
                    <?php if ($authorizer?->can($user, \DigitalLibrary\Security\Permission::UserManage)): ?>
                        <li><a href="/admin/users"><?= $view->t('nav.users') ?></a></li>
                    <?php endif; ?>
                    <li><a href="/account/password"><?= $view->t('nav.account') ?></a></li>
                    <li>
                        <form method="post" action="/logout" class="inline-form">
                            <?= $view->csrfField() ?>
                            <button type="submit" class="link-button"><?= $view->t('nav.logout') ?></button>
                        </form>
                    </li>
                </ul>
            </nav>
        <?php endif; ?>
    </div>
</header>

<main id="main" class="container" tabindex="-1">
    <?php foreach ($flashes ?? [] as $flash): ?>
        <div class="alert alert-<?= $view->e($flash['type']) ?>" role="status"><?= $view->t($flash['message']) ?></div>
    <?php endforeach; ?>
    <?= $content ?>
</main>

<footer class="site-footer">
    <div class="container footer-inner">
        <p><?= $view->t('app.footer') ?></p>
        <form method="post" action="/locale" class="locale-form">
            <?= $view->csrfField() ?>
            <input type="hidden" name="return_to" value="<?= $view->e($current_path ?? '/') ?>">
            <label for="locale-select"><?= $view->t('nav.language') ?></label>
            <select id="locale-select" name="locale" data-autosubmit>
                <?php foreach (['en' => 'English', 'es' => 'Español', 'gl' => 'Galego'] as $code => $label): ?>
                    <option value="<?= $code ?>" lang="<?= $code ?>"<?= $view->locale() === $code ? ' selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="button button-small" data-autosubmit-fallback><?= $view->t('actions.apply') ?></button>
        </form>
    </div>
</footer>
</body>
</html>

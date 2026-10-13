<?php
/**
 * @var \DigitalLibrary\View\View $view
 * @var array<string, int> $stats  @var int $my_copies  @var list<array<string, mixed>> $recent
 * @var \DigitalLibrary\Auth\User $current_user
 */
$canCreate = $authorizer->can($current_user, \DigitalLibrary\Security\Permission::CatalogueCreate);
?>
<h1><?= $view->t('dashboard.welcome', ['name' => $current_user->displayName]) ?></h1>
<p class="muted"><?= $view->t('dashboard.role') ?> <strong><?= $view->t($current_user->role->labelKey()) ?></strong></p>

<form method="get" action="/books" class="search-form" role="search">
    <div class="search-main">
        <label for="dashboard-q" class="visually-hidden"><?= $view->t('catalogue.search.label') ?></label>
        <input id="dashboard-q" name="q" type="search" maxlength="200" placeholder="<?= $view->t('catalogue.search.placeholder') ?>">
        <button type="submit" class="button button-primary"><?= $view->t('catalogue.search.submit') ?></button>
        <?php if ($canCreate): ?><a class="button" href="/books/new"><?= $view->t('nav.add_book') ?></a><?php endif; ?>
    </div>
</form>

<section aria-labelledby="stats-heading">
    <h2 id="stats-heading" class="visually-hidden"><?= $view->t('dashboard.stats') ?></h2>
    <dl class="stat-grid">
        <?php foreach (['works', 'editions', 'copies', 'authors'] as $key): ?>
            <div class="stat">
                <dt><?= $view->t('dashboard.' . $key) ?></dt>
                <dd><?= $view->e($stats[$key] ?? 0) ?></dd>
            </div>
        <?php endforeach; ?>
        <div class="stat">
            <dt><?= $view->t('dashboard.my_copies') ?></dt>
            <dd><a href="<?= $view->e($view->url('/books', ['owner' => $current_user->id])) ?>"><?= $view->e($my_copies) ?></a></dd>
        </div>
    </dl>
</section>

<section aria-labelledby="recent-heading">
    <div class="page-header">
        <h2 id="recent-heading"><?= $view->t('dashboard.recent') ?></h2>
        <a href="<?= $view->e($view->url('/books', ['sort' => 'recent'])) ?>"><?= $view->t('dashboard.see_all') ?></a>
    </div>
    <?php if ($recent === []): ?>
        <div class="notice"><p><?= $view->t('catalogue.empty') ?></p></div>
    <?php else: ?>
        <ul class="cover-grid">
            <?php foreach ($recent as $book): ?>
                <?= $view->partial('partials/book_card', ['book' => $book]) ?>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

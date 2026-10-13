<?php
/** @var \DigitalLibrary\View\View $view */
/** @var array<string, int> $stats */
/** @var int $my_copies */
/** @var \DigitalLibrary\Auth\User $current_user */
?>
<h1><?= $view->t('dashboard.welcome', ['name' => $current_user->displayName]) ?></h1>
<p class="muted"><?= $view->t('dashboard.role') ?> <strong><?= $view->t($current_user->role->labelKey()) ?></strong></p>

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
            <dd><?= $view->e($my_copies) ?></dd>
        </div>
    </dl>
</section>

<section class="notice" aria-labelledby="next-heading">
    <h2 id="next-heading"><?= $view->t('dashboard.coming_title') ?></h2>
    <p><?= $view->t('dashboard.coming_body') ?></p>
</section>

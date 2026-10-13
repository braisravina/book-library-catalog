<?php
/**
 * @var \DigitalLibrary\View\View $view  @var array<string, mixed> $author
 * @var list<array<string, mixed>> $works  @var list<array<string, mixed>> $contributions  @var bool $can_edit
 */
?>
<p class="eyebrow"><a href="/authors"><?= $view->t('catalogue.authors.title') ?></a></p>
<div class="page-header">
    <h1><?= $view->e($author['name']) ?></h1>
    <?php if ($can_edit): ?><a class="button" href="/authors/<?= (int) $author['id'] ?>/edit"><?= $view->t('actions.edit') ?></a><?php endif; ?>
</div>
<?php if ($author['birth_year'] !== null || $author['death_year'] !== null): ?>
    <p class="muted"><?= $view->e(($author['birth_year'] ?? '?') . '–' . ($author['death_year'] ?? '')) ?></p>
<?php endif; ?>
<?php if ($author['notes'] !== null): ?><div class="description"><?= nl2br($view->e($author['notes'])) ?></div><?php endif; ?>

<h2><?= $view->t('catalogue.authors.works') ?></h2>
<?php if ($works === []): ?>
    <p class="muted"><?= $view->t('catalogue.authors.no_works') ?></p>
<?php else: ?>
    <ul class="simple-list">
        <?php foreach ($works as $work): ?>
            <li><a href="/works/<?= (int) $work['id'] ?>"><?= $view->e($work['title']) ?></a>
                <span class="muted"><?= $work['role'] !== 'author' ? $view->t('person_roles.' . $work['role']) . ' · ' : '' ?><?= $view->t('catalogue.work.editions', ['count' => (int) $work['edition_count']]) ?></span></li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
<?php if ($contributions !== []): ?>
    <h2><?= $view->t('catalogue.authors.contributions') ?></h2>
    <ul class="simple-list">
        <?php foreach ($contributions as $c): ?>
            <li><a href="/editions/<?= (int) $c['id'] ?>"><?= $view->e($c['title']) ?></a> <span class="muted"><?= $view->t('person_roles.' . $c['role']) ?></span></li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

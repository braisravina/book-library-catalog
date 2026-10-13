<?php
/** @var \DigitalLibrary\View\View $view  @var array<string, mixed> $publisher  @var list<array<string, mixed>> $editions  @var bool $can_edit */
?>
<p class="eyebrow"><a href="/publishers"><?= $view->t('catalogue.publishers.title') ?></a></p>
<div class="page-header">
    <h1><?= $view->e($publisher['name']) ?></h1>
    <?php if ($can_edit): ?><a class="button" href="/publishers/<?= (int) $publisher['id'] ?>/edit"><?= $view->t('actions.edit') ?></a><?php endif; ?>
</div>
<ul class="simple-list">
    <?php foreach ($editions as $e): ?>
        <li><a href="/editions/<?= (int) $e['id'] ?>"><?= $view->e($e['title']) ?></a> <span class="muted"><?= $view->e($e['publication_year'] ?? '') ?></span></li>
    <?php endforeach; ?>
</ul>

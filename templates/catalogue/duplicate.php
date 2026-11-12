<?php
/**
 * @var \DigitalLibrary\View\View $view  @var array<string, mixed> $edition
 * @var list<array<string, mixed>> $authors  @var int $copy_count  @var bool $can_add_copy
 */
$names = implode(', ', array_column(array_filter($authors, static fn (array $a): bool => $a['role'] === 'author'), 'name'));
?>
<section class="form-card">
    <h1><?= $view->t('catalogue.duplicate.title') ?></h1>
    <div class="alert alert-info" role="status"><?= $view->t('catalogue.duplicate.message', ['isbn' => (string) $edition['isbn_13']]) ?></div>
    <div class="duplicate-summary">
        <p class="duplicate-title"><strong><?= $view->e($edition['title']) ?></strong></p>
        <?php if ($names !== ''): ?><p><?= $view->e($names) ?></p><?php endif; ?>
        <p class="muted">
            <?= $view->e(implode(' · ', array_filter([(string) ($edition['publisher_name'] ?? ''), (string) ($edition['publication_year'] ?? '')]))) ?>
        </p>
        <p><?= $view->t('catalogue.duplicate.copies', ['count' => $copy_count]) ?></p>
    </div>
    <div class="form-actions">
        <a class="button button-primary" href="/editions/<?= (int) $edition['id'] ?>"><?= $view->t('catalogue.duplicate.view') ?></a>
        <?php if ($can_add_copy): ?>
            <a class="button" href="/editions/<?= (int) $edition['id'] ?>/copies/new"><?= $view->t('catalogue.duplicate.add_copy') ?></a>
        <?php endif; ?>
        <a class="button" href="/books/new"><?= $view->t('catalogue.duplicate.other_isbn') ?></a>
    </div>
</section>

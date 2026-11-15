<?php
/**
 * @var \DigitalLibrary\View\View $view  @var array<string, mixed> $edition
 * @var list<array<string, mixed>> $authors  @var int $copy_count  @var bool $can_add_copy
 * @var bool $visible  false when the edition is outside the user's view: bibliographic description only
 * @var string $add_copy_url  by edition for a visible edition, by ISBN otherwise (never an id the user cannot see)
 */
$names = implode(', ', array_column(array_filter($authors, static fn (array $a): bool => $a['role'] === 'author'), 'name'));
?>
<section class="form-card">
    <h1><?= $view->t('catalogue.duplicate.title') ?></h1>
    <div class="alert alert-info" role="status"><?= $view->t($visible ? 'catalogue.duplicate.message' : ($can_add_copy ? 'catalogue.duplicate.shared_add' : 'catalogue.duplicate.shared'), ['isbn' => (string) $edition['isbn_13']]) ?></div>
    <div class="duplicate-summary">
        <p class="duplicate-title"><strong><?= $view->e($edition['title']) ?></strong></p>
        <?php if ($names !== ''): ?><p><?= $view->e($names) ?></p><?php endif; ?>
        <p class="muted">
            <?= $view->e(implode(' · ', array_filter([(string) ($edition['publisher_name'] ?? ''), (string) ($edition['publication_year'] ?? '')]))) ?>
        </p>
        <?php if ($visible): ?><p><?= $view->t('catalogue.duplicate.copies', ['count' => $copy_count]) ?></p><?php endif; ?>
    </div>
    <div class="form-actions">
        <?php if ($visible): ?><a class="button button-primary" href="/editions/<?= (int) $edition['id'] ?>"><?= $view->t('catalogue.duplicate.view') ?></a><?php endif; ?>
        <?php if ($can_add_copy): ?>
            <a class="button<?= $visible ? '' : ' button-primary' ?>" href="<?= $view->e($add_copy_url) ?>"><?= $view->t($visible ? 'catalogue.duplicate.add_copy' : 'catalogue.duplicate.add_own_copy') ?></a>
        <?php endif; ?>
        <a class="button" href="/books/new"><?= $view->t('catalogue.duplicate.other_isbn') ?></a>
    </div>
</section>

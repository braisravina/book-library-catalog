<?php
/**
 * Edit an edition, or add a new edition to an existing work.
 * @var \DigitalLibrary\View\View $view  @var array<string, mixed> $edition
 * @var \DigitalLibrary\Catalogue\Input\EditionInput $input  @var array<string, list<string>> $errors
 * @var int|null $duplicate_id  @var string $action  @var string $cancel
 */
?>
<section class="form-card form-card-wide">
    <h1><?= $view->t($page_title) ?></h1>
    <p class="muted"><?= $view->t('catalogue.edition.of_work') ?> <a href="/works/<?= (int) $edition['work_id'] ?>"><?= $view->e($edition['work_title']) ?></a></p>
    <?php if (!empty($conflict)): ?>
        <div class="alert alert-error" role="alert"><?= $view->t('catalogue.edit_conflict') ?> <a href="/editions/<?= (int) ($edition['id'] ?? 0) ?>" target="_blank" rel="noopener"><?= $view->t('catalogue.edit_conflict_view') ?></a></div>
    <?php endif; ?>
    <?php if ($duplicate_id !== null): ?>
        <div class="alert alert-error" role="alert">
            <?= $view->t('validation.isbn_taken') ?> <a href="/editions/<?= (int) $duplicate_id ?>"><?= $view->t('catalogue.duplicate.view') ?></a>
        </div>
    <?php elseif ($errors !== []): ?>
        <div class="alert alert-error" role="alert"><?= $view->t('validation.summary') ?></div>
    <?php endif; ?>
    <form method="post" action="<?= $view->e($action) ?>" class="form" novalidate>
        <?= $view->csrfField() ?>
        <?php if (isset($edition['row_version'])): ?><input type="hidden" name="row_version" value="<?= (int) $edition['row_version'] ?>"><?php endif; ?>
        <?= $view->partial('catalogue/_edition_fields', ['edition' => $input, 'errors' => $errors, 'combined' => false]) ?>
        <div class="form-actions">
            <button type="submit" class="button button-primary"><?= $view->t('actions.save') ?></button>
            <a class="button" href="<?= $view->e($cancel) ?>"><?= $view->t('actions.cancel') ?></a>
        </div>
    </form>
    <?= $view->partial('catalogue/_datalists', ['author_names' => $author_names, 'publisher_names' => $publisher_names]) ?>
</section>

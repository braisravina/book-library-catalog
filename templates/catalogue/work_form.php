<?php
/**
 * @var \DigitalLibrary\View\View $view  @var array<string, mixed> $work
 * @var \DigitalLibrary\Catalogue\Input\WorkInput $input  @var array<string, list<string>> $errors
 */
?>
<section class="form-card form-card-wide">
    <h1><?= $view->t('catalogue.work.edit_title') ?></h1>
    <p class="hint"><?= $view->t('catalogue.work.edit_hint') ?></p>
    <?php if (!empty($conflict)): ?>
        <div class="alert alert-error" role="alert"><?= $view->t('catalogue.edit_conflict') ?> <a href="/works/<?= (int) $work['id'] ?>" target="_blank" rel="noopener"><?= $view->t('catalogue.edit_conflict_view') ?></a></div>
    <?php endif; ?>
    <?php if ($errors !== []): ?>
        <div class="alert alert-error" role="alert"><?= $view->t('validation.summary') ?></div>
    <?php endif; ?>
    <form method="post" action="/works/<?= (int) $work['id'] ?>" class="form" novalidate>
        <?= $view->csrfField() ?>
        <input type="hidden" name="row_version" value="<?= (int) $work['row_version'] ?>">
        <?= $view->partial('catalogue/_work_fields', ['work' => $input, 'errors' => $errors, 'category_tree' => $category_tree]) ?>
        <div class="form-actions">
            <button type="submit" class="button button-primary"><?= $view->t('actions.save') ?></button>
            <a class="button" href="/works/<?= (int) $work['id'] ?>"><?= $view->t('actions.cancel') ?></a>
        </div>
    </form>
    <?= $view->partial('catalogue/_datalists', ['author_names' => $author_names, 'publisher_names' => $publisher_names]) ?>
</section>

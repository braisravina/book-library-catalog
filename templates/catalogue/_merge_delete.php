<?php
/**
 * Merge/delete controls for shared entities (authors, publishers).
 * @var \DigitalLibrary\View\View $view  @var string $base  e.g. "/authors/12"
 * @var list<array{id: int, name: string}> $options  @var int $usage  @var string $entity "authors"|"publishers"
 */
?>
<section class="form-card" aria-labelledby="merge-heading">
    <h2 id="merge-heading"><?= $view->t('catalogue.merge.title') ?></h2>
    <p class="hint"><?= $view->t('catalogue.merge.hint_' . $entity) ?></p>
    <?php if ($options === []): ?>
        <p class="muted"><?= $view->t('catalogue.merge.no_targets') ?></p>
    <?php else: ?>
        <form method="post" action="<?= $view->e($base . '/merge') ?>" class="form" data-confirm="<?= $view->t('catalogue.merge.confirm') ?>">
            <?= $view->csrfField() ?>
            <div class="form-field">
                <label for="target_id"><?= $view->t('catalogue.merge.target') ?></label>
                <select id="target_id" name="target_id" required>
                    <option value=""><?= $view->t('catalogue.merge.choose') ?></option>
                    <?php foreach ($options as $option): ?>
                        <option value="<?= (int) $option['id'] ?>"><?= $view->e($option['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="button danger"><?= $view->t('catalogue.merge.submit') ?></button>
        </form>
    <?php endif; ?>
</section>
<?php if ($usage === 0): ?>
    <section class="danger-zone">
        <form method="post" action="<?= $view->e($base . '/delete') ?>" class="inline-form" data-confirm="<?= $view->t('catalogue.merge.confirm_delete') ?>">
            <?= $view->csrfField() ?>
            <button type="submit" class="button danger"><?= $view->t('catalogue.merge.delete_unused') ?></button>
        </form>
    </section>
<?php endif; ?>

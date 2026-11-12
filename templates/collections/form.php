<?php
/**
 * @var \DigitalLibrary\View\View $view  @var array<string, mixed>|null $collection  @var \DigitalLibrary\Collections\CollectionInput $input
 * @var array<string, list<string>> $errors  @var list<string> $visibilities
 */
$action = $collection === null ? '/collections' : '/collections/' . (int) $collection['id'];
?>
<section class="form-card">
    <h1><?= $view->t($collection === null ? 'collections.new' : 'collections.edit') ?></h1>
    <?php if ($errors !== []): ?><div class="alert alert-error" role="alert"><?= $view->t('validation.summary') ?></div><?php endif; ?>
    <form method="post" action="<?= $view->e($action) ?>" class="form" novalidate>
        <?= $view->csrfField() ?>
        <?= $view->partial('partials/input', ['name' => 'name', 'label' => 'fields.name', 'value' => $input->name, 'errors' => $errors, 'maxlength' => 150, 'required' => true]) ?>
        <?= $view->partial('partials/input', ['name' => 'description', 'label' => 'fields.description', 'value' => $input->description, 'errors' => $errors, 'type' => 'textarea', 'rows' => 3, 'maxlength' => 2000]) ?>
        <fieldset>
            <legend><?= $view->t('collections.fields.visibility') ?></legend>
            <?php foreach ($visibilities as $v): ?>
                <label class="checkbox"><input type="radio" name="visibility" value="<?= $v ?>"<?= $input->visibility === $v ? ' checked' : '' ?>> <?= $view->t('collections.visibility.' . $v) ?></label>
                <p class="hint"><?= $view->t('collections.visibility_hint.' . $v) ?></p>
            <?php endforeach; ?>
            <?= $view->partial('partials/field_errors', ['messages' => $errors['visibility'] ?? [], 'id' => 'visibility-errors']) ?>
        </fieldset>
        <div class="form-actions">
            <button type="submit" class="button button-primary"><?= $view->t($collection === null ? 'actions.create' : 'actions.save') ?></button>
            <a class="button" href="<?= $collection === null ? '/collections' : '/collections/' . (int) $collection['id'] ?>"><?= $view->t('actions.cancel') ?></a>
        </div>
    </form>
</section>
<?php if ($collection !== null): ?>
    <section class="danger-zone" aria-labelledby="delete-heading">
        <h2 id="delete-heading"><?= $view->t('collections.delete.title') ?></h2>
        <p><?= $view->t('collections.delete.explain', ['count' => (int) $collection['item_count']]) ?></p>
        <form method="post" action="/collections/<?= (int) $collection['id'] ?>/delete" class="form" data-confirm="<?= $view->t('collections.delete.title') ?>">
            <?= $view->csrfField() ?>
            <label class="checkbox confirm"><input type="checkbox" name="confirm" value="1" required> <?= $view->t('collections.delete.confirm') ?></label>
            <div class="form-actions"><button type="submit" class="button danger"><?= $view->t('collections.delete.submit') ?></button></div>
        </form>
    </section>
<?php endif; ?>

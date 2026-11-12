<?php
/**
 * @var \DigitalLibrary\View\View $view  @var array<string, mixed> $author
 * @var array<string, string> $old  @var array<string, list<string>> $errors
 */
?>
<section class="form-card">
    <h1><?= $view->t('catalogue.authors.edit_title') ?></h1>
    <p class="hint"><?= $view->t('catalogue.authors.edit_hint') ?></p>
    <form method="post" action="/authors/<?= (int) $author['id'] ?>" class="form" novalidate>
        <?= $view->csrfField() ?>
        <?= $view->partial('partials/input', ['name' => 'name', 'label' => 'fields.name', 'value' => $old['name'], 'errors' => $errors, 'required' => true, 'maxlength' => 255]) ?>
        <?= $view->partial('partials/input', ['name' => 'sort_name', 'label' => 'fields.sort_name', 'value' => $old['sort_name'], 'errors' => $errors, 'required' => true, 'maxlength' => 255, 'hint' => 'catalogue.authors.sort_hint']) ?>
        <div class="form-row">
            <?= $view->partial('partials/input', ['name' => 'birth_year', 'label' => 'fields.birth_year', 'value' => $old['birth_year'], 'errors' => $errors, 'inputmode' => 'numeric', 'maxlength' => 5]) ?>
            <?= $view->partial('partials/input', ['name' => 'death_year', 'label' => 'fields.death_year', 'value' => $old['death_year'], 'errors' => $errors, 'inputmode' => 'numeric', 'maxlength' => 5]) ?>
        </div>
        <?= $view->partial('partials/input', ['name' => 'notes', 'label' => 'fields.notes', 'value' => $old['notes'], 'errors' => $errors, 'type' => 'textarea', 'maxlength' => 5000]) ?>
        <div class="form-actions">
            <button type="submit" class="button button-primary"><?= $view->t('actions.save') ?></button>
            <a class="button" href="/authors/<?= (int) $author['id'] ?>"><?= $view->t('actions.cancel') ?></a>
        </div>
    </form>
</section>
<?= $view->partial('catalogue/_merge_delete', ['base' => '/authors/' . (int) $author['id'], 'options' => $merge_options, 'usage' => $usage, 'entity' => 'authors']) ?>

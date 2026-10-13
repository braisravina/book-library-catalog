<?php
/** @var \DigitalLibrary\View\View $view  @var array<string, mixed> $publisher  @var string $name  @var array<string, list<string>> $errors */
?>
<section class="form-card">
    <h1><?= $view->t('catalogue.publishers.edit_title') ?></h1>
    <form method="post" action="/publishers/<?= (int) $publisher['id'] ?>" class="form" novalidate>
        <?= $view->csrfField() ?>
        <?= $view->partial('partials/input', ['name' => 'name', 'label' => 'fields.name', 'value' => $name, 'errors' => $errors, 'required' => true, 'maxlength' => 255]) ?>
        <div class="form-actions">
            <button type="submit" class="button button-primary"><?= $view->t('actions.save') ?></button>
            <a class="button" href="/publishers/<?= (int) $publisher['id'] ?>"><?= $view->t('actions.cancel') ?></a>
        </div>
    </form>
</section>

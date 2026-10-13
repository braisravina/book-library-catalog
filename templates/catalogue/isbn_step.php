<?php
/** @var \DigitalLibrary\View\View $view  @var string $isbn  @var string|null $error */
?>
<section class="form-card">
    <h1><?= $view->t('catalogue.add.title') ?></h1>
    <p><?= $view->t('catalogue.add.isbn_intro') ?></p>
    <form method="get" action="/books/new" class="form" novalidate>
        <div class="form-field">
            <label for="isbn"><?= $view->t('fields.isbn') ?></label>
            <input id="isbn" name="isbn" type="text" inputmode="numeric" autocomplete="off" maxlength="32" required autofocus
                   value="<?= $view->e($isbn) ?>"<?= $error !== null ? ' aria-invalid="true" aria-describedby="isbn-errors"' : ' aria-describedby="isbn-hint"' ?>>
            <p class="hint" id="isbn-hint"><?= $view->t('catalogue.add.isbn_hint') ?></p>
            <?php if ($error !== null): ?>
                <?= $view->partial('partials/field_errors', ['messages' => [$error], 'id' => 'isbn-errors']) ?>
            <?php endif; ?>
        </div>
        <div class="form-actions">
            <button type="submit" class="button button-primary"><?= $view->t('catalogue.add.check_isbn') ?></button>
            <a class="button" href="/books/new?manual=1"><?= $view->t('catalogue.add.without_isbn') ?></a>
        </div>
    </form>
</section>

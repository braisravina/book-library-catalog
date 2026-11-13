<?php
/**
 * ISBN step of "Add a book". Works without JavaScript (the server validates
 * and explains); with it, app.js checks the ISBN as it is typed, using the
 * data-msg-* messages below, and shows an accessible loading state.
 * @var \DigitalLibrary\View\View $view  @var string $isbn
 * @var array{string, array<string, int>}|null $error  translation key and parameters
 * @var bool $lookup_enabled
 */
$describedBy = 'isbn-hint' . ($error !== null ? ' isbn-errors' : '') . ' isbn-check';
?>
<section class="form-card">
    <h1><?= $view->t('catalogue.add.title') ?></h1>
    <p><?= $view->t('catalogue.add.isbn_intro') ?></p>
    <form method="get" action="/books/new" class="form isbn-form" novalidate data-isbn-form>
        <div class="form-field">
            <label for="isbn"><?= $view->t('fields.isbn') ?></label>
            <input id="isbn" name="isbn" type="text" inputmode="text" autocapitalize="characters" autocorrect="off" spellcheck="false"
                   enterkeyhint="<?= $lookup_enabled ? 'search' : 'go' ?>" autocomplete="off" maxlength="32" required autofocus
                   value="<?= $view->e($isbn) ?>" aria-describedby="<?= $describedBy ?>"<?= $error !== null ? ' aria-invalid="true"' : '' ?>
                   data-isbn-input
                   data-msg-required="<?= $view->t('validation.isbn_required') ?>"
                   data-msg-characters="<?= $view->t('validation.isbn_characters') ?>"
                   data-msg-length="<?= $view->t('validation.isbn_length', ['count' => ':count']) ?>"
                   data-msg-prefix="<?= $view->t('validation.isbn_prefix') ?>"
                   data-msg-checksum="<?= $view->t('validation.isbn_checksum') ?>"
                   data-msg-valid10="<?= $view->t('catalogue.add.isbn_valid_10') ?>"
                   data-msg-valid13="<?= $view->t('catalogue.add.isbn_valid_13') ?>">
            <p class="hint" id="isbn-hint"><?= $view->t('catalogue.add.isbn_hint') ?></p>
            <?php if ($error !== null): ?>
                <ul class="field-errors" id="isbn-errors" data-isbn-server-error>
                    <li><?= $view->t($error[0], $error[1]) ?></li>
                </ul>
            <?php endif; ?>
            <p class="isbn-check" id="isbn-check" aria-live="polite" data-isbn-check></p>
        </div>
        <div class="form-actions">
            <button type="submit" class="button button-primary" data-isbn-lookup
                    data-busy-label="<?= $view->t('catalogue.add.look_up_busy') ?>"><?= $view->t($lookup_enabled ? 'catalogue.add.look_up' : 'catalogue.add.check_isbn') ?></button>
            <?php if ($lookup_enabled): ?>
                <button type="submit" class="button" name="manual" value="1" formnovalidate data-isbn-manual aria-describedby="manual-hint"><?= $view->t('catalogue.add.enter_manually') ?></button>
            <?php endif; ?>
            <a class="button" href="/books/new?manual=1"><?= $view->t('catalogue.add.without_isbn') ?></a>
        </div>
        <?php if ($lookup_enabled): ?>
            <p class="hint" id="manual-hint"><?= $view->t('catalogue.add.manual_hint') ?></p>
        <?php endif; ?>
        <p class="lookup-status" role="status" data-lookup-status data-message="<?= $view->t('catalogue.add.looking_up') ?>"></p>
    </form>
</section>

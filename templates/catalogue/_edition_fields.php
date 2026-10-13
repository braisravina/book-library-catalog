<?php
/**
 * Edition-level fields. In the combined new-book form the edition title is
 * optional (it defaults to the work title), so it is shown in a details box.
 * @var \DigitalLibrary\View\View $view  @var \DigitalLibrary\Catalogue\Input\EditionInput $edition
 * @var array<string, list<string>> $errors  @var bool $combined  @var list<string> $publisher_names
 */
$titleFields = $view->partial('partials/input', ['name' => 'edition_title', 'label' => 'fields.edition_title', 'value' => $combined && $edition->title === ($work_title ?? null) ? '' : $edition->title, 'errors' => $errors, 'required' => !$combined, 'maxlength' => 500, 'hint' => $combined ? 'catalogue.edition.title_hint' : null])
    . $view->partial('partials/input', ['name' => 'edition_subtitle', 'label' => 'fields.edition_subtitle', 'value' => $combined && $edition->subtitle === ($work_subtitle ?? null) ? '' : $edition->subtitle, 'errors' => $errors, 'maxlength' => 500]);
?>
<?= $view->partial('partials/input', ['name' => 'isbn', 'label' => 'fields.isbn', 'value' => $edition->isbn, 'errors' => $errors, 'inputmode' => 'numeric', 'maxlength' => 32, 'autocomplete' => 'off', 'hint' => 'catalogue.edition.isbn_hint']) ?>
<?php if ($combined): ?>
    <details class="subsection"<?= isset($errors['edition_title']) || isset($errors['edition_subtitle']) ? ' open' : '' ?>>
        <summary><?= $view->t('catalogue.edition.title_differs') ?></summary>
        <?= $titleFields ?>
    </details>
<?php else: ?>
    <?= $titleFields ?>
<?php endif; ?>
<div class="form-row">
    <?= $view->partial('partials/input', ['name' => 'publisher', 'label' => 'fields.publisher', 'value' => $edition->publisher, 'errors' => $errors, 'maxlength' => 255, 'list' => 'publisher-names']) ?>
    <?= $view->partial('partials/input', ['name' => 'publication_year', 'label' => 'fields.publication_year', 'value' => $edition->publicationYear, 'errors' => $errors, 'inputmode' => 'numeric', 'maxlength' => 5]) ?>
</div>
<div class="form-row">
    <div class="form-field">
        <label for="language"><?= $view->t('fields.language') ?></label>
        <select id="language" name="language">
            <option value=""><?= $view->t('catalogue.unspecified') ?></option>
            <?php foreach (\DigitalLibrary\Catalogue\Vocabulary::LANGUAGES as $code): ?>
                <option value="<?= $code ?>"<?= $edition->language === $code ? ' selected' : '' ?>><?= $view->t('languages.' . $code) ?></option>
            <?php endforeach; ?>
        </select>
        <?= $view->partial('partials/field_errors', ['messages' => $errors['language'] ?? [], 'id' => 'language-errors']) ?>
    </div>
    <div class="form-field">
        <label for="format"><?= $view->t('fields.format') ?></label>
        <select id="format" name="format">
            <option value=""><?= $view->t('catalogue.unspecified') ?></option>
            <?php foreach (\DigitalLibrary\Catalogue\Vocabulary::FORMATS as $format): ?>
                <option value="<?= $format ?>"<?= $edition->format === $format ? ' selected' : '' ?>><?= $view->t('formats.' . $format) ?></option>
            <?php endforeach; ?>
        </select>
        <?= $view->partial('partials/field_errors', ['messages' => $errors['format'] ?? [], 'id' => 'format-errors']) ?>
    </div>
    <?= $view->partial('partials/input', ['name' => 'page_count', 'label' => 'fields.page_count', 'value' => $edition->pageCount, 'errors' => $errors, 'inputmode' => 'numeric', 'maxlength' => 5]) ?>
</div>
<div class="form-row">
    <?= $view->partial('partials/input', ['name' => 'edition_statement', 'label' => 'fields.edition_statement', 'value' => $edition->editionStatement, 'errors' => $errors, 'maxlength' => 255, 'hint' => 'catalogue.edition.statement_hint']) ?>
    <?= $view->partial('partials/input', ['name' => 'legal_deposit', 'label' => 'fields.legal_deposit', 'value' => $edition->legalDeposit, 'errors' => $errors, 'maxlength' => 64]) ?>
</div>
<?= $view->partial('partials/people_fields', ['prefix' => 'contributor', 'legend' => 'fields.contributors', 'people' => $edition->contributors, 'roles' => \DigitalLibrary\Catalogue\Repository\EditionRepository::CONTRIBUTOR_ROLES, 'errors' => $errors, 'errorKey' => 'contributors']) ?>
<?= $view->partial('partials/input', ['name' => 'edition_description', 'label' => 'fields.edition_description', 'value' => $edition->description, 'errors' => $errors, 'type' => 'textarea', 'maxlength' => 10000]) ?>

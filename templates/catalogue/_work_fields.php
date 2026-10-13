<?php
/**
 * Work-level fields shared by the new-book and edit-work forms.
 * @var \DigitalLibrary\View\View $view  @var \DigitalLibrary\Catalogue\Input\WorkInput $work
 * @var array<string, list<string>> $errors  @var list<array<string, mixed>> $category_tree
 */
?>
<?= $view->partial('partials/input', ['name' => 'title', 'label' => 'fields.title', 'value' => $work->title, 'errors' => $errors, 'required' => true, 'maxlength' => 500]) ?>
<?= $view->partial('partials/input', ['name' => 'subtitle', 'label' => 'fields.subtitle', 'value' => $work->subtitle, 'errors' => $errors, 'maxlength' => 500]) ?>
<?= $view->partial('partials/people_fields', ['prefix' => 'author', 'legend' => 'fields.authors', 'people' => $work->authors, 'roles' => \DigitalLibrary\Catalogue\Vocabulary::AUTHOR_ROLES, 'errors' => $errors, 'errorKey' => 'authors']) ?>
<div class="form-row">
    <?= $view->partial('partials/input', ['name' => 'original_title', 'label' => 'fields.original_title', 'value' => $work->originalTitle, 'errors' => $errors, 'maxlength' => 500]) ?>
    <div class="form-field">
        <label for="original_language"><?= $view->t('fields.original_language') ?></label>
        <select id="original_language" name="original_language">
            <option value=""><?= $view->t('catalogue.unspecified') ?></option>
            <?php foreach (\DigitalLibrary\Catalogue\Vocabulary::LANGUAGES as $code): ?>
                <option value="<?= $code ?>"<?= $work->originalLanguage === $code ? ' selected' : '' ?>><?= $view->t('languages.' . $code) ?></option>
            <?php endforeach; ?>
        </select>
        <?= $view->partial('partials/field_errors', ['messages' => $errors['original_language'] ?? [], 'id' => 'original_language-errors']) ?>
    </div>
    <?= $view->partial('partials/input', ['name' => 'first_published_year', 'label' => 'fields.first_published_year', 'value' => $work->firstPublishedYear, 'errors' => $errors, 'inputmode' => 'numeric', 'maxlength' => 5]) ?>
</div>
<?php if ($category_tree !== []): ?>
    <fieldset class="checkbox-tree">
        <legend><?= $view->t('fields.categories') ?></legend>
        <?php foreach ($category_tree as $node): ?>
            <label class="checkbox depth-<?= min(4, (int) $node['depth']) ?>">
                <input type="checkbox" name="categories[]" value="<?= (int) $node['id'] ?>"<?= in_array((int) $node['id'], $work->categoryIds, true) ? ' checked' : '' ?>>
                <?= $view->e($node['name']) ?>
            </label>
        <?php endforeach; ?>
        <?= $view->partial('partials/field_errors', ['messages' => $errors['categories'] ?? [], 'id' => 'categories-errors']) ?>
    </fieldset>
<?php endif; ?>
<?= $view->partial('partials/input', ['name' => 'work_description', 'label' => 'fields.work_description', 'value' => $work->description, 'errors' => $errors, 'type' => 'textarea', 'maxlength' => 10000]) ?>

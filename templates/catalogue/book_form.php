<?php
/**
 * @var \DigitalLibrary\View\View $view
 * @var \DigitalLibrary\Catalogue\Input\WorkInput $work  @var \DigitalLibrary\Catalogue\Input\EditionInput $edition
 * @var array<string, list<string>> $errors  @var list<array<string, mixed>> $title_matches
 * @var bool $add_copy  @var \DigitalLibrary\Catalogue\Input\CopyInput|null $copy  @var bool $can_add_copy
 * @var list<array<string, mixed>> $location_tree
 */
?>
<section class="form-card form-card-wide">
    <h1><?= $view->t('catalogue.add.title') ?></h1>
    <?php if ($errors !== []): ?>
        <div class="alert alert-error" role="alert"><?= $view->t('validation.summary') ?></div>
    <?php endif; ?>
    <?php if ($lookup !== null): ?>
        <?php if ($lookup['found']): ?>
            <div class="alert alert-info" role="status">
                <?php if ($lookup['sources'] !== []): ?>
                    <?= $view->t('metadata.found', ['sources' => implode(', ', array_map(static fn (string $s): string => $view->trans('metadata.providers.' . $s), $lookup['sources']))]) ?>
                <?php else: ?>
                    <?= $view->t('metadata.found_cached') ?>
                <?php endif; ?>
                <?= $view->t('metadata.review') ?>
            </div>
        <?php elseif ($lookup['failures'] !== []): ?>
            <div class="alert alert-info" role="status"><?= $view->t('metadata.unavailable') ?></div>
        <?php else: ?>
            <div class="alert alert-info" role="status"><?= $view->t('metadata.not_found') ?></div>
        <?php endif; ?>
    <?php endif; ?>
    <?php if ($title_matches !== []): ?>
        <div class="alert alert-info" role="alert">
            <p><strong><?= $view->t('catalogue.possible_duplicates.title') ?></strong></p>
            <p><?= $view->t('catalogue.possible_duplicates.message') ?></p>
            <ul>
                <?php foreach ($title_matches as $match): ?>
                    <li><a href="/editions/<?= (int) $match['id'] ?>"><?= $view->e($match['title']) ?></a>
                        <span class="muted"><?= $view->e(implode(' · ', array_filter([(string) ($match['publisher_name'] ?? ''), (string) ($match['publication_year'] ?? '')]))) ?></span></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
    <form method="post" action="/books" class="form" novalidate>
        <?= $view->csrfField() ?>
        <?php if ($lookup !== null && $lookup['record_id'] !== null): ?>
            <input type="hidden" name="metadata_record_id" value="<?= (int) $lookup['record_id'] ?>">
        <?php endif; ?>
        <h2><?= $view->t('catalogue.work.section') ?></h2>
        <?= $view->partial('catalogue/_work_fields', ['work' => $work, 'errors' => $errors, 'category_tree' => $category_tree]) ?>

        <h2><?= $view->t('catalogue.edition.section') ?></h2>
        <?= $view->partial('catalogue/_edition_fields', ['edition' => $edition, 'errors' => $errors, 'combined' => true, 'work_title' => $work->title, 'work_subtitle' => $work->subtitle]) ?>

        <?php if ($lookup !== null && $lookup['record_id'] !== null && $lookup['cover_available']): ?>
            <label class="checkbox"><input type="checkbox" name="import_cover" value="1"<?= $lookup['import_cover'] ? ' checked' : '' ?>> <?= $view->t('metadata.import_cover') ?></label>
            <p class="hint"><?= $view->t('metadata.import_cover_hint') ?></p>
        <?php endif; ?>

        <?php if ($can_add_copy): ?>
            <h2><?= $view->t('catalogue.copy.section') ?></h2>
            <fieldset class="subsection">
                <legend class="visually-hidden"><?= $view->t('catalogue.copy.section') ?></legend>
                <label class="checkbox"><input type="checkbox" name="add_copy" value="1"<?= $add_copy ? ' checked' : '' ?>> <?= $view->t('catalogue.add.i_own_a_copy') ?></label>
                <div class="form-row">
                    <div class="form-field">
                        <label for="copy_location_id"><?= $view->t('fields.location') ?></label>
                        <select id="copy_location_id" name="copy_location_id">
                            <option value=""><?= $view->t('catalogue.unspecified') ?></option>
                            <?= $view->partial('partials/tree_options', ['tree' => $location_tree, 'selected' => $copy?->locationId]) ?>
                        </select>
                        <?= $view->partial('partials/field_errors', ['messages' => $errors['copy_location_id'] ?? [], 'id' => 'copy_location_id-errors']) ?>
                    </div>
                    <div class="form-field">
                        <label for="copy_condition"><?= $view->t('fields.condition') ?></label>
                        <select id="copy_condition" name="copy_condition">
                            <option value=""><?= $view->t('catalogue.unspecified') ?></option>
                            <?php foreach (\DigitalLibrary\Catalogue\Vocabulary::CONDITIONS as $condition): ?>
                                <option value="<?= $condition ?>"<?= $copy?->condition === $condition ? ' selected' : '' ?>><?= $view->t('conditions.' . $condition) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?= $view->partial('partials/field_errors', ['messages' => $errors['copy_copy_condition'] ?? [], 'id' => 'copy_condition-errors']) ?>
                    </div>
                </div>
            </fieldset>
        <?php endif; ?>

        <?php if ($title_matches !== []): ?>
            <label class="checkbox confirm"><input type="checkbox" name="confirm_new" value="1"> <?= $view->t('catalogue.possible_duplicates.confirm') ?></label>
        <?php endif; ?>
        <div class="form-actions">
            <button type="submit" class="button button-primary"><?= $view->t('catalogue.add.submit') ?></button>
            <a class="button" href="/books"><?= $view->t('actions.cancel') ?></a>
        </div>
    </form>
    <?= $view->partial('catalogue/_datalists', ['author_names' => $author_names, 'publisher_names' => $publisher_names]) ?>
</section>

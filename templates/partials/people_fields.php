<?php
/**
 * Repeating name + role rows (authors, contributors). Without JavaScript a
 * few blank rows are always available; app.js adds more on demand.
 * @var \DigitalLibrary\View\View $view
 * @var string $prefix  "author" or "contributor"
 * @var string $legend  translation key
 * @var list<array{name: string, role: string}> $people
 * @var list<string> $roles
 * @var array<string, list<string>> $errors  @var string $errorKey
 */
$rows = $people;
$blankRows = max(2, 3 - count($rows));
for ($i = 0; $i < $blankRows; $i++) {
    $rows[] = ['name' => '', 'role' => $roles[0]];
}
$messages = $errors[$errorKey] ?? [];
?>
<fieldset class="people-fields" data-repeat>
    <legend><?= $view->t($legend) ?></legend>
    <?php foreach ($rows as $i => $person): ?>
        <div class="people-row" data-repeat-row>
            <div class="form-field">
                <label for="<?= $prefix ?>-name-<?= $i ?>" class="visually-hidden"><?= $view->t('fields.person_name') ?> <?= $i + 1 ?></label>
                <input id="<?= $prefix ?>-name-<?= $i ?>" name="<?= $prefix ?>_names[]" type="text" maxlength="255" list="author-names"
                       value="<?= $view->e($person['name']) ?>" placeholder="<?= $view->t('fields.person_name') ?>">
            </div>
            <div class="form-field">
                <label for="<?= $prefix ?>-role-<?= $i ?>" class="visually-hidden"><?= $view->t('fields.role') ?> <?= $i + 1 ?></label>
                <select id="<?= $prefix ?>-role-<?= $i ?>" name="<?= $prefix ?>_roles[]">
                    <?php foreach ($roles as $role): ?>
                        <option value="<?= $view->e($role) ?>"<?= $person['role'] === $role ? ' selected' : '' ?>><?= $view->t('person_roles.' . $role) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    <?php endforeach; ?>
    <button type="button" class="button button-small" data-repeat-add hidden><?= $view->t('actions.add_row') ?></button>
    <?= $view->partial('partials/field_errors', ['messages' => $messages, 'id' => $prefix . '-errors']) ?>
</fieldset>

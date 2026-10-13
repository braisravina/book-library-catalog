<?php
/**
 * Labelled select with errors.
 * @var \DigitalLibrary\View\View $view
 * @var string $name  @var string $label  @var string|null $value
 * @var array<string, string> $options  value => already-translated label
 * Optional: $blank (translation key for an empty option), $errors, $id
 */
$id = $id ?? $name;
$messages = ($errors ?? [])[$name] ?? [];
?>
<div class="form-field">
    <label for="<?= $view->e($id) ?>"><?= $view->t($label) ?></label>
    <select id="<?= $view->e($id) ?>" name="<?= $view->e($name) ?>"<?= $messages !== [] ? ' aria-invalid="true" aria-describedby="' . $view->e($id) . '-errors"' : '' ?>>
        <?php if (isset($blank)): ?><option value=""><?= $view->t($blank) ?></option><?php endif; ?>
        <?php foreach ($options as $optionValue => $optionLabel): ?>
            <option value="<?= $view->e($optionValue) ?>"<?= (string) $optionValue === (string) ($value ?? '') ? ' selected' : '' ?>><?= $view->e($optionLabel) ?></option>
        <?php endforeach; ?>
    </select>
    <?= $view->partial('partials/field_errors', ['messages' => $messages, 'id' => $id . '-errors']) ?>
</div>

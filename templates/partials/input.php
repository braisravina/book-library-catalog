<?php
/**
 * Labelled text-like input with errors.
 * @var \DigitalLibrary\View\View $view
 * @var string $name  @var string $label  translation key
 * @var string|null $value  @var array<string, list<string>> $errors
 * Optional: $type, $maxlength, $required, $hint (key), $list, $inputmode, $id, $autocomplete, $placeholder
 */
$id = $id ?? $name;
$messages = $errors[$name] ?? [];
$describedBy = trim((!empty($hint) ? $id . '-hint ' : '') . ($messages !== [] ? $id . '-errors' : ''));
?>
<div class="form-field">
    <label for="<?= $view->e($id) ?>"><?= $view->t($label) ?><?php if (!empty($required)): ?> <span class="required" aria-hidden="true">*</span><?php endif; ?></label>
    <?php if (($type ?? 'text') === 'textarea'): ?>
        <textarea id="<?= $view->e($id) ?>" name="<?= $view->e($name) ?>" rows="<?= (int) ($rows ?? 4) ?>"<?= isset($maxlength) ? ' maxlength="' . (int) $maxlength . '"' : '' ?><?= !empty($required) ? ' required' : '' ?><?= $messages !== [] ? ' aria-invalid="true"' : '' ?><?= $describedBy !== '' ? ' aria-describedby="' . $view->e($describedBy) . '"' : '' ?>><?= $view->e($value ?? '') ?></textarea>
    <?php else: ?>
        <input id="<?= $view->e($id) ?>" name="<?= $view->e($name) ?>" type="<?= $view->e($type ?? 'text') ?>" value="<?= $view->e($value ?? '') ?>"<?= isset($maxlength) ? ' maxlength="' . (int) $maxlength . '"' : '' ?><?= !empty($required) ? ' required' : '' ?><?= isset($list) ? ' list="' . $view->e($list) . '"' : '' ?><?= isset($inputmode) ? ' inputmode="' . $view->e($inputmode) . '"' : '' ?><?= isset($autocomplete) ? ' autocomplete="' . $view->e($autocomplete) . '"' : '' ?><?= isset($placeholder) ? ' placeholder="' . $view->e($placeholder) . '"' : '' ?><?= $messages !== [] ? ' aria-invalid="true"' : '' ?><?= $describedBy !== '' ? ' aria-describedby="' . $view->e($describedBy) . '"' : '' ?>>
    <?php endif; ?>
    <?php if (!empty($hint)): ?><p class="hint" id="<?= $view->e($id) ?>-hint"><?= $view->t($hint) ?></p><?php endif; ?>
    <?= $view->partial('partials/field_errors', ['messages' => $messages, 'id' => $id . '-errors']) ?>
</div>

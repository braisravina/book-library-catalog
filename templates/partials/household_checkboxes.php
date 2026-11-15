<?php
/**
 * Optional household memberships for a new account or an invitation.
 * Nothing is ticked by default: an account belongs to no household until
 * an administrator assigns one.
 *
 * @var \DigitalLibrary\View\View $view
 * @var array<string, string> $households  id => name
 * @var list<string> $selected             submitted ids
 * @var array<string, list<string>> $errors
 * @var string $hint                       translation key
 */
?>
<fieldset>
    <legend><?= $view->t('households.assign.legend') ?></legend>
    <?php foreach ($households as $householdId => $householdName): ?>
        <label class="checkbox"><input type="checkbox" name="household_ids[]" value="<?= $view->e((string) $householdId) ?>"<?= in_array((string) $householdId, $selected, true) ? ' checked' : '' ?>> <?= $view->e($householdName) ?></label>
    <?php endforeach; ?>
    <p class="hint"><?= $view->t($hint) ?></p>
    <?= $view->partial('partials/field_errors', ['messages' => $errors['household_ids'] ?? [], 'id' => 'household_ids-errors']) ?>
</fieldset>

<?php
/**
 * @var \DigitalLibrary\View\View $view  @var array<string, mixed> $edition  @var array<string, mixed>|null $copy
 * @var \DigitalLibrary\Catalogue\Input\CopyInput $input  @var array<string, list<string>> $errors
 * @var list<\DigitalLibrary\Auth\User> $owners  @var bool $can_choose_owner  @var string $action
 * @var list<array<string, mixed>> $location_tree
 * @var array<string, string> $household_options  id => name; empty while the household feature is disabled
 * @var string|null $edition_url  null when the edition is not in the user's view (a copy added by ISBN):
 *                                then only its bibliographic description is shown, with no link to it
 * @var string|null $isbn         the ISBN-13 the copy is added by, instead of an edition id
 */
use DigitalLibrary\Catalogue\Vocabulary;

$back = $edition_url ?? '/books/new';

$enum = static function (array $values, string $group) use ($view): array {
    $out = [];
    foreach ($values as $value) {
        $out[$value] = $view->trans($group . '.' . $value);
    }

    return $out;
};
?>
<section class="form-card form-card-wide">
    <h1><?= $view->t($page_title) ?></h1>
    <?php if ($edition_url !== null): ?>
        <p class="muted"><a href="<?= $view->e($edition_url) ?>"><?= $view->e($edition['title']) ?></a></p>
    <?php else: ?>
        <p class="muted"><?= $view->e($edition['title']) ?><?php if ($isbn !== null): ?> · <?= $view->t('fields.isbn') ?> <?= $view->e($isbn) ?><?php endif; ?></p>
        <div class="alert alert-info" role="status"><?= $view->t('catalogue.copy_shared_record') ?></div>
    <?php endif; ?>
    <?php if (!empty($conflict) && $edition_url !== null): ?>
        <div class="alert alert-error" role="alert"><?= $view->t('catalogue.edit_conflict') ?> <a href="<?= $view->e($edition_url) ?>" target="_blank" rel="noopener"><?= $view->t('catalogue.edit_conflict_view') ?></a></div>
    <?php endif; ?>
    <?php if ($errors !== []): ?><div class="alert alert-error" role="alert"><?= $view->t('validation.summary') ?></div><?php endif; ?>
    <form method="post" action="<?= $view->e($action) ?>" class="form" novalidate>
        <?= $view->csrfField() ?>
        <?php if ($copy !== null): ?><input type="hidden" name="row_version" value="<?= (int) $copy['row_version'] ?>"><?php endif; ?>
        <?php if ($isbn !== null): ?><input type="hidden" name="isbn" value="<?= $view->e($isbn) ?>"><?php endif; ?>
        <div class="form-row">
            <?php if ($can_choose_owner): ?>
                <?php $ownerOptions = []; foreach ($owners as $owner) { $ownerOptions[(string) $owner->id] = $owner->displayName; } ?>
                <?= $view->partial('partials/select', ['name' => 'owner_user_id', 'label' => 'fields.owner', 'value' => $input->ownerId, 'options' => $ownerOptions, 'errors' => $errors]) ?>
            <?php else: ?>
                <div class="form-field">
                    <span class="label"><?= $view->t('fields.owner') ?></span>
                    <p><?= $view->e($owners[0]->displayName) ?></p>
                    <input type="hidden" name="owner_user_id" value="<?= (int) $owners[0]->id ?>">
                </div>
            <?php endif; ?>
            <?php if (($household_options ?? []) !== []): ?>
                <?= $view->partial('partials/select', ['name' => 'household_id', 'label' => 'fields.household', 'value' => $input->householdId, 'options' => $household_options, 'errors' => $errors] + ($copy === null && count($household_options) > 1 ? ['blank' => 'households.choose'] : [])) ?>
            <?php endif; ?>
            <div class="form-field">
                <label for="location_id"><?= $view->t('fields.location') ?></label>
                <select id="location_id" name="location_id"<?= ($household_options ?? []) !== [] ? ' data-household-locations="household_id"' : '' ?>>
                    <option value=""><?= $view->t('catalogue.unspecified') ?></option>
                    <?= $view->partial('partials/location_options', ['tree' => $location_tree, 'selected' => $input->locationId, 'households' => $household_options ?? []]) ?>
                </select>
                <?= $view->partial('partials/field_errors', ['messages' => $errors['location_id'] ?? [], 'id' => 'location_id-errors']) ?>
            </div>
        </div>
        <div class="form-row">
            <?= $view->partial('partials/select', ['name' => 'copy_condition', 'label' => 'fields.condition', 'value' => $input->condition, 'options' => $enum(Vocabulary::CONDITIONS, 'conditions'), 'blank' => 'catalogue.unspecified', 'errors' => $errors]) ?>
            <?= $view->partial('partials/select', ['name' => 'status', 'label' => 'fields.status', 'value' => $input->status, 'options' => $enum(Vocabulary::STATUSES, 'statuses'), 'errors' => $errors]) ?>
            <?= $view->partial('partials/input', ['name' => 'inventory_code', 'label' => 'fields.inventory_code', 'value' => $input->inventoryCode, 'errors' => $errors, 'maxlength' => 64]) ?>
        </div>
        <label class="checkbox"><input type="checkbox" name="is_signed" value="1"<?= $input->isSigned ? ' checked' : '' ?>> <?= $view->t('fields.is_signed') ?></label>
        <div class="form-row">
            <?= $view->partial('partials/input', ['name' => 'acquired_on', 'label' => 'fields.acquired_on', 'value' => $input->acquiredOn, 'errors' => $errors, 'type' => 'date']) ?>
            <?= $view->partial('partials/input', ['name' => 'acquisition_source', 'label' => 'fields.acquisition_source', 'value' => $input->acquisitionSource, 'errors' => $errors, 'maxlength' => 255]) ?>
        </div>
        <div class="form-row">
            <?= $view->partial('partials/input', ['name' => 'price', 'label' => 'fields.price', 'value' => $input->price, 'errors' => $errors, 'inputmode' => 'decimal', 'maxlength' => 10]) ?>
            <?= $view->partial('partials/select', ['name' => 'currency', 'label' => 'fields.currency', 'value' => $input->currency, 'options' => array_combine(Vocabulary::CURRENCIES, Vocabulary::CURRENCIES), 'blank' => 'catalogue.unspecified', 'errors' => $errors]) ?>
        </div>
        <?= $view->partial('partials/input', ['name' => 'notes', 'label' => 'fields.notes', 'value' => $input->notes, 'errors' => $errors, 'type' => 'textarea', 'maxlength' => 5000, 'rows' => 3]) ?>
        <div class="form-actions">
            <button type="submit" class="button button-primary"><?= $view->t('actions.save') ?></button>
            <a class="button" href="<?= $view->e($back) ?>"><?= $view->t('actions.cancel') ?></a>
        </div>
    </form>
</section>

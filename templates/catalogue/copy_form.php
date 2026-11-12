<?php
/**
 * @var \DigitalLibrary\View\View $view  @var array<string, mixed> $edition  @var array<string, mixed>|null $copy
 * @var \DigitalLibrary\Catalogue\Input\CopyInput $input  @var array<string, list<string>> $errors
 * @var list<\DigitalLibrary\Auth\User> $owners  @var bool $can_choose_owner  @var string $action
 * @var list<array<string, mixed>> $location_tree
 */
use DigitalLibrary\Catalogue\Vocabulary;

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
    <p class="muted"><a href="/editions/<?= (int) $edition['id'] ?>"><?= $view->e($edition['title']) ?></a></p>
    <?php if (!empty($conflict)): ?>
        <div class="alert alert-error" role="alert"><?= $view->t('catalogue.edit_conflict') ?> <a href="/editions/<?= (int) $edition['id'] ?>" target="_blank" rel="noopener"><?= $view->t('catalogue.edit_conflict_view') ?></a></div>
    <?php endif; ?>
    <?php if ($errors !== []): ?><div class="alert alert-error" role="alert"><?= $view->t('validation.summary') ?></div><?php endif; ?>
    <form method="post" action="<?= $view->e($action) ?>" class="form" novalidate>
        <?= $view->csrfField() ?>
        <?php if ($copy !== null): ?><input type="hidden" name="row_version" value="<?= (int) $copy['row_version'] ?>"><?php endif; ?>
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
            <div class="form-field">
                <label for="location_id"><?= $view->t('fields.location') ?></label>
                <select id="location_id" name="location_id">
                    <option value=""><?= $view->t('catalogue.unspecified') ?></option>
                    <?= $view->partial('partials/tree_options', ['tree' => $location_tree, 'selected' => $input->locationId]) ?>
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
            <a class="button" href="/editions/<?= (int) $edition['id'] ?>"><?= $view->t('actions.cancel') ?></a>
        </div>
    </form>
</section>

<?php
/**
 * Shared admin page for categories and locations.
 * @var \DigitalLibrary\View\View $view  @var string $key  @var string $base_path
 * @var list<array<string, mixed>> $tree  @var list<array<string, mixed>> $parent_options
 * @var array<string, string> $old  @var array<string, list<string>> $errors  @var array<string, mixed>|null $node
 * @var array<string, string>|null $households  locations with households in use: id => name. Every household
 *                                              has its own location tree; the list is then grouped by household.
 */
$isLocation = $key === 'locations';
$editing = $node !== null;
$households = $households ?? [];
// One group per household (in the households' order), then locations without one; a single unnamed group otherwise.
$groups = [];
if ($households === []) {
    $groups[] = ['label' => null, 'rows' => $tree];
} else {
    $byHousehold = [];
    foreach ($tree as $row) {
        $byHousehold[(string) ($row['household_id'] ?? '')][] = $row;
    }
    foreach ($households as $householdId => $householdName) {
        $groups[] = ['label' => $householdName, 'rows' => $byHousehold[(string) $householdId] ?? []];
    }
    if (isset($byHousehold[''])) {
        $groups[] = ['label' => $view->trans('households.unassigned'), 'rows' => $byHousehold['']];
    }
}
?>
<h1><?= $view->t('admin.' . $key . '.title') ?></h1>
<div class="two-column">
    <section aria-labelledby="tree-heading">
        <h2 id="tree-heading" class="visually-hidden"><?= $view->t('admin.' . $key . '.title') ?></h2>
        <?php if ($tree === [] && $households === []): ?>
            <p class="muted"><?= $view->t('admin.' . $key . '.empty') ?></p>
        <?php else: ?>
            <?php foreach ($groups as $group): ?>
                <?php if ($group['label'] !== null): ?><h3><?= $view->e($group['label']) ?></h3><?php endif; ?>
                <?php if ($group['rows'] === []): ?>
                    <p class="muted"><?= $view->t('admin.' . $key . '.empty') ?></p>
                    <?php continue; ?>
                <?php endif; ?>
                <ul class="tree-list">
                    <?php foreach ($group['rows'] as $row): ?>
                        <li class="depth-<?= min(6, (int) $row['depth']) ?>">
                            <span class="tree-name"><?= $view->e($row['name']) ?></span>
                            <?php if ($isLocation): ?><span class="badge"><?= $view->t('location_kinds.' . $row['kind']) ?></span><?php endif; ?>
                            <span class="muted small"><?= $view->t('admin.' . $key . '.usage', ['count' => (int) $row['usage']]) ?></span>
                            <span class="tree-actions">
                                <a href="<?= $view->e($base_path . '/' . (int) $row['id'] . '/edit') ?>"><?= $view->t('actions.edit') ?></a>
                                <?php if ($key === 'categories'): ?><a href="<?= $view->e($view->url('/books', ['category' => (int) $row['id']])) ?>"><?= $view->t('actions.browse') ?></a><?php else: ?><a href="<?= $view->e($view->url('/books', ['location' => (int) $row['id']])) ?>"><?= $view->t('actions.browse') ?></a><?php endif; ?>
                                <form method="post" action="<?= $view->e($base_path . '/' . (int) $row['id'] . '/delete') ?>" class="inline-form" data-confirm="<?= $view->t('admin.tree.confirm_delete') ?>">
                                    <?= $view->csrfField() ?>
                                    <button type="submit" class="link-button danger"><?= $view->t('actions.delete') ?></button>
                                </form>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>
    <section class="form-card" aria-labelledby="form-heading">
        <h2 id="form-heading"><?= $view->t($editing ? 'admin.' . $key . '.edit' : 'admin.' . $key . '.new') ?></h2>
        <form method="post" action="<?= $view->e($editing ? $base_path . '/' . (int) $node['id'] : $base_path) ?>" class="form" novalidate>
            <?= $view->csrfField() ?>
            <?= $view->partial('partials/input', ['name' => 'name', 'label' => 'fields.name', 'value' => $old['name'], 'errors' => $errors, 'required' => true, 'maxlength' => 150]) ?>
            <div class="form-field">
                <label for="parent_id"><?= $view->t('fields.parent') ?></label>
                <select id="parent_id" name="parent_id"<?= !empty($errors['parent_id']) ? ' aria-invalid="true" aria-describedby="parent_id-errors"' : '' ?>>
                    <option value=""><?= $view->t('admin.tree.top_level') ?></option>
                    <?php if ($households === []): ?>
                        <?= $view->partial('partials/tree_options', ['tree' => $parent_options, 'selected' => $old['parent_id']]) ?>
                    <?php else: ?>
                        <?php
                        // An existing location stays in its household: only that household's tree is offered.
                        $own = $editing ? (string) ($node['household_id'] ?? '') : null;
                        $options = $own === null ? $parent_options : array_values(array_filter($parent_options, static fn (array $r): bool => (string) ($r['household_id'] ?? '') === $own));
                        ?>
                        <?= $view->partial('partials/location_options', ['tree' => $options, 'selected' => $old['parent_id'], 'households' => $households]) ?>
                    <?php endif; ?>
                </select>
                <?= $view->partial('partials/field_errors', ['messages' => $errors['parent_id'] ?? [], 'id' => 'parent_id-errors']) ?>
            </div>
            <?php if ($households !== [] && !$editing): ?>
                <?= $view->partial('partials/select', ['name' => 'household_id', 'label' => 'fields.household', 'value' => $old['household_id'] ?? '', 'options' => $households, 'blank' => 'admin.locations.household_of_parent', 'errors' => $errors]) ?>
                <p class="hint"><?= $view->t('admin.locations.household_hint') ?></p>
            <?php elseif ($households !== [] && $editing): ?>
                <div class="form-field">
                    <span class="label"><?= $view->t('fields.household') ?></span>
                    <p><?= $view->e($households[(string) ($node['household_id'] ?? '')] ?? $view->trans('households.unassigned')) ?></p>
                </div>
            <?php endif; ?>
            <?php if ($isLocation): ?>
                <?php $kinds = []; foreach (\DigitalLibrary\Catalogue\Vocabulary::LOCATION_KINDS as $kind) { $kinds[$kind] = $view->trans('location_kinds.' . $kind); } ?>
                <?= $view->partial('partials/select', ['name' => 'kind', 'label' => 'fields.kind', 'value' => $old['kind'], 'options' => $kinds, 'errors' => $errors]) ?>
            <?php endif; ?>
            <?= $view->partial('partials/input', ['name' => 'description', 'label' => 'fields.description', 'value' => $old['description'], 'errors' => $errors, 'type' => 'textarea', 'rows' => 2, 'maxlength' => $isLocation ? 500 : 1000]) ?>
            <div class="form-actions">
                <button type="submit" class="button button-primary"><?= $view->t($editing ? 'actions.save' : 'actions.create') ?></button>
                <?php if ($editing): ?><a class="button" href="<?= $view->e($base_path) ?>"><?= $view->t('actions.cancel') ?></a><?php endif; ?>
            </div>
        </form>
    </section>
</div>

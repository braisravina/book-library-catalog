<?php
/**
 * Shared admin page for categories and locations.
 * @var \DigitalLibrary\View\View $view  @var string $key  @var string $base_path
 * @var list<array<string, mixed>> $tree  @var list<array<string, mixed>> $parent_options
 * @var array<string, string> $old  @var array<string, list<string>> $errors  @var array<string, mixed>|null $node
 */
$isLocation = $key === 'locations';
$editing = $node !== null;
?>
<h1><?= $view->t('admin.' . $key . '.title') ?></h1>
<div class="two-column">
    <section aria-labelledby="tree-heading">
        <h2 id="tree-heading" class="visually-hidden"><?= $view->t('admin.' . $key . '.title') ?></h2>
        <?php if ($tree === []): ?>
            <p class="muted"><?= $view->t('admin.' . $key . '.empty') ?></p>
        <?php else: ?>
            <ul class="tree-list">
                <?php foreach ($tree as $row): ?>
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
                    <?= $view->partial('partials/tree_options', ['tree' => $parent_options, 'selected' => $old['parent_id']]) ?>
                </select>
                <?= $view->partial('partials/field_errors', ['messages' => $errors['parent_id'] ?? [], 'id' => 'parent_id-errors']) ?>
            </div>
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

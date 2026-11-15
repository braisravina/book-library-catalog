<?php

declare(strict_types=1);
/** @var \DigitalLibrary\View\View $view */
$householdOptions = ['unassigned' => $view->trans('households.unassigned')];
foreach ($households as $household) { $householdOptions[(string) $household['id']] = $household['name']; }
$sourceValue = $source === null ? 'unassigned' : (string) $source;
?>
<h1><?= $view->t('transfers.title') ?></h1>
<p><?= $view->t('transfers.help', ['limit' => $limit]) ?></p>
<?php foreach ($conflicts as $conflict): ?><p class="alert alert-error" role="alert"><?= $view->t($conflict['key']) ?> <?= $view->e((string) ($conflict['id'] ?? '')) ?></p><?php endforeach; ?>
<form method="get" action="/admin/inventory" class="form">
    <label for="source-filter"><?= $view->t('transfers.source') ?></label>
    <select id="source-filter" name="source"><?php foreach ($householdOptions as $id => $name): ?><option value="<?= $view->e($id) ?>"<?= $sourceValue === (string) $id ? ' selected' : '' ?>><?= $view->e($name) ?></option><?php endforeach; ?></select>
    <button class="button" type="submit"><?= $view->t('actions.browse') ?></button>
</form>
<form method="post" action="/admin/inventory/preview" class="form">
    <?= $view->csrfField() ?>
    <input type="hidden" name="source" value="<?= $view->e($sourceValue) ?>">
    <fieldset><legend><?= $view->t('transfers.selection') ?></legend>
    <?php foreach ($copies as $copy): ?>
        <p><label class="checkbox"><input type="checkbox" name="selected[]" value="<?= (int) $copy['id'] ?>:<?= (int) $copy['row_version'] ?>"> #<?= (int) $copy['id'] ?> <?= $view->e($copy['title']) ?> — <?= $view->e($copy['inventory_code'] ?? '') ?></label>
        <span class="muted"><?= $view->t('fields.owner') ?> #<?= (int) $copy['owner_user_id'] ?> · <?= $view->t('fields.location') ?> #<?= $view->e((string) ($copy['location_id'] ?? '')) ?></span></p>
    <?php endforeach; ?>
    <?php if (count($copies) === 100): ?><p><a href="<?= $view->e($view->url('/admin/inventory', ['source' => $sourceValue, 'after' => end($copies)['id']])) ?>"><?= $view->t('transfers.next') ?></a></p><?php endif; ?>
    </fieldset>
    <div class="form-row">
        <div class="form-field"><label for="transfer-kind"><?= $view->t('transfers.kind') ?></label><select name="kind" id="transfer-kind"><?php foreach (['copies', 'subtree', 'owner'] as $kind): ?><option value="<?= $view->e($kind) ?>"><?= $view->t('transfers.kind.' . $kind) ?></option><?php endforeach; ?></select></div>
        <div class="form-field"><label for="transfer-destination"><?= $view->t('transfers.destination') ?></label><select name="destination" id="transfer-destination"><?php foreach ($householdOptions as $id => $name): ?><option value="<?= $view->e($id) ?>"><?= $view->e($name) ?></option><?php endforeach; ?></select></div>
    </div>
    <p class="hint"><?= $view->t('transfers.placement_hint') ?></p>
    <?php foreach (['location' => 'fields.location', 'parent' => 'fields.parent'] as $field => $label): ?>
    <div class="form-field"><label for="transfer-<?= $view->e($field) ?>"><?= $view->t($label) ?></label><select name="<?= $view->e($field) ?>" id="transfer-<?= $view->e($field) ?>"><option value=""><?= $view->t('catalogue.unspecified') ?></option><?php foreach ($locations as $location): ?><option value="<?= (int) $location['id'] ?>">#<?= (int) $location['id'] ?> <?= $view->e($location['name']) ?> — <?= $view->e($householdOptions[(string) ($location['household_id'] ?? 'unassigned')] ?? '') ?></option><?php endforeach; ?></select></div>
    <?php endforeach; ?>
    <div class="form-field"><label for="transfer-root"><?= $view->t('transfers.root') ?></label><select name="root" id="transfer-root"><option value=""><?= $view->t('catalogue.unspecified') ?></option><?php foreach ($locations as $location): if ($location['household_id'] !== $source) { continue; } ?><option value="<?= (int) $location['id'] ?>:<?= (int) $location['row_version'] ?>">#<?= (int) $location['id'] ?> <?= $view->e($location['name']) ?></option><?php endforeach; ?></select></div>
    <div class="form-field"><label for="transfer-owner"><?= $view->t('transfers.new_owner') ?></label><select name="owner" id="transfer-owner"><option value=""><?= $view->t('catalogue.unspecified') ?></option><?php foreach ($owners as $owner): if (!$owner->isActive) { continue; } ?><option value="<?= (int) $owner->id ?>">#<?= (int) $owner->id ?> <?= $view->e($owner->displayName) ?></option><?php endforeach; ?></select></div>
    <label for="transfer-reason"><?= $view->t('transfers.reason') ?></label><textarea name="reason" id="transfer-reason" required maxlength="1000" rows="3"></textarea>
    <div class="form-actions"><button class="button" type="submit" name="intent" value="dry-run"><?= $view->t('transfers.dry_run') ?></button><button class="button button-primary" type="submit" name="intent" value="plan"><?= $view->t('transfers.save_preview') ?></button></div>
</form>
<h2><?= $view->t('transfers.dependencies') ?></h2>
<div class="table-wrap"><table class="table"><caption><?= $view->t('transfers.dependencies') ?></caption><thead><tr><?php foreach (['fields.household', 'transfers.copies', 'transfers.locations', 'transfers.members', 'transfers.collections', 'transfers.invitations', 'transfers.loans'] as $key): ?><th scope="col"><?= $view->t($key) ?></th><?php endforeach; ?></tr></thead><tbody>
<?php foreach ($diagnostics['dependencies'] as $dependency): ?><tr><th scope="row"><?= $view->e($dependency['name']) ?><?php if ($dependency['system_key'] !== null): ?> · <?= $view->t('households.default_badge') ?><?php endif; ?></th><?php foreach (['copies', 'locations', 'members', 'collections', 'invitations', 'active_loans'] as $key): ?><td><?= (int) $dependency[$key] ?></td><?php endforeach; ?></tr><?php endforeach; ?>
</tbody></table></div>
<h2><?= $view->t('transfers.diagnostics') ?></h2>
<?php foreach (['unassigned_copies', 'unassigned_locations', 'location_mismatches', 'parent_mismatches'] as $key): ?><p><?= $view->t('transfers.' . $key) ?>: <?= (int) $diagnostics[$key] ?></p><?php endforeach; ?>
<p><?= $view->t('transfers.code_warning') ?></p>
<ul><?php foreach ($diagnostics['global_code_conflicts'] as $conflict): ?><li><?= $view->e($conflict['inventory_code']) ?>: <?= (int) $conflict['copies'] ?></li><?php endforeach; ?></ul>
<h2><?= $view->t('transfers.history') ?></h2>
<p><?= $view->t('transfers.recent') ?></p>
<ul><?php foreach ($history as $operation): ?><li><a href="/admin/inventory/transfers/<?= (int) $operation['id'] ?>">#<?= (int) $operation['id'] ?></a> <?= $view->t('transfers.status.' . $operation['status']) ?> · <?= $view->e($operation['created_at']) ?> · <?= $view->t('transfers.actor') ?> #<?= (int) $operation['actor_id'] ?></li><?php endforeach; ?></ul>

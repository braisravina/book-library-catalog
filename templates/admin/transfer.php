<?php

declare(strict_types=1);
/** @var \DigitalLibrary\View\View $view */
?>
<h1><?= $view->t('transfers.preview') ?><?php if ($operation !== null): ?> #<?= (int) $operation['id'] ?><?php endif; ?></h1>
<?php foreach ($conflicts as $conflict): ?><p class="alert alert-error" role="alert"><?= $view->t($conflict['key']) ?> <?= $view->e((string) ($conflict['id'] ?? '')) ?></p><?php endforeach; ?>
<p class="alert alert-info"><?= $view->t('transfers.review_warning') ?></p>
<dl>
    <dt><?= $view->t('transfers.kind') ?></dt><dd><?= $view->t('transfers.kind.' . $plan['kind']) ?></dd>
    <dt><?= $view->t('transfers.source') ?></dt><dd><?= $plan['source'] === null ? $view->t('households.unassigned') : '#' . (int) $plan['source'] . ' ' . $view->e($plan['source_household']['name']) ?></dd>
    <dt><?= $view->t('transfers.destination') ?></dt><dd><?= $plan['destination'] === null ? $view->t('households.unassigned') : '#' . (int) $plan['destination'] . ' ' . $view->e($plan['destination_household']['name']) ?></dd>
    <dt><?= $view->t('transfers.reason') ?></dt><dd><?= $view->e($plan['reason']) ?></dd>
    <dt><?= $view->t('transfers.copies') ?></dt><dd><?= count($plan['copies']) ?></dd>
    <dt><?= $view->t('transfers.locations') ?></dt><dd><?= count($plan['locations']) ?></dd>
    <?php if ($operation !== null): ?><dt><?= $view->t('transfers.actor') ?></dt><dd>#<?= (int) $operation['actor_id'] ?></dd><dt><?= $view->t('transfers.status') ?></dt><dd><?= $view->t('transfers.status.' . $operation['status']) ?></dd><?php endif; ?>
</dl>
<ul><?php foreach ($plan['copies'] as $copy): ?><li>#<?= (int) $copy['id'] ?> · <?= $view->t('fields.inventory_code') ?> <?= $view->e($copy['inventory_code'] ?? '') ?> · <?= $view->t('transfers.version') ?> <?= (int) $copy['row_version'] ?> · <?= $view->t('fields.location') ?> #<?= $view->e((string) ($copy['location_id'] ?? '')) ?> → #<?= $view->e((string) ($plan['kind'] === 'subtree' ? $copy['location_id'] : $plan['location'])) ?> · <?= $view->t('fields.owner') ?> #<?= (int) $copy['owner_user_id'] ?> → #<?= (int) ($plan['kind'] === 'owner' ? $plan['owner'] : $copy['owner_user_id']) ?></li><?php endforeach; ?></ul>
<ul><?php foreach ($plan['locations'] as $location): ?><li>#<?= (int) $location['id'] ?> <?= $view->e($location['name']) ?> · <?= $view->t('fields.parent') ?> #<?= $view->e((string) ($location['parent_id'] ?? '')) ?> → #<?= $view->e((string) ($location['id'] === $plan['root'] ? $plan['parent'] : $location['parent_id'])) ?></li><?php endforeach; ?></ul>
<?php if ($operation !== null && $operation['status'] === 'planned'): ?>
<form method="post" action="/admin/inventory/transfers/<?= (int) $operation['id'] ?>/apply" class="form">
    <?= $view->csrfField() ?><input type="hidden" name="digest" value="<?= $view->e($operation['digest']) ?>">
    <label class="checkbox"><input type="checkbox" name="confirmed" value="yes" required> <?= $view->t('transfers.confirm') ?></label>
    <button class="button button-primary" type="submit"><?= $view->t('transfers.apply') ?></button>
</form>
<form method="post" action="/admin/inventory/transfers/<?= (int) $operation['id'] ?>/cancel" class="form"><?= $view->csrfField() ?><button class="button" type="submit"><?= $view->t('actions.cancel') ?></button></form>
<?php endif; ?>
<p><a class="button" href="/admin/inventory"><?= $view->t('transfers.title') ?></a></p>

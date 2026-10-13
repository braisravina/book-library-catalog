<?php
/**
 * @var \DigitalLibrary\View\View $view  @var array<string, mixed> $copy  @var string|null $location_path
 * @var array<string, mixed>|null $active  @var bool $can_manage  @var bool $can_see_active  @var bool $can_edit_copy
 * @var list<array<string, mixed>> $history  @var string $today
 */
$details = [
    'fields.owner' => $copy['owner_name'],
    'fields.location' => $location_path,
    'fields.condition' => $copy['copy_condition'] !== null ? $view->trans('conditions.' . $copy['copy_condition']) : null,
    'fields.inventory_code' => $copy['inventory_code'],
    'fields.acquired_on' => $copy['acquired_on'],
];
?>
<p class="eyebrow"><a href="/editions/<?= (int) $copy['edition_id'] ?>"><?= $view->e($copy['edition_title']) ?></a></p>
<div class="page-header">
    <h1><?= $view->t('loans.copy.title') ?></h1>
    <div class="form-actions">
        <?php if ($can_manage && $active === null && $copy['status'] === 'available'): ?>
            <a class="button button-primary" href="/copies/<?= (int) $copy['id'] ?>/lend"><?= $view->t('loans.lend.action') ?></a>
        <?php endif; ?>
        <?php if ($can_manage && $active !== null): ?>
            <a class="button button-primary" href="/loans/<?= (int) $active['id'] ?>/return"><?= $view->t('loans.return.action') ?></a>
        <?php endif; ?>
        <?php if ($can_edit_copy): ?><a class="button" href="/copies/<?= (int) $copy['id'] ?>/edit"><?= $view->t('actions.edit') ?></a><?php endif; ?>
    </div>
</div>

<section class="availability" aria-labelledby="availability-heading">
    <h2 id="availability-heading" class="visually-hidden"><?= $view->t('loans.availability') ?></h2>
    <p><?= $view->partial('loans/_status', ['status' => (string) $copy['status'], 'loan' => $active, 'show_borrower' => $can_see_active, 'today' => $today]) ?></p>
    <?php if ($active !== null && $can_see_active && $active['notes'] !== null): ?>
        <p class="muted"><?= nl2br($view->e($active['notes'])) ?></p>
    <?php endif; ?>
</section>

<dl class="details">
    <?php foreach ($details as $label => $value): ?>
        <?php if ($value === null || $value === '') { continue; } ?>
        <div><dt><?= $view->t($label) ?></dt><dd><?= $view->e($value) ?></dd></div>
    <?php endforeach; ?>
</dl>

<?php if ($can_manage): ?>
    <section aria-labelledby="history-heading">
        <h2 id="history-heading"><?= $view->t('loans.history.title') ?></h2>
        <?php if ($history === []): ?>
            <p class="muted"><?= $view->t('loans.history.empty') ?></p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <caption class="visually-hidden"><?= $view->t('loans.history.title') ?></caption>
                    <thead><tr>
                        <th scope="col"><?= $view->t('loans.fields.borrower') ?></th>
                        <th scope="col"><?= $view->t('loans.fields.loaned_on') ?></th>
                        <th scope="col"><?= $view->t('loans.fields.due_on') ?></th>
                        <th scope="col"><?= $view->t('loans.fields.returned_on') ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($history as $loan): ?>
                        <tr>
                            <td><?= $view->e($loan['borrower_display_name'] ?? $loan['borrower_name'] ?? '') ?></td>
                            <td><?= $view->e($loan['loaned_on']) ?></td>
                            <td><?= $view->e($loan['due_on'] ?? '') ?><?php if ((int) $loan['is_overdue'] === 1): ?> <span class="badge badge-warning"><?= $view->t('loans.overdue') ?></span><?php endif; ?></td>
                            <td><?= $loan['returned_on'] !== null ? $view->e($loan['returned_on']) : '<span class="muted">' . $view->t('loans.not_returned') . '</span>' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>

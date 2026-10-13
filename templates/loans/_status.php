<?php
/**
 * Availability of one copy.
 * @var \DigitalLibrary\View\View $view  @var string $status  copies.status
 * @var array<string, mixed>|null $loan  active loan (if any)  @var bool $show_borrower  @var string $today
 */
$overdue = $loan !== null && $loan['due_on'] !== null && (string) $loan['due_on'] < $today;
?>
<?php if ($loan !== null || $status === 'on_loan'): ?>
    <span class="status status-on-loan"><?= $view->t('statuses.on_loan') ?></span>
    <?php if ($loan !== null && $loan['due_on'] !== null): ?>
        <span class="muted small"><?= $view->t('loans.due', ['date' => (string) $loan['due_on']]) ?></span>
    <?php endif; ?>
    <?php if ($overdue): ?><span class="badge badge-warning"><?= $view->t('loans.overdue') ?></span><?php endif; ?>
    <?php if ($loan !== null && $show_borrower): ?>
        <span class="small"><?= $view->t('loans.borrowed_by', ['name' => (string) ($loan['borrower_display_name'] ?? $loan['borrower_name'] ?? '')]) ?></span>
    <?php endif; ?>
<?php else: ?>
    <span class="status status-<?= $view->e($status) ?>"><?= $view->t('statuses.' . $status) ?></span>
<?php endif; ?>

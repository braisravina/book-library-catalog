<?php
/** @var \DigitalLibrary\View\View $view  @var array<string, mixed> $loan  @var string $today */
?>
<section class="form-card">
    <h1><?= $view->t('loans.return.title') ?></h1>
    <div class="duplicate-summary">
        <p><strong><?= $view->e($loan['edition_title']) ?></strong></p>
        <p><?= $view->t('loans.borrowed_by', ['name' => (string) ($loan['borrower_display_name'] ?? $loan['borrower_name'] ?? '')]) ?></p>
        <p class="muted"><?= $view->t('loans.fields.loaned_on') ?>: <?= $view->e($loan['loaned_on']) ?><?php if ($loan['due_on'] !== null): ?> · <?= $view->t('loans.fields.due_on') ?>: <?= $view->e($loan['due_on']) ?><?php endif; ?></p>
    </div>
    <p><?= $view->t('loans.return.confirm', ['date' => $today]) ?></p>
    <form method="post" action="/loans/<?= (int) $loan['id'] ?>/return" class="form-actions">
        <?= $view->csrfField() ?>
        <button type="submit" class="button button-primary"><?= $view->t('loans.return.submit') ?></button>
        <a class="button" href="/copies/<?= (int) $loan['copy_id'] ?>"><?= $view->t('actions.cancel') ?></a>
    </form>
</section>

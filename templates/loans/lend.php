<?php
/**
 * @var \DigitalLibrary\View\View $view  @var array<string, mixed> $copy  @var \DigitalLibrary\Loans\LoanInput $input
 * @var array<string, list<string>> $errors  @var string|null $refusal  @var list<\DigitalLibrary\Auth\User> $borrowers
 * @var bool $available  @var string $today
 */
?>
<section class="form-card">
    <h1><?= $view->t('loans.lend.title') ?></h1>
    <p class="muted"><a href="/copies/<?= (int) $copy['id'] ?>"><?= $view->e($copy['edition_title']) ?></a> · <?= $view->t('fields.owner') ?>: <?= $view->e($copy['owner_name']) ?></p>
    <?php if ($refusal !== null): ?>
        <div class="alert alert-error" role="alert"><?= $view->t($refusal) ?></div>
    <?php elseif ($errors !== []): ?>
        <div class="alert alert-error" role="alert"><?= $view->t('validation.summary') ?></div>
    <?php endif; ?>
    <?php if (!$available): ?>
        <div class="alert alert-info" role="status"><?= $view->t('loans.error.copy_unavailable') ?></div>
        <p><a class="button" href="/copies/<?= (int) $copy['id'] ?>"><?= $view->t('loans.copy.back') ?></a></p>
    <?php else: ?>
        <form method="post" action="/copies/<?= (int) $copy['id'] ?>/lend" class="form" novalidate>
            <?= $view->csrfField() ?>
            <fieldset>
                <legend><?= $view->t('loans.fields.borrower') ?></legend>
                <label class="checkbox"><input type="radio" name="borrower_type" value="name"<?= $input->borrowerType === 'name' ? ' checked' : '' ?>> <?= $view->t('loans.lend.external') ?></label>
                <?= $view->partial('partials/input', ['name' => 'borrower_name', 'label' => 'loans.fields.borrower_name', 'value' => $input->borrowerName, 'errors' => $errors, 'maxlength' => 150, 'hint' => 'loans.lend.name_hint', 'autocomplete' => 'off']) ?>
                <?php if ($borrowers !== []): ?>
                    <label class="checkbox"><input type="radio" name="borrower_type" value="user"<?= $input->borrowerType === 'user' ? ' checked' : '' ?>> <?= $view->t('loans.lend.internal') ?></label>
                    <?php $options = []; foreach ($borrowers as $b) { $options[(string) $b->id] = $b->displayName; } ?>
                    <?= $view->partial('partials/select', ['name' => 'borrower_user_id', 'label' => 'loans.fields.borrower_user', 'value' => $input->borrowerUserId, 'options' => $options, 'blank' => 'catalogue.merge.choose', 'errors' => $errors]) ?>
                <?php endif; ?>
            </fieldset>
            <div class="form-row">
                <?= $view->partial('partials/input', ['name' => 'loaned_on', 'label' => 'loans.fields.loaned_on', 'value' => $input->loanedOn, 'errors' => $errors, 'type' => 'date', 'required' => true]) ?>
                <?= $view->partial('partials/input', ['name' => 'due_on', 'label' => 'loans.fields.due_on', 'value' => $input->dueOn, 'errors' => $errors, 'type' => 'date', 'hint' => 'loans.lend.due_hint']) ?>
            </div>
            <?= $view->partial('partials/input', ['name' => 'notes', 'label' => 'fields.notes', 'value' => $input->notes, 'errors' => $errors, 'type' => 'textarea', 'rows' => 2, 'maxlength' => 1000]) ?>
            <p class="hint"><?= $view->t('loans.lend.privacy') ?></p>
            <div class="form-actions">
                <button type="submit" class="button button-primary"><?= $view->t('loans.lend.submit') ?></button>
                <a class="button" href="/copies/<?= (int) $copy['id'] ?>"><?= $view->t('actions.cancel') ?></a>
            </div>
        </form>
    <?php endif; ?>
</section>

<?php
/**
 * @var \DigitalLibrary\View\View $view  @var array<string, string> $input  @var array<string, list<string>> $errors
 * @var array<string, string> $roles  value => translated label  @var string $min_expiry  @var string $max_expiry
 */
?>
<section class="form-card">
    <h1><?= $view->t('invitations.admin.new') ?></h1>
    <?php if ($errors !== []): ?><div class="alert alert-error" role="alert"><?= $view->t('validation.summary') ?></div><?php endif; ?>
    <form method="post" action="/admin/invitations" class="form" novalidate>
        <?= $view->csrfField() ?>
        <?= $view->partial('partials/input', ['name' => 'email', 'label' => 'fields.email', 'value' => $input['email'], 'errors' => $errors, 'type' => 'email', 'maxlength' => 254, 'required' => true, 'autocomplete' => 'off']) ?>
        <?= $view->partial('partials/select', ['name' => 'role', 'label' => 'fields.role', 'value' => $input['role'], 'options' => $roles, 'errors' => $errors]) ?>
        <p class="hint"><?= $view->t('invitations.admin.role_hint') ?></p>
        <div class="form-field">
            <label for="expires_on"><?= $view->t('invitations.fields.expires_on') ?></label>
            <input id="expires_on" name="expires_on" type="date" required min="<?= $view->e($min_expiry) ?>" max="<?= $view->e($max_expiry) ?>" value="<?= $view->e($input['expires_on']) ?>" aria-describedby="expires_on-hint<?= !empty($errors['expires_on']) ? ' expires_on-errors' : '' ?>"<?= !empty($errors['expires_on']) ? ' aria-invalid="true"' : '' ?>>
            <p class="hint" id="expires_on-hint"><?= $view->t('invitations.admin.expiry_hint', ['max' => $max_expiry]) ?></p>
            <?= $view->partial('partials/field_errors', ['messages' => $errors['expires_on'] ?? [], 'id' => 'expires_on-errors']) ?>
        </div>
        <div class="form-actions">
            <button type="submit" class="button button-primary"><?= $view->t('invitations.admin.create') ?></button>
            <a class="button" href="/admin/invitations"><?= $view->t('actions.cancel') ?></a>
        </div>
    </form>
</section>

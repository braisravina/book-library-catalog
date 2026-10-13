<?php
/** @var \DigitalLibrary\View\View $view */
/** @var array<string, list<string>> $errors */
$fields = [
    'current_password' => ['label' => 'account.password.current', 'autocomplete' => 'current-password'],
    'new_password' => ['label' => 'account.password.new', 'autocomplete' => 'new-password'],
    'new_password_confirmation' => ['label' => 'account.password.confirm', 'autocomplete' => 'new-password'],
];
?>
<section class="auth-card">
    <h1><?= $view->t('account.password.title') ?></h1>
    <p class="muted"><?= $view->t('account.password.help', ['min' => \DigitalLibrary\Security\PasswordPolicy::MIN_LENGTH]) ?></p>
    <form method="post" action="/account/password" class="form" novalidate>
        <?= $view->csrfField() ?>
        <?php foreach ($fields as $name => $field): $hasError = !empty($errors[$name]); ?>
            <div class="form-field">
                <label for="<?= $name ?>"><?= $view->t($field['label']) ?></label>
                <input id="<?= $name ?>" name="<?= $name ?>" type="password" required maxlength="512"
                       autocomplete="<?= $field['autocomplete'] ?>"<?= $hasError ? ' aria-invalid="true" aria-describedby="' . $name . '-errors"' : '' ?>>
                <?= $view->partial('partials/field_errors', ['messages' => $errors[$name] ?? [], 'id' => $name . '-errors']) ?>
            </div>
        <?php endforeach; ?>
        <button type="submit" class="button button-primary"><?= $view->t('actions.save') ?></button>
    </form>
</section>

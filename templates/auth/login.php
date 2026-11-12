<?php
/** @var \DigitalLibrary\View\View $view */
/** @var string $email */
/** @var string|null $error */
?>
<section class="auth-card">
    <h1><?= $view->t('auth.login.title') ?></h1>
    <?php if ($error !== null): ?>
        <div class="alert alert-error" role="alert" id="login-error"><?= $view->t($error, $error_params ?? []) ?></div>
    <?php endif; ?>
    <form method="post" action="/login" class="form" novalidate>
        <?= $view->csrfField() ?>
        <div class="form-field">
            <label for="email"><?= $view->t('fields.email') ?></label>
            <input id="email" name="email" type="email" autocomplete="username" required maxlength="254"
                   value="<?= $view->e($email) ?>"<?= $error !== null ? ' aria-describedby="login-error" aria-invalid="true"' : '' ?>>
        </div>
        <div class="form-field">
            <label for="password"><?= $view->t('fields.password') ?></label>
            <input id="password" name="password" type="password" autocomplete="current-password" required maxlength="4096">
        </div>
        <button type="submit" class="button button-primary"><?= $view->t('auth.login.submit') ?></button>
    </form>
    <p class="muted small"><?= $view->t('auth.login.no_registration') ?></p>
</section>

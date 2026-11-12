<?php
/**
 * @var \DigitalLibrary\View\View $view  @var array<string, string> $input  @var array<string, list<string>> $errors
 */
?>
<section class="auth-card">
    <h1><?= $view->t('invitations.accept.title') ?></h1>
    <p class="muted"><?= $view->t('invitations.accept.intro') ?></p>
    <?php if ($errors !== []): ?><div class="alert alert-error" role="alert"><?= $view->t('validation.summary') ?></div><?php endif; ?>
    <form method="post" action="/invite/accept" class="form" novalidate>
        <?= $view->csrfField() ?>
        <?= $view->partial('partials/input', ['name' => 'email', 'label' => 'invitations.accept.email', 'value' => $input['email'], 'errors' => $errors, 'type' => 'email', 'maxlength' => 254, 'required' => true, 'autocomplete' => 'username', 'hint' => 'invitations.accept.email_hint']) ?>
        <?= $view->partial('partials/input', ['name' => 'display_name', 'label' => 'fields.display_name', 'value' => $input['display_name'], 'errors' => $errors, 'maxlength' => 100, 'required' => true, 'autocomplete' => 'nickname']) ?>
        <?= $view->partial('partials/input', ['name' => 'password', 'label' => 'fields.password', 'value' => '', 'errors' => $errors, 'type' => 'password', 'maxlength' => 4096, 'required' => true, 'autocomplete' => 'new-password', 'hint' => 'invitations.accept.password_hint']) ?>
        <?= $view->partial('partials/input', ['name' => 'password_confirmation', 'label' => 'invitations.accept.password_confirmation', 'value' => '', 'errors' => $errors, 'type' => 'password', 'maxlength' => 4096, 'required' => true, 'autocomplete' => 'new-password']) ?>
        <button type="submit" class="button button-primary"><?= $view->t('invitations.accept.submit') ?></button>
    </form>
</section>

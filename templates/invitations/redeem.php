<?php
/**
 * Landing page of fragment invitation links (/invite/redeem#CODE). The code
 * after "#" never reaches the server; assets/js/app.js copies it into this
 * form, removes it from the address bar and submits. Without JavaScript the
 * invitee pastes the code. The page itself never contains a token.
 * @var \DigitalLibrary\View\View $view
 */
?>
<section class="auth-card">
    <h1><?= $view->t('invitations.redeem.title') ?></h1>
    <p class="muted"><?= $view->t('invitations.redeem.intro') ?></p>
    <form method="post" action="/invite/redeem" class="form" novalidate data-invitation-fragment>
        <?= $view->csrfField() ?>
        <?= $view->partial('partials/input', ['name' => 'invitation_code', 'label' => 'invitations.redeem.code', 'value' => '', 'errors' => [], 'maxlength' => 43, 'required' => true, 'autocomplete' => 'off', 'hint' => 'invitations.redeem.code_hint']) ?>
        <button type="submit" class="button button-primary"><?= $view->t('invitations.redeem.submit') ?></button>
    </form>
</section>

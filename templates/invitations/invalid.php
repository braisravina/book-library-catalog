<?php
/**
 * Generic answer for every unusable invitation (unknown, expired, used,
 * revoked) and for throttled attempts.
 * @var \DigitalLibrary\View\View $view
 */
?>
<section class="auth-card">
    <h1><?= $view->t('invitations.invalid.title') ?></h1>
    <?php if (isset($retry_minutes)): ?>
        <div class="alert alert-error" role="alert"><?= $view->t('invitations.invalid.throttled', ['minutes' => (int) $retry_minutes]) ?></div>
    <?php else: ?>
        <p><?= $view->t('invitations.invalid.message') ?></p>
    <?php endif; ?>
    <p><a class="button" href="/login"><?= $view->t('invitations.invalid.login') ?></a></p>
</section>

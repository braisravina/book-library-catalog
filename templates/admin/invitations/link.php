<?php
/**
 * One-time display of an invitation link (never stored, never shown again).
 * @var \DigitalLibrary\View\View $view  @var int $invitation_id  @var string $email  @var string $role
 * @var string $expires_at  @var string|null $link  @var bool $replaced
 */
?>
<section class="form-card">
    <h1><?= $view->t('invitations.admin.link_title') ?></h1>
    <div class="alert alert-success" role="status"><?= $view->t($replaced ? 'invitations.admin.replaced_done' : 'invitations.admin.created') ?></div>
    <dl class="details">
        <div><dt><?= $view->t('fields.email') ?></dt><dd><?= $view->e($email) ?></dd></div>
        <div><dt><?= $view->t('fields.role') ?></dt><dd><?= $view->t('roles.' . $role) ?></dd></div>
        <div><dt><?= $view->t('invitations.fields.expires_at') ?></dt><dd><?= $view->e($expires_at) ?> UTC</dd></div>
    </dl>
    <?php if ($link !== null): ?>
        <div class="form-field">
            <label for="invitation-link"><?= $view->t('invitations.admin.link_label') ?></label>
            <input id="invitation-link" type="text" readonly value="<?= $view->e($link) ?>" autocomplete="off" spellcheck="false" aria-describedby="invitation-link-hint">
            <p class="hint" id="invitation-link-hint"><?= $view->t('invitations.admin.link_once') ?></p>
        </div>
        <p><button type="button" class="button" data-copy="invitation-link" data-copied="<?= $view->t('invitations.admin.copied') ?>" hidden><?= $view->t('invitations.admin.copy') ?></button></p>
    <?php else: ?>
        <p><?= $view->t('invitations.admin.delivered') ?></p>
    <?php endif; ?>
    <p><a class="button button-primary" href="/admin/invitations"><?= $view->t('invitations.admin.back') ?></a></p>
</section>

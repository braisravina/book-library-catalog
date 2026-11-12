<?php
/**
 * @var \DigitalLibrary\View\View $view  @var \DigitalLibrary\Auth\User $target  @var bool $is_self
 * @var array<string, string> $old  @var array<string, list<string>> $errors  @var string|null $refusal
 * @var list<\DigitalLibrary\Security\Role> $roles  @var list<string> $locales
 */
$roleOptions = [];
foreach ($roles as $role) {
    $roleOptions[$role->value] = $view->trans($role->labelKey());
}
$localeOptions = [];
foreach ($locales as $code) {
    $localeOptions[$code] = $view->trans('locales.' . $code);
}
?>
<p class="eyebrow"><a href="/admin/users"><?= $view->t('admin.users.title') ?></a></p>
<h1><?= $view->e($target->displayName) ?></h1>
<p class="muted"><?= $view->e($target->email) ?> · <?= $view->t('fields.last_login') ?>: <?= $target->lastLoginAt !== null ? $view->e($target->lastLoginAt . ' UTC') : $view->t('admin.users.never') ?></p>
<?php if ($refusal !== null): ?>
    <div class="alert alert-error" role="alert"><?= $view->t($refusal) ?></div>
<?php endif; ?>

<div class="two-column">
    <section class="form-card" aria-labelledby="profile-heading">
        <h2 id="profile-heading"><?= $view->t('admin.users.profile') ?></h2>
        <form method="post" action="/admin/users/<?= (int) $target->id ?>" class="form" novalidate>
            <?= $view->csrfField() ?>
            <?= $view->partial('partials/input', ['name' => 'display_name', 'label' => 'fields.display_name', 'value' => $old['display_name'], 'errors' => $errors, 'required' => true, 'maxlength' => 100]) ?>
            <?= $view->partial('partials/input', ['name' => 'email', 'label' => 'fields.email', 'value' => $old['email'], 'errors' => $errors, 'required' => true, 'maxlength' => 254, 'type' => 'email', 'autocomplete' => 'off']) ?>
            <?php if ($is_self): ?>
                <input type="hidden" name="role" value="<?= $view->e($old['role']) ?>">
                <input type="hidden" name="is_active" value="1">
                <p class="hint"><?= $view->t('admin.users.self_hint') ?></p>
            <?php else: ?>
                <?= $view->partial('partials/select', ['name' => 'role', 'label' => 'fields.role', 'value' => $old['role'], 'options' => $roleOptions, 'errors' => $errors]) ?>
            <?php endif; ?>
            <?= $view->partial('partials/select', ['name' => 'locale', 'label' => 'fields.locale', 'value' => $old['locale'], 'options' => $localeOptions, 'errors' => $errors]) ?>
            <?php if (!$is_self): ?>
                <label class="checkbox"><input type="checkbox" name="is_active" value="1"<?= $old['is_active'] === '1' ? ' checked' : '' ?>> <?= $view->t('fields.is_active') ?></label>
                <p class="hint"><?= $view->t('admin.users.deactivate_hint') ?></p>
            <?php endif; ?>
            <div class="form-actions">
                <button type="submit" class="button button-primary"><?= $view->t('actions.save') ?></button>
                <a class="button" href="/admin/users"><?= $view->t('actions.cancel') ?></a>
            </div>
        </form>
    </section>

    <?php if (!$is_self): ?>
        <div>
            <section class="form-card" aria-labelledby="password-heading">
                <h2 id="password-heading"><?= $view->t('admin.users.password_reset_title') ?></h2>
                <p class="hint"><?= $view->t('admin.users.password_reset_hint') ?></p>
                <form method="post" action="/admin/users/<?= (int) $target->id ?>/password" class="form" novalidate>
                    <?= $view->csrfField() ?>
                    <?= $view->partial('partials/input', ['name' => 'new_password', 'label' => 'account.password.new', 'value' => '', 'errors' => $errors, 'type' => 'password', 'maxlength' => 512, 'autocomplete' => 'new-password']) ?>
                    <?= $view->partial('partials/input', ['name' => 'new_password_confirmation', 'label' => 'account.password.confirm', 'value' => '', 'errors' => $errors, 'type' => 'password', 'maxlength' => 512, 'autocomplete' => 'new-password']) ?>
                    <button type="submit" class="button"><?= $view->t('admin.users.password_reset_submit') ?></button>
                </form>
            </section>
            <section class="form-card" aria-labelledby="sessions-heading">
                <h2 id="sessions-heading"><?= $view->t('admin.users.sessions_title') ?></h2>
                <p class="hint"><?= $view->t('admin.users.sessions_hint') ?></p>
                <form method="post" action="/admin/users/<?= (int) $target->id ?>/sessions" class="inline-form" data-confirm="<?= $view->t('admin.users.sessions_confirm') ?>">
                    <?= $view->csrfField() ?>
                    <button type="submit" class="button danger"><?= $view->t('admin.users.sessions_submit') ?></button>
                </form>
            </section>
        </div>
    <?php endif; ?>
</div>

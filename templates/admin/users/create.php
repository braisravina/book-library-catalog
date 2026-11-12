<?php
/** @var \DigitalLibrary\View\View $view */
/** @var array<string, string> $old */
/** @var array<string, list<string>> $errors */
/** @var list<\DigitalLibrary\Security\Role> $roles */
/** @var list<string> $locales */
$invalid = static fn (string $f): string => !empty($errors[$f]) ? ' aria-invalid="true" aria-describedby="' . $f . '-errors"' : '';
?>
<section class="form-card">
    <h1><?= $view->t('admin.users.new') ?></h1>
    <form method="post" action="/admin/users" class="form" novalidate>
        <?= $view->csrfField() ?>
        <div class="form-field">
            <label for="display_name"><?= $view->t('fields.display_name') ?></label>
            <input id="display_name" name="display_name" type="text" required maxlength="100" value="<?= $view->e($old['display_name']) ?>"<?= $invalid('display_name') ?>>
            <?= $view->partial('partials/field_errors', ['messages' => $errors['display_name'] ?? [], 'id' => 'display_name-errors']) ?>
        </div>
        <div class="form-field">
            <label for="email"><?= $view->t('fields.email') ?></label>
            <input id="email" name="email" type="email" required maxlength="254" autocomplete="off" value="<?= $view->e($old['email']) ?>"<?= $invalid('email') ?>>
            <?= $view->partial('partials/field_errors', ['messages' => $errors['email'] ?? [], 'id' => 'email-errors']) ?>
        </div>
        <div class="form-field">
            <label for="role"><?= $view->t('fields.role') ?></label>
            <select id="role" name="role"<?= $invalid('role') ?>>
                <?php foreach ($roles as $role): ?>
                    <option value="<?= $view->e($role->value) ?>"<?= $old['role'] === $role->value ? ' selected' : '' ?>><?= $view->t($role->labelKey()) ?></option>
                <?php endforeach; ?>
            </select>
            <?= $view->partial('partials/field_errors', ['messages' => $errors['role'] ?? [], 'id' => 'role-errors']) ?>
        </div>
        <div class="form-field">
            <label for="locale"><?= $view->t('fields.locale') ?></label>
            <select id="locale" name="locale"<?= $invalid('locale') ?>>
                <?php foreach ($locales as $code): ?>
                    <option value="<?= $view->e($code) ?>"<?= $old['locale'] === $code ? ' selected' : '' ?>><?= $view->t('locales.' . $code) ?></option>
                <?php endforeach; ?>
            </select>
            <?= $view->partial('partials/field_errors', ['messages' => $errors['locale'] ?? [], 'id' => 'locale-errors']) ?>
        </div>
        <div class="form-field">
            <label for="password"><?= $view->t('fields.initial_password') ?></label>
            <input id="password" name="password" type="password" required maxlength="512" autocomplete="new-password"<?= $invalid('password') ?>>
            <p class="hint"><?= $view->t('account.password.help', ['min' => \DigitalLibrary\Security\PasswordPolicy::MIN_LENGTH]) ?></p>
            <?= $view->partial('partials/field_errors', ['messages' => $errors['password'] ?? [], 'id' => 'password-errors']) ?>
        </div>
        <div class="form-actions">
            <button type="submit" class="button button-primary"><?= $view->t('actions.create') ?></button>
            <a class="button" href="/admin/users"><?= $view->t('actions.cancel') ?></a>
        </div>
    </form>
</section>

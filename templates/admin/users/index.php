<?php
/** @var \DigitalLibrary\View\View $view */
/** @var list<\DigitalLibrary\Auth\User> $users */
?>
<div class="page-header">
    <h1><?= $view->t('admin.users.title') ?></h1>
    <a class="button button-primary" href="/admin/users/new"><?= $view->t('admin.users.new') ?></a>
</div>
<div class="table-wrap">
    <table class="table">
        <caption class="visually-hidden"><?= $view->t('admin.users.title') ?></caption>
        <thead>
        <tr>
            <th scope="col"><?= $view->t('fields.display_name') ?></th>
            <th scope="col"><?= $view->t('fields.email') ?></th>
            <th scope="col"><?= $view->t('fields.role') ?></th>
            <th scope="col"><?= $view->t('fields.status') ?></th>
            <th scope="col"><?= $view->t('fields.last_login') ?></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($users as $u): ?>
            <tr>
                <td><?= $view->e($u->displayName) ?></td>
                <td><?= $view->e($u->email) ?></td>
                <td><?= $view->t($u->role->labelKey()) ?></td>
                <td><?= $view->t($u->isActive ? 'admin.users.active' : 'admin.users.inactive') ?></td>
                <td><?= $u->lastLoginAt !== null ? $view->e($u->lastLoginAt . ' UTC') : $view->t('admin.users.never') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

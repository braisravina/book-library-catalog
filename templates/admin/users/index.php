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
            <th scope="col"><span class="visually-hidden"><?= $view->t('actions.actions') ?></span></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($users as $u): ?>
            <tr>
                <td><?= $view->e($u->displayName) ?></td>
                <td><?= $view->e($u->email) ?></td>
                <td><?= $view->t($u->role->labelKey()) ?></td>
                <td><?php if ($u->isActive): ?><?= $view->t('admin.users.active') ?><?php else: ?><span class="badge"><?= $view->t('admin.users.inactive') ?></span><?php endif; ?></td>
                <td><?= $u->lastLoginAt !== null ? $view->e($u->lastLoginAt . ' UTC') : $view->t('admin.users.never') ?></td>
                <td class="cell-actions"><a href="/admin/users/<?= (int) $u->id ?>/edit"><?= $view->t('actions.edit') ?></a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

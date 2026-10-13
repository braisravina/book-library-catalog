<?php
/**
 * @var \DigitalLibrary\View\View $view  @var string $status  @var list<string> $filters
 * @var list<array<string, mixed>> $rows  @var int $total  @var int $page  @var int $pages
 * @var string $default_expiry  @var string $max_expiry
 */
?>
<div class="page-header">
    <h1><?= $view->t('invitations.admin.title') ?></h1>
    <a class="button button-primary" href="/admin/invitations/new"><?= $view->t('invitations.admin.new') ?></a>
</div>
<p class="hint"><?= $view->t('invitations.admin.intro') ?></p>
<nav class="tabs" aria-label="<?= $view->t('invitations.admin.filter_label') ?>">
    <?php foreach ($filters as $f): ?>
        <a class="button button-small<?= $status === $f ? ' is-active' : '' ?>" href="<?= $view->e($view->url('/admin/invitations', ['status' => $f === 'pending' ? null : $f])) ?>"<?= $status === $f ? ' aria-current="page"' : '' ?>><?= $view->t('invitations.status.' . $f) ?></a>
    <?php endforeach; ?>
</nav>
<?php if ($rows === []): ?>
    <div class="notice"><p><?= $view->t('invitations.admin.empty') ?></p></div>
<?php else: ?>
    <div class="table-wrap">
        <table class="table">
            <caption class="visually-hidden"><?= $view->t('invitations.admin.title') ?></caption>
            <thead><tr>
                <th scope="col"><?= $view->t('fields.email') ?></th>
                <th scope="col"><?= $view->t('fields.role') ?></th>
                <th scope="col"><?= $view->t('fields.status') ?></th>
                <th scope="col"><?= $view->t('invitations.fields.expires_at') ?></th>
                <th scope="col"><?= $view->t('invitations.fields.invited_by') ?></th>
                <th scope="col"><?= $view->t('actions.actions') ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <?php $id = (int) $row['id']; $open = in_array($row['status'], ['pending', 'expired'], true); ?>
                <tr id="invitation-<?= $id ?>">
                    <td><?= $view->e($row['email']) ?></td>
                    <td><?= $view->t('roles.' . $row['role']) ?></td>
                    <td>
                        <span class="badge<?= $row['status'] === 'pending' ? ' badge-success' : ($row['status'] === 'expired' ? ' badge-warning' : '') ?>"><?= $view->t('invitations.status.' . $row['status']) ?></span>
                        <?php if ($row['status'] === 'accepted' && $row['accepted_user_name'] !== null): ?><br><span class="muted small"><?= $view->e($row['accepted_user_name']) ?></span><?php endif; ?>
                        <?php if ($row['replaced_by_id'] !== null): ?><br><span class="muted small"><?= $view->t('invitations.admin.replaced') ?></span><?php endif; ?>
                    </td>
                    <td><time datetime="<?= $view->e(str_replace(' ', 'T', (string) $row['expires_at'])) ?>Z"><?= $view->e($row['expires_at']) ?></time> <span class="muted small">UTC</span></td>
                    <td><?= $view->e($row['invited_by_name'] ?? '—') ?></td>
                    <td class="cell-actions">
                        <?php if ($open): ?>
                            <div class="inline-actions">
                                <form method="post" action="/admin/invitations/<?= $id ?>/replace">
                                    <?= $view->csrfField() ?>
                                    <input type="hidden" name="expires_on" value="<?= $view->e($default_expiry) ?>">
                                    <button type="submit" class="button button-small"><?= $view->t('invitations.admin.replace') ?><span class="visually-hidden">: <?= $view->e($row['email']) ?></span></button>
                                </form>
                                <?php if ($row['status'] === 'pending'): ?>
                                    <form method="post" action="/admin/invitations/<?= $id ?>/revoke" data-confirm="<?= $view->t('invitations.admin.revoke_confirm') ?>">
                                        <?= $view->csrfField() ?>
                                        <button type="submit" class="button button-small danger"><?= $view->t('invitations.admin.revoke') ?><span class="visually-hidden">: <?= $view->e($row['email']) ?></span></button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
<?= $view->partial('partials/pagination', ['page' => $page, 'pages' => $pages, 'path' => '/admin/invitations', 'query' => ['status' => $status === 'pending' ? null : $status]]) ?>

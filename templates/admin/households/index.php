<?php
/** @var \DigitalLibrary\View\View $view */
/** @var list<array<string, mixed>> $households */
?>
<div class="page-header">
    <h1><?= $view->t('households.title') ?></h1>
    <a class="button button-primary" href="/admin/households/new"><?= $view->t('households.new') ?></a>
</div>
<div class="alert alert-info" role="note"><?= $view->t('households.preview_notice') ?></div>
<?php if ($households === []): ?>
    <p class="muted"><?= $view->t('households.empty') ?></p>
<?php else: ?>
    <div class="table-wrap">
        <table class="table">
            <caption class="visually-hidden"><?= $view->t('households.title') ?></caption>
            <thead>
            <tr>
                <th scope="col"><?= $view->t('fields.name') ?></th>
                <th scope="col"><?= $view->t('households.columns.members') ?></th>
                <th scope="col"><?= $view->t('households.columns.copies') ?></th>
                <th scope="col"><?= $view->t('households.columns.locations') ?></th>
                <th scope="col"><span class="visually-hidden"><?= $view->t('actions.actions') ?></span></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($households as $h): ?>
                <tr>
                    <td><?= $view->e($h['name']) ?><?php if ($h['system_key'] !== null): ?> <span class="badge"><?= $view->t('households.default_badge') ?></span><?php endif; ?></td>
                    <td><?= (int) $h['member_count'] ?></td>
                    <td><?= (int) $h['copy_count'] ?></td>
                    <td><?= (int) $h['location_count'] ?></td>
                    <td class="cell-actions"><a href="/admin/households/<?= (int) $h['id'] ?>/edit"><?= $view->t('actions.edit') ?></a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

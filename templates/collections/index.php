<?php
/**
 * @var \DigitalLibrary\View\View $view  @var string $scope  @var list<string> $scopes
 * @var list<array<string, mixed>> $rows  @var int $total  @var int $page  @var int $pages  @var bool $can_create  @var int $user_id
 */
?>
<div class="page-header">
    <h1><?= $view->t('collections.title') ?></h1>
    <?php if ($can_create): ?><a class="button button-primary" href="/collections/new"><?= $view->t('collections.new') ?></a><?php endif; ?>
</div>
<?php if (count($scopes) > 1): ?>
    <nav class="tabs" aria-label="<?= $view->t('collections.scope.label') ?>">
        <?php foreach ($scopes as $s): ?>
            <a class="button button-small<?= $scope === $s ? ' is-active' : '' ?>" href="<?= $view->e($view->url('/collections', ['scope' => $s])) ?>"<?= $scope === $s ? ' aria-current="page"' : '' ?>><?= $view->t('collections.scope.' . $s) ?></a>
        <?php endforeach; ?>
    </nav>
<?php endif; ?>
<?php if ($rows === []): ?>
    <div class="notice"><p><?= $view->t('collections.empty') ?></p></div>
<?php else: ?>
    <div class="table-wrap">
        <table class="table">
            <caption class="visually-hidden"><?= $view->t('collections.title') ?></caption>
            <thead><tr>
                <th scope="col"><?= $view->t('fields.name') ?></th>
                <th scope="col"><?= $view->t('fields.owner') ?></th>
                <th scope="col"><?= $view->t('collections.fields.visibility') ?></th>
                <th scope="col"><?= $view->t('collections.fields.items') ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $c): ?>
                <tr>
                    <td><a href="/collections/<?= (int) $c['id'] ?>"><?= $view->e($c['name']) ?></a>
                        <?php if ($c['description'] !== null): ?><br><span class="muted small"><?= $view->e(mb_strimwidth((string) $c['description'], 0, 140, '…')) ?></span><?php endif; ?></td>
                    <td><?= (int) $c['owner_user_id'] === $user_id ? $view->t('collections.you') : $view->e($c['owner_name']) ?></td>
                    <td><span class="badge"><?= $view->t('collections.visibility.' . $c['visibility']) ?></span></td>
                    <td><?= (int) $c['item_count'] ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
<?= $view->partial('partials/pagination', ['page' => $page, 'pages' => $pages, 'path' => '/collections', 'query' => ['scope' => $scope]]) ?>

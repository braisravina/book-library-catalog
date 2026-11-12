<?php
/**
 * @var \DigitalLibrary\View\View $view  @var array<string, mixed> $collection  @var list<array<string, mixed>> $items
 * @var int $total  @var int $page  @var int $pages  @var int $offset  @var bool $can_manage  @var bool $is_owner
 */
$id = (int) $collection['id'];
$moves = ['top' => '⇈', 'up' => '↑', 'down' => '↓', 'bottom' => '⇊'];
?>
<p class="eyebrow"><a href="/collections"><?= $view->t('collections.title') ?></a></p>
<div class="page-header">
    <h1><?= $view->e($collection['name']) ?></h1>
    <?php if ($can_manage): ?><a class="button" href="/collections/<?= $id ?>/edit"><?= $view->t('actions.edit') ?></a><?php endif; ?>
</div>
<p class="muted">
    <span class="badge"><?= $view->t('collections.visibility.' . $collection['visibility']) ?></span>
    <?= $is_owner ? $view->t('collections.owned_by_you') : $view->t('collections.owned_by', ['name' => (string) $collection['owner_name']]) ?>
    · <?= $view->t('collections.count', ['count' => $total]) ?>
</p>
<?php if ($collection['description'] !== null): ?>
    <div class="description"><?= nl2br($view->e($collection['description'])) ?></div>
<?php endif; ?>

<?php if ($items === []): ?>
    <div class="notice"><p><?= $view->t($can_manage ? 'collections.items_empty_manage' : 'collections.items_empty') ?></p></div>
<?php else: ?>
    <div class="table-wrap">
        <table class="table">
            <caption class="visually-hidden"><?= $view->t('collections.fields.items') ?></caption>
            <thead><tr>
                <th scope="col">#</th>
                <th scope="col"><span class="visually-hidden"><?= $view->t('fields.cover') ?></span></th>
                <th scope="col"><?= $view->t('fields.title') ?></th>
                <?php if ($can_manage): ?><th scope="col"><?= $view->t('actions.actions') ?></th><?php endif; ?>
            </tr></thead>
            <tbody>
            <?php foreach ($items as $i => $item): ?>
                <?php $editionId = (int) $item['edition_id']; ?>
                <tr id="item-<?= $editionId ?>">
                    <td><?= $offset + $i + 1 ?></td>
                    <td class="cell-cover"><?= $view->partial('partials/cover', ['cover_id' => $item['cover_id'], 'title' => (string) $item['title'], 'authors' => $item['authors'], 'class' => 'cover-mini']) ?></td>
                    <td><a href="/editions/<?= $editionId ?>"><?= $view->e($item['title']) ?></a>
                        <?php if ($item['authors'] !== null): ?><br><span class="muted small"><?= $view->e($item['authors']) ?></span><?php endif; ?>
                        <?php if ($item['publication_year'] !== null): ?><span class="muted small"> · <?= (int) $item['publication_year'] ?></span><?php endif; ?></td>
                    <?php if ($can_manage): ?>
                        <td class="cell-actions">
                            <div class="inline-actions">
                                <?php foreach ($moves as $direction => $symbol): ?>
                                    <form method="post" action="/collections/<?= $id ?>/items/<?= $editionId ?>/move">
                                        <?= $view->csrfField() ?>
                                        <input type="hidden" name="direction" value="<?= $direction ?>">
                                        <input type="hidden" name="page" value="<?= $page ?>">
                                        <button type="submit" class="button button-small" title="<?= $view->t('collections.move.' . $direction) ?>"><span aria-hidden="true"><?= $symbol ?></span><span class="visually-hidden"><?= $view->t('collections.move.' . $direction) ?>: <?= $view->e($item['title']) ?></span></button>
                                    </form>
                                <?php endforeach; ?>
                                <form method="post" action="/collections/<?= $id ?>/items/<?= $editionId ?>/remove" data-confirm="<?= $view->t('collections.remove_confirm') ?>">
                                    <?= $view->csrfField() ?>
                                    <button type="submit" class="button button-small danger"><?= $view->t('collections.remove') ?><span class="visually-hidden">: <?= $view->e($item['title']) ?></span></button>
                                </form>
                            </div>
                        </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
<?= $view->partial('partials/pagination', ['page' => $page, 'pages' => $pages, 'path' => '/collections/' . $id, 'query' => []]) ?>
<?php if ($can_manage): ?><p class="hint"><?= $view->t('collections.add_hint') ?></p><?php endif; ?>

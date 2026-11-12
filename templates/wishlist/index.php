<?php
/**
 * @var \DigitalLibrary\View\View $view  @var string $status  @var list<string> $filters  @var array<string, int> $counts
 * @var list<array<string, mixed>> $rows  @var int $total  @var int $page  @var int $pages
 */
$statusActions = [
    'wanted' => ['acquired', 'archived'],
    'acquired' => ['wanted', 'archived'],
    'archived' => ['wanted'],
];
?>
<div class="page-header">
    <h1><?= $view->t('wishlist.title') ?></h1>
    <a class="button button-primary" href="/wishlist/new"><?= $view->t('wishlist.new') ?></a>
</div>
<p class="hint"><?= $view->t('wishlist.private_hint') ?></p>
<nav class="tabs" aria-label="<?= $view->t('wishlist.filter_label') ?>">
    <?php foreach ($filters as $f): ?>
        <a class="button button-small<?= $status === $f ? ' is-active' : '' ?>" href="<?= $view->e($view->url('/wishlist', ['status' => $f === 'wanted' ? null : $f])) ?>"<?= $status === $f ? ' aria-current="page"' : '' ?>>
            <?= $view->t('wishlist.filter.' . $f) ?><?php if ($f !== 'all'): ?> <span class="badge"><?= (int) ($counts[$f] ?? 0) ?></span><?php endif; ?>
        </a>
    <?php endforeach; ?>
</nav>

<?php if ($rows === []): ?>
    <div class="notice"><p><?= $view->t('wishlist.empty') ?></p></div>
<?php else: ?>
    <div class="table-wrap">
        <table class="table">
            <caption class="visually-hidden"><?= $view->t('wishlist.title') ?></caption>
            <thead><tr>
                <th scope="col"><?= $view->t('fields.title') ?></th>
                <th scope="col"><?= $view->t('wishlist.fields.priority') ?></th>
                <th scope="col"><?= $view->t('fields.status') ?></th>
                <th scope="col"><?= $view->t('fields.notes') ?></th>
                <th scope="col"><span class="visually-hidden"><?= $view->t('actions.actions') ?></span></th>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $item): ?>
                <?php $id = (int) $item['id']; $itemStatus = (string) $item['status']; ?>
                <tr id="item-<?= $id ?>">
                    <td>
                        <?php if ($item['edition_id'] !== null): ?>
                            <a href="/editions/<?= (int) $item['edition_id'] ?>"><?= $view->e($item['title']) ?></a>
                        <?php else: ?>
                            <span class="label"><?= $view->e($item['title']) ?></span>
                        <?php endif; ?>
                        <?php if ($item['author'] !== null): ?><br><span class="muted small"><?= $view->e($item['author']) ?></span><?php endif; ?>
                        <?php if ($item['isbn_13'] !== null): ?><br><span class="muted small">ISBN <?= $view->e($item['isbn_13']) ?></span><?php endif; ?>
                        <?php if ((int) $item['own_copies'] > 0 && $itemStatus === 'wanted'): ?><br><span class="badge badge-success"><?= $view->t('wishlist.already_own') ?></span><?php endif; ?>
                    </td>
                    <td><span class="badge<?= $item['priority'] === 'high' ? ' badge-warning' : '' ?>"><?= $view->t('wishlist.priority.' . $item['priority']) ?></span></td>
                    <td><?= $view->t('wishlist.status.' . $itemStatus) ?><?php if ($item['status_changed_at'] !== null): ?><br><span class="muted small"><time datetime="<?= $view->e(str_replace(' ', 'T', (string) $item['status_changed_at'])) ?>Z"><?= $view->e(substr((string) $item['status_changed_at'], 0, 10)) ?></time></span><?php endif; ?></td>
                    <td><?= $item['notes'] !== null ? nl2br($view->e($item['notes'])) : '' ?></td>
                    <td class="cell-actions">
                        <div class="inline-actions">
                            <?php if ($itemStatus === 'acquired'): ?>
                                <?php
                                $addUrl = $item['edition_id'] !== null
                                    ? '/editions/' . (int) $item['edition_id'] . '/copies/new'
                                    : ($item['isbn_13'] !== null ? $view->url('/books/new', ['isbn' => (string) $item['isbn_13']]) : '/books/new?manual=1');
                                ?>
                                <a class="button button-small button-primary" href="<?= $view->e($addUrl) ?>"><?= $view->t('wishlist.add_to_catalogue') ?></a>
                            <?php endif; ?>
                            <a class="button button-small" href="/wishlist/<?= $id ?>/edit"><?= $view->t('actions.edit') ?><span class="visually-hidden">: <?= $view->e($item['title']) ?></span></a>
                            <?php foreach ($statusActions[$itemStatus] ?? [] as $target): ?>
                                <form method="post" action="/wishlist/<?= $id ?>/status">
                                    <?= $view->csrfField() ?>
                                    <input type="hidden" name="status" value="<?= $view->e($target) ?>">
                                    <button type="submit" class="button button-small"><?= $view->t('wishlist.action.' . $target) ?><span class="visually-hidden">: <?= $view->e($item['title']) ?></span></button>
                                </form>
                            <?php endforeach; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
<?= $view->partial('partials/pagination', ['page' => $page, 'pages' => $pages, 'path' => '/wishlist', 'query' => ['status' => $status === 'wanted' ? null : $status]]) ?>

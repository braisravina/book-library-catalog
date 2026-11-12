<?php
/**
 * @var \DigitalLibrary\View\View $view
 * @var int $page  @var int $pages  @var string $path  @var array<string, scalar|null> $query
 */
if ($pages <= 1) {
    return;
}
$window = array_unique(array_filter([1, $page - 2, $page - 1, $page, $page + 1, $page + 2, $pages], static fn (int $p): bool => $p >= 1 && $p <= $pages));
sort($window);
?>
<nav class="pagination" aria-label="<?= $view->t('pagination.label') ?>">
    <ul>
        <?php if ($page > 1): ?>
            <li><a href="<?= $view->e($view->url($path, ['page' => $page - 1 > 1 ? $page - 1 : null] + $query)) ?>" rel="prev"><?= $view->t('pagination.previous') ?></a></li>
        <?php endif; ?>
        <?php $previous = 0; foreach ($window as $p): ?>
            <?php if ($p > $previous + 1): ?><li class="pagination-gap" aria-hidden="true">…</li><?php endif; ?>
            <li>
                <?php if ($p === $page): ?>
                    <span aria-current="page"><?= $p ?></span>
                <?php else: ?>
                    <a href="<?= $view->e($view->url($path, ['page' => $p > 1 ? $p : null] + $query)) ?>"><?= $p ?></a>
                <?php endif; ?>
            </li>
        <?php $previous = $p; endforeach; ?>
        <?php if ($page < $pages): ?>
            <li><a href="<?= $view->e($view->url($path, ['page' => $page + 1] + $query)) ?>" rel="next"><?= $view->t('pagination.next') ?></a></li>
        <?php endif; ?>
    </ul>
</nav>

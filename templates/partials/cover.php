<?php
/**
 * Cover image or a typographic placeholder.
 * @var \DigitalLibrary\View\View $view
 * @var int|string|null $cover_id  @var string $title  @var string|null $authors
 * Optional: $size ('thumb' | 'full'), $class
 */
$size = $size ?? 'thumb';
?>
<?php if (!empty($cover_id)): ?>
    <img class="cover <?= $view->e($class ?? '') ?>" src="<?= $view->e($view->url('/covers/' . (int) $cover_id, ['size' => $size === 'thumb' ? 'thumb' : null])) ?>"
         alt="<?= $view->t('catalogue.cover.alt', ['title' => $title]) ?>" loading="lazy" decoding="async">
<?php else: ?>
    <div class="cover cover-placeholder <?= $view->e($class ?? '') ?>" aria-hidden="true">
        <span class="cover-placeholder-title"><?= $view->e(mb_strimwidth($title, 0, 80, '…')) ?></span>
        <?php if (!empty($authors)): ?><span class="cover-placeholder-author"><?= $view->e(mb_strimwidth((string) $authors, 0, 60, '…')) ?></span><?php endif; ?>
    </div>
<?php endif; ?>

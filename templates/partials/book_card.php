<?php
/**
 * @var \DigitalLibrary\View\View $view
 * @var array<string, mixed> $book  a CatalogueSearch / recent() row
 */
?>
<li class="book-card">
    <a href="/editions/<?= (int) $book['id'] ?>" class="book-card-link">
        <?= $view->partial('partials/cover', ['cover_id' => $book['cover_id'] ?? null, 'title' => (string) $book['title'], 'authors' => $book['authors'] ?? null]) ?>
        <span class="book-card-title"><?= $view->e($book['title']) ?></span>
    </a>
    <?php if (!empty($book['authors'])): ?><span class="book-card-meta"><?= $view->e($book['authors']) ?></span><?php endif; ?>
    <?php if (!empty($book['publication_year'])): ?><span class="book-card-meta"><?= $view->e($book['publication_year']) ?></span><?php endif; ?>
</li>

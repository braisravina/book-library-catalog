<?php
/**
 * @var \DigitalLibrary\View\View $view  @var array<string, mixed> $work
 * @var list<array<string, mixed>> $authors  @var list<array<string, mixed>> $categories
 * @var list<array<string, mixed>> $editions  @var bool $can_edit  @var bool $can_add_edition  @var bool $can_delete
 */
$authorNames = implode(', ', array_column($authors, 'name'));
?>
<p class="eyebrow"><a href="/books"><?= $view->t('catalogue.title') ?></a> · <?= $view->t('catalogue.work.label') ?></p>
<h1><?= $view->e($work['title']) ?></h1>
<?php if ($work['subtitle'] !== null): ?><p class="lead"><?= $view->e($work['subtitle']) ?></p><?php endif; ?>
<?php if ($authors !== []): ?>
    <p class="byline"><?php foreach ($authors as $i => $author): ?><?= $i > 0 ? ', ' : '' ?><a href="/authors/<?= (int) $author['id'] ?>"><?= $view->e($author['name']) ?></a><?php endforeach; ?></p>
<?php endif; ?>
<?php if ($categories !== []): ?>
    <ul class="tags" aria-label="<?= $view->t('fields.categories') ?>">
        <?php foreach ($categories as $category): ?><li><a href="<?= $view->e($view->url('/books', ['category' => (int) $category['id']])) ?>"><?= $view->e($category['path']) ?></a></li><?php endforeach; ?>
    </ul>
<?php endif; ?>
<?php if ($work['description'] !== null): ?><div class="description"><?= nl2br($view->e($work['description'])) ?></div><?php endif; ?>

<div class="form-actions">
    <?php if ($can_edit): ?><a class="button" href="/works/<?= (int) $work['id'] ?>/edit"><?= $view->t('catalogue.work.edit_title') ?></a><?php endif; ?>
    <?php if ($can_add_edition): ?><a class="button button-primary" href="/works/<?= (int) $work['id'] ?>/editions/new"><?= $view->t('catalogue.edition.new_title') ?></a><?php endif; ?>
</div>

<h2><?= $view->t('catalogue.work.editions', ['count' => count($editions)]) ?></h2>
<?php if ($editions === []): ?>
    <p class="muted"><?= $view->t('catalogue.work.no_editions') ?></p>
<?php else: ?>
    <ul class="cover-grid">
        <?php foreach ($editions as $edition): ?>
            <li class="book-card">
                <a href="/editions/<?= (int) $edition['id'] ?>" class="book-card-link">
                    <?= $view->partial('partials/cover', ['cover_id' => $edition['cover_id'], 'title' => (string) $edition['title'], 'authors' => $authorNames]) ?>
                    <span class="book-card-title"><?= $view->e($edition['title']) ?></span>
                </a>
                <span class="book-card-meta"><?= $view->e(implode(' · ', array_filter([(string) ($edition['publisher_name'] ?? ''), (string) ($edition['publication_year'] ?? '')]))) ?></span>
                <span class="book-card-meta"><?= $view->t('catalogue.copy.count', ['count' => (int) $edition['copy_count']]) ?></span>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<?php if ($can_delete && $editions === []): ?>
    <section class="danger-zone">
        <form method="post" action="/works/<?= (int) $work['id'] ?>/delete" class="inline-form" data-confirm="<?= $view->t('catalogue.work.confirm_delete') ?>">
            <?= $view->csrfField() ?>
            <button type="submit" class="button danger"><?= $view->t('catalogue.work.delete') ?></button>
        </form>
    </section>
<?php endif; ?>

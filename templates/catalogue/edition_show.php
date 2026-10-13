<?php
/**
 * @var \DigitalLibrary\View\View $view  @var array<string, mixed> $edition
 * @var list<array<string, mixed>> $authors  @var list<array<string, mixed>> $contributors
 * @var list<array<string, mixed>> $categories  @var list<array<string, mixed>> $other_editions
 * @var list<array<string, mixed>> $copies  @var list<array<string, mixed>> $covers
 * @var array<int, string> $location_paths  @var bool $can_edit  @var bool $can_edit_work
 * @var bool $can_delete  @var bool $can_add_copy  @var int $max_upload_bytes
 */
$primary = $covers[0] ?? null;
$authorNames = implode(', ', array_column(array_filter($authors, static fn (array $a): bool => $a['role'] === 'author'), 'name'));
$details = [
    'fields.publisher' => $edition['publisher_name'] !== null ? ['link' => '/publishers/' . (int) $edition['publisher_id'], 'text' => $edition['publisher_name']] : null,
    'fields.publication_year' => $edition['publication_year'],
    'fields.edition_statement' => $edition['edition_statement'],
    'fields.language' => $edition['language'] !== null ? $view->t('languages.' . $edition['language']) : null,
    'fields.format' => $edition['format'] !== null ? $view->t('formats.' . $edition['format']) : null,
    'fields.page_count' => $edition['page_count'],
    'fields.isbn_13' => $edition['isbn_13'],
    'fields.isbn_10' => $edition['isbn_10'],
    'fields.legal_deposit' => $edition['legal_deposit'],
    'fields.original_title' => $edition['original_title'],
    'fields.original_language' => $edition['original_language'] !== null ? $view->t('languages.' . $edition['original_language']) : null,
    'fields.first_published_year' => $edition['first_published_year'],
];
?>
<article class="record">
    <div class="record-cover">
        <?= $view->partial('partials/cover', ['cover_id' => $primary['id'] ?? null, 'title' => (string) $edition['title'], 'authors' => $authorNames, 'size' => 'full', 'class' => 'cover-large']) ?>
    </div>
    <div class="record-main">
        <p class="eyebrow"><a href="/books"><?= $view->t('catalogue.title') ?></a></p>
        <h1><?= $view->e($edition['title']) ?></h1>
        <?php if ($edition['subtitle'] !== null): ?><p class="lead"><?= $view->e($edition['subtitle']) ?></p><?php endif; ?>
        <?php if ($authors !== []): ?>
            <p class="byline">
                <?php foreach ($authors as $i => $author): ?><?= $i > 0 ? ', ' : '' ?><a href="/authors/<?= (int) $author['id'] ?>"><?= $view->e($author['name']) ?></a><?php if ($author['role'] !== 'author'): ?> <span class="muted">(<?= $view->t('person_roles.' . $author['role']) ?>)</span><?php endif; ?><?php endforeach; ?>
            </p>
        <?php endif; ?>
        <?php if ($contributors !== []): ?>
            <p class="muted">
                <?php foreach ($contributors as $i => $c): ?><?= $i > 0 ? '; ' : '' ?><?= $view->t('person_roles.' . $c['role']) ?>: <a href="/authors/<?= (int) $c['id'] ?>"><?= $view->e($c['name']) ?></a><?php endforeach; ?>
            </p>
        <?php endif; ?>
        <?php if ($categories !== []): ?>
            <ul class="tags" aria-label="<?= $view->t('fields.categories') ?>">
                <?php foreach ($categories as $category): ?>
                    <li><a href="<?= $view->e($view->url('/books', ['category' => (int) $category['id']])) ?>"><?= $view->e($category['path']) ?></a></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <dl class="details">
            <?php foreach ($details as $label => $value): ?>
                <?php if ($value === null || $value === '') { continue; } ?>
                <div><dt><?= $view->t($label) ?></dt>
                    <dd><?php if (is_array($value)): ?><a href="<?= $view->e($value['link']) ?>"><?= $view->e($value['text']) ?></a><?php else: ?><?= $view->e($value) ?><?php endif; ?></dd></div>
            <?php endforeach; ?>
        </dl>

        <?php if (($edition['description'] ?? $edition['work_description']) !== null): ?>
            <div class="description"><?= nl2br($view->e($edition['description'] ?? $edition['work_description'])) ?></div>
        <?php endif; ?>

        <div class="form-actions">
            <?php if ($can_edit): ?><a class="button" href="/editions/<?= (int) $edition['id'] ?>/edit"><?= $view->t('catalogue.edition.edit_title') ?></a><?php endif; ?>
            <?php if ($can_edit_work): ?><a class="button" href="/works/<?= (int) $edition['work_id'] ?>/edit"><?= $view->t('catalogue.work.edit_title') ?></a><?php endif; ?>
            <a class="button" href="/works/<?= (int) $edition['work_id'] ?>"><?= $view->t('catalogue.work.view_all_editions') ?></a>
        </div>
    </div>
</article>

<section aria-labelledby="copies-heading" id="copies">
    <div class="page-header">
        <h2 id="copies-heading"><?= $view->t('catalogue.copy.heading', ['count' => count($copies)]) ?></h2>
        <?php if ($can_add_copy): ?><a class="button button-primary" href="/editions/<?= (int) $edition['id'] ?>/copies/new"><?= $view->t('catalogue.copy.add') ?></a><?php endif; ?>
    </div>
    <?php if ($copies === []): ?>
        <p class="muted"><?= $view->t('catalogue.copy.none') ?></p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <caption class="visually-hidden"><?= $view->t('catalogue.copy.heading', ['count' => count($copies)]) ?></caption>
                <thead><tr>
                    <th scope="col"><?= $view->t('fields.owner') ?></th>
                    <th scope="col"><?= $view->t('fields.location') ?></th>
                    <th scope="col"><?= $view->t('fields.condition') ?></th>
                    <th scope="col"><?= $view->t('fields.status') ?></th>
                    <th scope="col"><?= $view->t('fields.inventory_code') ?></th>
                    <th scope="col"><span class="visually-hidden"><?= $view->t('actions.actions') ?></span></th>
                </tr></thead>
                <tbody>
                <?php foreach ($copies as $copy): ?>
                    <tr>
                        <td><?= $view->e($copy['owner_name']) ?><?= (int) $copy['is_signed'] === 1 ? ' <span class="badge">' . $view->t('fields.is_signed') . '</span>' : '' ?></td>
                        <td><?= $copy['location_id'] !== null ? $view->e($location_paths[(int) $copy['location_id']] ?? '') : '<span class="muted">' . $view->t('catalogue.unspecified') . '</span>' ?></td>
                        <td><?= $copy['copy_condition'] !== null ? $view->t('conditions.' . $copy['copy_condition']) : '' ?></td>
                        <td><?= $view->t('statuses.' . $copy['status']) ?></td>
                        <td><?= $view->e($copy['inventory_code'] ?? '') ?></td>
                        <td class="cell-actions">
                            <?php if ($copy['can_manage']): ?>
                                <a href="/copies/<?= (int) $copy['id'] ?>/edit"><?= $view->t('actions.edit') ?></a>
                                <form method="post" action="/copies/<?= (int) $copy['id'] ?>/delete" class="inline-form" data-confirm="<?= $view->t('catalogue.copy.confirm_delete') ?>">
                                    <?= $view->csrfField() ?>
                                    <button type="submit" class="link-button danger"><?= $view->t('actions.delete') ?></button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section aria-labelledby="covers-heading" id="covers">
    <h2 id="covers-heading"><?= $view->t('catalogue.cover.heading') ?></h2>
    <?php if ($covers !== []): ?>
        <ul class="cover-strip">
            <?php foreach ($covers as $cover): ?>
                <li>
                    <?= $view->partial('partials/cover', ['cover_id' => $cover['id'], 'title' => (string) $edition['title'], 'class' => 'cover-mini']) ?>
                    <?php if ((int) $cover['is_primary'] === 1): ?><span class="badge"><?= $view->t('catalogue.cover.primary') ?></span><?php endif; ?>
                    <?php if ($can_edit): ?>
                        <form method="post" action="/covers/<?= (int) $cover['id'] ?>/delete" class="inline-form" data-confirm="<?= $view->t('catalogue.cover.confirm_delete') ?>">
                            <?= $view->csrfField() ?>
                            <button type="submit" class="link-button danger"><?= $view->t('actions.delete') ?></button>
                        </form>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php else: ?>
        <p class="muted"><?= $view->t('catalogue.cover.none') ?></p>
    <?php endif; ?>
    <?php if ($can_edit): ?>
        <form method="post" action="/editions/<?= (int) $edition['id'] ?>/covers" enctype="multipart/form-data" class="form upload-form">
            <?= $view->csrfField() ?>
            <input type="hidden" name="MAX_FILE_SIZE" value="<?= (int) $max_upload_bytes ?>">
            <div class="form-field">
                <label for="cover"><?= $view->t('catalogue.cover.upload') ?></label>
                <input id="cover" name="cover" type="file" accept="image/jpeg,image/png,image/webp" required aria-describedby="cover-hint">
                <p class="hint" id="cover-hint"><?= $view->t('catalogue.cover.hint', ['size' => (int) round($max_upload_bytes / 1048576)]) ?></p>
            </div>
            <button type="submit" class="button"><?= $view->t('catalogue.cover.submit') ?></button>
        </form>
    <?php endif; ?>
</section>

<?php if ($other_editions !== []): ?>
    <section aria-labelledby="other-heading">
        <h2 id="other-heading"><?= $view->t('catalogue.edition.other_editions') ?></h2>
        <ul class="simple-list">
            <?php foreach ($other_editions as $other): ?>
                <li><a href="/editions/<?= (int) $other['id'] ?>"><?= $view->e($other['title']) ?></a>
                    <span class="muted"><?= $view->e(implode(' · ', array_filter([(string) ($other['publisher_name'] ?? ''), (string) ($other['publication_year'] ?? ''), (string) ($other['isbn_13'] ?? '')]))) ?></span></li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endif; ?>

<?php if ($can_delete): ?>
    <section class="danger-zone" aria-labelledby="danger-heading">
        <h2 id="danger-heading"><?= $view->t('catalogue.danger_zone') ?></h2>
        <form method="post" action="/editions/<?= (int) $edition['id'] ?>/delete" class="inline-form" data-confirm="<?= $view->t('catalogue.edition.confirm_delete') ?>">
            <?= $view->csrfField() ?>
            <button type="submit" class="button danger"><?= $view->t('catalogue.edition.delete') ?></button>
        </form>
        <p class="hint"><?= $view->t('catalogue.edition.delete_hint') ?></p>
    </section>
<?php endif; ?>

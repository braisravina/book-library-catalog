<?php
/**
 * @var \DigitalLibrary\View\View $view
 * @var \DigitalLibrary\Catalogue\SearchCriteria $criteria
 * @var array{rows: list<array<string, mixed>>, total: int, page: int, pages: int} $result
 * @var list<array<string, mixed>> $category_tree  @var list<array<string, mixed>> $location_tree
 * @var list<\DigitalLibrary\Auth\User> $owners  @var list<string> $languages
 */
$base = $criteria->toQuery(['page' => null]);
$canCreate = $authorizer->can($current_user, \DigitalLibrary\Security\Permission::CatalogueCreate);
?>
<div class="page-header">
    <h1><?= $view->t('catalogue.title') ?></h1>
    <?php if ($canCreate): ?><a class="button button-primary" href="/books/new"><?= $view->t('catalogue.add.title') ?></a><?php endif; ?>
</div>

<form method="get" action="/books" class="search-form" role="search">
    <div class="search-main">
        <label for="q" class="visually-hidden"><?= $view->t('catalogue.search.label') ?></label>
        <input id="q" name="q" type="search" value="<?= $view->e($criteria->query) ?>" maxlength="200"
               placeholder="<?= $view->t('catalogue.search.placeholder') ?>">
        <button type="submit" class="button button-primary"><?= $view->t('catalogue.search.submit') ?></button>
    </div>
    <?php $filtersOpen = $criteria->categoryId !== null || $criteria->language !== null || $criteria->ownerId !== null || $criteria->locationId !== null || $criteria->sort !== 'title'; ?>
    <details class="filters"<?= $filtersOpen ? ' open' : '' ?>>
        <summary><?= $view->t('catalogue.filters.title') ?></summary>
        <div class="filter-grid">
            <div class="form-field">
                <label for="category"><?= $view->t('fields.category') ?></label>
                <select id="category" name="category">
                    <option value=""><?= $view->t('catalogue.filters.any') ?></option>
                    <?= $view->partial('partials/tree_options', ['tree' => $category_tree, 'selected' => $criteria->categoryId]) ?>
                </select>
            </div>
            <div class="form-field">
                <label for="language"><?= $view->t('fields.language') ?></label>
                <select id="language" name="language">
                    <option value=""><?= $view->t('catalogue.filters.any') ?></option>
                    <?php foreach ($languages as $code): ?>
                        <option value="<?= $view->e($code) ?>"<?= $criteria->language === $code ? ' selected' : '' ?>><?= $view->t('languages.' . $code) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-field">
                <label for="owner"><?= $view->t('fields.owner') ?></label>
                <select id="owner" name="owner">
                    <option value=""><?= $view->t('catalogue.filters.any') ?></option>
                    <?php foreach ($owners as $owner): ?>
                        <option value="<?= $owner->id ?>"<?= $criteria->ownerId === $owner->id ? ' selected' : '' ?>><?= $view->e($owner->displayName) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-field">
                <label for="location"><?= $view->t('fields.location') ?></label>
                <select id="location" name="location">
                    <option value=""><?= $view->t('catalogue.filters.any') ?></option>
                    <?= $view->partial('partials/tree_options', ['tree' => $location_tree, 'selected' => $criteria->locationId]) ?>
                </select>
            </div>
            <div class="form-field">
                <label for="sort"><?= $view->t('catalogue.sort.label') ?></label>
                <select id="sort" name="sort">
                    <?php foreach (\DigitalLibrary\Catalogue\SearchCriteria::SORTS as $sort): ?>
                        <option value="<?= $sort ?>"<?= $criteria->sort === $sort ? ' selected' : '' ?>><?= $view->t('catalogue.sort.' . $sort) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <input type="hidden" name="view" value="<?= $view->e($criteria->view) ?>">
        <div class="form-actions">
            <button type="submit" class="button"><?= $view->t('catalogue.filters.apply') ?></button>
            <a class="button" href="/books"><?= $view->t('catalogue.filters.clear') ?></a>
        </div>
    </details>
</form>

<div class="results-bar">
    <p class="muted" role="status"><?= $view->t('catalogue.results', ['count' => $result['total']]) ?></p>
    <div class="view-toggle" role="group" aria-label="<?= $view->t('catalogue.view.label') ?>">
        <?php foreach (\DigitalLibrary\Catalogue\SearchCriteria::VIEWS as $mode): ?>
            <a class="button button-small<?= $criteria->view === $mode ? ' is-active' : '' ?>"
               href="<?= $view->e($view->url('/books', ['view' => $mode === 'grid' ? null : $mode] + $base)) ?>"
               <?= $criteria->view === $mode ? 'aria-current="true"' : '' ?>><?= $view->t('catalogue.view.' . $mode) ?></a>
        <?php endforeach; ?>
    </div>
</div>

<?php if ($result['rows'] === []): ?>
    <div class="notice">
        <p><?= $view->t($criteria->hasFilters() ? 'catalogue.empty_filtered' : 'catalogue.empty') ?></p>
    </div>
<?php elseif ($criteria->view === 'list'): ?>
    <div class="table-wrap">
        <table class="table book-table">
            <caption class="visually-hidden"><?= $view->t('catalogue.title') ?></caption>
            <thead>
            <tr>
                <th scope="col"><span class="visually-hidden"><?= $view->t('fields.cover') ?></span></th>
                <th scope="col"><?= $view->t('fields.title') ?></th>
                <th scope="col"><?= $view->t('fields.authors') ?></th>
                <th scope="col"><?= $view->t('fields.publisher') ?></th>
                <th scope="col"><?= $view->t('fields.year') ?></th>
                <th scope="col"><?= $view->t('fields.language') ?></th>
                <th scope="col"><?= $view->t('fields.copies') ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($result['rows'] as $book): ?>
                <tr>
                    <td class="cell-cover"><?= $view->partial('partials/cover', ['cover_id' => $book['cover_id'], 'title' => (string) $book['title'], 'authors' => null, 'class' => 'cover-mini']) ?></td>
                    <td><a href="/editions/<?= (int) $book['id'] ?>"><?= $view->e($book['title']) ?></a>
                        <?php if (!empty($book['subtitle'])): ?><br><span class="muted small"><?= $view->e($book['subtitle']) ?></span><?php endif; ?></td>
                    <td><?= $view->e($book['authors'] ?? '') ?></td>
                    <td><?= $view->e($book['publisher_name'] ?? '') ?></td>
                    <td><?= $view->e($book['publication_year'] ?? '') ?></td>
                    <td><?= $book['language'] ? $view->t('languages.' . $book['language']) : '' ?></td>
                    <td><?= (int) $book['copy_count'] ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php else: ?>
    <ul class="cover-grid">
        <?php foreach ($result['rows'] as $book): ?>
            <?= $view->partial('partials/book_card', ['book' => $book]) ?>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<?= $view->partial('partials/pagination', ['page' => $result['page'], 'pages' => $result['pages'], 'path' => '/books', 'query' => $base]) ?>

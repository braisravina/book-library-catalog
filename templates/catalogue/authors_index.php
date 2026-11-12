<?php
/**
 * @var \DigitalLibrary\View\View $view  @var string $query  @var list<array<string, mixed>> $rows
 * @var int $page  @var int $pages  @var int $total
 */
?>
<h1><?= $view->t('catalogue.authors.title') ?></h1>
<form method="get" action="/authors" class="search-form" role="search">
    <div class="search-main">
        <label for="q" class="visually-hidden"><?= $view->t('catalogue.authors.search') ?></label>
        <input id="q" name="q" type="search" value="<?= $view->e($query) ?>" maxlength="100" placeholder="<?= $view->t('catalogue.authors.search') ?>">
        <button type="submit" class="button button-primary"><?= $view->t('catalogue.search.submit') ?></button>
    </div>
</form>
<p class="muted" role="status"><?= $view->t('catalogue.results', ['count' => $total]) ?></p>
<?php if ($rows !== []): ?>
    <div class="table-wrap">
        <table class="table">
            <caption class="visually-hidden"><?= $view->t('catalogue.authors.title') ?></caption>
            <thead><tr><th scope="col"><?= $view->t('fields.name') ?></th><th scope="col"><?= $view->t('fields.dates') ?></th><th scope="col"><?= $view->t('catalogue.authors.works') ?></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td><a href="/authors/<?= (int) $row['id'] ?>"><?= $view->e($row['sort_name']) ?></a></td>
                    <td><?= $view->e(trim(($row['birth_year'] ?? '') . ($row['birth_year'] !== null || $row['death_year'] !== null ? '–' : '') . ($row['death_year'] ?? ''))) ?></td>
                    <td><?= (int) $row['work_count'] ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
<?= $view->partial('partials/pagination', ['page' => $page, 'pages' => $pages, 'path' => '/authors', 'query' => ['q' => $query]]) ?>

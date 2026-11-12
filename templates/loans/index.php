<?php
/**
 * @var \DigitalLibrary\View\View $view  @var string $scope  @var string $status  @var list<string> $statuses
 * @var list<string> $scopes  @var list<array<string, mixed>> $rows  @var int $total  @var int $page  @var int $pages
 * @var string $today
 */
?>
<h1><?= $view->t('loans.title') ?></h1>
<nav class="tabs" aria-label="<?= $view->t('loans.scope.label') ?>">
    <?php foreach ($scopes as $s): ?>
        <a class="button button-small<?= $scope === $s ? ' is-active' : '' ?>" href="<?= $view->e($view->url('/loans', ['scope' => $s, 'status' => $status === 'active' ? null : $status])) ?>"<?= $scope === $s ? ' aria-current="page"' : '' ?>><?= $view->t('loans.scope.' . $s) ?></a>
    <?php endforeach; ?>
</nav>
<form method="get" action="/loans" class="filter-inline">
    <input type="hidden" name="scope" value="<?= $view->e($scope) ?>">
    <label for="status"><?= $view->t('fields.status') ?></label>
    <select id="status" name="status" data-autosubmit>
        <?php foreach ($statuses as $s): ?>
            <option value="<?= $s ?>"<?= $status === $s ? ' selected' : '' ?>><?= $view->t('loans.filter.' . $s) ?></option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="button button-small" data-autosubmit-fallback><?= $view->t('actions.apply') ?></button>
</form>
<p class="muted" role="status"><?= $view->t('catalogue.results', ['count' => $total]) ?></p>
<?php if ($rows === []): ?>
    <div class="notice"><p><?= $view->t('loans.empty') ?></p></div>
<?php else: ?>
    <div class="table-wrap">
        <table class="table">
            <caption class="visually-hidden"><?= $view->t('loans.title') ?></caption>
            <thead><tr>
                <th scope="col"><?= $view->t('fields.title') ?></th>
                <?php if ($scope !== 'lent'): ?><th scope="col"><?= $view->t('fields.owner') ?></th><?php endif; ?>
                <?php if ($scope !== 'borrowed'): ?><th scope="col"><?= $view->t('loans.fields.borrower') ?></th><?php endif; ?>
                <th scope="col"><?= $view->t('loans.fields.loaned_on') ?></th>
                <th scope="col"><?= $view->t('loans.fields.due_on') ?></th>
                <th scope="col"><?= $view->t('loans.fields.returned_on') ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $loan): ?>
                <tr>
                    <td><a href="/copies/<?= (int) $loan['copy_id'] ?>"><?= $view->e($loan['edition_title']) ?></a></td>
                    <?php if ($scope !== 'lent'): ?><td><?= $view->e($loan['owner_name']) ?></td><?php endif; ?>
                    <?php if ($scope !== 'borrowed'): ?><td><?= $view->e($loan['borrower_display_name'] ?? $loan['borrower_name'] ?? '') ?></td><?php endif; ?>
                    <td><?= $view->e($loan['loaned_on']) ?></td>
                    <td><?= $view->e($loan['due_on'] ?? '') ?><?php if ((int) $loan['is_overdue'] === 1): ?> <span class="badge badge-warning"><?= $view->t('loans.overdue') ?></span><?php endif; ?></td>
                    <td><?= $loan['returned_on'] !== null ? $view->e($loan['returned_on']) : '<span class="muted">' . $view->t('loans.not_returned') . '</span>' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
<?= $view->partial('partials/pagination', ['page' => $page, 'pages' => $pages, 'path' => '/loans', 'query' => ['scope' => $scope, 'status' => $status === 'active' ? null : $status]]) ?>

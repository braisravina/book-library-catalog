<?php
/**
 * @var \DigitalLibrary\View\View $view  @var \DigitalLibrary\Audit\AuditQuery $query
 * @var list<array<string, mixed>> $rows  @var int|null $next  @var bool $timed_out
 * @var list<string> $actions  @var list<string> $entity_types  @var list<\DigitalLibrary\Auth\User> $users  @var int $per_page
 */
$filters = $query->query();
?>
<h1><?= $view->t('audit.title') ?></h1>
<p class="hint"><?= $view->t('audit.timezone_note') ?></p>

<form method="get" action="/admin/audit" class="search-form">
    <div class="filter-grid">
        <div class="form-field">
            <label for="from"><?= $view->t('audit.filter.from') ?></label>
            <input id="from" name="from" type="date" value="<?= $view->e($query->from) ?>">
        </div>
        <div class="form-field">
            <label for="to"><?= $view->t('audit.filter.to') ?></label>
            <input id="to" name="to" type="date" value="<?= $view->e($query->to) ?>">
        </div>
        <div class="form-field">
            <label for="action"><?= $view->t('audit.fields.action') ?></label>
            <select id="action" name="action">
                <option value=""><?= $view->t('audit.filter.any') ?></option>
                <?php foreach ($actions as $a): ?>
                    <option value="<?= $view->e($a) ?>"<?= $query->action === $a ? ' selected' : '' ?>><?= $view->e($a) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-field">
            <label for="user"><?= $view->t('audit.fields.user') ?></label>
            <select id="user" name="user">
                <option value=""><?= $view->t('audit.filter.any') ?></option>
                <?php foreach ($users as $u): ?>
                    <option value="<?= (int) $u->id ?>"<?= $query->userId === $u->id ? ' selected' : '' ?>><?= $view->e($u->displayName) ?> (<?= $view->e($u->email) ?>)</option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-field">
            <label for="entity_type"><?= $view->t('audit.fields.entity_type') ?></label>
            <select id="entity_type" name="entity_type">
                <option value=""><?= $view->t('audit.filter.any') ?></option>
                <?php foreach ($entity_types as $t): ?>
                    <option value="<?= $view->e($t) ?>"<?= $query->entityType === $t ? ' selected' : '' ?>><?= $view->e($t) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-field">
            <label for="entity_id"><?= $view->t('audit.fields.entity_id') ?></label>
            <input id="entity_id" name="entity_id" type="text" inputmode="numeric" value="<?= $view->e((string) ($query->entityId ?? '')) ?>">
        </div>
        <div class="form-field">
            <label for="q"><?= $view->t('audit.filter.search') ?></label>
            <input id="q" name="q" type="search" maxlength="100" value="<?= $view->e($query->search ?? '') ?>" aria-describedby="q-hint">
            <p class="hint" id="q-hint"><?= $view->t('audit.filter.search_hint') ?></p>
        </div>
    </div>
    <div class="form-actions">
        <button type="submit" class="button button-primary"><?= $view->t('actions.apply') ?></button>
        <a class="button" href="/admin/audit"><?= $view->t('audit.filter.reset') ?></a>
    </div>
</form>

<?php foreach ($query->errors as $error): ?>
    <div class="alert alert-info" role="status"><?= $view->t($error, ['days' => \DigitalLibrary\Audit\AuditQuery::MAX_DAYS, 'min' => \DigitalLibrary\Audit\AuditQuery::MIN_SEARCH, 'max' => \DigitalLibrary\Audit\AuditQuery::MAX_SEARCH]) ?></div>
<?php endforeach; ?>
<?php if ($timed_out): ?>
    <div class="alert alert-error" role="alert"><?= $view->t('audit.timed_out') ?></div>
<?php endif; ?>

<p class="muted" role="status"><?= $view->t('audit.range', ['from' => $query->from, 'to' => $query->to]) ?></p>
<?php if ($rows === []): ?>
    <div class="notice"><p><?= $view->t('audit.empty') ?></p></div>
<?php else: ?>
    <div class="table-wrap">
        <table class="table">
            <caption class="visually-hidden"><?= $view->t('audit.title') ?></caption>
            <thead><tr>
                <th scope="col"><?= $view->t('audit.fields.time') ?></th>
                <th scope="col"><?= $view->t('audit.fields.action') ?></th>
                <th scope="col"><?= $view->t('audit.fields.user') ?></th>
                <th scope="col"><?= $view->t('audit.fields.entity') ?></th>
                <th scope="col"><?= $view->t('audit.fields.ip') ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td><a href="/admin/audit/<?= (int) $row['id'] ?>"><time datetime="<?= $view->e(str_replace(' ', 'T', (string) $row['created_at'])) ?>Z"><?= $view->e($row['created_at']) ?></time></a></td>
                    <td><code><?= $view->e($row['action']) ?></code></td>
                    <td><?= $row['user_id'] === null ? '<span class="muted">' . $view->t('audit.no_user') . '</span>' : $view->e($row['user_name'] ?? '#' . $row['user_id']) ?></td>
                    <td><?= $row['entity_type'] !== null ? $view->e($row['entity_type'] . ($row['entity_id'] !== null ? ' #' . $row['entity_id'] : '')) : '' ?></td>
                    <td><?= $view->e($row['ip_address'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
<nav class="pagination" aria-label="<?= $view->t('pagination.label') ?>">
    <ul>
        <?php if ($query->before !== null): ?>
            <li><a href="<?= $view->e($view->url('/admin/audit', $filters)) ?>"><?= $view->t('audit.newest') ?></a></li>
        <?php endif; ?>
        <?php if ($next !== null): ?>
            <li><a href="<?= $view->e($view->url('/admin/audit', $filters + ['before' => $next])) ?>" rel="next"><?= $view->t('audit.older') ?></a></li>
        <?php endif; ?>
    </ul>
</nav>

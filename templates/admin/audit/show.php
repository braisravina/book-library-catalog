<?php
/**
 * @var \DigitalLibrary\View\View $view  @var array<string, mixed> $event  @var string|null $details
 */
?>
<p class="eyebrow"><a href="/admin/audit"><?= $view->t('audit.title') ?></a></p>
<h1><?= $view->t('audit.event.title') ?> #<?= (int) $event['id'] ?></h1>
<dl class="details">
    <div><dt><?= $view->t('audit.fields.time') ?></dt><dd><time datetime="<?= $view->e(str_replace(' ', 'T', (string) $event['created_at'])) ?>Z"><?= $view->e($event['created_at']) ?> UTC</time></dd></div>
    <div><dt><?= $view->t('audit.fields.action') ?></dt><dd><code><?= $view->e($event['action']) ?></code></dd></div>
    <div><dt><?= $view->t('audit.fields.user') ?></dt><dd>
        <?php if ($event['user_id'] === null): ?>
            <span class="muted"><?= $view->t('audit.no_user') ?></span>
        <?php elseif ($event['user_name'] === null): ?>
            #<?= (int) $event['user_id'] ?>
        <?php else: ?>
            <a href="/admin/users/<?= (int) $event['user_id'] ?>/edit"><?= $view->e($event['user_name']) ?></a> <span class="muted small"><?= $view->e($event['user_email']) ?></span>
        <?php endif; ?>
    </dd></div>
    <div><dt><?= $view->t('audit.fields.entity') ?></dt><dd><?= $event['entity_type'] !== null ? $view->e($event['entity_type'] . ($event['entity_id'] !== null ? ' #' . $event['entity_id'] : '')) : '—' ?></dd></div>
    <div><dt><?= $view->t('audit.fields.ip') ?></dt><dd><?= $view->e($event['ip_address'] ?? '—') ?></dd></div>
    <div><dt><?= $view->t('audit.fields.user_agent') ?></dt><dd><?= $view->e($event['user_agent'] ?? '—') ?></dd></div>
</dl>
<h2><?= $view->t('audit.fields.details') ?></h2>
<?php if ($details === null): ?>
    <p class="muted"><?= $view->t('audit.no_details') ?></p>
<?php else: ?>
    <pre class="json-view"><code><?= $view->e($details) ?></code></pre>
<?php endif; ?>
<p class="hint"><?= $view->t('audit.read_only') ?></p>
<p><a class="button" href="<?= $view->e($view->url('/admin/audit', ['action' => (string) $event['action']])) ?>"><?= $view->t('audit.same_action') ?></a></p>

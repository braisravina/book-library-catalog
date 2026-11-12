<?php /** @var \DigitalLibrary\View\View $view  @var list<array<string, mixed>> $publishers */ ?>
<h1><?= $view->t('catalogue.publishers.title') ?></h1>
<?php if ($publishers === []): ?>
    <p class="muted"><?= $view->t('catalogue.publishers.none') ?></p>
<?php else: ?>
    <div class="table-wrap">
        <table class="table">
            <caption class="visually-hidden"><?= $view->t('catalogue.publishers.title') ?></caption>
            <thead><tr><th scope="col"><?= $view->t('fields.name') ?></th><th scope="col"><?= $view->t('catalogue.publishers.editions') ?></th></tr></thead>
            <tbody>
            <?php foreach ($publishers as $p): ?>
                <tr><td><a href="/publishers/<?= (int) $p['id'] ?>"><?= $view->e($p['name']) ?></a></td><td><?= (int) $p['edition_count'] ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

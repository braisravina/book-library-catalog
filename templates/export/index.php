<?php
/**
 * @var \DigitalLibrary\View\View $view  @var list<string> $personal  @var list<string> $catalogue
 */
?>
<h1><?= $view->t('export.title') ?></h1>
<p class="lead"><?= $view->t('export.intro') ?></p>

<section aria-labelledby="personal-heading">
    <h2 id="personal-heading"><?= $view->t('export.personal.title') ?></h2>
    <p><?= $view->t('export.personal.explain') ?></p>
    <p><a class="button button-primary" href="/export/personal.json" download><?= $view->t('export.personal.json') ?></a></p>
    <div class="table-wrap">
        <table class="table">
            <caption class="visually-hidden"><?= $view->t('export.personal.title') ?></caption>
            <thead><tr><th scope="col"><?= $view->t('export.dataset') ?></th><th scope="col"><?= $view->t('export.contents') ?></th><th scope="col"><?= $view->t('export.download') ?></th></tr></thead>
            <tbody>
            <?php foreach ($personal as $dataset): ?>
                <tr>
                    <th scope="row"><?= $view->t('export.datasets.' . $dataset) ?></th>
                    <td class="small"><?= $view->t('export.describe.personal_' . $dataset) ?></td>
                    <td><a href="/export/personal/<?= $view->e($dataset) ?>.csv" download>CSV<span class="visually-hidden">: <?= $view->t('export.datasets.' . $dataset) ?></span></a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php if ($catalogue !== []): ?>
    <section aria-labelledby="catalogue-heading">
        <h2 id="catalogue-heading"><?= $view->t('export.catalogue.title') ?></h2>
        <p><?= $view->t('export.catalogue.explain') ?></p>
        <div class="notice"><p><?= $view->t('export.catalogue.excluded') ?></p></div>
        <p><a class="button button-primary" href="/admin/export/catalogue.json" download><?= $view->t('export.catalogue.json') ?></a></p>
        <div class="table-wrap">
            <table class="table">
                <caption class="visually-hidden"><?= $view->t('export.catalogue.title') ?></caption>
                <thead><tr><th scope="col"><?= $view->t('export.dataset') ?></th><th scope="col"><?= $view->t('export.download') ?></th></tr></thead>
                <tbody>
                <?php foreach ($catalogue as $dataset): ?>
                    <tr>
                        <th scope="row"><?= $view->t('export.datasets.' . $dataset) ?></th>
                        <td><a href="/admin/export/<?= $view->e($dataset) ?>.csv" download>CSV<span class="visually-hidden">: <?= $view->t('export.datasets.' . $dataset) ?></span></a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<section aria-labelledby="formats-heading">
    <h2 id="formats-heading"><?= $view->t('export.formats.title') ?></h2>
    <ul class="simple-list">
        <li><?= $view->t('export.formats.csv') ?></li>
        <li><?= $view->t('export.formats.json') ?></li>
    </ul>
</section>

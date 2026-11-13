<?php
/**
 * Optional, manual contribution to Open Library. Nothing is sent from here:
 * the user chooses the fields, copies the prepared text and opens Open
 * Library (or their e-mail) in their own browser, where they log in and
 * submit. Read-only GET page; the text holds only allowlisted bibliographic
 * fields the user selected.
 * @var \DigitalLibrary\View\View $view  @var array<string, mixed> $edition
 * @var \DigitalLibrary\Metadata\OpenLibraryContribution $plan  @var list<string> $fields
 * @var string|null $text  @var array<string, string> $links
 */
use DigitalLibrary\Metadata\OpenLibraryContribution;

$labels = [
    'isbn_13' => 'fields.isbn_13', 'isbn_10' => 'fields.isbn_10', 'title' => 'fields.title', 'subtitle' => 'fields.subtitle',
    'authors' => 'fields.authors', 'contributors' => 'fields.contributors', 'publisher' => 'fields.publisher',
    'publication_year' => 'fields.publication_year', 'language' => 'fields.language', 'page_count' => 'fields.page_count',
    'edition_statement' => 'fields.edition_statement', 'format' => 'fields.format', 'legal_deposit' => 'fields.legal_deposit',
    'description' => 'fields.description',
];
$external = ' rel="noopener noreferrer external"';
?>
<section class="form-card form-card-wide open-library">
    <p class="eyebrow"><a href="/editions/<?= (int) $edition['id'] ?>"><?= $view->e($edition['title']) ?></a></p>
    <h1><?= $view->t('openlibrary.title') ?></h1>
    <p><?= $view->t('openlibrary.intro') ?></p>
    <p class="hint"><?= $view->t('openlibrary.nothing_sent') ?></p>

    <div class="alert alert-info open-library-situation">
        <h2><?= $view->t('openlibrary.situation_title.' . $plan->situation) ?></h2>
        <p><?= $view->t('openlibrary.situation.' . $plan->situation) ?></p>
        <?php if ($plan->problems !== []): ?>
            <ul>
                <?php foreach ($plan->problems as $p): ?>
                    <li><a href="<?= $view->e(OpenLibraryContribution::BASE . '/books/' . $p['edition']) ?>"<?= $external ?>><?= $view->e($p['title'] !== '' ? $p['title'] : $p['edition']) ?></a>
                        <span class="muted"><?= $p['authors'] !== [] ? $view->e(implode(', ', $p['authors'])) : $view->t('openlibrary.no_author') ?> · <?= $view->e($p['edition']) ?></span></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <?php if ($plan->missingThere !== []): ?>
            <p><?= $view->t('openlibrary.missing_there') ?>
                <?= $view->e(implode(', ', array_map(static fn (string $f): string => $view->trans($labels[$f]), $plan->missingThere))) ?></p>
        <?php endif; ?>
    </div>

    <form method="get" action="/editions/<?= (int) $edition['id'] ?>/open-library" class="form">
        <input type="hidden" name="prepared" value="1">
        <fieldset>
            <legend><?= $view->t('openlibrary.choose_fields') ?></legend>
            <p class="hint"><?= $view->t('openlibrary.choose_help') ?></p>
            <?php foreach (OpenLibraryContribution::FIELDS as $field => $definition): ?>
                <?php if (!isset($plan->values[$field])) {
                    continue;
                } ?>
                <?php $id = 'ol-field-' . $field; $support = $plan->support[$field] ?? []; ?>
                <div class="checkbox open-library-field">
                    <input type="checkbox" id="<?= $id ?>" name="fields[]" value="<?= $field ?>"<?= in_array($field, $fields, true) ? ' checked' : '' ?> aria-describedby="<?= $id ?>-source">
                    <label for="<?= $id ?>"><strong><?= $view->t($labels[$field]) ?>:</strong>
                        <span class="open-library-value"><?= $view->e(mb_strlen($plan->values[$field]) > 160 ? mb_substr($plan->values[$field], 0, 160) . '…' : $plan->values[$field]) ?></span></label>
                    <span class="hint" id="<?= $id ?>-source"><?= $support !== []
                        ? $view->t('openlibrary.supported_by', ['sources' => implode(', ', array_map(static fn (string $s): string => $view->trans('metadata.providers.' . $s), $support))])
                        : $view->t('openlibrary.catalogue_only') ?></span>
                </div>
            <?php endforeach; ?>
            <p class="hint"><?= $view->t('openlibrary.description_note') ?></p>
        </fieldset>
        <div class="form-actions">
            <button type="submit" class="button button-primary"><?= $view->t('openlibrary.prepare') ?></button>
            <a class="button" href="/editions/<?= (int) $edition['id'] ?>"><?= $view->t('actions.cancel') ?></a>
        </div>
    </form>

    <?php if ($text !== null): ?>
        <section aria-labelledby="ol-prepared-heading" class="open-library-prepared">
            <h2 id="ol-prepared-heading"><?= $view->t('openlibrary.prepared_title') ?></h2>
            <p class="hint"><?= $view->t('openlibrary.prepared_help') ?></p>
            <label for="ol-contribution-text" class="visually-hidden"><?= $view->t('openlibrary.prepared_title') ?></label>
            <textarea id="ol-contribution-text" rows="<?= min(18, substr_count($text, "\n") + 2) ?>" readonly><?= $view->e($text) ?></textarea>
            <p><button type="button" class="button" data-copy="ol-contribution-text" data-copied="<?= $view->t('openlibrary.copied') ?>" data-copy-status="ol-copy-status" hidden><?= $view->t('openlibrary.copy') ?></button></p>
            <p class="visually-hidden" role="status" id="ol-copy-status"></p>
        </section>
    <?php endif; ?>

    <section aria-labelledby="ol-links-heading" class="open-library-links">
        <h2 id="ol-links-heading"><?= $view->t('openlibrary.next_steps') ?></h2>
        <p class="hint"><?= $view->t('openlibrary.login_note') ?></p>
        <ul>
            <?php foreach (['isbn', 'edition', 'work', 'search', 'add', 'email', 'librarians'] as $purpose): ?>
                <?php if (isset($links[$purpose])): ?>
                    <li><a href="<?= $view->e($links[$purpose]) ?>"<?= $external ?>><?= $view->t('openlibrary.link.' . $purpose) ?></a>
                        <span class="hint"><?= $view->t('openlibrary.link_help.' . $purpose) ?></span></li>
                <?php endif; ?>
            <?php endforeach; ?>
        </ul>
        <p class="hint"><?= $view->t('openlibrary.not_verified') ?></p>
    </section>
</section>

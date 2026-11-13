<?php
/**
 * Field-by-field summary of an ISBN lookup: the source of each value and
 * how far it is supported (MetadataReview statuses, explained in a legend),
 * values other sources disagree on, values from records set aside as
 * questionable (never pre-filled), plausibility warnings, other records that
 * claim the ISBN, and fields no source supplied. Informational only: the
 * form fields stay editable. "Use this value" buttons are hidden unless
 * app.js enables them.
 * @var \DigitalLibrary\View\View $view  @var \DigitalLibrary\Metadata\MetadataReview $review
 */
use DigitalLibrary\Metadata\MetadataReview;

$display = static fn (string $field, string $value): string => $field === 'language' && $value !== ''
    ? $view->trans('languages.' . $value)
    : $value;
$long = static fn (string $field): bool => $field === 'description';
$source = static function (?string $provider, ?string $origin) use ($view): string {
    if ($provider === null) {
        return '';
    }
    $name = $view->trans('metadata.providers.' . $provider);
    if (in_array($origin, ['work', 'search'], true)) {
        $name .= ' ' . $view->trans('metadata.origin.' . $origin);
    }

    return $name;
};
?>
<section class="lookup-review" aria-labelledby="lookup-review-title">
    <h2 id="lookup-review-title"><?= $view->t('metadata.review_title') ?></h2>
    <p class="hint"><?= $view->t('metadata.review_intro') ?></p>
    <?php if ($review->hasConflicts()): ?>
        <p class="lookup-conflict-note"><?= $view->t('metadata.conflicts') ?></p>
    <?php endif; ?>

    <?php foreach ($review->warnings as $provider => $codes): ?>
        <?php $setAside = in_array($provider, $review->questionable, true); ?>
        <div class="lookup-warning<?= $setAside ? ' is-set-aside' : '' ?>" role="note">
            <p><strong><?= $view->t($setAside ? 'metadata.set_aside_title' : 'metadata.warnings_title', ['provider' => $view->trans('metadata.providers.' . $provider)]) ?></strong>
                <?php if ($setAside): ?> <?= $view->t('metadata.set_aside_help') ?><?php endif; ?></p>
            <ul>
                <?php foreach ($codes as $code): ?>
                    <li><?= $view->t('metadata.warning.' . $code) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endforeach; ?>
    <?php foreach ($review->candidates as $provider => $candidates): ?>
        <div class="lookup-candidates">
            <p><?= $view->t('metadata.candidates_title', ['provider' => $view->trans('metadata.providers.' . $provider)]) ?></p>
            <ul>
                <?php foreach ($candidates as $candidate): ?>
                    <li>
                        <a href="https://openlibrary.org/books/<?= $view->e($candidate['edition']) ?>" rel="noopener noreferrer external"><?= $view->e($candidate['title'] !== '' ? $candidate['title'] : $candidate['edition']) ?></a>
                        <?php if ($candidate['authors'] !== []): ?><span class="muted">· <?= $view->e(implode(', ', $candidate['authors'])) ?></span><?php endif; ?>
                        <span class="muted">(<?= $view->e($candidate['edition']) ?>)</span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endforeach; ?>

    <?php if ($review->found !== []): ?>
        <dl class="lookup-fields">
            <?php foreach ($review->found as $entry): ?>
                <?php [$labelKey, $target] = MetadataReview::FIELDS[$entry['field']]; ?>
                <div class="lookup-field status-<?= $view->e($entry['status']) ?>">
                    <dt><?php if ($target !== null): ?><a href="#<?= $view->e($target) ?>"><?= $view->t($labelKey) ?></a><?php else: ?><?= $view->t($labelKey) ?><?php endif; ?></dt>
                    <dd>
                        <span class="lookup-badge"><?= $view->t('metadata.status.' . $entry['status']) ?></span>
                        <?php if ($entry['status'] === 'questionable'): ?>
                            <span class="lookup-value muted"><?= $view->t('metadata.not_filled') ?></span>
                        <?php elseif ($entry['field'] === 'cover'): ?>
                            <span class="lookup-value"><?= $view->t('metadata.cover_found') ?></span>
                        <?php elseif ($long($entry['field']) && mb_strlen($entry['value']) > 160): ?>
                            <details class="lookup-long"><summary><?= $view->e(mb_substr($entry['value'], 0, 120)) ?>…</summary><p><?= $view->e($entry['value']) ?></p></details>
                        <?php else: ?>
                            <span class="lookup-value"><?= $view->e($display($entry['field'], $entry['value'])) ?></span>
                        <?php endif; ?>
                        <?php if ($entry['source'] !== null && $entry['status'] !== 'questionable'): ?>
                            <span class="lookup-source"><?= $view->t('metadata.source', ['provider' => $source($entry['source'], $entry['origin'])]) ?></span>
                        <?php endif; ?>
                        <?php if ($entry['alternatives'] !== []): ?>
                            <ul class="lookup-alternatives">
                                <?php foreach ($entry['alternatives'] as $alternative): ?>
                                    <?php $shown = $display($entry['field'], $alternative['value']); ?>
                                    <li<?= $alternative['questionable'] ? ' class="is-questionable"' : '' ?>>
                                        <span class="lookup-alt-label"><?= $view->t($alternative['questionable'] ? 'metadata.alternative_questionable' : 'metadata.alternatives') ?> (<?= $view->e($source($alternative['provider'], $alternative['origin'])) ?>):</span>
                                        <?php if ($long($entry['field']) && mb_strlen($alternative['value']) > 160): ?>
                                            <details class="lookup-long"><summary><?= $view->e(mb_substr($alternative['value'], 0, 120)) ?>…</summary><p><?= $view->e($alternative['value']) ?></p></details>
                                        <?php else: ?>
                                            <span class="lookup-value"><?= $view->e($shown) ?></span>
                                        <?php endif; ?>
                                        <?php if ($target !== null && in_array($entry['field'], MetadataReview::FILLABLE, true)): ?>
                                            <button type="button" class="button button-small" hidden data-fill-target="<?= $view->e($target) ?>" data-fill-value="<?= $view->e($alternative['value']) ?>"><?= $view->t('metadata.use_value') ?><span class="visually-hidden"> (<?= $view->t($labelKey) ?>: <?= $view->e(mb_substr($shown, 0, 80)) ?>)</span></button>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </dd>
                </div>
            <?php endforeach; ?>
        </dl>
    <?php endif; ?>
    <?php if ($review->statuses() !== []): ?>
        <details class="lookup-legend">
            <summary><?= $view->t('metadata.legend_title') ?></summary>
            <dl>
                <?php foreach ($review->statuses() as $status): ?>
                    <dt><?= $view->t('metadata.status.' . $status) ?></dt>
                    <dd><?= $view->t('metadata.status_help.' . $status) ?></dd>
                <?php endforeach; ?>
            </dl>
        </details>
    <?php endif; ?>
    <?php if ($review->missing !== []): ?>
        <div class="lookup-missing">
            <h3><?= $view->t('metadata.missing_title') ?></h3>
            <p><?= $view->t('metadata.missing_intro') ?></p>
            <ul>
                <?php foreach ($review->missing as $field): ?>
                    <?php [$labelKey, $target] = MetadataReview::FIELDS[$field]; ?>
                    <li><?php if ($target !== null): ?><a href="#<?= $view->e($target) ?>"><?= $view->t($labelKey) ?></a><?php else: ?><?= $view->t($labelKey) ?><?php endif; ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
    <p class="visually-hidden" role="status" data-fill-status data-message="<?= $view->t('metadata.value_used') ?>"></p>
</section>

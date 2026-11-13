<?php
/**
 * Field-by-field summary of an ISBN lookup: the source of each pre-filled
 * value, values other sources disagree on, and fields no source supplied.
 * Informational only: the form fields stay editable. "Use this value"
 * buttons are hidden unless app.js enables them.
 * @var \DigitalLibrary\View\View $view  @var \DigitalLibrary\Metadata\MetadataReview $review
 */
use DigitalLibrary\Metadata\MetadataReview;

$display = static fn (string $field, string $value): string => $field === 'language' && $value !== ''
    ? $view->trans('languages.' . $value)
    : $value;
$long = static fn (string $field): bool => $field === 'description';
?>
<section class="lookup-review" aria-labelledby="lookup-review-title">
    <h2 id="lookup-review-title"><?= $view->t('metadata.review_title') ?></h2>
    <p class="hint"><?= $view->t('metadata.review_intro') ?></p>
    <?php if ($review->hasConflicts()): ?>
        <p class="lookup-conflict-note"><?= $view->t('metadata.conflicts') ?></p>
    <?php endif; ?>
    <dl class="lookup-fields">
        <?php foreach ($review->found as $entry): ?>
            <?php [$labelKey, $target] = MetadataReview::FIELDS[$entry['field']]; ?>
            <div class="lookup-field<?= $entry['alternatives'] !== [] ? ' has-conflict' : '' ?>">
                <dt><?php if ($target !== null): ?><a href="#<?= $view->e($target) ?>"><?= $view->t($labelKey) ?></a><?php else: ?><?= $view->t($labelKey) ?><?php endif; ?></dt>
                <dd>
                    <?php if ($entry['field'] === 'cover'): ?>
                        <span class="lookup-value"><?= $view->t('metadata.cover_found') ?></span>
                    <?php elseif ($long($entry['field']) && mb_strlen($entry['value']) > 160): ?>
                        <details class="lookup-long"><summary><?= $view->e(mb_substr($entry['value'], 0, 120)) ?>…</summary><p><?= $view->e($entry['value']) ?></p></details>
                    <?php else: ?>
                        <span class="lookup-value"><?= $view->e($display($entry['field'], $entry['value'])) ?></span>
                    <?php endif; ?>
                    <span class="lookup-source"><?= $view->t('metadata.source', ['provider' => $view->trans('metadata.providers.' . $entry['source'])]) ?></span>
                    <?php if ($entry['alternatives'] !== []): ?>
                        <ul class="lookup-alternatives">
                            <?php foreach ($entry['alternatives'] as $alternative): ?>
                                <?php $shown = $display($entry['field'], $alternative['value']); ?>
                                <li>
                                    <span class="lookup-alt-label"><?= $view->t('metadata.alternatives') ?> (<?= $view->t('metadata.providers.' . $alternative['provider']) ?>):</span>
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

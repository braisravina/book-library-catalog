<?php
/**
 * What the online lookup did, overall and per provider, in plain words: a
 * provider with no record is never described as "busy", and a source that
 * could not be checked is never described as having no record.
 * @var \DigitalLibrary\View\View $view  @var array<string, mixed> $lookup
 */
use DigitalLibrary\Metadata\LookupResult;

$outcomes = $lookup['outcomes'] ?? [];
$statuses = array_column($outcomes, 'status');
$unavailable = array_values(array_filter($statuses, static fn (string $s): bool => in_array($s, LookupResult::UNAVAILABLE, true)));
$throttled = ($lookup['throttled'] ?? []) !== [];
$providers = static fn (array $names): string => implode(', ', array_map(static fn (string $s): string => $view->trans('metadata.providers.' . $s), $names));
if ($lookup['found']) {
    $summary = [$view->trans('metadata.found', ['sources' => $providers($lookup['sources'])]), $view->trans('metadata.review')];
    if ($throttled) {
        $summary[] = $view->trans('metadata.throttled');
    } elseif ($unavailable !== []) {
        $summary[] = $view->trans('metadata.partial');
    }
    if (in_array('questionable', $statuses, true)) {
        $summary[] = $view->trans('metadata.questionable_set_aside');
    }
} elseif ($outcomes !== [] && count($unavailable) === count($outcomes)) {
    $summary = [$view->trans($throttled ? 'metadata.unavailable_throttled' : 'metadata.unavailable')];
} elseif ($unavailable !== []) {
    $summary = [$view->trans('metadata.mixed')];
} elseif (in_array('questionable', $statuses, true)) {
    $summary = [$view->trans('metadata.questionable_only')];
} elseif (in_array('ambiguous', $statuses, true)) {
    $summary = [$view->trans('metadata.ambiguous_only')];
} else {
    $summary = [$view->trans('metadata.not_found')];
}
?>
<div class="alert alert-info lookup-status-summary" role="status">
    <p><?= $view->e(implode(' ', $summary)) ?></p>
    <?php if ($outcomes !== []): ?>
        <ul class="lookup-outcomes">
            <?php foreach ($outcomes as $o): ?>
                <?php
                $key = $o['status'] === 'found' && $o['cached'] ? 'found_cached' : $o['status'];
                $minutes = $o['retry_in'] !== null ? max(1, (int) ceil($o['retry_in'] / 60)) : null;
                $text = $view->trans('metadata.outcome.' . $key, ['minutes' => (string) ($minutes ?? 1)]);
                if ($minutes === null && in_array($o['status'], ['rate_limited', 'cooling_down'], true)) {
                    $text = $view->trans('metadata.outcome.' . $key . '_later');
                }
                ?>
                <li><strong><?= $view->t('metadata.providers.' . $o['provider']) ?>:</strong> <?= $view->e($text) ?><?php if ($o['incomplete']): ?> <?= $view->t('metadata.outcome.incomplete') ?><?php endif; ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>

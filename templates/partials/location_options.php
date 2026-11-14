<?php
/**
 * <option> list of locations for a copy form. Each household has its own
 * location tree: when households are offered ($households not empty) the
 * locations are grouped by household, and app.js shows only the group of
 * the household chosen in the form's household field (the server checks
 * it again: Catalogue\CopyService). Without households it is the plain
 * indented tree.
 *
 * @var \DigitalLibrary\View\View $view
 * @var list<array<string, mixed>> $tree       flattened; each node carries household_id
 * @var string|int|null $selected
 * @var array<string, string> $households      id => name of the households the form offers
 */
$households = $households ?? [];
if ($households === []) {
    echo $view->partial('partials/tree_options', ['tree' => $tree, 'selected' => $selected ?? null]);

    return;
}
$groups = [];
foreach ($tree as $node) {
    $groups[(string) ($node['household_id'] ?? '')][] = $node;
}
foreach ($groups as $householdId => $nodes): ?>
    <optgroup label="<?= $householdId === '' || !isset($households[$householdId]) ? $view->t('households.unassigned') : $view->e($households[$householdId]) ?>" data-household="<?= $view->e((string) $householdId) ?>">
        <?= $view->partial('partials/tree_options', ['tree' => $nodes, 'selected' => $selected ?? null]) ?>
    </optgroup>
<?php endforeach;

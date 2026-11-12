<?php
/**
 * <option> list for a flattened tree, indented by depth.
 * @var \DigitalLibrary\View\View $view
 * @var list<array<string, mixed>> $tree
 * @var string|int|null $selected
 */
foreach ($tree as $node): ?>
    <option value="<?= (int) $node['id'] ?>"<?= (string) $node['id'] === (string) ($selected ?? '') ? ' selected' : '' ?>><?= str_repeat('&nbsp;&nbsp;', (int) $node['depth']) ?><?= $view->e($node['name']) ?></option>
<?php endforeach;

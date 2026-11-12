<?php
/** @var \DigitalLibrary\View\View $view  @var list<string> $author_names  @var list<string> $publisher_names */
?>
<datalist id="author-names">
    <?php foreach ($author_names as $name): ?><option value="<?= $view->e($name) ?>"></option><?php endforeach; ?>
</datalist>
<datalist id="publisher-names">
    <?php foreach ($publisher_names as $name): ?><option value="<?= $view->e($name) ?>"></option><?php endforeach; ?>
</datalist>

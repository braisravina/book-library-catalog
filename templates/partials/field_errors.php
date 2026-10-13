<?php
/** @var \DigitalLibrary\View\View $view */
/** @var list<string> $messages */
/** @var string $id */
?>
<?php if (!empty($messages)): ?>
    <ul class="field-errors" id="<?= $view->e($id) ?>">
        <?php foreach ($messages as $message): ?>
            <li><?= $view->t($message) ?></li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

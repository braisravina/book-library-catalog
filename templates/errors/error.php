<?php
/** @var \DigitalLibrary\View\View $view */
/** @var int $status */
/** @var string $title */
/** @var string $message */
/** @var string|null $debug */
?>
<section class="error-page">
    <p class="error-code"><?= $view->e($status) ?></p>
    <h1><?= $view->t($title) ?></h1>
    <p><?= $view->t($message) ?></p>
    <?php if ($debug !== null): ?>
        <pre class="debug"><?= $view->e($debug) ?></pre>
    <?php endif; ?>
    <p><a class="button" href="/"><?= $view->t('errors.back_home') ?></a></p>
</section>

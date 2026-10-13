<?php /** @var \DigitalLibrary\View\View $view */ ?>
<section class="hero">
    <h1><?= $view->t('app.name') ?></h1>
    <p class="lead"><?= $view->t('landing.tagline') ?></p>
    <p><?= $view->t('landing.private') ?></p>
    <p><a class="button button-primary" href="/login"><?= $view->t('auth.login.submit') ?></a></p>
</section>

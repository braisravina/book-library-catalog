<?php
/**
 * Existing works that may be the same book as the ISBN being added.
 * Suggestions only: "Add as a new edition" opens the normal new-edition
 * form for that work, which the user reviews and submits; nothing merges.
 * @var \DigitalLibrary\View\View $view
 * @var list<array{id: int, title: string, subtitle: string, authors: string, edition_count: int, author_match: bool}> $works
 * @var string $isbn13
 */
?>
<section class="alert alert-info similar-works" aria-labelledby="similar-works-title">
    <h2 id="similar-works-title"><?= $view->t('metadata.similar_title') ?></h2>
    <p><?= $view->t('metadata.similar_intro') ?></p>
    <ul class="similar-list">
        <?php foreach ($works as $work): ?>
            <li class="similar-item">
                <div class="similar-text">
                    <strong><?= $view->e($work['title']) ?></strong><?php if ($work['subtitle'] !== ''): ?> <span><?= $view->e($work['subtitle']) ?></span><?php endif; ?>
                    <?php if ($work['authors'] !== ''): ?><br><span class="muted"><?= $view->e($work['authors']) ?></span><?php endif; ?>
                    <?php if ($work['author_match']): ?> <span class="badge"><?= $view->t('metadata.similar_author_match') ?></span><?php endif; ?>
                    <span class="muted"> · <?= $view->t('metadata.similar_editions', ['count' => $work['edition_count']]) ?></span>
                </div>
                <div class="similar-actions">
                    <a class="button button-small" href="/works/<?= (int) $work['id'] ?>"><?= $view->t('metadata.similar_view') ?><span class="visually-hidden">: <?= $view->e($work['title']) ?></span></a>
                    <a class="button button-small button-primary" href="<?= $view->e($view->url('/works/' . (int) $work['id'] . '/editions/new', ['isbn' => $isbn13])) ?>"><?= $view->t('metadata.similar_add_edition') ?><span class="visually-hidden">: <?= $view->e($work['title']) ?></span></a>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
    <p><?= $view->t('metadata.similar_continue') ?></p>
</section>

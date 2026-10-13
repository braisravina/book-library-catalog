<?php
/**
 * @var \DigitalLibrary\View\View $view  @var array<string, mixed>|null $item  @var \DigitalLibrary\Wishlist\WishlistInput $input
 * @var array<string, list<string>> $errors  @var array<string, mixed>|null $edition  @var string|null $refusal
 * @var int|null $existing_id  @var list<string> $priorities
 */
$action = $item === null ? '/wishlist' : '/wishlist/' . (int) $item['id'];
$priorityOptions = [];
foreach ($priorities as $p) {
    $priorityOptions[$p] = $view->trans('wishlist.priority.' . $p);
}
?>
<section class="form-card">
    <h1><?= $view->t($item === null ? 'wishlist.new' : 'wishlist.edit') ?></h1>
    <?php if ($refusal !== null): ?>
        <div class="alert alert-error" role="alert">
            <?= $view->t($refusal) ?>
            <?php if ($existing_id !== null): ?> <a href="/wishlist/<?= (int) $existing_id ?>/edit"><?= $view->t('wishlist.view_existing') ?></a><?php endif; ?>
        </div>
    <?php elseif ($errors !== []): ?>
        <div class="alert alert-error" role="alert"><?= $view->t('validation.summary') ?></div>
    <?php endif; ?>
    <?php if ($edition !== null): ?>
        <p class="muted"><?= $view->t('wishlist.from_catalogue') ?> <a href="/editions/<?= (int) $edition['id'] ?>"><?= $view->e($edition['title']) ?></a></p>
    <?php endif; ?>
    <form method="post" action="<?= $view->e($action) ?>" class="form" novalidate>
        <?= $view->csrfField() ?>
        <?php if ($edition !== null): ?><input type="hidden" name="edition_id" value="<?= (int) $edition['id'] ?>"><?php endif; ?>
        <?= $view->partial('partials/input', ['name' => 'title', 'label' => 'fields.title', 'value' => $input->title, 'errors' => $errors, 'maxlength' => 500, 'required' => true]) ?>
        <div class="form-row">
            <?= $view->partial('partials/input', ['name' => 'author', 'label' => 'wishlist.fields.author', 'value' => $input->author, 'errors' => $errors, 'maxlength' => 255]) ?>
            <?= $view->partial('partials/input', ['name' => 'isbn', 'label' => 'fields.isbn', 'value' => $input->isbn, 'errors' => $errors, 'maxlength' => 20, 'inputmode' => 'numeric', 'hint' => 'wishlist.isbn_hint']) ?>
        </div>
        <?= $view->partial('partials/select', ['name' => 'priority', 'label' => 'wishlist.fields.priority', 'value' => $input->priority, 'options' => $priorityOptions, 'errors' => $errors]) ?>
        <?= $view->partial('partials/input', ['name' => 'notes', 'label' => 'fields.notes', 'value' => $input->notes, 'errors' => $errors, 'type' => 'textarea', 'rows' => 3, 'maxlength' => 1000]) ?>
        <p class="hint"><?= $view->t('wishlist.private_hint') ?></p>
        <div class="form-actions">
            <button type="submit" class="button button-primary"><?= $view->t($item === null ? 'wishlist.add' : 'actions.save') ?></button>
            <a class="button" href="/wishlist"><?= $view->t('actions.cancel') ?></a>
        </div>
    </form>
</section>

<?php
/**
 * @var \DigitalLibrary\View\View $view  @var array<string, mixed>|null $household
 * @var \DigitalLibrary\Households\HouseholdInput $input  @var array<string, list<string>> $errors
 * @var string|null $refusal  @var list<array<string, mixed>> $members  @var list<array<string, mixed>> $candidates
 */
$id = $household === null ? null : (int) $household['id'];
?>
<div class="page-header">
    <h1><?= $view->t($household === null ? 'households.new' : 'households.edit_title') ?></h1>
    <a class="button" href="/admin/households"><?= $view->t('households.back') ?></a>
</div>
<div class="alert alert-info" role="note"><?= $view->t('households.preview_notice') ?></div>
<?php if ($refusal !== null): ?><div class="alert alert-error" role="alert"><?= $view->t($refusal) ?></div><?php endif; ?>
<section class="form-card">
    <?php if ($errors !== []): ?><div class="alert alert-error" role="alert"><?= $view->t('validation.summary') ?></div><?php endif; ?>
    <form method="post" action="<?= $id === null ? '/admin/households' : '/admin/households/' . $id ?>" class="form" novalidate>
        <?= $view->csrfField() ?>
        <?php if ($household !== null): ?><input type="hidden" name="row_version" value="<?= (int) $household['row_version'] ?>"><?php endif; ?>
        <?= $view->partial('partials/input', ['name' => 'name', 'label' => 'fields.name', 'value' => $input->name, 'errors' => $errors, 'maxlength' => 150, 'required' => true]) ?>
        <?= $view->partial('partials/input', ['name' => 'description', 'label' => 'fields.description', 'value' => $input->description, 'errors' => $errors, 'type' => 'textarea', 'maxlength' => 1000, 'rows' => 3]) ?>
        <div class="form-actions">
            <button type="submit" class="button button-primary"><?= $view->t('actions.save') ?></button>
            <a class="button" href="/admin/households"><?= $view->t('actions.cancel') ?></a>
        </div>
    </form>
</section>
<?php if ($household !== null): ?>
    <section class="form-card" aria-labelledby="members-heading" id="members">
        <h2 id="members-heading"><?= $view->t('households.members.title') ?></h2>
        <p class="hint"><?= $view->t('households.members.hint') ?></p>
        <?php if ($members === []): ?>
            <p class="muted"><?= $view->t('households.members.none') ?></p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <caption class="visually-hidden"><?= $view->t('households.members.title') ?></caption>
                    <thead>
                    <tr>
                        <th scope="col"><?= $view->t('fields.display_name') ?></th>
                        <th scope="col"><?= $view->t('fields.email') ?></th>
                        <th scope="col"><?= $view->t('fields.role') ?></th>
                        <th scope="col"><?= $view->t('households.columns.copies') ?></th>
                        <th scope="col"><span class="visually-hidden"><?= $view->t('actions.actions') ?></span></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($members as $member): ?>
                        <tr>
                            <td><?= $view->e($member['display_name']) ?><?php if ((int) $member['is_active'] !== 1): ?> <span class="badge"><?= $view->t('admin.users.inactive') ?></span><?php endif; ?></td>
                            <td><?= $view->e($member['email']) ?></td>
                            <td><?= $view->t('roles.' . $member['role_code']) ?></td>
                            <td><?= (int) $member['owned_copies'] ?></td>
                            <td class="cell-actions">
                                <form method="post" action="/admin/households/<?= $id ?>/members/<?= (int) $member['id'] ?>/remove" class="inline-form" data-confirm="<?= $view->t('households.members.remove_confirm') ?>">
                                    <?= $view->csrfField() ?>
                                    <button type="submit" class="link-button danger"><?= $view->t('households.members.remove') ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        <?php if ($candidates === []): ?>
            <p class="muted"><?= $view->t('households.members.none_available') ?></p>
        <?php else: ?>
            <?php $options = []; foreach ($candidates as $candidate) { $options[(string) $candidate['id']] = $candidate['display_name'] . ' (' . $candidate['email'] . ')'; } ?>
            <form method="post" action="/admin/households/<?= $id ?>/members" class="form">
                <?= $view->csrfField() ?>
                <?= $view->partial('partials/select', ['name' => 'user_id', 'label' => 'households.members.add_label', 'value' => '', 'options' => $options]) ?>
                <div class="form-actions">
                    <button type="submit" class="button"><?= $view->t('households.members.add') ?></button>
                </div>
            </form>
        <?php endif; ?>
    </section>
    <section class="form-card" aria-labelledby="delete-heading">
        <h2 id="delete-heading"><?= $view->t('households.delete.title') ?></h2>
        <?php if ($household['system_key'] !== null): ?>
            <p class="hint"><?= $view->t('households.error.default_protected') ?></p>
        <?php else: ?>
            <p class="hint"><?= $view->t('households.delete.hint') ?></p>
            <form method="post" action="/admin/households/<?= $id ?>/delete" class="inline-form" data-confirm="<?= $view->t('households.delete.confirm') ?>">
                <?= $view->csrfField() ?>
                <button type="submit" class="button danger"><?= $view->t('households.delete.action') ?></button>
            </form>
        <?php endif; ?>
    </section>
<?php endif; ?>

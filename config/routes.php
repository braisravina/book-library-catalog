<?php

declare(strict_types=1);

use DigitalLibrary\Core\Services;
use DigitalLibrary\Http\Controllers\AccountController;
use DigitalLibrary\Http\Controllers\Admin\AuditController;
use DigitalLibrary\Http\Controllers\Admin\CategoryController;
use DigitalLibrary\Http\Controllers\Admin\InvitationController;
use DigitalLibrary\Http\Controllers\Admin\LocationController;
use DigitalLibrary\Http\Controllers\Admin\UserController;
use DigitalLibrary\Http\Controllers\AuthController;
use DigitalLibrary\Http\Controllers\Catalogue\AuthorController;
use DigitalLibrary\Http\Controllers\Catalogue\CatalogueController;
use DigitalLibrary\Http\Controllers\Catalogue\CopyController;
use DigitalLibrary\Http\Controllers\Catalogue\CoverController;
use DigitalLibrary\Http\Controllers\Catalogue\EditionController;
use DigitalLibrary\Http\Controllers\Catalogue\PublisherController;
use DigitalLibrary\Http\Controllers\Catalogue\WorkController;
use DigitalLibrary\Http\Controllers\HealthController;
use DigitalLibrary\Http\Controllers\HomeController;
use DigitalLibrary\Http\Controllers\Library\CollectionController;
use DigitalLibrary\Http\Controllers\Library\ExportController;
use DigitalLibrary\Http\Controllers\Library\LoanController;
use DigitalLibrary\Http\Controllers\Library\WishlistController;
use DigitalLibrary\Http\Controllers\LocaleController;
use DigitalLibrary\Http\Controllers\RegistrationController;
use DigitalLibrary\Http\Router;

/*
 * Route table. Every route other than the landing page, login, invitation
 * acceptance, locale switch and health check requires authentication; the
 * catalogue is private.
 * Middleware aliases: auth, guest, can:<permission>.
 *
 * Route guards express role-level permissions. Record-level rules (members
 * edit only records they created and manage only copies they own) are
 * enforced inside the controllers through Security\Authorizer.
 */
return static function (Router $router, Services $s): void {
    $home = new HomeController($s);
    $auth = new AuthController($s);
    $account = new AccountController($s);
    $locale = new LocaleController($s);
    $health = new HealthController($s);
    $users = new UserController($s);
    $catalogue = new CatalogueController($s);
    $editions = new EditionController($s);
    $works = new WorkController($s);
    $copies = new CopyController($s);
    $covers = new CoverController($s);
    $authors = new AuthorController($s);
    $publishers = new PublisherController($s);
    $categories = new CategoryController($s);
    $locations = new LocationController($s);
    $loans = new LoanController($s);
    $wishlist = new WishlistController($s);
    $collections = new CollectionController($s);
    $audit = new AuditController($s);
    $invitations = new InvitationController($s);
    $registration = new RegistrationController($s);
    $export = new ExportController($s);

    $view = ['auth', 'can:catalogue.view'];
    $create = ['auth', 'can:catalogue.create'];
    $editOwn = ['auth', 'can:catalogue.edit_own'];
    $editAny = ['auth', 'can:catalogue.edit_any'];
    $delete = ['auth', 'can:catalogue.delete'];
    $copyOwn = ['auth', 'can:copy.manage_own'];

    $router->get('/', $home->index(...), [], 'home');
    $router->get('/health', $health->show(...), [], 'health');
    $router->post('/locale', $locale->update(...), [], 'locale.update');

    $router->get('/login', $auth->showLogin(...), ['guest'], 'login');
    $router->post('/login', $auth->login(...), ['guest'], 'login.submit');

    // Invitation-only registration (no open sign-up). The token is only accepted on
    // GET /invite and is exchanged for session state before any page is shown.
    $router->get('/invite', $registration->open(...), ['guest'], 'invite.open');
    $router->get('/invite/accept', $registration->show(...), ['guest'], 'invite.accept');
    $router->get('/invite/invalid', $registration->invalidPage(...), ['guest'], 'invite.invalid');
    $router->post('/invite/accept', $registration->accept(...), ['guest'], 'invite.accept.submit');
    $router->post('/logout', $auth->logout(...), ['auth'], 'logout');

    $router->get('/account/password', $account->editPassword(...), ['auth'], 'account.password');
    $router->post('/account/password', $account->updatePassword(...), ['auth'], 'account.password.update');

    // Catalogue: browse, search and add.
    $router->get('/books', $catalogue->index(...), $view, 'books.index');
    $router->get('/books/new', $catalogue->create(...), $create, 'books.create');
    $router->post('/books', $catalogue->store(...), $create, 'books.store');

    // Works (abstract books) and editions (published manifestations).
    $router->get('/works/{id:\d+}', $works->show(...), $view, 'works.show');
    $router->get('/works/{id:\d+}/edit', $works->edit(...), $editOwn, 'works.edit');
    $router->post('/works/{id:\d+}', $works->update(...), $editOwn, 'works.update');
    $router->post('/works/{id:\d+}/delete', $works->destroy(...), $delete, 'works.delete');
    $router->get('/works/{id:\d+}/editions/new', $works->createEdition(...), $create, 'editions.create');
    $router->post('/works/{id:\d+}/editions', $works->storeEdition(...), $create, 'editions.store');

    $router->get('/editions/{id:\d+}', $editions->show(...), $view, 'editions.show');
    $router->get('/editions/{id:\d+}/edit', $editions->edit(...), $editOwn, 'editions.edit');
    $router->post('/editions/{id:\d+}', $editions->update(...), $editOwn, 'editions.update');
    $router->post('/editions/{id:\d+}/delete', $editions->destroy(...), $delete, 'editions.delete');

    // Physical copies.
    $router->get('/editions/{id:\d+}/copies/new', $copies->create(...), $copyOwn, 'copies.create');
    $router->post('/editions/{id:\d+}/copies', $copies->store(...), $copyOwn, 'copies.store');
    $router->get('/copies/{id:\d+}/edit', $copies->edit(...), $copyOwn, 'copies.edit');
    $router->post('/copies/{id:\d+}', $copies->update(...), $copyOwn, 'copies.update');
    $router->post('/copies/{id:\d+}/delete', $copies->destroy(...), $copyOwn, 'copies.delete');

    // Copy details and lending (Phase 4A). Object-level checks in LoanController/LoanService.
    $loanOwn = ['auth', 'can:loan.manage_own'];
    $router->get('/copies/{id:\d+}', $loans->copy(...), $view, 'copies.show');
    $router->get('/copies/{id:\d+}/lend', $loans->create(...), $loanOwn, 'loans.create');
    $router->post('/copies/{id:\d+}/lend', $loans->store(...), $loanOwn, 'loans.store');
    $router->get('/loans', $loans->index(...), $view, 'loans.index');
    $router->get('/loans/{id:\d+}/return', $loans->confirmReturn(...), $loanOwn, 'loans.return');
    $router->post('/loans/{id:\d+}/return', $loans->storeReturn(...), $loanOwn, 'loans.return.store');

    // Wishlists: private to their owner (object-level checks in the controller and service).
    $wish = ['auth', 'can:wishlist.manage_own'];
    $router->get('/wishlist', $wishlist->index(...), $wish, 'wishlist.index');
    $router->get('/wishlist/new', $wishlist->create(...), $wish, 'wishlist.create');
    $router->post('/wishlist', $wishlist->store(...), $wish, 'wishlist.store');
    $router->get('/wishlist/{id:\d+}/edit', $wishlist->edit(...), $wish, 'wishlist.edit');
    $router->post('/wishlist/{id:\d+}', $wishlist->update(...), $wish, 'wishlist.update');
    $router->post('/wishlist/{id:\d+}/status', $wishlist->status(...), $wish, 'wishlist.status');

    // Collections: visibility and management are checked per collection in the controller and service.
    $collectionOwn = ['auth', 'can:collection.manage_own'];
    $router->get('/collections', $collections->index(...), $view, 'collections.index');
    $router->get('/collections/new', $collections->create(...), $collectionOwn, 'collections.create');
    $router->post('/collections', $collections->store(...), $collectionOwn, 'collections.store');
    $router->get('/collections/{id:\d+}', $collections->show(...), $view, 'collections.show');
    $router->get('/collections/{id:\d+}/edit', $collections->edit(...), $collectionOwn, 'collections.edit');
    $router->post('/collections/{id:\d+}', $collections->update(...), $collectionOwn, 'collections.update');
    $router->post('/collections/{id:\d+}/delete', $collections->destroy(...), $collectionOwn, 'collections.delete');
    $router->post('/collections/{id:\d+}/items', $collections->addItem(...), $collectionOwn, 'collections.items.add');
    $router->post('/collections/add-item', $collections->addItemFromEdition(...), $collectionOwn, 'collections.items.add_from_edition');
    $router->post('/collections/{id:\d+}/items/{edition:\d+}/remove', $collections->removeItem(...), $collectionOwn, 'collections.items.remove');
    $router->post('/collections/{id:\d+}/items/{edition:\d+}/move', $collections->moveItem(...), $collectionOwn, 'collections.items.move');

    // Data export. Personal exports are scoped to the signed-in user (no user
    // parameter); the catalogue export is administrators only (catalogue.export).
    $router->get('/export', $export->index(...), $view, 'export.index');
    $router->get('/export/personal.json', $export->personalJson(...), $view, 'export.personal.json');
    $router->get('/export/personal/{dataset:[a-z_]+}.csv', $export->personalCsv(...), $view, 'export.personal.csv');
    $exportAll = ['auth', 'can:catalogue.export'];
    $router->get('/admin/export/catalogue.json', $export->catalogueJson(...), $exportAll, 'export.catalogue.json');
    $router->get('/admin/export/{dataset:[a-z_]+}.csv', $export->catalogueCsv(...), $exportAll, 'export.catalogue.csv');

    // Audit-log viewer: administrators only, read-only (GET routes only).
    $auditView = ['auth', 'can:audit.view'];
    $router->get('/admin/audit', $audit->index(...), $auditView, 'admin.audit');
    $router->get('/admin/audit/{id:\d+}', $audit->show(...), $auditView, 'admin.audit.show');

    // Covers.
    $router->post('/editions/{id:\d+}/covers', $covers->store(...), $editOwn, 'covers.store');
    $router->get('/covers/{id:\d+}', $covers->show(...), $view, 'covers.show');
    $router->post('/covers/{id:\d+}/delete', $covers->destroy(...), $editOwn, 'covers.delete');

    // Authors and publishers (shared entities; editing is admin-only).
    $router->get('/authors', $authors->index(...), $view, 'authors.index');
    $router->get('/authors/{id:\d+}', $authors->show(...), $view, 'authors.show');
    $router->get('/authors/{id:\d+}/edit', $authors->edit(...), $editAny, 'authors.edit');
    $router->post('/authors/{id:\d+}', $authors->update(...), $editAny, 'authors.update');
    $router->post('/authors/{id:\d+}/merge', $authors->merge(...), $editAny, 'authors.merge');
    $router->post('/authors/{id:\d+}/delete', $authors->destroy(...), $editAny, 'authors.delete');
    $router->get('/publishers', $publishers->index(...), $view, 'publishers.index');
    $router->get('/publishers/{id:\d+}', $publishers->show(...), $view, 'publishers.show');
    $router->get('/publishers/{id:\d+}/edit', $publishers->edit(...), $editAny, 'publishers.edit');
    $router->post('/publishers/{id:\d+}', $publishers->update(...), $editAny, 'publishers.update');
    $router->post('/publishers/{id:\d+}/merge', $publishers->merge(...), $editAny, 'publishers.merge');
    $router->post('/publishers/{id:\d+}/delete', $publishers->destroy(...), $editAny, 'publishers.delete');

    // Administration.
    $invite = ['auth', 'can:invitation.manage'];
    $router->get('/admin/invitations', $invitations->index(...), $invite, 'admin.invitations');
    $router->get('/admin/invitations/new', $invitations->create(...), $invite, 'admin.invitations.create');
    $router->post('/admin/invitations', $invitations->store(...), $invite, 'admin.invitations.store');
    $router->post('/admin/invitations/{id:\d+}/revoke', $invitations->revoke(...), $invite, 'admin.invitations.revoke');
    $router->post('/admin/invitations/{id:\d+}/replace', $invitations->replace(...), $invite, 'admin.invitations.replace');
    $router->get('/admin/users', $users->index(...), ['auth', 'can:user.manage'], 'admin.users');
    $router->get('/admin/users/new', $users->create(...), ['auth', 'can:user.manage'], 'admin.users.create');
    $router->post('/admin/users', $users->store(...), ['auth', 'can:user.manage'], 'admin.users.store');
    $router->get('/admin/users/{id:\d+}/edit', $users->edit(...), ['auth', 'can:user.manage'], 'admin.users.edit');
    $router->post('/admin/users/{id:\d+}', $users->update(...), ['auth', 'can:user.manage'], 'admin.users.update');
    $router->post('/admin/users/{id:\d+}/password', $users->resetPassword(...), ['auth', 'can:user.manage'], 'admin.users.password');
    $router->post('/admin/users/{id:\d+}/sessions', $users->revokeSessions(...), ['auth', 'can:user.manage'], 'admin.users.sessions');

    $manageCategories = ['auth', 'can:category.manage'];
    $router->get('/admin/categories', $categories->index(...), $manageCategories, 'admin.categories');
    $router->post('/admin/categories', $categories->store(...), $manageCategories, 'admin.categories.store');
    $router->get('/admin/categories/{id:\d+}/edit', $categories->edit(...), $manageCategories, 'admin.categories.edit');
    $router->post('/admin/categories/{id:\d+}', $categories->update(...), $manageCategories, 'admin.categories.update');
    $router->post('/admin/categories/{id:\d+}/delete', $categories->destroy(...), $manageCategories, 'admin.categories.delete');

    $manageLocations = ['auth', 'can:location.manage'];
    $router->get('/admin/locations', $locations->index(...), $manageLocations, 'admin.locations');
    $router->post('/admin/locations', $locations->store(...), $manageLocations, 'admin.locations.store');
    $router->get('/admin/locations/{id:\d+}/edit', $locations->edit(...), $manageLocations, 'admin.locations.edit');
    $router->post('/admin/locations/{id:\d+}', $locations->update(...), $manageLocations, 'admin.locations.update');
    $router->post('/admin/locations/{id:\d+}/delete', $locations->destroy(...), $manageLocations, 'admin.locations.delete');
};

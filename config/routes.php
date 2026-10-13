<?php

declare(strict_types=1);

use DigitalLibrary\Core\Services;
use DigitalLibrary\Http\Controllers\AccountController;
use DigitalLibrary\Http\Controllers\Admin\CategoryController;
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
use DigitalLibrary\Http\Controllers\LocaleController;
use DigitalLibrary\Http\Router;

/*
 * Route table. Every route other than the landing page, login, locale switch
 * and health check requires authentication; the catalogue is private.
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

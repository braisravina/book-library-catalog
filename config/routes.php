<?php

declare(strict_types=1);

use DigitalLibrary\Core\Services;
use DigitalLibrary\Http\Controllers\AccountController;
use DigitalLibrary\Http\Controllers\Admin\UserController;
use DigitalLibrary\Http\Controllers\AuthController;
use DigitalLibrary\Http\Controllers\HealthController;
use DigitalLibrary\Http\Controllers\HomeController;
use DigitalLibrary\Http\Controllers\LocaleController;
use DigitalLibrary\Http\Router;

/*
 * Route table. Every route other than the landing page, login, locale switch
 * and health check requires authentication; the catalogue is private.
 * Middleware aliases: auth, guest, can:<permission>.
 */
return static function (Router $router, Services $s): void {
    $home = new HomeController($s);
    $auth = new AuthController($s);
    $account = new AccountController($s);
    $locale = new LocaleController($s);
    $health = new HealthController($s);
    $users = new UserController($s);

    $router->get('/', $home->index(...), [], 'home');
    $router->get('/health', $health->show(...), [], 'health');
    $router->post('/locale', $locale->update(...), [], 'locale.update');

    $router->get('/login', $auth->showLogin(...), ['guest'], 'login');
    $router->post('/login', $auth->login(...), ['guest'], 'login.submit');
    $router->post('/logout', $auth->logout(...), ['auth'], 'logout');

    $router->get('/account/password', $account->editPassword(...), ['auth'], 'account.password');
    $router->post('/account/password', $account->updatePassword(...), ['auth'], 'account.password.update');

    $router->get('/admin/users', $users->index(...), ['auth', 'can:user.manage'], 'admin.users');
    $router->get('/admin/users/new', $users->create(...), ['auth', 'can:user.manage'], 'admin.users.create');
    $router->post('/admin/users', $users->store(...), ['auth', 'can:user.manage'], 'admin.users.store');
};

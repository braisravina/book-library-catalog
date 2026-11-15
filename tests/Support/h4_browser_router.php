<?php

declare(strict_types=1);

// Run only via the PHP development server with explicit browser-test environment.
if (PHP_SAPI !== 'cli-server' || getenv('APP_ENV') !== 'testing' || getenv('DB_HOST') !== '127.0.0.1'
    || !str_ends_with((string) getenv('DB_DATABASE'), '_test') || getenv('DB_PORT') === '3306') {
    http_response_code(503);
    exit;
}
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (is_string($path) && preg_match('#^/assets/[a-zA-Z0-9_/.-]+$#D', $path) && !str_contains($path, '..') && is_file(dirname(__DIR__, 2) . '/public' . $path)) {
    return false;
}
require dirname(__DIR__, 2) . '/vendor/autoload.php';
$services = new \DigitalLibrary\Core\Services(\DigitalLibrary\Core\Config::fromEnvironment(), dirname(__DIR__, 2));
// Never contact metadata providers, even if a test accidentally requests lookup.
$services->set(\DigitalLibrary\Metadata\Http\HttpClient::class, new \DigitalLibrary\Tests\Support\FakeHttpClient());
(new \DigitalLibrary\Core\Application($services))->handle(\DigitalLibrary\Http\Request::fromGlobals())->send();

<?php

declare(strict_types=1);

use DigitalLibrary\Core\Application;
use DigitalLibrary\Core\Bootstrap;
use DigitalLibrary\Http\Request;

$basePath = dirname(__DIR__);
require $basePath . '/vendor/autoload.php';

$app = new Application(Bootstrap::services($basePath));
$app->handle(Request::fromGlobals())->send();

<?php

declare(strict_types=1);

namespace DigitalLibrary\Core;

final class Bootstrap
{
    public static function services(string $basePath): Services
    {
        $config = Config::fromEnvironment($basePath . '/.env');
        date_default_timezone_set('UTC');
        mb_internal_encoding('UTF-8');

        return new Services($config, $basePath);
    }
}

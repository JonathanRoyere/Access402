<?php

declare(strict_types=1);

namespace Access402\Support;

final class Autoloader
{
    public static function register(): void
    {
        spl_autoload_register([self::class, 'autoload']);
    }

    public static function autoload(string $class): void
    {
        $prefix = 'Access402\\';

        if (! str_starts_with($class, $prefix)) {
            return;
        }

        $relative = substr($class, strlen($prefix));
        $path     = dirname(__DIR__, 2) . '/src/' . str_replace('\\', '/', $relative) . '.php';

        if (is_readable($path)) {
            require_once $path;
        }
    }
}

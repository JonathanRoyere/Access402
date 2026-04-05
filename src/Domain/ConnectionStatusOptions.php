<?php

declare(strict_types=1);

namespace Access402\Domain;

final class ConnectionStatusOptions
{
    public const NOT_TESTED = 'not_tested';
    public const CONNECTED  = 'connected';
    public const FAILED     = 'failed';

    public static function options(): array
    {
        return [
            self::NOT_TESTED => 'Not tested',
            self::CONNECTED  => 'Connected',
            self::FAILED     => 'Failed',
        ];
    }

    public static function labels(): array
    {
        return self::options();
    }

    public static function is_valid(string $value): bool
    {
        return array_key_exists($value, self::options());
    }
}

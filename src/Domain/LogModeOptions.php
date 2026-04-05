<?php

declare(strict_types=1);

namespace Access402\Domain;

final class LogModeOptions
{
    public const TEST = 'test';
    public const LIVE = 'live';

    public static function options(): array
    {
        return [
            self::TEST => 'Test',
            self::LIVE => 'Live',
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

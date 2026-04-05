<?php

declare(strict_types=1);

namespace Access402\Domain;

final class RuleStatusOptions
{
    public const ACTIVE   = 'active';
    public const DISABLED = 'disabled';

    public static function options(): array
    {
        return [
            self::ACTIVE   => 'Active',
            self::DISABLED => 'Disabled',
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

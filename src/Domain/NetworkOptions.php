<?php

declare(strict_types=1);

namespace Access402\Domain;

final class NetworkOptions
{
    public const BASE   = 'Base';
    public const SOLANA = 'Solana';

    public static function options(): array
    {
        return [
            self::BASE   => 'Base',
            self::SOLANA => 'Solana',
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

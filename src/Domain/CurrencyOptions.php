<?php

declare(strict_types=1);

namespace Access402\Domain;

final class CurrencyOptions
{
    public const USDC = 'USDC';
    public const ETH  = 'ETH';
    public const SOL  = 'SOL';

    public static function options(): array
    {
        return [
            self::USDC => 'USDC',
            self::ETH  => 'ETH',
            self::SOL  => 'SOL',
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

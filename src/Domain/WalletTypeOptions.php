<?php

declare(strict_types=1);

namespace Access402\Domain;

final class WalletTypeOptions
{
    public const EVM    = 'evm';
    public const SOLANA = 'solana';
    public const OTHER  = 'other';

    public static function options(): array
    {
        return [
            self::EVM    => 'EVM',
            self::SOLANA => 'Solana',
            self::OTHER  => 'Other',
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

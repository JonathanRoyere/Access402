<?php

declare(strict_types=1);

namespace Access402\Domain;

final class UnlockBehaviorOptions
{
    public const PER_REQUEST = 'per_request';
    public const WALLET_ONCE = 'wallet_once';
    public const FOURTEEN_DAYS = '14_days';
    public const FOREVER = 'forever';

    public static function options(): array
    {
        return [
            self::PER_REQUEST   => 'Per request',
            self::WALLET_ONCE   => 'One time per wallet',
            self::FOURTEEN_DAYS => '14 days',
            self::FOREVER       => 'Forever',
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

<?php

declare(strict_types=1);

namespace Access402\Domain;

final class LogDecisionOptions
{
    public const ALLOWED          = 'allowed';
    public const PAYMENT_REQUIRED = 'payment_required';
    public const BYPASSED         = 'bypassed';
    public const ERROR            = 'error';

    public static function options(): array
    {
        return [
            self::ALLOWED          => 'Allowed',
            self::PAYMENT_REQUIRED => 'Payment required',
            self::BYPASSED         => 'Bypassed',
            self::ERROR            => 'Error',
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

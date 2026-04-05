<?php

declare(strict_types=1);

namespace Access402\Services;

use Access402\Domain\CurrencyOptions;
use Access402\Domain\NetworkOptions;

final class NetworkResolver
{
    public function resolve(string $currency): string
    {
        return match ($currency) {
            CurrencyOptions::SOL => NetworkOptions::SOLANA,
            CurrencyOptions::USDC, CurrencyOptions::ETH => NetworkOptions::BASE,
            default => NetworkOptions::BASE,
        };
    }

    public function map(): array
    {
        return [
            CurrencyOptions::USDC => $this->resolve(CurrencyOptions::USDC),
            CurrencyOptions::ETH  => $this->resolve(CurrencyOptions::ETH),
            CurrencyOptions::SOL  => $this->resolve(CurrencyOptions::SOL),
        ];
    }
}

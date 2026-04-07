<?php

declare(strict_types=1);

namespace Access402\Services;

use Access402\Domain\CurrencyOptions;
use Access402\Domain\UnlockBehaviorOptions;
use Access402\Support\Helpers;

final class SettingsValidator
{
    public function __construct(
        private readonly NetworkResolver $network_resolver,
        private readonly WalletValidator $wallet_validator
    ) {
    }

    public function sanitize(array $input, array $existing): array
    {
        $currency = strtoupper(sanitize_text_field((string) ($input['default_currency'] ?? $existing['default_currency'] ?? CurrencyOptions::USDC)));
        $errors   = [];

        if (! CurrencyOptions::is_valid($currency)) {
            $currency = CurrencyOptions::USDC;
            $errors[] = __('Choose a supported default currency.', 'access402');
        }

        $network = $this->network_resolver->resolve($currency);
        $price   = trim((string) ($input['default_price'] ?? $existing['default_price'] ?? ''));

        if ($price === '' || ! is_numeric(str_replace(',', '.', $price)) || (float) $price <= 0) {
            $errors[] = __('Default price must be a positive number.', 'access402');
        }

        $unlock_behavior = sanitize_text_field((string) ($input['default_unlock_behavior'] ?? $existing['default_unlock_behavior'] ?? UnlockBehaviorOptions::FOURTEEN_DAYS));

        if (! UnlockBehaviorOptions::is_valid($unlock_behavior)) {
            $errors[] = __('Choose a valid default unlock behavior.', 'access402');
        }

        $test_wallet = trim((string) ($input['test_wallet'] ?? ''));
        $live_wallet = trim((string) ($input['live_wallet'] ?? ''));

        foreach (['test' => $test_wallet, 'live' => $live_wallet] as $mode => $wallet) {
            if ($wallet === '') {
                continue;
            }

            $validation = $this->wallet_validator->validate($wallet, $network);

            if (! $validation['valid']) {
                $errors[] = sprintf(
                    __('%1$s wallet: %2$s', 'access402'),
                    ucfirst($mode),
                    $validation['message']
                );
            }
        }

        return [
            'data' => [
                'test_mode'               => Helpers::bool($input['test_mode'] ?? false) ? 1 : 0,
                'provider'                => 'coinbase_cdp',
                'test_api_key'            => '',
                'test_api_secret'         => '',
                'live_api_key'            => trim((string) ($input['live_api_key'] ?? '')),
                'live_api_secret'         => $this->normalize_secret((string) ($input['live_api_secret'] ?? '')),
                'test_wallet'             => $test_wallet,
                'live_wallet'             => $live_wallet,
                'default_currency'        => $currency,
                'default_network'         => $network,
                'default_price'           => Helpers::decimal_string($price !== '' ? $price : '0'),
                'default_unlock_behavior' => $unlock_behavior,
                'enable_logging'          => Helpers::bool($input['enable_logging'] ?? false) ? 1 : 0,
            ],
            'errors' => $errors,
        ];
    }

    private function normalize_secret(string $secret): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", $secret));
    }
}

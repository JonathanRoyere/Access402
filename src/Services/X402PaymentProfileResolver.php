<?php

declare(strict_types=1);

namespace Access402\Services;

use Access402\Domain\CurrencyOptions;
use Access402\Domain\LogModeOptions;

final class X402PaymentProfileResolver
{
    private const NETWORKS = [
        LogModeOptions::TEST => [
            'network'               => 'eip155:84532',
            'network_label'         => 'Base Sepolia',
            'asset'                 => '0x036CbD53842c5426634e7929541eC2318f3dCF7e',
            'asset_symbol'          => 'USDC',
            'asset_name'            => 'USDC',
            'asset_version'         => '2',
            'asset_transfer_method' => 'eip3009',
            'decimals'              => 6,
        ],
        LogModeOptions::LIVE => [
            'network'               => 'eip155:8453',
            'network_label'         => 'Base',
            'asset'                 => '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913',
            'asset_symbol'          => 'USDC',
            'asset_name'            => 'USD Coin',
            'asset_version'         => '2',
            'asset_transfer_method' => 'eip3009',
            'decimals'              => 6,
        ],
    ];

    public function __construct(private readonly X402FacilitatorResolver $facilitator_resolver)
    {
    }

    public function resolve(array $effective_config, array $settings): array
    {
        $mode        = (string) ($effective_config['mode'] ?? LogModeOptions::TEST);
        $currency    = (string) ($effective_config['currency'] ?? '');
        $wallet      = trim((string) ($effective_config['wallet'] ?? ''));
        $price       = trim((string) ($effective_config['price'] ?? ''));
        $network     = self::NETWORKS[$mode] ?? null;
        $facilitator = $this->facilitator_resolver->resolve($mode, $settings);

        if (! is_array($network)) {
            return [
                'supported' => false,
                'message'   => __('Access402 could not resolve the active payment mode to a supported x402 network.', 'access402'),
            ];
        }

        if ($currency !== CurrencyOptions::USDC) {
            return [
                'supported' => false,
                'message'   => sprintf(
                    __('Access402 real x402 settlement currently supports %1$s only. The matched rule resolved to %2$s.', 'access402'),
                    'USDC',
                    $currency !== '' ? $currency : __('an unknown currency', 'access402')
                ),
            ];
        }

        if ($wallet === '') {
            return [
                'supported' => false,
                'message'   => __('Enter a payout wallet for the active mode before requiring payment on this rule.', 'access402'),
            ];
        }

        $amount = $this->decimal_to_atomic_units($price, (int) $network['decimals']);

        if ($amount === '' || $amount === '0') {
            return [
                'supported' => false,
                'message'   => __('Enter a positive USDC price before requiring payment on this rule.', 'access402'),
            ];
        }

        if (($facilitator['requires_auth'] ?? false) === true && ! ($facilitator['credentials_complete'] ?? false)) {
            return [
                'supported' => false,
                'message'   => $mode === LogModeOptions::LIVE
                    ? __('Live mode requires a CDP API key and secret before payments can be settled.', 'access402')
                    : __('Test mode will only use Coinbase CDP when both a test API key and secret are configured.', 'access402'),
            ];
        }

        return [
            'supported'              => true,
            'x402_version'           => 2,
            'scheme'                 => 'exact',
            'facilitator_id'         => (string) ($facilitator['id'] ?? ''),
            'facilitator_label' => (string) ($facilitator['label'] ?? ''),
            'facilitator_url'        => (string) ($facilitator['base_url'] ?? ''),
            'network'                => (string) $network['network'],
            'network_label'          => (string) $network['network_label'],
            'asset'                  => (string) $network['asset'],
            'asset_symbol'           => (string) $network['asset_symbol'],
            'asset_name'             => (string) ($network['asset_name'] ?? ''),
            'asset_version'          => (string) ($network['asset_version'] ?? ''),
            'asset_transfer_method'  => (string) ($network['asset_transfer_method'] ?? ''),
            'decimals'               => (int) $network['decimals'],
            'amount'                 => $amount,
            'amount_display'         => $price,
            'pay_to'                 => $wallet,
            'testnet'                => $mode === LogModeOptions::TEST,
            'timeout_seconds'        => 300,
        ];
    }

    private function decimal_to_atomic_units(string $value, int $decimals): string
    {
        $normalized = trim(str_replace(',', '.', $value));

        if ($normalized === '' || ! preg_match('/^\d+(?:\.\d+)?$/', $normalized)) {
            return '';
        }

        [$whole, $fraction] = array_pad(explode('.', $normalized, 2), 2, '');
        $fraction           = preg_replace('/\D+/', '', $fraction) ?? '';
        $round_digit        = '0';

        if (strlen($fraction) > $decimals) {
            $round_digit = $fraction[$decimals];
            $fraction    = substr($fraction, 0, $decimals);
        }

        $fraction = str_pad($fraction, $decimals, '0');
        $units    = ltrim($whole . $fraction, '0');
        $units    = $units === '' ? '0' : $units;

        if ((int) $round_digit >= 5) {
            $units = $this->increment_numeric_string($units);
        }

        return $units;
    }

    private function increment_numeric_string(string $value): string
    {
        $value = $value === '' ? '0' : $value;
        $carry = 1;
        $chars = str_split($value);

        for ($index = count($chars) - 1; $index >= 0; $index--) {
            $digit = ((int) $chars[$index]) + $carry;

            if ($digit >= 10) {
                $chars[$index] = '0';
                $carry         = 1;
                continue;
            }

            $chars[$index] = (string) $digit;
            $carry         = 0;
            break;
        }

        if ($carry === 1) {
            array_unshift($chars, '1');
        }

        return implode('', $chars);
    }
}

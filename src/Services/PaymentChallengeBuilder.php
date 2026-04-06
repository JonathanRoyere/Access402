<?php

declare(strict_types=1);

namespace Access402\Services;

use Access402\Domain\UnlockBehaviorOptions;

final class PaymentChallengeBuilder
{
    public function build(
        array $rule,
        array $effective_config,
        array $payment_profile,
        string $summary,
        string $resource_url,
        bool $accepts_json
    ): array
    {
        $path            = (string) (wp_parse_url($resource_url, PHP_URL_PATH) ?: '/');
        $name            = trim((string) ($rule['name'] ?? ''));
        $unlock_behavior = (string) ($effective_config['unlock_behavior'] ?? '');
        $extra           = array_filter(
            [
                'name'                => trim((string) ($payment_profile['asset_name'] ?? '')),
                'version'             => trim((string) ($payment_profile['asset_version'] ?? '')),
                'assetTransferMethod' => trim((string) ($payment_profile['asset_transfer_method'] ?? '')),
            ],
            static fn (string $value): bool => $value !== ''
        );

        return [
            'x402Version' => (int) ($payment_profile['x402_version'] ?? 2),
            'resource'    => [
                'url'         => $resource_url,
                'description' => $name !== ''
                    ? $name
                    : sprintf(__('Access to %s', 'access402'), $path),
                'mimeType'    => $accepts_json ? 'application/json' : 'text/html',
            ],
            'accepts'     => [
                [
                    'scheme'            => (string) ($payment_profile['scheme'] ?? 'exact'),
                    'network'           => (string) ($payment_profile['network'] ?? ''),
                    'asset'             => (string) ($payment_profile['asset'] ?? ''),
                    'amount'            => (string) ($payment_profile['amount'] ?? ''),
                    'payTo'             => (string) ($payment_profile['pay_to'] ?? ''),
                    'maxTimeoutSeconds' => (int) ($payment_profile['timeout_seconds'] ?? 300),
                    'extra'             => $extra !== [] ? $extra : new \stdClass(),
                ],
            ],
            'extensions'  => [
                'access402' => [
                    'provider'       => (string) ($effective_config['provider'] ?? 'coinbase_cdp'),
                    'facilitator'    => [
                        'id'    => (string) ($payment_profile['facilitator_id'] ?? ''),
                        'label' => (string) ($payment_profile['facilitator_label'] ?? ''),
                        'url'   => (string) ($payment_profile['facilitator_url'] ?? ''),
                    ],
                    'mode'           => (string) ($effective_config['mode'] ?? ''),
                    'currency'       => (string) ($effective_config['currency'] ?? ''),
                    'network'        => (string) ($payment_profile['network_label'] ?? $effective_config['network'] ?? ''),
                    'price'          => (string) ($effective_config['price'] ?? ''),
                    'wallet'         => (string) ($effective_config['wallet'] ?? ''),
                    'unlockBehavior' => $unlock_behavior,
                    'unlockLabel'    => UnlockBehaviorOptions::labels()[$unlock_behavior] ?? $unlock_behavior,
                    'path'           => $path,
                    'rule'           => [
                        'id'   => (int) ($rule['id'] ?? 0),
                        'name' => (string) ($rule['name'] ?? ''),
                    ],
                    'summary'        => $summary,
                ],
            ],
        ];
    }
}

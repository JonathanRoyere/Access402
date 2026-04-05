<?php

declare(strict_types=1);

namespace Access402\Services;

use Access402\Domain\ConnectionStatusOptions;

final class ProviderConnectionTester
{
    private const KEY_PERMISSION_URL = 'https://api.coinbase.com/api/v3/brokerage/key_permissions';

    public function __construct(
        private readonly CdpJwtEncoder $jwt_encoder,
        private readonly NetworkResolver $network_resolver,
        private readonly WalletValidator $wallet_validator
    ) {
    }

    public function test(string $mode, array $payload): array
    {
        $currency  = (string) ($payload['default_currency'] ?? '');
        $network   = $this->network_resolver->resolve($currency);
        $wallet    = trim((string) ($payload[$mode . '_wallet'] ?? ''));
        $api_key   = trim((string) ($payload[$mode . '_api_key'] ?? ''));
        $api_secret= trim((string) ($payload[$mode . '_api_secret'] ?? ''));

        if ($wallet !== '') {
            $validation = $this->wallet_validator->validate($wallet, $network);

            if (! $validation['valid']) {
                return [
                    'status'  => ConnectionStatusOptions::FAILED,
                    'message' => $validation['message'],
                ];
            }
        }

        $token = $this->jwt_encoder->encode(
            $api_key,
            $api_secret,
            'api.coinbase.com',
            '/api/v3/brokerage/key_permissions',
            'GET'
        );

        if (is_wp_error($token)) {
            return [
                'status'  => ConnectionStatusOptions::FAILED,
                'message' => $token->get_error_message(),
            ];
        }

        $response = wp_remote_get(
            self::KEY_PERMISSION_URL,
            [
                'timeout' => 15,
                'headers' => [
                    'Accept'        => 'application/json',
                    'Authorization' => 'Bearer ' . $token,
                ],
            ]
        );

        if (is_wp_error($response)) {
            return [
                'status'  => ConnectionStatusOptions::FAILED,
                'message' => $response->get_error_message(),
            ];
        }

        $status_code = (int) wp_remote_retrieve_response_code($response);
        $body        = json_decode((string) wp_remote_retrieve_body($response), true);
        $message     = is_array($body)
            ? (string) ($body['message'] ?? $body['error_response']['message'] ?? '')
            : '';

        if ($status_code >= 200 && $status_code < 300) {
            return [
                'status'  => ConnectionStatusOptions::CONNECTED,
                'message' => __('Coinbase CDP credentials are valid and the provider responded successfully.', 'access402'),
            ];
        }

        return [
            'status'  => ConnectionStatusOptions::FAILED,
            'message' => $message !== '' ? $message : sprintf(__('Coinbase CDP responded with HTTP %d.', 'access402'), $status_code),
        ];
    }
}

<?php

declare(strict_types=1);

namespace Access402\Services;

use Access402\Domain\ConnectionStatusOptions;

final class ProviderConnectionTester
{
    public function __construct(
        private readonly X402FacilitatorClient $facilitator_client,
        private readonly X402FacilitatorResolver $facilitator_resolver,
        private readonly NetworkResolver $network_resolver,
        private readonly WalletValidator $wallet_validator
    ) {
    }

    public function test(string $mode, array $payload): array
    {
        if ($mode !== 'live') {
            return [
                'status'  => ConnectionStatusOptions::FAILED,
                'message' => __('Sandbox mode does not have a configurable provider connection in v1.', 'access402'),
            ];
        }

        $currency  = (string) ($payload['default_currency'] ?? '');
        $network   = $this->network_resolver->resolve($currency);
        $wallet    = trim((string) ($payload[$mode . '_wallet'] ?? ''));
        if ($wallet !== '') {
            $validation = $this->wallet_validator->validate($wallet, $network);

            if (! $validation['valid']) {
                return [
                    'status'  => ConnectionStatusOptions::FAILED,
                    'message' => $validation['message'],
                ];
            }
        }

        $facilitator = $this->facilitator_resolver->resolve($mode, $payload);
        $probe       = $this->facilitator_client->probe($mode, $payload);

        if (is_wp_error($probe)) {
            return [
                'status'  => ConnectionStatusOptions::FAILED,
                'message' => $probe->get_error_message(),
            ];
        }

        $status_code = (int) ($probe['status_code'] ?? 500);
        $body        = (array) ($probe['body'] ?? []);
        $message     = (string) ($body['message'] ?? $body['error'] ?? $body['invalidMessage'] ?? $body['errorMessage'] ?? '');

        if ($status_code === 404 || $status_code >= 500 || ($facilitator['requires_auth'] ?? false) && in_array($status_code, [401, 403], true)) {
            return [
                'status'  => ConnectionStatusOptions::FAILED,
                'message' => $message !== '' ? $message : sprintf(__('The selected x402 facilitator responded with HTTP %d.', 'access402'), $status_code),
            ];
        }

        return [
            'status'  => ConnectionStatusOptions::CONNECTED,
            'message' => $this->success_message($facilitator),
        ];
    }

    private function success_message(array $facilitator): string
    {
        if (($facilitator['requires_auth'] ?? false) === true) {
            return __('Live mode will use Coinbase CDP and the facilitator responded successfully.', 'access402');
        }

        return __('Test mode is using the public x402.org facilitator, which does not require CDP API keys.', 'access402');
    }
}

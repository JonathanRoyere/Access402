<?php

declare(strict_types=1);

namespace Access402\Services;

use Access402\Domain\NetworkOptions;

final class WalletValidator
{
    public function validate(string $wallet, string $network): array
    {
        $wallet = trim($wallet);

        if ($wallet === '') {
            return [
                'valid'   => false,
                'message' => __('Enter a wallet address.', 'access402'),
            ];
        }

        $pattern = $this->patterns()[$network]['pattern'] ?? null;
        $message = $this->patterns()[$network]['message'] ?? __('Wallet validation is not available for this network.', 'access402');

        if (! is_string($pattern) || ! preg_match($pattern, $wallet)) {
            return [
                'valid'   => false,
                'message' => $message,
            ];
        }

        return [
            'valid'   => true,
            'message' => __('Wallet looks valid.', 'access402'),
        ];
    }

    public function client_config(): array
    {
        $config = [];

        foreach ($this->patterns() as $network => $data) {
            $config[$network] = [
                'pattern' => trim((string) $data['pattern'], '/'),
                'flags'   => 'u',
                'message' => $data['message'],
            ];
        }

        return $config;
    }

    private function patterns(): array
    {
        return [
            NetworkOptions::BASE => [
                'pattern' => '/^0x[a-fA-F0-9]{40}$/',
                'message' => __('Enter a valid EVM wallet address for Base.', 'access402'),
            ],
            NetworkOptions::SOLANA => [
                'pattern' => '/^[1-9A-HJ-NP-Za-km-z]{32,44}$/',
                'message' => __('Enter a valid Solana wallet address.', 'access402'),
            ],
        ];
    }
}

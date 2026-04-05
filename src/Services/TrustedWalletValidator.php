<?php

declare(strict_types=1);

namespace Access402\Services;

use Access402\Domain\NetworkOptions;
use Access402\Domain\RuleStatusOptions;
use Access402\Domain\WalletTypeOptions;

final class TrustedWalletValidator
{
    public function __construct(private readonly WalletValidator $wallet_validator)
    {
    }

    public function sanitize(array $input): array
    {
        $label          = sanitize_text_field((string) ($input['label'] ?? ''));
        $wallet_address = trim((string) ($input['wallet_address'] ?? ''));
        $wallet_type    = sanitize_key((string) ($input['wallet_type'] ?? WalletTypeOptions::OTHER));
        $status         = ! empty($input['status']) && $input['status'] !== 'disabled'
            ? RuleStatusOptions::ACTIVE
            : RuleStatusOptions::DISABLED;
        $errors         = [];

        if ($wallet_address === '') {
            $errors[] = __('Wallet address is required.', 'access402');
        }

        if (! WalletTypeOptions::is_valid($wallet_type)) {
            $errors[] = __('Choose a valid wallet type.', 'access402');
        }

        if ($wallet_address !== '' && $wallet_type === WalletTypeOptions::EVM) {
            $validation = $this->wallet_validator->validate($wallet_address, NetworkOptions::BASE);

            if (! $validation['valid']) {
                $errors[] = $validation['message'];
            }
        }

        if ($wallet_address !== '' && $wallet_type === WalletTypeOptions::SOLANA) {
            $validation = $this->wallet_validator->validate($wallet_address, NetworkOptions::SOLANA);

            if (! $validation['valid']) {
                $errors[] = $validation['message'];
            }
        }

        return [
            'data' => [
                'label'          => $label,
                'wallet_address' => $wallet_address,
                'wallet_type'    => $wallet_type,
                'status'         => $status,
            ],
            'errors' => $errors,
        ];
    }
}

<?php

declare(strict_types=1);

namespace Access402\Services;

use Access402\Repositories\SettingsRepository;
use Access402\Repositories\TrustedIpRepository;
use Access402\Repositories\TrustedWalletRepository;

final class AccessEvaluator
{
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly TrustedWalletRepository $trusted_wallets,
        private readonly TrustedIpRepository $trusted_ips
    ) {
    }

    public function evaluate(array $context): array
    {
        $settings      = $this->settings->all();
        $bypass_roles  = array_values((array) ($settings['bypass_roles'] ?? []));
        $user_roles    = array_values((array) ($context['user_roles'] ?? []));
        $wallet        = trim((string) ($context['wallet_address'] ?? ''));
        $ip            = trim((string) ($context['ip_address'] ?? ''));

        if ($bypass_roles !== [] && array_intersect($bypass_roles, $user_roles) !== []) {
            return [
                'bypassed' => true,
                'reason'   => 'role',
                'message'  => __('A trusted WordPress role bypassed payment.', 'access402'),
            ];
        }

        if ($wallet !== '') {
            foreach ($this->trusted_wallets->active() as $entry) {
                if (strcasecmp(trim((string) $entry['wallet_address']), $wallet) === 0) {
                    return [
                        'bypassed' => true,
                        'reason'   => 'wallet',
                        'message'  => __('A trusted wallet bypassed payment.', 'access402'),
                    ];
                }
            }
        }

        if ($ip !== '') {
            foreach ($this->trusted_ips->active() as $entry) {
                if (trim((string) $entry['ip_address']) === $ip) {
                    return [
                        'bypassed' => true,
                        'reason'   => 'ip',
                        'message'  => __('A trusted IP bypassed payment.', 'access402'),
                    ];
                }
            }
        }

        return [
            'bypassed' => false,
            'reason'   => '',
            'message'  => '',
        ];
    }
}

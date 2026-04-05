<?php

declare(strict_types=1);

namespace Access402\Services;

use Access402\Repositories\SettingsRepository;
use Access402\Support\Helpers;

final class EffectiveRuleConfigResolver
{
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly NetworkResolver $network_resolver
    ) {
    }

    public function resolve(array $rule, ?array $settings = null): array
    {
        $settings = $settings ?? $this->settings->all();
        $mode     = $this->settings->active_mode($settings);
        $currency = (string) ($settings['default_currency'] ?? 'USDC');
        $wallet   = (string) ($settings[$mode . '_wallet'] ?? '');

        return [
            'mode'             => $mode,
            'wallet'           => $wallet,
            'currency'         => $currency,
            'network'          => $this->network_resolver->resolve($currency),
            'price'            => $this->resolve_price($rule, $settings),
            'unlock_behavior'  => $this->resolve_unlock_behavior($rule, $settings),
            'provider'         => (string) ($settings['provider'] ?? 'coinbase_cdp'),
        ];
    }

    private function resolve_price(array $rule, array $settings): string
    {
        $override = $rule['price_override'] ?? null;

        if ($override !== null && $override !== '') {
            return Helpers::format_decimal((string) $override);
        }

        return Helpers::format_decimal((string) ($settings['default_price'] ?? ''));
    }

    private function resolve_unlock_behavior(array $rule, array $settings): string
    {
        $override = (string) ($rule['unlock_behavior_override'] ?? '');

        if ($override !== '') {
            return $override;
        }

        return (string) ($settings['default_unlock_behavior'] ?? '');
    }
}

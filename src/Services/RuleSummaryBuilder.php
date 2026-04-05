<?php

declare(strict_types=1);

namespace Access402\Services;

use Access402\Domain\UnlockBehaviorOptions;

final class RuleSummaryBuilder
{
    public function __construct(private readonly EffectiveRuleConfigResolver $resolver)
    {
    }

    public function build(array $rule, ?array $settings = null): string
    {
        $effective        = $this->resolver->resolve($rule, $settings);
        $path             = (string) ($rule['path_pattern'] ?? '/');
        $has_price_override = $rule['price_override'] !== null && $rule['price_override'] !== '';
        $price_phrase     = $has_price_override
            ? sprintf(
                __('require %1$s %2$s on %3$s', 'access402'),
                $effective['price'],
                $effective['currency'],
                $effective['network']
            )
            : sprintf(
                __('use the global default price in %1$s on %2$s', 'access402'),
                $effective['currency'],
                $effective['network']
            );

        return sprintf(
            __('Requests matching %1$s will %2$s and unlock access %3$s.', 'access402'),
            $path,
            $price_phrase,
            $this->unlock_phrase((string) $effective['unlock_behavior'])
        );
    }

    private function unlock_phrase(string $value): string
    {
        return match ($value) {
            UnlockBehaviorOptions::PER_REQUEST => __('per request', 'access402'),
            UnlockBehaviorOptions::WALLET_ONCE => __('one time per wallet', 'access402'),
            UnlockBehaviorOptions::FOURTEEN_DAYS => __('for 14 days', 'access402'),
            UnlockBehaviorOptions::FOREVER => __('forever', 'access402'),
            default => __('using the configured default', 'access402'),
        };
    }
}

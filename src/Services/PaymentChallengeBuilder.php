<?php

declare(strict_types=1);

namespace Access402\Services;

use Access402\Domain\UnlockBehaviorOptions;

final class PaymentChallengeBuilder
{
    public function build(array $rule, array $effective_config, string $summary, string $path): array
    {
        return [
            'schema'          => 'access402.x402.v1',
            'provider'        => $effective_config['provider'],
            'mode'            => $effective_config['mode'],
            'currency'        => $effective_config['currency'],
            'network'         => $effective_config['network'],
            'price'           => $effective_config['price'],
            'wallet'          => $effective_config['wallet'],
            'unlock_behavior' => $effective_config['unlock_behavior'],
            'unlock_label'    => UnlockBehaviorOptions::labels()[$effective_config['unlock_behavior']] ?? $effective_config['unlock_behavior'],
            'path'            => $path,
            'rule'            => [
                'id'   => (int) ($rule['id'] ?? 0),
                'name' => (string) ($rule['name'] ?? ''),
            ],
            'summary'         => $summary,
        ];
    }
}

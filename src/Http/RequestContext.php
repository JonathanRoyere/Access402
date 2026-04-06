<?php

declare(strict_types=1);

namespace Access402\Http;

final class RequestContext
{
    public function __construct(
        public readonly string $path,
        public readonly string $url,
        public readonly string $method,
        public readonly string $type,
        public readonly string $ip_address,
        public readonly string $wallet_address,
        public readonly string $payment_signature,
        public readonly array $user_roles,
        public readonly bool $accepts_json
    ) {
    }
}

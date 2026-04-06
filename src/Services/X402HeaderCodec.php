<?php

declare(strict_types=1);

namespace Access402\Services;

final class X402HeaderCodec
{
    public function encode(array $payload): string
    {
        return base64_encode((string) wp_json_encode($payload));
    }

    public function decode(string $header_value): array|\WP_Error
    {
        $header_value = trim($header_value);

        if ($header_value === '' || ! preg_match('/^[A-Za-z0-9+\/]*={0,2}$/', $header_value)) {
            return new \WP_Error('invalid_payment_header', __('The PAYMENT-SIGNATURE header is not valid base64.', 'access402'));
        }

        $decoded = base64_decode($header_value, true);

        if ($decoded === false) {
            return new \WP_Error('invalid_payment_header', __('The PAYMENT-SIGNATURE header could not be decoded.', 'access402'));
        }

        $payload = json_decode($decoded, true);

        if (! is_array($payload)) {
            return new \WP_Error('invalid_payment_header', __('The PAYMENT-SIGNATURE header did not contain valid JSON.', 'access402'));
        }

        return $payload;
    }
}

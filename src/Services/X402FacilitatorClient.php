<?php

declare(strict_types=1);

namespace Access402\Services;

final class X402FacilitatorClient
{
    public function __construct(
        private readonly X402FacilitatorResolver $facilitator_resolver,
        private readonly CdpJwtEncoder $jwt_encoder
    ) {
    }

    public function verify(array $payment_payload, array $payment_requirements, string $mode, array $settings): array|\WP_Error
    {
        return $this->request('verify', $payment_payload, $payment_requirements, $mode, $settings);
    }

    public function settle(array $payment_payload, array $payment_requirements, string $mode, array $settings): array|\WP_Error
    {
        return $this->request('settle', $payment_payload, $payment_requirements, $mode, $settings);
    }

    public function probe(string $mode, array $settings): array|\WP_Error
    {
        return $this->request('verify', [], [], $mode, $settings, true);
    }

    private function request(
        string $endpoint,
        array $payment_payload,
        array $payment_requirements,
        string $mode,
        array $settings,
        bool $probe = false
    ): array|\WP_Error {
        $facilitator = $this->facilitator_resolver->resolve($mode, $settings);
        $request_uri = '/platform/v2/x402/' . ltrim($endpoint, '/');
        $headers     = [
            'Accept'       => 'application/json',
            'Content-Type' => 'application/json',
        ];

        if (($facilitator['requires_auth'] ?? false) === true) {
            $api_key    = trim((string) ($settings[$mode . '_api_key'] ?? ''));
            $api_secret = trim((string) ($settings[$mode . '_api_secret'] ?? ''));
            $token      = $this->jwt_encoder->encode(
                $api_key,
                $api_secret,
                (string) ($facilitator['api_host'] ?? ''),
                $request_uri,
                'POST'
            );

            if (is_wp_error($token)) {
                return $token;
            }

            $headers['Authorization'] = 'Bearer ' . $token;
        }

        $body = $probe
            ? new \stdClass()
            : [
                'x402Version'         => (int) ($payment_payload['x402Version'] ?? 2),
                'paymentPayload'      => $payment_payload,
                'paymentRequirements' => $payment_requirements,
            ];

        $response = wp_remote_post(
            trailingslashit((string) ($facilitator['base_url'] ?? '')) . ltrim($endpoint, '/'),
            [
                'timeout' => 25,
                'headers' => $headers,
                'body'    => wp_json_encode($body),
            ]
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $status_code = (int) wp_remote_retrieve_response_code($response);
        $raw_body    = (string) wp_remote_retrieve_body($response);
        $body        = json_decode($raw_body, true);

        if (! is_array($body)) {
            return new \WP_Error(
                'invalid_facilitator_response',
                sprintf(
                    __('The x402 %1$s response from %2$s could not be parsed as JSON (HTTP %3$d).', 'access402'),
                    $endpoint,
                    (string) ($facilitator['label'] ?? __('the facilitator', 'access402')),
                    $status_code
                )
            );
        }

        return [
            'status_code' => $status_code,
            'body'        => $body,
            'facilitator' => $facilitator,
        ];
    }
}

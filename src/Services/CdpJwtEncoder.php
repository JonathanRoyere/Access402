<?php

declare(strict_types=1);

namespace Access402\Services;

final class CdpJwtEncoder
{
    public function encode(string $api_key, string $api_secret, string $request_host, string $request_path, string $request_method = 'GET'): string|\WP_Error
    {
        $api_key    = trim($api_key);
        $api_secret = trim($api_secret);

        if ($api_key === '' || $api_secret === '') {
            return new \WP_Error('missing_credentials', __('CDP API key and secret are required.', 'access402'));
        }

        $algorithm = str_contains($api_secret, 'BEGIN') ? 'ES256' : 'EdDSA';
        $header    = [
            'alg'   => $algorithm,
            'kid'   => $api_key,
            'nonce' => wp_generate_password(16, false, false),
            'typ'   => 'JWT',
        ];
        $payload   = [
            'iss' => 'cdp',
            'sub' => $api_key,
            'nbf' => time(),
            'exp' => time() + 120,
            'uri' => strtoupper($request_method) . ' ' . $request_host . $request_path,
        ];

        $segments = [
            $this->base64_url_encode((string) wp_json_encode($header)),
            $this->base64_url_encode((string) wp_json_encode($payload)),
        ];
        $input    = implode('.', $segments);

        if ($algorithm === 'EdDSA') {
            if (! function_exists('sodium_crypto_sign_detached')) {
                return new \WP_Error('missing_sodium', __('The Sodium extension is required for Ed25519 CDP keys.', 'access402'));
            }

            $secret_key = base64_decode(str_replace(["\n", "\r", ' '], '', $api_secret), true);

            if ($secret_key === false || ! in_array(strlen($secret_key), [32, 64], true)) {
                return new \WP_Error('invalid_secret', __('The CDP secret could not be decoded as an Ed25519 private key.', 'access402'));
            }

            if (strlen($secret_key) === 32) {
                $secret_key = sodium_crypto_sign_seed_keypair($secret_key);
                $secret_key = sodium_crypto_sign_secretkey($secret_key);
            }

            $signature = sodium_crypto_sign_detached($input, $secret_key);

            return $input . '.' . $this->base64_url_encode($signature);
        }

        $private_key = openssl_pkey_get_private($api_secret);

        if ($private_key === false) {
            return new \WP_Error('invalid_secret', __('The CDP secret could not be parsed as a private key.', 'access402'));
        }

        $signature = '';
        $signed    = openssl_sign($input, $signature, $private_key, OPENSSL_ALGO_SHA256);
        openssl_free_key($private_key);

        if (! $signed) {
            return new \WP_Error('sign_failed', __('Failed to sign the CDP JWT.', 'access402'));
        }

        return $input . '.' . $this->base64_url_encode($this->ecdsa_der_to_jose($signature, 64));
    }

    private function base64_url_encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function ecdsa_der_to_jose(string $signature, int $length): string
    {
        $offset = 3;
        $r_size = ord($signature[$offset]);
        $r      = substr($signature, $offset + 1, $r_size);
        $offset = $offset + 2 + $r_size;
        $s_size = ord($signature[$offset]);
        $s      = substr($signature, $offset + 1, $s_size);
        $r      = ltrim($r, "\x00");
        $s      = ltrim($s, "\x00");
        $r      = str_pad($r, $length / 2, "\x00", STR_PAD_LEFT);
        $s      = str_pad($s, $length / 2, "\x00", STR_PAD_LEFT);

        return $r . $s;
    }
}

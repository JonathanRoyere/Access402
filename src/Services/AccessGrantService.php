<?php

declare(strict_types=1);

namespace Access402\Services;

use Access402\Domain\UnlockBehaviorOptions;

final class AccessGrantService
{
    private const COOKIE_PREFIX = 'access402_unlock_';

    public function issue_grant(int $rule_id, string $path, string $unlock_behavior, array $metadata = []): void
    {
        $expires_at = $this->expiry_for_unlock_behavior($unlock_behavior);
        $value      = $this->sign(array_merge(
            [
                'rule_id'         => $rule_id,
                'path'            => $path,
                'unlock_behavior' => $unlock_behavior,
                'exp'             => $expires_at,
                'iat'             => time(),
            ],
            $this->sanitize_metadata($metadata)
        ));

        $this->set_cookie($this->cookie_name($rule_id, $path), $value, $expires_at);
    }

    public function grant_for(int $rule_id, string $path): ?array
    {
        $cookie = $_COOKIE[$this->cookie_name($rule_id, $path)] ?? null;

        if (! is_string($cookie) || $cookie === '') {
            return null;
        }

        $payload = $this->verify($cookie);

        if (! is_array($payload)) {
            $this->clear_grant($rule_id, $path);

            return null;
        }

        if ((int) ($payload['rule_id'] ?? 0) !== $rule_id || (string) ($payload['path'] ?? '') !== $path) {
            $this->clear_grant($rule_id, $path);

            return null;
        }

        $expires_at = (int) ($payload['exp'] ?? 0);

        if ($expires_at > 0 && $expires_at < time()) {
            $this->clear_grant($rule_id, $path);

            return null;
        }

        return $payload;
    }

    public function consume_if_needed(array $grant): void
    {
        if ((string) ($grant['unlock_behavior'] ?? '') !== UnlockBehaviorOptions::PER_REQUEST) {
            return;
        }

        $this->clear_grant((int) ($grant['rule_id'] ?? 0), (string) ($grant['path'] ?? ''));
    }

    public function clear_grant(int $rule_id, string $path): void
    {
        $this->set_cookie($this->cookie_name($rule_id, $path), '', time() - HOUR_IN_SECONDS);
    }

    private function sanitize_metadata(array $metadata): array
    {
        $sanitized = [];

        foreach ($metadata as $key => $value) {
            if (! is_scalar($value) || $value === '') {
                continue;
            }

            $sanitized[sanitize_key((string) $key)] = is_string($value)
                ? sanitize_text_field($value)
                : $value;
        }

        return $sanitized;
    }

    private function expiry_for_unlock_behavior(string $unlock_behavior): int
    {
        return match ($unlock_behavior) {
            UnlockBehaviorOptions::PER_REQUEST => time() + HOUR_IN_SECONDS,
            UnlockBehaviorOptions::WALLET_ONCE => 0,
            UnlockBehaviorOptions::FOURTEEN_DAYS => time() + (14 * DAY_IN_SECONDS),
            UnlockBehaviorOptions::FOREVER => time() + YEAR_IN_SECONDS * 10,
            default => time() + HOUR_IN_SECONDS,
        };
    }

    private function cookie_name(int $rule_id, string $path): string
    {
        return self::COOKIE_PREFIX . $rule_id . '_' . substr(md5($path), 0, 12);
    }

    private function sign(array $payload): string
    {
        $json      = wp_json_encode($payload);
        $encoded   = $this->base64_url_encode((string) $json);
        $signature = hash_hmac('sha256', $encoded, wp_salt('auth'));

        return $encoded . '.' . $signature;
    }

    private function verify(string $token): ?array
    {
        [$encoded, $signature] = array_pad(explode('.', $token, 2), 2, '');

        if ($encoded === '' || $signature === '') {
            return null;
        }

        $expected = hash_hmac('sha256', $encoded, wp_salt('auth'));

        if (! hash_equals($expected, $signature)) {
            return null;
        }

        $padded  = strtr($encoded, '-_', '+/');
        $padded .= str_repeat('=', (4 - strlen($padded) % 4) % 4);
        $decoded = base64_decode($padded, true);
        $data    = json_decode((string) $decoded, true);

        return is_array($data) ? $data : null;
    }

    private function base64_url_encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function set_cookie(string $name, string $value, int $expires_at): void
    {
        setcookie(
            $name,
            $value,
            [
                'expires'  => $expires_at,
                'path'     => COOKIEPATH ?: '/',
                'domain'   => COOKIE_DOMAIN ?: '',
                'secure'   => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]
        );
    }
}

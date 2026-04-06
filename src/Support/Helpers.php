<?php

declare(strict_types=1);

namespace Access402\Support;

use Access402\Capabilities;

final class Helpers
{
    public static function table(string $suffix): string
    {
        global $wpdb;

        return $wpdb->prefix . 'access402_' . $suffix;
    }

    public static function now(): string
    {
        return current_time('mysql');
    }

    public static function bool(mixed $value): bool
    {
        return in_array($value, [1, '1', true, 'true', 'on', 'yes'], true);
    }

    public static function admin_url(array $args = []): string
    {
        return add_query_arg(
            array_merge(
                [
                    'page' => 'access402',
                ],
                $args
            ),
            admin_url('admin.php')
        );
    }

    public static function current_user_can_manage(): bool
    {
        return current_user_can(Capabilities::MANAGE) || current_user_can('manage_options');
    }

    public static function request_path(): string
    {
        $request_uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash((string) $_SERVER['REQUEST_URI']) : '/';
        $path        = (string) wp_parse_url($request_uri, PHP_URL_PATH);

        return self::normalize_path($path);
    }

    public static function request_url(): string
    {
        $request_uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash((string) $_SERVER['REQUEST_URI']) : '/';

        return home_url($request_uri);
    }

    public static function normalize_path(string $path): string
    {
        $path = trim($path);

        if ($path === '') {
            return '/';
        }

        $parsed = wp_parse_url($path);
        $path   = is_array($parsed) && isset($parsed['path']) ? (string) $parsed['path'] : $path;
        $path   = '/' . ltrim($path, '/');
        $path   = preg_replace('#/+#', '/', $path) ?: '/';

        if ($path !== '/') {
            $path = untrailingslashit($path);
        }

        return $path;
    }

    public static function normalize_path_pattern(string $pattern): string
    {
        $pattern = trim($pattern);

        if ($pattern === '') {
            return '';
        }

        $has_wildcard = str_contains($pattern, '*');
        $placeholder  = '__ACCESS402_WILDCARD__';

        if ($has_wildcard) {
            $pattern = str_replace('*', $placeholder, $pattern);
        }

        $pattern = self::normalize_path($pattern);

        if ($has_wildcard) {
            $pattern = str_replace($placeholder, '*', $pattern);
        }

        return $pattern;
    }

    public static function decimal_string(mixed $value, int $scale = 8): string
    {
        $normalized = str_replace(',', '.', trim((string) $value));
        $number     = is_numeric($normalized) ? (float) $normalized : 0.0;

        return number_format($number, $scale, '.', '');
    }

    public static function format_decimal(?string $value, int $scale = 8): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $formatted = self::decimal_string($value, $scale);

        return rtrim(rtrim($formatted, '0'), '.');
    }

    public static function active_mode(array $settings): string
    {
        return self::bool($settings['test_mode'] ?? true) ? 'test' : 'live';
    }

    public static function request_ip(): string
    {
        $candidates = [
            $_SERVER['HTTP_CF_CONNECTING_IP'] ?? null,
            $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (! is_string($candidate) || trim($candidate) === '') {
                continue;
            }

            $ip = trim(explode(',', $candidate)[0]);

            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }

        return '';
    }

    public static function request_headers(): array
    {
        if (function_exists('getallheaders')) {
            $headers = getallheaders();

            if (is_array($headers)) {
                return $headers;
            }
        }

        $headers = [];

        foreach ($_SERVER as $key => $value) {
            if (! str_starts_with((string) $key, 'HTTP_')) {
                continue;
            }

            $name            = str_replace('_', '-', substr((string) $key, 5));
            $headers[$name]  = (string) $value;
        }

        return $headers;
    }

    public static function request_wallet_address(): string
    {
        $headers = self::request_headers();
        $aliases = [
            'PAYMENT-WALLET',
            'X-WALLET-ADDRESS',
            'X-PAYMENT-FROM',
            'X-ACCESS402-WALLET',
        ];

        foreach ($aliases as $alias) {
            foreach ($headers as $name => $value) {
                if (strcasecmp($name, $alias) === 0 && is_string($value) && trim($value) !== '') {
                    return trim($value);
                }
            }
        }

        return '';
    }

    public static function request_payment_signature(): string
    {
        $headers = self::request_headers();
        $aliases = [
            'PAYMENT-SIGNATURE',
            'X-PAYMENT',
        ];

        foreach ($aliases as $alias) {
            foreach ($headers as $name => $value) {
                if (strcasecmp($name, $alias) === 0 && is_string($value) && trim($value) !== '') {
                    return trim($value);
                }
            }
        }

        return '';
    }
}

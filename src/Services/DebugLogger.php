<?php

declare(strict_types=1);

namespace Access402\Services;

final class DebugLogger
{
    private const MAX_DEPTH = 4;
    private const MAX_STRING_LENGTH = 1200;

    public function is_enabled(): bool
    {
        return defined('WP_DEBUG_LOG') && (bool) WP_DEBUG_LOG;
    }

    public function log(string $event, array $context = []): void
    {
        if (! $this->is_enabled()) {
            return;
        }

        $event = preg_replace('/[^a-z0-9_\-]+/i', '_', strtolower(trim($event))) ?: 'event';
        $json  = wp_json_encode($this->normalize_array($context), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        error_log(sprintf('[Access402][%s] %s', $event, is_string($json) ? $json : '{}'));
    }

    private function normalize_array(array $context, int $depth = 0): array
    {
        if ($depth >= self::MAX_DEPTH) {
            return ['notice' => 'depth_limited'];
        }

        $normalized = [];

        foreach ($context as $key => $value) {
            $string_key = is_string($key) ? $key : (string) $key;

            if ($this->is_sensitive_key($string_key)) {
                $normalized[$string_key] = $this->redact_value($value);
                continue;
            }

            $normalized[$string_key] = $this->normalize_value($value, $depth + 1);
        }

        return $normalized;
    }

    private function normalize_value(mixed $value, int $depth): mixed
    {
        if ($depth >= self::MAX_DEPTH) {
            return 'depth_limited';
        }

        if (is_null($value) || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value)) {
            return $this->truncate($value);
        }

        if (is_array($value)) {
            return $this->normalize_array($value, $depth);
        }

        if ($value instanceof \WP_Error) {
            return [
                'code'    => $value->get_error_code(),
                'message' => $value->get_error_message(),
            ];
        }

        if (is_object($value)) {
            return [
                'class' => $value::class,
            ];
        }

        return $this->truncate((string) $value);
    }

    private function is_sensitive_key(string $key): bool
    {
        $key = strtolower($key);

        foreach (['secret', 'token', 'authorization', 'api_key', 'api_secret', 'payment_signature', 'signature'] as $needle) {
            if (str_contains($key, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function redact_value(mixed $value): string
    {
        $value = is_scalar($value) ? trim((string) $value) : 'redacted';

        if ($value === '' || $value === 'redacted') {
            return 'redacted';
        }

        if (strlen($value) <= 12) {
            return 'redacted';
        }

        return substr($value, 0, 6) . '...' . substr($value, -4);
    }

    private function truncate(string $value): string
    {
        $value = trim($value);

        if (strlen($value) <= self::MAX_STRING_LENGTH) {
            return $value;
        }

        return substr($value, 0, self::MAX_STRING_LENGTH) . '...';
    }
}

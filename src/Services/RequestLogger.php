<?php

declare(strict_types=1);

namespace Access402\Services;

use Access402\Repositories\LogRepository;
use Access402\Repositories\SettingsRepository;

final class RequestLogger
{
    private const DEDUPE_WINDOW_SECONDS = 10;

    public function __construct(
        private readonly LogRepository $logs,
        private readonly SettingsRepository $settings
    ) {
    }

    public function maybe_log(array $data): void
    {
        if (! $this->settings->get('enable_logging', 1)) {
            return;
        }

        $data = $this->normalize($data);

        if ($this->should_skip($data)) {
            return;
        }

        if ($this->logs->has_recent_duplicate($data, self::DEDUPE_WINDOW_SECONDS)) {
            return;
        }

        $this->logs->insert($data);
    }

    private function normalize(array $data): array
    {
        $data['path']            = \Access402\Support\Helpers::normalize_path((string) ($data['path'] ?? '/'));
        $data['matched_rule_id'] = isset($data['matched_rule_id']) && $data['matched_rule_id'] !== '' ? (int) $data['matched_rule_id'] : null;
        $data['decision']        = sanitize_key((string) ($data['decision'] ?? ''));
        $data['wallet_address']  = isset($data['wallet_address']) && trim((string) $data['wallet_address']) !== '' ? trim((string) $data['wallet_address']) : null;
        $data['mode']            = sanitize_key((string) ($data['mode'] ?? 'test'));
        $data['message']         = trim((string) ($data['message'] ?? ''));

        return $data;
    }

    private function should_skip(array $data): bool
    {
        $path   = strtolower((string) ($data['path'] ?? ''));
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper(sanitize_text_field(wp_unslash((string) $_SERVER['REQUEST_METHOD']))) : 'GET';

        if ($path === '' || $path === '/') {
            return true;
        }

        if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
            return true;
        }

        if ($path === '/robots.txt' || str_starts_with($path, '/favicon')) {
            return true;
        }

        if (str_starts_with($path, '/apple-touch-icon')) {
            return true;
        }

        return false;
    }
}

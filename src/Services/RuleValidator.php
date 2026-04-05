<?php

declare(strict_types=1);

namespace Access402\Services;

use Access402\Domain\RuleStatusOptions;
use Access402\Domain\UnlockBehaviorOptions;
use Access402\Support\Helpers;

final class RuleValidator
{
    public function sanitize(array $input): array
    {
        $name         = sanitize_text_field(trim((string) ($input['name'] ?? '')));
        $path_pattern = Helpers::normalize_path_pattern((string) ($input['path_pattern'] ?? ''));
        $price_raw    = trim((string) ($input['price_override'] ?? ''));
        $unlock_raw   = sanitize_text_field((string) ($input['unlock_behavior_override'] ?? ''));
        $status_input = $input['status'] ?? 'active';
        $status       = ! empty($status_input) && $status_input !== 'disabled'
            ? RuleStatusOptions::ACTIVE
            : RuleStatusOptions::DISABLED;
        $errors       = [];

        if ($name === '') {
            $errors[] = __('Rule name is required.', 'access402');
        }

        if ($path_pattern === '') {
            $errors[] = __('A path or URL pattern is required.', 'access402');
        }

        $price_override = null;

        if ($price_raw !== '') {
            if (! is_numeric(str_replace(',', '.', $price_raw)) || (float) $price_raw <= 0) {
                $errors[] = __('Price override must be a positive number.', 'access402');
            } else {
                $price_override = Helpers::decimal_string($price_raw);
            }
        }

        $unlock_override = null;

        if ($unlock_raw !== '' && $unlock_raw !== '__global') {
            if (! UnlockBehaviorOptions::is_valid($unlock_raw)) {
                $errors[] = __('Choose a valid unlock behavior override.', 'access402');
            } else {
                $unlock_override = $unlock_raw;
            }
        }

        return [
            'data' => [
                'name'                     => $name,
                'path_pattern'             => $path_pattern,
                'price_override'           => $price_override,
                'unlock_behavior_override' => $unlock_override,
                'status'                   => $status,
            ],
            'errors' => $errors,
        ];
    }

    public function sanitize_preview(array $input): array
    {
        $result = $this->sanitize($input);
        $result['data']['name'] = $result['data']['name'] ?: __('Untitled rule', 'access402');
        $result['errors'] = array_values(
            array_filter(
                $result['errors'],
                static fn(string $error): bool => $error !== __('Rule name is required.', 'access402')
            )
        );

        return $result;
    }
}

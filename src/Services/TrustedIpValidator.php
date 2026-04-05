<?php

declare(strict_types=1);

namespace Access402\Services;

use Access402\Domain\RuleStatusOptions;

final class TrustedIpValidator
{
    public function sanitize(array $input): array
    {
        $label      = sanitize_text_field((string) ($input['label'] ?? ''));
        $ip_address = trim((string) ($input['ip_address'] ?? ''));
        $status     = ! empty($input['status']) && $input['status'] !== 'disabled'
            ? RuleStatusOptions::ACTIVE
            : RuleStatusOptions::DISABLED;
        $errors     = [];

        if ($ip_address === '' || ! filter_var($ip_address, FILTER_VALIDATE_IP)) {
            $errors[] = __('Enter a valid single IP address.', 'access402');
        }

        return [
            'data' => [
                'label'      => $label,
                'ip_address' => $ip_address,
                'status'     => $status,
            ],
            'errors' => $errors,
        ];
    }
}

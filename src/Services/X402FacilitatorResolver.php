<?php

declare(strict_types=1);

namespace Access402\Services;

use Access402\Domain\LogModeOptions;

final class X402FacilitatorResolver
{
    private const CDP_BASE_URL = 'https://api.cdp.coinbase.com/platform/v2/x402';
    private const CDP_API_HOST = 'api.cdp.coinbase.com';
    private const X402_ORG_BASE_URL = 'https://www.x402.org/facilitator';
    private const X402_ORG_API_HOST = 'www.x402.org';

    public function resolve(string $mode, array $settings): array
    {
        $mode       = $mode === LogModeOptions::LIVE ? LogModeOptions::LIVE : LogModeOptions::TEST;
        $api_key    = trim((string) ($settings[$mode . '_api_key'] ?? ''));
        $api_secret = trim((string) ($settings[$mode . '_api_secret'] ?? ''));
        $has_key    = $api_key !== '';
        $has_secret = $api_secret !== '';

        if ($mode === LogModeOptions::LIVE) {
            return [
                'id'                   => 'coinbase_cdp',
                'label'                => 'Coinbase CDP',
                'base_url'             => self::CDP_BASE_URL,
                'api_host'             => self::CDP_API_HOST,
                'requires_auth'        => true,
                'credentials_complete' => $has_key && $has_secret,
                'credentials_partial'  => $has_key xor $has_secret,
            ];
        }

        return [
            'id'                   => 'x402_org',
            'label'                => 'x402.org',
            'base_url'             => self::X402_ORG_BASE_URL,
            'api_host'             => self::X402_ORG_API_HOST,
            'requires_auth'        => false,
            'credentials_complete' => false,
            'credentials_partial'  => false,
        ];
    }
}

<?php

declare(strict_types=1);

namespace Access402\Repositories;

use Access402\Domain\ConnectionStatusOptions;
use Access402\Domain\CurrencyOptions;
use Access402\Domain\NetworkOptions;
use Access402\Domain\UnlockBehaviorOptions;
use Access402\Support\Helpers;

final class SettingsRepository
{
    public const OPTION_KEY     = 'access402_settings';
    public const DB_VERSION_KEY = 'access402_db_version';
    public const NOTICE_KEY     = 'access402_admin_notice_';

    public function defaults(): array
    {
        return [
            'test_mode'              => 1,
            'provider'               => 'coinbase_cdp',
            'test_api_key'           => '',
            'test_api_secret'        => '',
            'live_api_key'           => '',
            'live_api_secret'        => '',
            'test_connection_status' => ConnectionStatusOptions::NOT_TESTED,
            'live_connection_status' => ConnectionStatusOptions::NOT_TESTED,
            'test_wallet'            => '',
            'live_wallet'            => '',
            'default_currency'       => CurrencyOptions::USDC,
            'default_network'        => NetworkOptions::BASE,
            'default_price'          => '0.50',
            'default_unlock_behavior'=> UnlockBehaviorOptions::FOURTEEN_DAYS,
            'enable_logging'         => 1,
            'bypass_roles'           => [],
        ];
    }

    public function all(): array
    {
        $settings = get_option(self::OPTION_KEY, []);

        if (! is_array($settings)) {
            $settings = [];
        }

        $settings = wp_parse_args($settings, $this->defaults());
        $settings['bypass_roles'] = array_values(array_filter(array_map('sanitize_key', (array) ($settings['bypass_roles'] ?? []))));

        return $settings;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $settings = $this->all();

        return $settings[$key] ?? $default;
    }

    public function update(array $values): void
    {
        update_option(self::OPTION_KEY, array_merge($this->all(), $values), false);
    }

    public function delete_all(): void
    {
        delete_option(self::OPTION_KEY);
        delete_option(self::DB_VERSION_KEY);
    }

    public function get_db_version(): string
    {
        return (string) get_option(self::DB_VERSION_KEY, '');
    }

    public function set_db_version(string $version): void
    {
        update_option(self::DB_VERSION_KEY, $version, false);
    }

    public function set_notice(string $type, string $message): void
    {
        if (! function_exists('get_current_user_id')) {
            return;
        }

        set_transient(
            self::NOTICE_KEY . get_current_user_id(),
            [
                'type'    => $type,
                'message' => $message,
            ],
            MINUTE_IN_SECONDS * 5
        );
    }

    public function pull_notice(): array
    {
        if (! function_exists('get_current_user_id')) {
            return [];
        }

        $key   = self::NOTICE_KEY . get_current_user_id();
        $value = get_transient($key);

        if (! is_array($value)) {
            return [];
        }

        delete_transient($key);

        return $value;
    }

    public function active_mode(array $settings = []): string
    {
        $settings = $settings !== [] ? $settings : $this->all();

        return Helpers::active_mode($settings);
    }
}

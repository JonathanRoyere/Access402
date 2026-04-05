<?php

declare(strict_types=1);

namespace Access402\Database;

use Access402\Repositories\SettingsRepository;
use Access402\Support\Helpers;

final class Migrator
{
    public function __construct(private readonly SettingsRepository $settings)
    {
    }

    public function migrate(): void
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $this->charset_collate();
        $rules           = Helpers::table('rules');
        $trusted_wallets = Helpers::table('trusted_wallets');
        $trusted_ips     = Helpers::table('trusted_ips');
        $logs            = Helpers::table('request_logs');

        dbDelta("
            CREATE TABLE {$rules} (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                sort_order int NOT NULL DEFAULT 0,
                name varchar(255) NOT NULL,
                path_pattern text NOT NULL,
                price_override decimal(20,8) NULL,
                unlock_behavior_override varchar(50) NULL,
                status varchar(20) NOT NULL DEFAULT 'active',
                hits_count bigint unsigned NOT NULL DEFAULT 0,
                last_matched_at datetime NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY sort_status (sort_order, status),
                KEY status (status),
                KEY last_matched_at (last_matched_at)
            ) {$charset_collate};
        ");

        dbDelta("
            CREATE TABLE {$trusted_wallets} (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                label varchar(255) NULL,
                wallet_address text NOT NULL,
                wallet_type varchar(50) NOT NULL,
                status varchar(20) NOT NULL DEFAULT 'active',
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY wallet_type_status (wallet_type, status),
                KEY status (status)
            ) {$charset_collate};
        ");

        dbDelta("
            CREATE TABLE {$trusted_ips} (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                label varchar(255) NULL,
                ip_address varchar(255) NOT NULL,
                status varchar(20) NOT NULL DEFAULT 'active',
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY status (status),
                KEY ip_address (ip_address(100))
            ) {$charset_collate};
        ");

        dbDelta("
            CREATE TABLE {$logs} (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                logged_at datetime NOT NULL,
                path text NOT NULL,
                matched_rule_id bigint unsigned NULL,
                decision varchar(50) NOT NULL,
                wallet_address text NULL,
                mode varchar(20) NOT NULL,
                message text NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY logged_at (logged_at),
                KEY matched_rule_id (matched_rule_id),
                KEY decision (decision),
                KEY mode (mode)
            ) {$charset_collate};
        ");

        if (! get_option(SettingsRepository::OPTION_KEY)) {
            update_option(SettingsRepository::OPTION_KEY, $this->settings->defaults(), false);
        }

        $this->settings->set_db_version(ACCESS402_DB_VERSION);
    }

    public function drop_all(): void
    {
        global $wpdb;

        foreach (['rules', 'trusted_wallets', 'trusted_ips', 'request_logs'] as $table) {
            $wpdb->query('DROP TABLE IF EXISTS ' . Helpers::table($table));
        }
    }

    private function charset_collate(): string
    {
        global $wpdb;

        return $wpdb->get_charset_collate();
    }
}

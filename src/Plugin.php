<?php

declare(strict_types=1);

namespace Access402;

use Access402\Admin\AdminController;
use Access402\Database\Migrator;
use Access402\Http\RuntimeController;
use Access402\Repositories\LogRepository;
use Access402\Repositories\RuleRepository;
use Access402\Repositories\SettingsRepository;
use Access402\Repositories\TrustedIpRepository;
use Access402\Repositories\TrustedWalletRepository;
use Access402\Services\AccessEvaluator;
use Access402\Services\CdpJwtEncoder;
use Access402\Services\EffectiveRuleConfigResolver;
use Access402\Services\NetworkResolver;
use Access402\Services\ProviderConnectionTester;
use Access402\Services\RequestLogger;
use Access402\Services\RuleMatcher;
use Access402\Services\RuleSummaryBuilder;
use Access402\Services\RuleValidator;
use Access402\Services\SettingsValidator;
use Access402\Services\TrustedIpValidator;
use Access402\Services\TrustedWalletValidator;
use Access402\Services\WalletValidator;

final class Plugin
{
    private static ?self $instance = null;

    private bool $booted = false;

    public static function instance(): self
    {
        if (! self::$instance instanceof self) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public static function activate(): void
    {
        Capabilities::grant();

        $settings = new SettingsRepository();
        $migrator = new Migrator($settings);

        $migrator->migrate();
    }

    public static function deactivate(): void
    {
    }

    public static function uninstall(): void
    {
        $cleanup = apply_filters(
            'access402_cleanup_on_uninstall',
            defined('ACCESS402_UNINSTALL_REMOVE_DATA') && ACCESS402_UNINSTALL_REMOVE_DATA
        );

        if ($cleanup) {
            $settings = new SettingsRepository();
            $migrator = new Migrator($settings);

            $migrator->drop_all();
            $settings->delete_all();
        }

        Capabilities::revoke();
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $this->booted = true;

        $settings                   = new SettingsRepository();
        $network_resolver           = new NetworkResolver();
        $wallet_validator           = new WalletValidator();
        $rule_repository            = new RuleRepository();
        $trusted_wallet_repository  = new TrustedWalletRepository();
        $trusted_ip_repository      = new TrustedIpRepository();
        $log_repository             = new LogRepository();
        $rule_resolver              = new EffectiveRuleConfigResolver($settings, $network_resolver);
        $rule_summary_builder       = new RuleSummaryBuilder($rule_resolver);
        $request_logger             = new RequestLogger($log_repository, $settings);
        $matcher                    = new RuleMatcher($rule_repository);
        $access_evaluator           = new AccessEvaluator($settings, $trusted_wallet_repository, $trusted_ip_repository);
        $provider_connection_tester = new ProviderConnectionTester(new CdpJwtEncoder(), $network_resolver, $wallet_validator);
        $settings_validator         = new SettingsValidator($network_resolver, $wallet_validator);
        $rule_validator             = new RuleValidator();
        $trusted_wallet_validator   = new TrustedWalletValidator($wallet_validator);
        $trusted_ip_validator       = new TrustedIpValidator();

        add_action(
            'init',
            static function () use ($settings): void {
                if ($settings->get_db_version() !== ACCESS402_DB_VERSION) {
                    (new Migrator($settings))->migrate();
                }
            },
            1
        );

        (new RuntimeController(
            $settings,
            $rule_repository,
            $matcher,
            $rule_resolver,
            $rule_summary_builder,
            $access_evaluator,
            $request_logger
        ))->boot();

        if (is_admin()) {
            (new AdminController(
                $settings,
                $rule_repository,
                $trusted_wallet_repository,
                $trusted_ip_repository,
                $log_repository,
                $network_resolver,
                $wallet_validator,
                $provider_connection_tester,
                $rule_resolver,
                $rule_summary_builder,
                $settings_validator,
                $rule_validator,
                $trusted_wallet_validator,
                $trusted_ip_validator
            ))->boot();
        }
    }
}

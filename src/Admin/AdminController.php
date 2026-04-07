<?php

declare(strict_types=1);

namespace Access402\Admin;

use Access402\Capabilities;
use Access402\Domain\CurrencyOptions;
use Access402\Domain\LogDecisionOptions;
use Access402\Domain\LogModeOptions;
use Access402\Domain\RuleStatusOptions;
use Access402\Domain\UnlockBehaviorOptions;
use Access402\Domain\WalletTypeOptions;
use Access402\Repositories\LogRepository;
use Access402\Repositories\RuleRepository;
use Access402\Repositories\SettingsRepository;
use Access402\Repositories\TrustedIpRepository;
use Access402\Repositories\TrustedWalletRepository;
use Access402\Services\EffectiveRuleConfigResolver;
use Access402\Services\NetworkResolver;
use Access402\Services\RuleSummaryBuilder;
use Access402\Services\RuleValidator;
use Access402\Services\SettingsValidator;
use Access402\Services\TrustedIpValidator;
use Access402\Services\TrustedWalletValidator;
use Access402\Services\WalletValidator;
use Access402\Support\Helpers;
use Access402\Support\View;

final class AdminController
{
    private const AJAX_NONCE_ACTION = 'access402_admin_ajax';

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly RuleRepository $rules,
        private readonly TrustedWalletRepository $trusted_wallets,
        private readonly TrustedIpRepository $trusted_ips,
        private readonly LogRepository $logs,
        private readonly NetworkResolver $network_resolver,
        private readonly WalletValidator $wallet_validator,
        private readonly EffectiveRuleConfigResolver $rule_resolver,
        private readonly RuleSummaryBuilder $rule_summary_builder,
        private readonly SettingsValidator $settings_validator,
        private readonly RuleValidator $rule_validator,
        private readonly TrustedWalletValidator $trusted_wallet_validator,
        private readonly TrustedIpValidator $trusted_ip_validator
    ) {
    }

    public function boot(): void
    {
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_notices', [$this, 'render_notice']);

        add_action('admin_post_access402_save_settings', [$this, 'save_settings']);
        add_action('admin_post_access402_save_access_settings', [$this, 'save_access_settings']);

        add_action('wp_ajax_access402_preview_rule_summary', [$this, 'ajax_preview_rule_summary']);
        add_action('wp_ajax_access402_save_rule', [$this, 'ajax_save_rule']);
        add_action('wp_ajax_access402_delete_rule', [$this, 'ajax_delete_rule']);
        add_action('wp_ajax_access402_toggle_rule_status', [$this, 'ajax_toggle_rule_status']);
        add_action('wp_ajax_access402_bulk_rules', [$this, 'ajax_bulk_rules']);
        add_action('wp_ajax_access402_save_trusted_wallet', [$this, 'ajax_save_trusted_wallet']);
        add_action('wp_ajax_access402_delete_trusted_wallet', [$this, 'ajax_delete_trusted_wallet']);
        add_action('wp_ajax_access402_toggle_trusted_wallet', [$this, 'ajax_toggle_trusted_wallet']);
        add_action('wp_ajax_access402_save_trusted_ip', [$this, 'ajax_save_trusted_ip']);
        add_action('wp_ajax_access402_delete_trusted_ip', [$this, 'ajax_delete_trusted_ip']);
        add_action('wp_ajax_access402_toggle_trusted_ip', [$this, 'ajax_toggle_trusted_ip']);
    }

    public function register_menu(): void
    {
        add_menu_page(
            __('Access402', 'access402'),
            __('Access402', 'access402'),
            Capabilities::MANAGE,
            'access402',
            [$this, 'render_page'],
            'dashicons-lock',
            58
        );
    }

    public function enqueue_assets(string $hook): void
    {
        if ($hook !== 'toplevel_page_access402') {
            return;
        }

        wp_enqueue_style('access402-admin', ACCESS402_PLUGIN_URL . 'assets/css/admin.css', [], ACCESS402_VERSION);
        wp_enqueue_script('access402-admin', ACCESS402_PLUGIN_URL . 'assets/js/admin.js', [], ACCESS402_VERSION, true);
        wp_localize_script(
            'access402-admin',
            'access402Admin',
            [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce'   => wp_create_nonce(self::AJAX_NONCE_ACTION),
                'networkByCurrency' => $this->network_resolver->map(),
                'walletValidation'  => $this->wallet_validator->client_config(),
                'ruleUnlockOptions' => UnlockBehaviorOptions::labels(),
                'ruleRecords'       => $this->records_by_id($this->rules->query(['per_page' => 200, 'page' => 1])),
                'trustedWallets'    => $this->records_by_id($this->trusted_wallets->query()),
                'trustedIps'        => $this->records_by_id($this->trusted_ips->query()),
            ]
        );
    }

    public function render_notice(): void
    {
        if ((string) ($_GET['page'] ?? '') !== 'access402') {
            return;
        }

        $notice = $this->settings->pull_notice();

        if ($notice === []) {
            return;
        }

        $class = ($notice['type'] ?? 'success') === 'error' ? 'notice notice-error' : 'notice notice-success';

        printf('<div class="%1$s"><p>%2$s</p></div>', esc_attr($class), esc_html((string) ($notice['message'] ?? '')));
    }

    public function render_page(): void
    {
        if (! Helpers::current_user_can_manage()) {
            wp_die(esc_html__('You do not have permission to manage Access402.', 'access402'));
        }

        $tab      = $this->current_tab();
        $settings = $this->settings->all();
        $context  = [
            'tab'      => $tab,
            'tabs'     => $this->tabs(),
            'settings' => $settings,
            'content'  => $this->tab_context($tab, $settings),
        ];

        View::render('admin/page', $context);
    }

    public function save_settings(): void
    {
        $this->verify_post_request('access402_save_settings');

        $result = $this->settings_validator->sanitize(wp_unslash($_POST), $this->settings->all());

        if ($result['errors'] !== []) {
            $this->settings->set_notice('error', implode(' ', $result['errors']));
            wp_safe_redirect(Helpers::admin_url(['tab' => 'settings']));
            exit;
        }

        $this->settings->update($result['data']);
        $this->settings->set_notice('success', __('Settings saved.', 'access402'));
        wp_safe_redirect(Helpers::admin_url(['tab' => 'settings']));
        exit;
    }

    public function save_access_settings(): void
    {
        $this->verify_post_request('access402_save_access_settings');

        $available_roles = array_keys((array) wp_roles()->roles);
        $roles           = array_values(
            array_intersect(
                $available_roles,
                array_map('sanitize_key', (array) wp_unslash($_POST['bypass_roles'] ?? []))
            )
        );

        $this->settings->update(['bypass_roles' => $roles]);
        $this->settings->set_notice('success', __('Access settings saved.', 'access402'));
        wp_safe_redirect(Helpers::admin_url(['tab' => 'access']));
        exit;
    }

    public function ajax_preview_rule_summary(): void
    {
        $this->verify_ajax_request();

        $result = $this->rule_validator->sanitize_preview(wp_unslash($_POST));

        if ($result['errors'] !== []) {
            wp_send_json_error(['message' => implode(' ', $result['errors'])], 400);
        }

        wp_send_json_success([
            'summary' => $this->rule_summary_builder->build($result['data']),
        ]);
    }

    public function ajax_save_rule(): void
    {
        $this->verify_ajax_request();

        $id     = absint($_POST['id'] ?? 0);
        $result = $this->rule_validator->sanitize(wp_unslash($_POST));

        if ($result['errors'] !== []) {
            wp_send_json_error(['message' => implode(' ', $result['errors'])], 400);
        }

        $saved_id = $this->rules->save($result['data'], $id > 0 ? $id : null);
        $rule     = $this->rules->find($saved_id);

        wp_send_json_success(
            [
                'message' => $id > 0 ? __('Rule updated.', 'access402') : __('Rule created.', 'access402'),
                'record'  => is_array($rule) ? $rule : [],
            ]
        );
    }

    public function ajax_delete_rule(): void
    {
        $this->verify_ajax_request();

        $id = absint($_POST['id'] ?? 0);

        if ($id <= 0) {
            wp_send_json_error(['message' => __('Rule not found.', 'access402')], 400);
        }

        $this->rules->delete($id);
        wp_send_json_success(['message' => __('Rule deleted.', 'access402')]);
    }

    public function ajax_toggle_rule_status(): void
    {
        $this->verify_ajax_request();

        $id     = absint($_POST['id'] ?? 0);
        $status = sanitize_key((string) ($_POST['status'] ?? RuleStatusOptions::ACTIVE));

        if ($id <= 0 || ! RuleStatusOptions::is_valid($status)) {
            wp_send_json_error(['message' => __('Invalid rule action.', 'access402')], 400);
        }

        $this->rules->set_status([$id], $status);
        wp_send_json_success(['message' => __('Rule status updated.', 'access402')]);
    }

    public function ajax_bulk_rules(): void
    {
        $this->verify_ajax_request();

        $ids    = array_values(array_filter(array_map('absint', (array) ($_POST['ids'] ?? []))));
        $action = sanitize_key((string) ($_POST['bulk_action'] ?? ''));

        if ($ids === []) {
            wp_send_json_error(['message' => __('Select at least one rule.', 'access402')], 400);
        }

        switch ($action) {
            case 'enable':
                $this->rules->set_status($ids, RuleStatusOptions::ACTIVE);
                break;
            case 'disable':
                $this->rules->set_status($ids, RuleStatusOptions::DISABLED);
                break;
            case 'delete':
                $this->rules->delete_many($ids);
                break;
            default:
                wp_send_json_error(['message' => __('Choose a valid bulk action.', 'access402')], 400);
        }

        wp_send_json_success(['message' => __('Bulk action applied.', 'access402')]);
    }

    public function ajax_save_trusted_wallet(): void
    {
        $this->verify_ajax_request();

        $id     = absint($_POST['id'] ?? 0);
        $result = $this->trusted_wallet_validator->sanitize(wp_unslash($_POST));

        if ($result['errors'] !== []) {
            wp_send_json_error(['message' => implode(' ', $result['errors'])], 400);
        }

        $saved_id = $this->trusted_wallets->save($result['data'], $id > 0 ? $id : null);
        $record   = $this->trusted_wallets->find($saved_id);

        wp_send_json_success(
            [
                'message' => $id > 0 ? __('Trusted wallet updated.', 'access402') : __('Trusted wallet created.', 'access402'),
                'record'  => is_array($record) ? $record : [],
            ]
        );
    }

    public function ajax_delete_trusted_wallet(): void
    {
        $this->verify_ajax_request();

        $id = absint($_POST['id'] ?? 0);
        $this->trusted_wallets->delete($id);
        wp_send_json_success(['message' => __('Trusted wallet deleted.', 'access402')]);
    }

    public function ajax_toggle_trusted_wallet(): void
    {
        $this->verify_ajax_request();

        $id     = absint($_POST['id'] ?? 0);
        $status = sanitize_key((string) ($_POST['status'] ?? RuleStatusOptions::ACTIVE));

        $this->trusted_wallets->set_status($id, $status);
        wp_send_json_success(['message' => __('Trusted wallet updated.', 'access402')]);
    }

    public function ajax_save_trusted_ip(): void
    {
        $this->verify_ajax_request();

        $id     = absint($_POST['id'] ?? 0);
        $result = $this->trusted_ip_validator->sanitize(wp_unslash($_POST));

        if ($result['errors'] !== []) {
            wp_send_json_error(['message' => implode(' ', $result['errors'])], 400);
        }

        $saved_id = $this->trusted_ips->save($result['data'], $id > 0 ? $id : null);
        $record   = $this->trusted_ips->find($saved_id);

        wp_send_json_success(
            [
                'message' => $id > 0 ? __('Trusted IP updated.', 'access402') : __('Trusted IP created.', 'access402'),
                'record'  => is_array($record) ? $record : [],
            ]
        );
    }

    public function ajax_delete_trusted_ip(): void
    {
        $this->verify_ajax_request();

        $id = absint($_POST['id'] ?? 0);
        $this->trusted_ips->delete($id);
        wp_send_json_success(['message' => __('Trusted IP deleted.', 'access402')]);
    }

    public function ajax_toggle_trusted_ip(): void
    {
        $this->verify_ajax_request();

        $id     = absint($_POST['id'] ?? 0);
        $status = sanitize_key((string) ($_POST['status'] ?? RuleStatusOptions::ACTIVE));

        $this->trusted_ips->set_status($id, $status);
        wp_send_json_success(['message' => __('Trusted IP updated.', 'access402')]);
    }

    private function tab_context(string $tab, array $settings): array
    {
        return match ($tab) {
            'settings' => [
                'settings'           => $settings,
                'currency_options'   => CurrencyOptions::labels(),
                'unlock_options'     => UnlockBehaviorOptions::labels(),
                'network'            => $this->network_resolver->resolve((string) $settings['default_currency']),
            ],
            'rules' => $this->rules_context(),
            'access' => $this->access_context($settings),
            'logs' => $this->logs_context(),
            default => [],
        };
    }

    private function rules_context(): array
    {
        $search   = sanitize_text_field((string) ($_GET['s'] ?? ''));
        $status   = sanitize_key((string) ($_GET['status'] ?? ''));
        $page     = max(1, absint($_GET['paged'] ?? 1));
        $filters  = [
            'search'   => $search,
            'status'   => $status,
            'per_page' => 20,
            'page'     => $page,
        ];
        $records  = $this->rules->query($filters);
        $total    = $this->rules->count($filters);

        foreach ($records as &$record) {
            $record['summary'] = $this->rule_summary_builder->build($record);
        }

        return [
            'records'              => $records,
            'search'               => $search,
            'status'               => $status,
            'status_options'       => RuleStatusOptions::labels(),
            'unlock_options'       => UnlockBehaviorOptions::labels(),
            'pagination'           => $this->pagination($total, 20, $page),
            'global_settings'      => $this->settings->all(),
        ];
    }

    private function access_context(array $settings): array
    {
        return [
            'roles'             => (array) wp_roles()->roles,
            'selected_roles'    => (array) ($settings['bypass_roles'] ?? []),
            'trusted_wallets'   => $this->trusted_wallets->query(),
            'trusted_ips'       => $this->trusted_ips->query(),
            'wallet_types'      => WalletTypeOptions::labels(),
        ];
    }

    private function logs_context(): array
    {
        $search_path = sanitize_text_field((string) ($_GET['search_path'] ?? ''));
        $decision    = sanitize_key((string) ($_GET['decision'] ?? ''));
        $mode        = sanitize_key((string) ($_GET['mode'] ?? ''));
        $page        = max(1, absint($_GET['paged'] ?? 1));
        $filters     = [
            'search_path' => $search_path,
            'decision'    => $decision,
            'mode'        => $mode,
            'per_page'    => 30,
            'page'        => $page,
        ];
        $records     = $this->logs->query($filters);
        $total       = $this->logs->count($filters);

        return [
            'records'          => $records,
            'search_path'      => $search_path,
            'decision'         => $decision,
            'mode'             => $mode,
            'decision_options' => LogDecisionOptions::labels(),
            'mode_options'     => LogModeOptions::labels(),
            'pagination'       => $this->pagination($total, 30, $page),
        ];
    }

    private function tabs(): array
    {
        return [
            'settings' => __('Settings', 'access402'),
            'rules'    => __('Rules', 'access402'),
            'access'   => __('Access', 'access402'),
            'logs'     => __('Logs', 'access402'),
        ];
    }

    private function current_tab(): string
    {
        $tab = sanitize_key((string) ($_GET['tab'] ?? 'settings'));

        return array_key_exists($tab, $this->tabs()) ? $tab : 'settings';
    }

    private function verify_post_request(string $nonce_action): void
    {
        if (! Helpers::current_user_can_manage()) {
            wp_die(esc_html__('You do not have permission to manage Access402.', 'access402'));
        }

        check_admin_referer($nonce_action);
    }

    private function verify_ajax_request(): void
    {
        if (! Helpers::current_user_can_manage()) {
            wp_send_json_error(['message' => __('You do not have permission to manage Access402.', 'access402')], 403);
        }

        check_ajax_referer(self::AJAX_NONCE_ACTION, 'nonce');
    }

    private function records_by_id(array $records): array
    {
        $indexed = [];

        foreach ($records as $record) {
            $indexed[(int) $record['id']] = $record;
        }

        return $indexed;
    }

    private function pagination(int $total, int $per_page, int $page): array
    {
        return [
            'total'       => $total,
            'per_page'    => $per_page,
            'current'     => $page,
            'total_pages' => max(1, (int) ceil($total / $per_page)),
        ];
    }
}

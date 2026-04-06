<?php

declare(strict_types=1);

namespace Access402\Http;

use Access402\Services\ProtectedPaymentFlow;
use Access402\Support\Helpers;

final class UnlockController
{
    public const ROUTE_NAMESPACE = 'access402/v1';
    public const ROUTE_UNLOCK = '/unlock';

    public function __construct(private readonly ProtectedPaymentFlow $payment_flow)
    {
    }

    public function boot(): void
    {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes(): void
    {
        register_rest_route(
            self::ROUTE_NAMESPACE,
            self::ROUTE_UNLOCK,
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'unlock'],
                'permission_callback' => '__return_true',
            ]
        );
    }

    public function unlock(\WP_REST_Request $request): \WP_REST_Response
    {
        $raw_target       = trim((string) ($request->get_param('target_path') ?: $request->get_param('path') ?: ''));
        $raw_redirect_url = trim((string) ($request->get_param('target_url') ?: ''));

        if ($raw_target === '') {
            return new \WP_REST_Response(
                [
                    'message' => __('Send a target_path when requesting an Access402 unlock.', 'access402'),
                ],
                400
            );
        }

        $target_path  = Helpers::normalize_path($raw_target);
        $redirect_url = wp_validate_redirect($raw_redirect_url, home_url($target_path));

        $context  = $this->build_context();
        $decision = $this->payment_flow->evaluate(
            $context,
            $target_path,
            [
                'grant_path'               => $target_path,
                'consume_existing_grant'   => false,
                'issue_grant_after_settle' => 'always',
                'accepts_json'             => true,
                'touch_match'              => false,
                'log_path'                 => $target_path,
            ]
        );

        if (($decision['allow'] ?? false) === true) {
            $matched_rule = $decision['matched_rule'] ?? null;

            return new \WP_REST_Response(
                [
                    'unlocked'        => true,
                    'redirectUrl'     => $redirect_url,
                    'targetPath'      => $target_path,
                    'requiresPayment' => false,
                    'matchedRuleId'   => is_array($matched_rule) ? (int) ($matched_rule['id'] ?? 0) : null,
                    'message'         => (string) ($decision['message'] ?? __('Access is already available for this page.', 'access402')),
                ],
                200,
                (array) ($decision['headers'] ?? [])
            );
        }

        return new \WP_REST_Response(
            [
                'message'         => (string) ($decision['message'] ?? __('Payment required.', 'access402')),
                'payment'         => $decision['payload'] ?? [],
                'targetPath'      => $target_path,
                'redirectUrl'     => $redirect_url,
                'requiresPayment' => ((int) ($decision['status'] ?? 402) === 402),
            ],
            (int) ($decision['status'] ?? 402),
            (array) ($decision['headers'] ?? [])
        );
    }

    private function build_context(): RequestContext
    {
        $user   = wp_get_current_user();
        $accept = isset($_SERVER['HTTP_ACCEPT']) ? wp_unslash((string) $_SERVER['HTTP_ACCEPT']) : '';
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper(sanitize_text_field(wp_unslash((string) $_SERVER['REQUEST_METHOD']))) : 'POST';

        return new RequestContext(
            Helpers::request_path(),
            Helpers::request_url(),
            $method,
            'unlock',
            Helpers::request_ip(),
            Helpers::request_wallet_address(),
            Helpers::request_payment_signature(),
            $user instanceof \WP_User ? (array) $user->roles : [],
            str_contains($accept, 'application/json')
        );
    }
}

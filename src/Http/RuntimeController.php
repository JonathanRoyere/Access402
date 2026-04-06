<?php

declare(strict_types=1);

namespace Access402\Http;

use Access402\Services\CheckoutPageRenderer;
use Access402\Services\ProtectedFileUrlService;
use Access402\Services\ProtectedPaymentFlow;
use Access402\Support\Helpers;

final class RuntimeController
{
    /**
     * REST responses need headers attached to the response object itself.
     *
     * @var array<string, string>
     */
    private array $pending_rest_headers = [];

    public function __construct(
        private readonly ProtectedPaymentFlow $payment_flow,
        private readonly CheckoutPageRenderer $checkout_renderer
    ) {
    }

    public function boot(): void
    {
        add_action('template_redirect', [$this, 'intercept_frontend'], 0);
        add_filter('rest_dispatch_request', [$this, 'intercept_rest'], 5, 4);
        add_filter('rest_post_dispatch', [$this, 'apply_rest_headers'], 5, 3);
    }

    public function intercept_frontend(): void
    {
        if (is_admin() || wp_doing_ajax() || wp_doing_cron() || (defined('REST_REQUEST') && REST_REQUEST)) {
            return;
        }

        if (isset($_GET[ProtectedFileUrlService::QUERY_ARG])) {
            return;
        }

        $context = $this->build_context('frontend');

        if ($context->method === 'OPTIONS') {
            return;
        }

        $decision = $this->payment_flow->evaluate($context);
        $this->send_headers((array) ($decision['headers'] ?? []));

        if (($decision['allow'] ?? false) === true) {
            return;
        }

        status_header((int) ($decision['status'] ?? 402));

        if ($context->method === 'HEAD') {
            exit;
        }

        if ($context->accepts_json) {
            echo wp_json_encode(
                [
                    'message' => (string) ($decision['message'] ?? __('Payment required.', 'access402')),
                    'payment' => $decision['payload'] ?? [],
                ],
                JSON_UNESCAPED_SLASHES
            ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            exit;
        }

        if ((int) ($decision['status'] ?? 402) === 402 && is_array($decision['matched_rule'] ?? null) && is_array($decision['payment_profile'] ?? null)) {
            echo $this->checkout_renderer->render(
                [
                    'app_name'          => get_bloginfo('name'),
                    'target_path'       => $context->path,
                    'target_url'        => $context->url,
                    'unlock_endpoint'   => rest_url(UnlockController::ROUTE_NAMESPACE . UnlockController::ROUTE_UNLOCK),
                    'summary'           => (string) ($decision['summary'] ?? $decision['message'] ?? ''),
                    'price'             => (string) (($decision['effective']['price'] ?? '')),
                    'currency'          => (string) (($decision['effective']['currency'] ?? '')),
                    'network_label'     => (string) (($decision['payment_profile']['network_label'] ?? '')),
                    'network_id'        => (string) (($decision['payment_profile']['network'] ?? '')),
                    'testnet'           => (bool) (($decision['payment_profile']['testnet'] ?? false)),
                    'facilitator_label' => (string) (($decision['payment_profile']['facilitator_label'] ?? '')),
                    'rule_name'         => (string) (($decision['matched_rule']['name'] ?? '')),
                ]
            ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            exit;
        }

        echo $this->render_fallback_page(
            (string) ($decision['message'] ?? __('Payment required.', 'access402')),
            (array) ($decision['payload'] ?? [])
        ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }

    public function intercept_rest(mixed $dispatch_result, \WP_REST_Request $request, string $route, array $handler): mixed
    {
        if ($request->get_route() === '/' . UnlockController::ROUTE_NAMESPACE . UnlockController::ROUTE_UNLOCK) {
            return $dispatch_result;
        }

        $context = $this->build_rest_context($request);

        if ($context->method === 'OPTIONS') {
            return $dispatch_result;
        }

        $decision = $this->payment_flow->evaluate($context);

        if (($decision['allow'] ?? false) === true) {
            $this->pending_rest_headers = array_filter(
                (array) ($decision['headers'] ?? []),
                static fn (mixed $value): bool => is_string($value) && $value !== ''
            );

            return $dispatch_result;
        }

        if ($this->should_render_rest_checkout($request, $decision)) {
            $this->send_headers((array) ($decision['headers'] ?? []));
            status_header((int) ($decision['status'] ?? 402));
            header('Content-Type: text/html; charset=' . get_bloginfo('charset'), true);

            if ($context->method === 'HEAD') {
                exit;
            }

            if ((int) ($decision['status'] ?? 402) === 402 && is_array($decision['matched_rule'] ?? null) && is_array($decision['payment_profile'] ?? null)) {
                echo $this->checkout_renderer->render(
                    [
                        'app_name'          => get_bloginfo('name'),
                        'target_path'       => $context->path,
                        'target_url'        => $context->url,
                        'unlock_endpoint'   => rest_url(UnlockController::ROUTE_NAMESPACE . UnlockController::ROUTE_UNLOCK),
                        'summary'           => (string) ($decision['summary'] ?? $decision['message'] ?? ''),
                        'price'             => (string) (($decision['effective']['price'] ?? '')),
                        'currency'          => (string) (($decision['effective']['currency'] ?? '')),
                        'network_label'     => (string) (($decision['payment_profile']['network_label'] ?? '')),
                        'network_id'        => (string) (($decision['payment_profile']['network'] ?? '')),
                        'testnet'           => (bool) (($decision['payment_profile']['testnet'] ?? false)),
                        'facilitator_label' => (string) (($decision['payment_profile']['facilitator_label'] ?? '')),
                        'rule_name'         => (string) (($decision['matched_rule']['name'] ?? '')),
                    ]
                ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                exit;
            }

            echo $this->render_fallback_page(
                (string) ($decision['message'] ?? __('Payment required.', 'access402')),
                (array) ($decision['payload'] ?? [])
            ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            exit;
        }

        return new \WP_REST_Response(
            [
                'message' => (string) ($decision['message'] ?? __('Payment required.', 'access402')),
                'payment' => $decision['payload'] ?? [],
            ],
            (int) ($decision['status'] ?? 402),
            (array) ($decision['headers'] ?? [])
        );
    }

    public function apply_rest_headers(\WP_HTTP_Response $response, \WP_REST_Server $server, \WP_REST_Request $request): \WP_HTTP_Response
    {
        foreach ($this->pending_rest_headers as $name => $value) {
            $response->header($name, $value);
        }

        $this->pending_rest_headers = [];

        return $response;
    }

    private function should_render_rest_checkout(\WP_REST_Request $request, array $decision): bool
    {
        $accept = strtolower(trim((string) $request->get_header('accept')));

        if ($accept === '') {
            return false;
        }

        if (! str_contains($accept, 'text/html') && ! str_contains($accept, 'application/xhtml+xml')) {
            return false;
        }

        $status = (int) ($decision['status'] ?? 402);

        return in_array($status, [402, 500], true);
    }

    private function build_context(string $type): RequestContext
    {
        $user   = wp_get_current_user();
        $accept = isset($_SERVER['HTTP_ACCEPT']) ? wp_unslash((string) $_SERVER['HTTP_ACCEPT']) : '';
        $wallet = Helpers::request_wallet_address();
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper(sanitize_text_field(wp_unslash((string) $_SERVER['REQUEST_METHOD']))) : 'GET';

        return new RequestContext(
            Helpers::request_path(),
            Helpers::request_url(),
            $method,
            $type,
            Helpers::request_ip(),
            $wallet,
            Helpers::request_payment_signature(),
            $user instanceof \WP_User ? (array) $user->roles : [],
            str_contains($accept, 'application/json') || $type === 'rest'
        );
    }

    private function build_rest_context(\WP_REST_Request $request): RequestContext
    {
        $user   = wp_get_current_user();
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper(sanitize_text_field(wp_unslash((string) $_SERVER['REQUEST_METHOD']))) : 'GET';
        $route  = Helpers::normalize_path('/' . trim(rest_get_url_prefix(), '/') . '/' . ltrim($request->get_route(), '/'));

        return new RequestContext(
            $route,
            rest_url(ltrim($request->get_route(), '/')),
            $method,
            'rest',
            Helpers::request_ip(),
            Helpers::request_wallet_address(),
            Helpers::request_payment_signature(),
            $user instanceof \WP_User ? (array) $user->roles : [],
            true
        );
    }

    private function render_fallback_page(string $message, array $payload): string
    {
        ob_start();
        ?>
        <!doctype html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo('charset'); ?>" />
            <meta name="viewport" content="width=device-width, initial-scale=1" />
            <title><?php esc_html_e('Payment Required', 'access402'); ?></title>
            <style>
                :root { color-scheme: light; }
                body { margin: 0; background: #f5f7fb; color: #111827; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
                .access402-shell { max-width: 860px; margin: 48px auto; padding: 0 24px; }
                .access402-card { background: #ffffff; border: 1px solid #d8dee9; border-radius: 24px; padding: 32px; box-shadow: 0 24px 56px rgba(15, 23, 42, 0.08); }
                .access402-kicker { margin: 0 0 12px; color: #2563eb; font-size: 12px; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase; }
                h1 { margin: 0 0 12px; font-size: 36px; line-height: 1.1; }
                p { margin: 0 0 16px; color: #475569; font-size: 16px; line-height: 1.6; }
                pre { margin: 20px 0 0; padding: 20px; border-radius: 18px; background: #0f172a; color: #e2e8f0; overflow: auto; font-size: 13px; line-height: 1.5; }
            </style>
        </head>
        <body>
            <div class="access402-shell">
                <div class="access402-card">
                    <p class="access402-kicker"><?php esc_html_e('Access402 Protected Resource', 'access402'); ?></p>
                    <h1><?php esc_html_e('Payment required', 'access402'); ?></h1>
                    <p><?php echo esc_html($message); ?></p>
                    <pre><?php echo esc_html((string) wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></pre>
                </div>
            </div>
        </body>
        </html>
        <?php

        return (string) ob_get_clean();
    }

    private function send_headers(array $headers): void
    {
        foreach ($headers as $name => $value) {
            if (is_string($value) && $value !== '') {
                header($name . ': ' . $value, true);
            }
        }
    }
}

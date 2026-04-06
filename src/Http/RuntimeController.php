<?php

declare(strict_types=1);

namespace Access402\Http;

use Access402\Services\CheckoutPageRenderer;
use Access402\Services\ProtectedPaymentFlow;
use Access402\Support\Helpers;

final class RuntimeController
{
    public function __construct(
        private readonly ProtectedPaymentFlow $payment_flow,
        private readonly CheckoutPageRenderer $checkout_renderer
    ) {
    }

    public function boot(): void
    {
        add_action('template_redirect', [$this, 'intercept_frontend'], 0);
        add_filter('rest_pre_dispatch', [$this, 'intercept_rest'], 5, 3);
    }

    public function intercept_frontend(): void
    {
        if (is_admin() || wp_doing_ajax() || wp_doing_cron() || (defined('REST_REQUEST') && REST_REQUEST)) {
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

    public function intercept_rest(mixed $result, \WP_REST_Server $server, \WP_REST_Request $request): mixed
    {
        if ($request->get_route() === '/' . UnlockController::ROUTE_NAMESPACE . UnlockController::ROUTE_UNLOCK) {
            return $result;
        }

        $context = $this->build_context('rest');

        if ($context->method === 'OPTIONS') {
            return $result;
        }

        $decision = $this->payment_flow->evaluate($context);

        if (($decision['allow'] ?? false) === true) {
            $this->send_headers((array) ($decision['headers'] ?? []));

            return $result;
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

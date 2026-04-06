<?php

declare(strict_types=1);

namespace Access402\Http;

use Access402\Services\CheckoutPageRenderer;
use Access402\Services\ProtectedFileUrlService;
use Access402\Services\ProtectedPaymentFlow;
use Access402\Support\Helpers;

final class FileController
{
    public function __construct(
        private readonly ProtectedPaymentFlow $payment_flow,
        private readonly CheckoutPageRenderer $checkout_renderer,
        private readonly ProtectedFileUrlService $protected_files
    ) {
    }

    public function boot(): void
    {
        add_action('template_redirect', [$this, 'maybe_serve'], -100);
    }

    public function maybe_serve(): void
    {
        if (is_admin() || wp_doing_ajax() || wp_doing_cron() || (defined('REST_REQUEST') && REST_REQUEST)) {
            return;
        }

        $token = $this->protected_files->current_request_token();

        if ($token === '') {
            return;
        }

        $public_path = $this->protected_files->decode_path_token($token);

        if (is_wp_error($public_path)) {
            $this->send_json_error(404, $public_path->get_error_message());
        }

        $file = $this->protected_files->resolve_local_file($public_path);

        if (is_wp_error($file)) {
            $this->send_json_error(404, $file->get_error_message());
        }

        $context       = $this->build_context();
        $protected_url = $this->protected_files->protected_url_for_path((string) $public_path);
        $decision      = $this->payment_flow->evaluate(
            $context,
            (string) $public_path,
            [
                'grant_path' => (string) $public_path,
                'log_path'   => (string) $public_path,
                'charge_url' => $protected_url,
            ]
        );

        $this->send_headers((array) ($decision['headers'] ?? []));

        if (($decision['allow'] ?? false) === true) {
            $this->stream_file($file, $context->method);
        }

        $status = (int) ($decision['status'] ?? 402);

        status_header($status);

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

        if ($status === 402 && is_array($decision['matched_rule'] ?? null) && is_array($decision['payment_profile'] ?? null)) {
            echo $this->checkout_renderer->render(
                [
                    'app_name'          => get_bloginfo('name'),
                    'target_path'       => (string) $public_path,
                    'target_url'        => $protected_url,
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

    private function build_context(): RequestContext
    {
        $user   = wp_get_current_user();
        $accept = isset($_SERVER['HTTP_ACCEPT']) ? wp_unslash((string) $_SERVER['HTTP_ACCEPT']) : '';
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper(sanitize_text_field(wp_unslash((string) $_SERVER['REQUEST_METHOD']))) : 'GET';
        $accepts_json = str_contains($accept, 'application/json')
            || (! str_contains($accept, 'text/html') && ! str_contains($accept, 'application/xhtml+xml'));

        return new RequestContext(
            Helpers::request_path(),
            Helpers::request_url(),
            $method,
            'file',
            Helpers::request_ip(),
            Helpers::request_wallet_address(),
            Helpers::request_payment_signature(),
            $user instanceof \WP_User ? (array) $user->roles : [],
            $accepts_json
        );
    }

    private function stream_file(array $file, string $method): never
    {
        $filename = str_replace(['"', '\\'], '', (string) ($file['filename'] ?? 'download'));

        status_header(200);
        header('Content-Type: ' . (string) ($file['mime_type'] ?? 'application/octet-stream'), true);
        header('Content-Length: ' . (string) ((int) ($file['size'] ?? 0)), true);
        header('Content-Disposition: inline; filename="' . $filename . '"; filename*=UTF-8\'\'' . rawurlencode($filename), true);
        header('Last-Modified: ' . (string) ($file['last_modified'] ?? gmdate('D, d M Y H:i:s') . ' GMT'), true);
        header('X-Content-Type-Options: nosniff', true);

        if ($method === 'HEAD') {
            exit;
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        readfile((string) $file['absolute_path']); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
        exit;
    }

    private function send_headers(array $headers): void
    {
        foreach ($headers as $name => $value) {
            if (is_string($value) && $value !== '') {
                header($name . ': ' . $value, true);
            }
        }
    }

    private function send_json_error(int $status, string $message): never
    {
        status_header($status);
        header('Content-Type: application/json; charset=utf-8', true);
        echo wp_json_encode(['message' => $message], JSON_UNESCAPED_SLASHES); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
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
}

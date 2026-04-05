<?php

declare(strict_types=1);

namespace Access402\Http;

use Access402\Domain\LogDecisionOptions;
use Access402\Repositories\RuleRepository;
use Access402\Repositories\SettingsRepository;
use Access402\Services\AccessEvaluator;
use Access402\Services\EffectiveRuleConfigResolver;
use Access402\Services\PaymentChallengeBuilder;
use Access402\Services\RequestLogger;
use Access402\Services\RuleMatcher;
use Access402\Services\RuleSummaryBuilder;
use Access402\Support\Helpers;

final class RuntimeController
{
    private PaymentChallengeBuilder $challenge_builder;

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly RuleRepository $rules,
        private readonly RuleMatcher $matcher,
        private readonly EffectiveRuleConfigResolver $resolver,
        private readonly RuleSummaryBuilder $summary_builder,
        private readonly AccessEvaluator $access_evaluator,
        private readonly RequestLogger $logger
    ) {
        $this->challenge_builder = new PaymentChallengeBuilder();
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

        $decision = $this->evaluate($context);

        if ($decision['allow']) {
            return;
        }

        $this->send_headers($decision['headers']);
        status_header(($decision['headers']['X-Access402-Decision'] ?? '') === LogDecisionOptions::ERROR ? 500 : 402);

        if ($context->method === 'HEAD') {
            exit;
        }

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
                .access402-kicker { margin: 0 0 12px; color: #4f46e5; font-size: 12px; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase; }
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
                    <p><?php echo esc_html((string) $decision['message']); ?></p>
                    <p><?php esc_html_e('This v1 runtime returns a structured x402-style challenge for matched paths so clients can understand the payment requirement cleanly.', 'access402'); ?></p>
                    <pre><?php echo esc_html(wp_json_encode($decision['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></pre>
                </div>
            </div>
        </body>
        </html>
        <?php
        exit;
    }

    public function intercept_rest(mixed $result, \WP_REST_Server $server, \WP_REST_Request $request): mixed
    {
        $context = $this->build_context('rest');

        if ($context->method === 'OPTIONS') {
            return $result;
        }

        $decision = $this->evaluate($context);

        if ($decision['allow']) {
            return $result;
        }

        return new \WP_REST_Response(
            [
                'message' => $decision['message'],
                'payment' => $decision['payload'],
            ],
            ($decision['headers']['X-Access402-Decision'] ?? '') === LogDecisionOptions::ERROR ? 500 : 402,
            $decision['headers']
        );
    }

    private function evaluate(RequestContext $context): array
    {
        $settings = $this->settings->all();
        $mode     = $this->settings->active_mode($settings);
        $bypass   = $this->access_evaluator->evaluate(
            [
                'user_roles'      => $context->user_roles,
                'wallet_address'  => $context->wallet_address,
                'ip_address'      => $context->ip_address,
            ]
        );

        if ($bypass['bypassed']) {
            $this->logger->maybe_log(
                [
                    'path'           => $context->path,
                    'matched_rule_id' => null,
                    'decision'       => LogDecisionOptions::BYPASSED,
                    'wallet_address' => $context->wallet_address ?: null,
                    'mode'           => $mode,
                    'message'        => $bypass['message'],
                ]
            );

            return [
                'allow'   => true,
                'headers' => [
                    'X-Access402-Decision' => LogDecisionOptions::BYPASSED,
                ],
            ];
        }

        $rule = $this->matcher->match($context->path);

        if (! is_array($rule)) {
            return [
                'allow'   => true,
                'headers' => [],
            ];
        }

        $this->rules->touch_match((int) $rule['id']);

        $effective = $this->resolver->resolve($rule, $settings);
        $summary   = $this->summary_builder->build($rule, $settings);

        if ($effective['wallet'] === '' || $effective['price'] === '') {
            $message = __('The matched rule cannot be enforced because the active mode is missing a wallet or default price.', 'access402');

            $this->logger->maybe_log(
                [
                    'path'            => $context->path,
                    'matched_rule_id' => (int) $rule['id'],
                    'decision'        => LogDecisionOptions::ERROR,
                    'wallet_address'  => $context->wallet_address ?: null,
                    'mode'            => $effective['mode'],
                    'message'         => $message,
                ]
            );

            return [
                'allow'   => false,
                'headers' => [
                    'Cache-Control'        => 'no-store, private',
                    'X-Access402-Decision' => LogDecisionOptions::ERROR,
                ],
                'message' => $message,
                'payload' => [
                    'error' => 'runtime_configuration_incomplete',
                ],
            ];
        }

        $payload   = $this->challenge_builder->build($rule, $effective, $summary, $context->path);
        $headers   = [
            'Cache-Control'         => 'no-store, private',
            'X-Access402-Decision'  => LogDecisionOptions::PAYMENT_REQUIRED,
            'PAYMENT-REQUIRED'      => base64_encode((string) wp_json_encode($payload)),
        ];

        $this->logger->maybe_log(
            [
                'path'            => $context->path,
                'matched_rule_id' => (int) $rule['id'],
                'decision'        => LogDecisionOptions::PAYMENT_REQUIRED,
                'wallet_address'  => $context->wallet_address ?: null,
                'mode'            => $effective['mode'],
                'message'         => $summary,
            ]
        );

        return [
            'allow'   => false,
            'headers' => $headers,
            'message' => $summary,
            'payload' => $payload,
        ];
    }

    private function build_context(string $type): RequestContext
    {
        $user      = wp_get_current_user();
        $accept    = isset($_SERVER['HTTP_ACCEPT']) ? wp_unslash((string) $_SERVER['HTTP_ACCEPT']) : '';
        $wallet    = Helpers::request_wallet_address();
        $method    = isset($_SERVER['REQUEST_METHOD']) ? strtoupper(sanitize_text_field(wp_unslash((string) $_SERVER['REQUEST_METHOD']))) : 'GET';

        return new RequestContext(
            Helpers::request_path(),
            $method,
            $type,
            Helpers::request_ip(),
            $wallet,
            $user instanceof \WP_User ? (array) $user->roles : [],
            str_contains($accept, 'application/json') || $type === 'rest'
        );
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

<?php

declare(strict_types=1);

namespace Access402\Services;

use Access402\Domain\LogDecisionOptions;
use Access402\Http\RequestContext;
use Access402\Repositories\RuleRepository;
use Access402\Repositories\SettingsRepository;

final class ProtectedPaymentFlow
{
    private PaymentChallengeBuilder $challenge_builder;

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly RuleRepository $rules,
        private readonly RuleMatcher $matcher,
        private readonly EffectiveRuleConfigResolver $resolver,
        private readonly RuleSummaryBuilder $summary_builder,
        private readonly AccessEvaluator $access_evaluator,
        private readonly AccessGrantService $access_grants,
        private readonly RequestLogger $logger,
        private readonly DebugLogger $debug_logger,
        private readonly X402FacilitatorClient $facilitator,
        private readonly X402PaymentProfileResolver $payment_profiles,
        private readonly X402HeaderCodec $header_codec
    ) {
        $this->challenge_builder = new PaymentChallengeBuilder();
    }

    public function evaluate(RequestContext $context, ?string $protected_path = null, array $options = []): array
    {
        $settings                = $this->settings->all();
        $mode                    = $this->settings->active_mode($settings);
        $protected_path          = $this->normalize_path($protected_path ?: $context->path);
        $grant_path              = $this->normalize_path((string) ($options['grant_path'] ?? $protected_path));
        $charge_url              = (string) ($options['charge_url'] ?? $context->url);
        $log_path                = $this->normalize_path((string) ($options['log_path'] ?? $protected_path));
        $accepts_json            = array_key_exists('accepts_json', $options) ? (bool) $options['accepts_json'] : $context->accepts_json;
        $consume_existing_grant  = array_key_exists('consume_existing_grant', $options) ? (bool) $options['consume_existing_grant'] : true;
        $touch_match             = array_key_exists('touch_match', $options) ? (bool) $options['touch_match'] : true;
        $issue_grant_after_settle = (string) ($options['issue_grant_after_settle'] ?? 'if_needed');
        $bypass                  = $this->access_evaluator->evaluate(
            [
                'user_roles'     => $context->user_roles,
                'wallet_address' => $context->wallet_address,
                'ip_address'     => $context->ip_address,
            ]
        );

        if ($bypass['bypassed']) {
            $this->logger->maybe_log(
                [
                    'path'            => $log_path,
                    'matched_rule_id' => null,
                    'decision'        => LogDecisionOptions::BYPASSED,
                    'wallet_address'  => $context->wallet_address ?: null,
                    'mode'            => $mode,
                    'message'         => $bypass['message'],
                ]
            );

            return [
                'allow'        => true,
                'headers'      => [
                    'X-Access402-Decision' => LogDecisionOptions::BYPASSED,
                ],
                'mode'         => $mode,
                'log_path'     => $log_path,
                'matched_rule' => null,
            ];
        }

        $rule = $this->matcher->match($protected_path);

        if (! is_array($rule)) {
            return [
                'allow'        => true,
                'headers'      => [],
                'mode'         => $mode,
                'log_path'     => $log_path,
                'matched_rule' => null,
            ];
        }

        if ($touch_match) {
            $this->rules->touch_match((int) $rule['id']);
        }

        $grant = $this->access_grants->grant_for((int) $rule['id'], $grant_path);

        if (is_array($grant)) {
            if ($consume_existing_grant) {
                $this->access_grants->consume_if_needed($grant);
            }

            $message = __('A previously settled browser grant unlocked this request.', 'access402');

            $this->logger->maybe_log(
                [
                    'path'            => $log_path,
                    'matched_rule_id' => (int) $rule['id'],
                    'decision'        => LogDecisionOptions::ALLOWED,
                    'wallet_address'  => $context->wallet_address ?: (string) ($grant['payer'] ?? null),
                    'mode'            => $mode,
                    'message'         => $message,
                ]
            );

            return [
                'allow'        => true,
                'headers'      => [
                    'X-Access402-Decision' => LogDecisionOptions::ALLOWED,
                ],
                'mode'         => $mode,
                'log_path'     => $log_path,
                'matched_rule' => $rule,
                'grant'        => $grant,
                'message'      => $message,
            ];
        }

        $effective = $this->resolver->resolve($rule, $settings);
        $summary   = $this->summary_builder->build($rule, $settings);

        if ($effective['wallet'] === '' || $effective['price'] === '') {
            $this->debug_logger->log(
                'runtime_configuration_incomplete',
                [
                    'path'    => $log_path,
                    'rule_id' => (int) $rule['id'],
                    'mode'    => (string) $effective['mode'],
                    'wallet'  => (string) $effective['wallet'],
                    'price'   => (string) $effective['price'],
                ]
            );

            return $this->runtime_error_response(
                $log_path,
                (int) $rule['id'],
                (string) $effective['mode'],
                __('The matched rule cannot be enforced because the active mode is missing required payment configuration.', 'access402'),
                [
                    'error' => 'runtime_configuration_incomplete',
                ],
                $context->wallet_address
            );
        }

        $payment_profile = $this->payment_profiles->resolve($effective, $settings);

        if (! ($payment_profile['supported'] ?? false)) {
            $this->debug_logger->log(
                'unsupported_payment_profile',
                [
                    'path'    => $log_path,
                    'rule_id' => (int) $rule['id'],
                    'mode'    => (string) $effective['mode'],
                    'message' => (string) ($payment_profile['message'] ?? ''),
                ]
            );

            return $this->runtime_error_response(
                $log_path,
                (int) $rule['id'],
                (string) $effective['mode'],
                (string) ($payment_profile['message'] ?? __('The matched rule could not be expressed as a supported x402 payment request.', 'access402')),
                [
                    'error' => 'unsupported_payment_profile',
                ],
                $context->wallet_address
            );
        }

        $payment_required = $this->challenge_builder->build(
            $rule,
            $effective,
            $payment_profile,
            $summary,
            $charge_url,
            $accepts_json
        );
        $requirement      = (array) ($payment_required['accepts'][0] ?? []);

        if ($context->payment_signature !== '') {
            $payment_payload = $this->header_codec->decode($context->payment_signature);

            if (is_wp_error($payment_payload)) {
                $this->debug_logger->log(
                    'payment_signature_decode_failed',
                    [
                        'path'             => $log_path,
                        'rule_id'          => (int) $rule['id'],
                        'mode'             => (string) $effective['mode'],
                        'signature_length' => strlen($context->payment_signature),
                        'error'            => $payment_payload,
                    ]
                );

                return $this->payment_failure_response(
                    $log_path,
                    (int) $rule['id'],
                    (string) $effective['mode'],
                    $payment_required,
                    $payment_payload->get_error_message(),
                    null,
                    $context->wallet_address
                );
            }

            $verify = $this->facilitator->verify($payment_payload, $requirement, (string) $effective['mode'], $settings);

            if (is_wp_error($verify)) {
                $this->debug_logger->log(
                    'verify_request_failed',
                    [
                        'path'    => $log_path,
                        'rule_id' => (int) $rule['id'],
                        'mode'    => (string) $effective['mode'],
                        'error'   => $verify,
                    ]
                );

                return $this->runtime_error_response(
                    $log_path,
                    (int) $rule['id'],
                    (string) $effective['mode'],
                    $verify->get_error_message(),
                    [
                        'error' => 'verify_request_failed',
                    ],
                    $context->wallet_address,
                    $payment_required
                );
            }

            $verify_body   = (array) ($verify['body'] ?? []);
            $verify_status = (int) ($verify['status_code'] ?? 500);
            $payer         = (string) ($verify_body['payer'] ?? $context->wallet_address);

            if ($verify_status >= 500 || ($verify_status >= 400 && ! array_key_exists('isValid', $verify_body))) {
                $this->debug_logger->log(
                    'verify_http_failure',
                    [
                        'path'        => $log_path,
                        'rule_id'     => (int) $rule['id'],
                        'mode'        => (string) $effective['mode'],
                        'status_code' => $verify_status,
                        'response'    => $verify_body,
                    ]
                );

                return $this->runtime_error_response(
                    $log_path,
                    (int) $rule['id'],
                    (string) $effective['mode'],
                    $this->payment_message($verify_body, __('The x402 verification step failed.', 'access402')),
                    [
                        'error' => 'verify_failed',
                    ],
                    $payer,
                    $payment_required
                );
            }

            if (($verify_body['isValid'] ?? false) !== true) {
                $this->debug_logger->log(
                    'verify_invalid_payment',
                    [
                        'path'        => $log_path,
                        'rule_id'     => (int) $rule['id'],
                        'mode'        => (string) $effective['mode'],
                        'status_code' => $verify_status,
                        'response'    => $verify_body,
                    ]
                );

                return $this->payment_failure_response(
                    $log_path,
                    (int) $rule['id'],
                    (string) $effective['mode'],
                    $payment_required,
                    $this->payment_message($verify_body, __('The submitted payment could not be verified.', 'access402')),
                    null,
                    $payer
                );
            }

            $settle = $this->facilitator->settle($payment_payload, $requirement, (string) $effective['mode'], $settings);

            if (is_wp_error($settle)) {
                $this->debug_logger->log(
                    'settle_request_failed',
                    [
                        'path'    => $log_path,
                        'rule_id' => (int) $rule['id'],
                        'mode'    => (string) $effective['mode'],
                        'error'   => $settle,
                    ]
                );

                return $this->runtime_error_response(
                    $log_path,
                    (int) $rule['id'],
                    (string) $effective['mode'],
                    $settle->get_error_message(),
                    [
                        'error' => 'settle_request_failed',
                    ],
                    $payer,
                    $payment_required
                );
            }

            $settle_body   = (array) ($settle['body'] ?? []);
            $settle_status = (int) ($settle['status_code'] ?? 500);

            if ($settle_status >= 500 || ($settle_status >= 400 && ! array_key_exists('success', $settle_body))) {
                $this->debug_logger->log(
                    'settle_http_failure',
                    [
                        'path'        => $log_path,
                        'rule_id'     => (int) $rule['id'],
                        'mode'        => (string) $effective['mode'],
                        'status_code' => $settle_status,
                        'response'    => $settle_body,
                    ]
                );

                return $this->runtime_error_response(
                    $log_path,
                    (int) $rule['id'],
                    (string) $effective['mode'],
                    $this->payment_message($settle_body, __('The x402 settlement step failed.', 'access402')),
                    [
                        'error' => 'settle_failed',
                    ],
                    (string) ($settle_body['payer'] ?? $payer),
                    $payment_required
                );
            }

            if (($settle_body['success'] ?? false) !== true) {
                $this->debug_logger->log(
                    'settle_unsuccessful',
                    [
                        'path'        => $log_path,
                        'rule_id'     => (int) $rule['id'],
                        'mode'        => (string) $effective['mode'],
                        'status_code' => $settle_status,
                        'response'    => $settle_body,
                    ]
                );

                return $this->payment_failure_response(
                    $log_path,
                    (int) $rule['id'],
                    (string) $effective['mode'],
                    $payment_required,
                    $this->payment_message($settle_body, __('The payment was signed but could not be settled onchain.', 'access402')),
                    $settle_body,
                    (string) ($settle_body['payer'] ?? $payer)
                );
            }

            $should_issue_grant = match ($issue_grant_after_settle) {
                'always' => true,
                'never' => false,
                default => (string) $effective['unlock_behavior'] !== 'per_request',
            };

            if ($should_issue_grant) {
                $this->access_grants->issue_grant(
                    (int) $rule['id'],
                    $grant_path,
                    (string) $effective['unlock_behavior'],
                    [
                        'payer'       => (string) ($settle_body['payer'] ?? $payer),
                        'transaction' => (string) ($settle_body['transaction'] ?? ''),
                        'mode'        => (string) $effective['mode'],
                    ]
                );
            }

            $payment_response_header = $this->header_codec->encode($settle_body);
            $wallet_address          = (string) ($settle_body['payer'] ?? $payer);
            $success_message         = $this->success_log_message($settle_body, $payment_profile);

            $this->logger->maybe_log(
                [
                    'path'            => $log_path,
                    'matched_rule_id' => (int) $rule['id'],
                    'decision'        => LogDecisionOptions::ALLOWED,
                    'wallet_address'  => $wallet_address !== '' ? $wallet_address : null,
                    'mode'            => (string) $effective['mode'],
                    'message'         => $success_message,
                ]
            );

            return [
                'allow'           => true,
                'headers'         => [
                    'Cache-Control'        => 'no-store, private',
                    'PAYMENT-RESPONSE'     => $payment_response_header,
                    'X-Access402-Decision' => LogDecisionOptions::ALLOWED,
                ],
                'mode'            => $mode,
                'log_path'        => $log_path,
                'matched_rule'    => $rule,
                'effective'       => $effective,
                'summary'         => $summary,
                'payment_profile' => $payment_profile,
                'payment_required'=> $payment_required,
                'settlement'      => $settle_body,
                'wallet_address'  => $wallet_address,
                'grant_issued'    => $should_issue_grant,
                'message'         => $success_message,
            ];
        }

        $payment_required_header = $this->header_codec->encode($payment_required);

        $this->logger->maybe_log(
            [
                'path'            => $log_path,
                'matched_rule_id' => (int) $rule['id'],
                'decision'        => LogDecisionOptions::PAYMENT_REQUIRED,
                'wallet_address'  => $context->wallet_address ?: null,
                'mode'            => (string) $effective['mode'],
                'message'         => $summary,
            ]
        );

        return [
            'allow'           => false,
            'status'          => 402,
            'headers'         => [
                'Access-Control-Expose-Headers' => 'PAYMENT-REQUIRED, PAYMENT-RESPONSE',
                'Cache-Control'                 => 'no-store, private',
                'PAYMENT-REQUIRED'              => $payment_required_header,
                'X-Access402-Decision'          => LogDecisionOptions::PAYMENT_REQUIRED,
            ],
            'message'         => $summary,
            'payload'         => $payment_required,
            'mode'            => $mode,
            'log_path'        => $log_path,
            'matched_rule'    => $rule,
            'effective'       => $effective,
            'summary'         => $summary,
            'payment_profile' => $payment_profile,
            'payment_required'=> $payment_required,
        ];
    }

    private function runtime_error_response(
        string $log_path,
        int $rule_id,
        string $mode,
        string $message,
        array $payload,
        string $wallet_address = '',
        ?array $payment_required = null
    ): array {
        $headers = [
            'Cache-Control'        => 'no-store, private',
            'X-Access402-Decision' => LogDecisionOptions::ERROR,
        ];

        if (is_array($payment_required)) {
            $headers['PAYMENT-REQUIRED'] = $this->header_codec->encode($payment_required);
            $headers['Access-Control-Expose-Headers'] = 'PAYMENT-REQUIRED, PAYMENT-RESPONSE';
        }

        $this->logger->maybe_log(
            [
                'path'            => $log_path,
                'matched_rule_id' => $rule_id,
                'decision'        => LogDecisionOptions::ERROR,
                'wallet_address'  => $wallet_address !== '' ? $wallet_address : null,
                'mode'            => $mode,
                'message'         => $message,
            ]
        );

        $this->debug_logger->log(
            'runtime_error_response',
            [
                'path'            => $log_path,
                'matched_rule_id' => $rule_id,
                'mode'            => $mode,
                'message'         => $message,
                'payload'         => $payload,
            ]
        );

        return [
            'allow'    => false,
            'status'   => 500,
            'headers'  => $headers,
            'message'  => $message,
            'payload'  => $payload,
            'log_path' => $log_path,
        ];
    }

    private function payment_failure_response(
        string $log_path,
        int $rule_id,
        string $mode,
        array $payment_required,
        string $message,
        ?array $payment_response = null,
        string $wallet_address = ''
    ): array {
        $payload = $payment_required;
        $headers = [
            'Access-Control-Expose-Headers' => 'PAYMENT-REQUIRED, PAYMENT-RESPONSE',
            'Cache-Control'                 => 'no-store, private',
            'PAYMENT-REQUIRED'              => $this->header_codec->encode($payment_required),
            'X-Access402-Decision'          => LogDecisionOptions::PAYMENT_REQUIRED,
        ];

        $payload['error'] = $message;

        if (is_array($payment_response)) {
            $headers['PAYMENT-RESPONSE'] = $this->header_codec->encode($payment_response);
        }

        $this->logger->maybe_log(
            [
                'path'            => $log_path,
                'matched_rule_id' => $rule_id,
                'decision'        => LogDecisionOptions::PAYMENT_REQUIRED,
                'wallet_address'  => $wallet_address !== '' ? $wallet_address : null,
                'mode'            => $mode,
                'message'         => $message,
            ]
        );

        $this->debug_logger->log(
            'payment_failure_response',
            [
                'path'             => $log_path,
                'matched_rule_id'  => $rule_id,
                'mode'             => $mode,
                'message'          => $message,
                'payment_response' => $payment_response ?? [],
            ]
        );

        return [
            'allow'    => false,
            'status'   => 402,
            'headers'  => $headers,
            'message'  => $message,
            'payload'  => $payload,
            'log_path' => $log_path,
        ];
    }

    private function success_log_message(array $settle_response, array $payment_profile): string
    {
        $transaction = trim((string) ($settle_response['transaction'] ?? ''));
        $network     = trim((string) ($payment_profile['network_label'] ?? $settle_response['network'] ?? ''));

        if ($transaction === '') {
            return sprintf(__('Payment settled successfully on %s.', 'access402'), $network !== '' ? $network : __('the configured network', 'access402'));
        }

        return sprintf(__('Payment settled successfully on %1$s in transaction %2$s.', 'access402'), $network !== '' ? $network : __('the configured network', 'access402'), $transaction);
    }

    private function payment_message(array $response_body, string $fallback): string
    {
        $candidates = [
            $response_body['invalidMessage'] ?? '',
            $response_body['errorMessage'] ?? '',
            $response_body['message'] ?? '',
            $response_body['invalidReason'] ?? '',
            $response_body['errorReason'] ?? '',
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return $fallback;
    }

    private function normalize_path(string $path): string
    {
        $path = trim($path);

        if ($path === '') {
            return '/';
        }

        $parsed = wp_parse_url($path, PHP_URL_PATH);
        $path   = is_string($parsed) && $parsed !== '' ? $parsed : $path;
        $path   = '/' . ltrim($path, '/');
        $path   = preg_replace('#/+#', '/', $path) ?: '/';

        if ($path !== '/') {
            $path = untrailingslashit($path);
        }

        return $path;
    }
}

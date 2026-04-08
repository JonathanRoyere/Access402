<?php

declare(strict_types=1);

namespace Access402\Services;

use Access402\Repositories\SettingsRepository;
use Access402\Support\Helpers;

final class CheckoutPageRenderer
{
    public function __construct(private readonly SettingsRepository $settings)
    {
    }

    public function render(array $context): string
    {
        $settings = $this->settings->all();
        $target_path = Helpers::normalize_path((string) ($context['target_path'] ?? '/'));
        $target_url = (string) ($context['target_url'] ?? home_url($target_path));
        $unlock_endpoint = (string) ($context['unlock_endpoint'] ?? rest_url('access402/v1/unlock'));
        $summary = (string) ($context['summary'] ?? '');
        $price = Helpers::format_decimal((string) ($context['price'] ?? ''));
        $currency = (string) ($context['currency'] ?? '');
        $network_label = (string) ($context['network_label'] ?? '');
        $network_id = (string) ($context['network_id'] ?? '');
        $testnet = (bool) ($context['testnet'] ?? true);
        $facilitator = (string) ($context['facilitator_label'] ?? '');
        $rule_name = trim((string) ($context['rule_name'] ?? ''));
        $app_name = (string) ($context['app_name'] ?? get_bloginfo('name'));
        $site_url = home_url('/');
        $site_icon = get_site_icon_url(192) ?: '';
        $walletconnect_project_id = trim((string) ($settings['walletconnect_project_id'] ?? ''));
        $logo_url = ACCESS402_PLUGIN_URL . 'assets/images/logo.png';
        $module_url = esc_url(ACCESS402_PLUGIN_URL . 'assets/js/frontend-checkout.js?ver=' . rawurlencode(ACCESS402_VERSION));
        $title = $rule_name !== '' ? $rule_name : sprintf(__('Unlock %s', 'access402'), $target_path);
        $amount_label = trim($price . ($currency !== '' ? ' ' . $currency : ''));
        $payment_badge = trim($amount_label . ($network_label !== '' ? ' on ' . $network_label : ''));
        $bootstrap = [
            'unlockEndpoint' => $unlock_endpoint,
            'target' => [
                'path' => $target_path,
                'url' => $target_url,
            ],
            'rule' => [
                'name' => $rule_name,
            ],
            'payment' => [
                'price' => $price,
                'currency' => $currency,
                'networkLabel' => $network_label,
                'networkId' => $network_id,
                'testnet' => $testnet,
                'facilitatorLabel' => $facilitator,
            ],
            'summary' => $summary,
            'siteName' => $app_name,
            'siteUrl' => $site_url,
            'siteIcon' => $site_icon,
            'walletConnectProjectId' => $walletconnect_project_id,
            'strings' => [
                'missingProvider' => __('No compatible browser wallet was detected. Install or enable MetaMask or Coinbase Wallet and reload this page.', 'access402'),
                'walletRequired' => __('Connect a wallet before paying for access.', 'access402'),
                'switchingChain' => __('Switching your wallet to the required network…', 'access402'),
                'connectingWallet' => __('Connecting wallet…', 'access402'),
                'creatingPayment' => __('Creating and submitting the x402 payment…', 'access402'),
                'unlocking' => __('Payment settled. Unlocking the page…', 'access402'),
                'paid' => __('Access unlocked. Reloading…', 'access402'),
                'wrongNetwork' => __('This payment requires the configured network in your wallet.', 'access402'),
                'walletDisconnected' => __('Wallet disconnected.', 'access402'),
                'walletConnectMissingProjectId' => __('WalletConnect is not configured for this site yet.', 'access402'),
                'genericError' => __('The payment could not be completed.', 'access402'),
            ],
        ];
        $json = wp_json_encode($bootstrap, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        ob_start();
        ?>
        <!doctype html>
        <html <?php language_attributes(); ?>>

        <head>
            <meta charset="<?php bloginfo('charset'); ?>" />
            <meta name="viewport" content="width=device-width, initial-scale=1" />
            <title><?php echo esc_html($title); ?></title>
            <style>
                :root {
                    color-scheme: light;
                }

                * {
                    box-sizing: border-box;
                }

                body {
                    margin: 0;
                    min-height: 100vh;
                    background:
                        radial-gradient(circle at top left, rgba(37, 99, 235, 0.10), transparent 32%),
                        linear-gradient(180deg, #f8fafc 0%, #eef2ff 100%);
                    color: #0f172a;
                    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
                }

                .access402-shell {
                    max-width: 980px;
                    margin: 0 auto;
                    min-height: 100vh;
                    padding: 40px 20px;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                }

                .access402-card {
                    width: 100%;
                    background: rgba(255, 255, 255, 0.96);
                    border: 1px solid rgba(148, 163, 184, 0.22);
                    border-radius: 28px;
                    padding: 36px;
                    box-shadow: 0 32px 80px rgba(15, 23, 42, 0.12);
                    backdrop-filter: blur(14px);
                }

                .access402-grid {
                    display: grid;
                    gap: 24px;
                    grid-template-columns: minmax(0, 1.4fr) minmax(280px, 0.9fr);
                }

                .access402-kicker {
                    margin: 0 0 12px;
                    color: #2563eb;
                    font-size: 12px;
                    font-weight: 700;
                    letter-spacing: 0.08em;
                    text-transform: uppercase;
                }

                .access402-brand {
                    display: inline-flex;
                    align-items: center;
                    gap: 14px;
                    margin-bottom: 16px;
                }

                .access402-brand-logo {
                    display: block;
                    max-width: 80px;
                    height: auto;
                }

                h1 {
                    margin: 0 0 14px;
                    font-size: clamp(2rem, 3vw, 3rem);
                    line-height: 1.02;
                    letter-spacing: -0.03em;
                }

                .access402-copy {
                    margin: 0;
                    color: #475569;
                    font-size: 16px;
                    line-height: 1.7;
                }

                .access402-summary {
                    margin-top: 20px;
                    padding: 18px 20px;
                    border-radius: 20px;
                    background: #f8fafc;
                    border: 1px solid #e2e8f0;
                    color: #334155;
                    font-size: 15px;
                    line-height: 1.6;
                }

                .access402-side {
                    display: grid;
                    gap: 14px;
                    align-content: start;
                }

                .access402-panel {
                    border-radius: 22px;
                    border: 1px solid #dbe4f0;
                    background: #ffffff;
                    padding: 20px;
                }

                .access402-panel-label {
                    margin: 0 0 8px;
                    color: #64748b;
                    font-size: 12px;
                    font-weight: 700;
                    letter-spacing: 0.08em;
                    text-transform: uppercase;
                }

                .access402-panel-value {
                    margin: 0;
                    color: #0f172a;
                    font-size: 18px;
                    font-weight: 600;
                    line-height: 1.4;
                }

                .access402-wallets {
                    display: grid;
                    gap: 10px;
                    margin-top: 18px;
                }

                .access402-wallet-button,
                .access402-pay-button,
                .access402-secondary-button {
                    width: 100%;
                    border: 1px solid #cbd5e1;
                    border-radius: 16px;
                    padding: 14px 16px;
                    background: #ffffff;
                    color: #0f172a;
                    font: inherit;
                    font-weight: 600;
                    cursor: pointer;
                    transition: transform 120ms ease, border-color 120ms ease, box-shadow 120ms ease, background 120ms ease;
                }

                .access402-wallet-button:hover,
                .access402-pay-button:hover,
                .access402-secondary-button:hover {
                    border-color: #93c5fd;
                    box-shadow: 0 12px 24px rgba(37, 99, 235, 0.12);
                    transform: translateY(-1px);
                }

                .access402-pay-button {
                    margin-top: 16px;
                    border-color: #2563eb;
                    background: #2563eb;
                    color: #ffffff;
                }

                .access402-pay-button[disabled],
                .access402-wallet-button[disabled],
                .access402-secondary-button[disabled] {
                    cursor: default;
                    opacity: 0.68;
                    transform: none;
                    box-shadow: none;
                }

                .access402-wallet-meta {
                    display: flex;
                    align-items: center;
                    justify-content: space-between;
                    gap: 12px;
                }

                .access402-wallet-name {
                    font-size: 15px;
                }

                .access402-wallet-status {
                    color: #64748b;
                    font-size: 13px;
                    font-weight: 500;
                }

                .access402-connected {
                    margin-top: 16px;
                    padding: 14px 16px;
                    border-radius: 18px;
                    background: #eff6ff;
                    border: 1px solid #bfdbfe;
                    color: #1d4ed8;
                    font-size: 14px;
                    line-height: 1.5;
                }

                .access402-status {
                    margin-top: 16px;
                    min-height: 24px;
                    color: #475569;
                    font-size: 14px;
                    line-height: 1.6;
                }

                .access402-status[data-tone="error"] {
                    color: #b91c1c;
                }

                .access402-status[data-tone="success"] {
                    color: #047857;
                }

                .access402-helper {
                    margin-top: 14px;
                    color: #64748b;
                    font-size: 13px;
                    line-height: 1.6;
                }

                .access402-link {
                    color: #2563eb;
                    text-decoration: none;
                }

                .access402-link:hover {
                    text-decoration: underline;
                }

                @media (max-width: 860px) {
                    .access402-card {
                        padding: 24px;
                    }

                    .access402-grid {
                        grid-template-columns: 1fr;
                    }

                    .access402-brand-logo {
                        max-width: 64px;
                    }
                }
            </style>
        </head>

        <body>
            <div class="access402-shell">
                <div class="access402-card">
                    <div class="access402-grid">
                        <section>
                            <div class="access402-brand">
                                <img class="access402-brand-logo" src="<?php echo esc_url($logo_url); ?>"
                                    alt="<?php esc_attr_e('Access402 logo', 'access402'); ?>" />
                                <p class="access402-kicker"><?php esc_html_e('Access402 Protected Resource', 'access402'); ?>
                                </p>
                            </div>
                            <h1><?php esc_html_e('Pay with your wallet to continue', 'access402'); ?></h1>
                            <p class="access402-copy">
                                <?php esc_html_e('This resource is protected with x402. Connect a compatible browser wallet, approve the payment, and Access402 will unlock the request as soon as the settlement succeeds.', 'access402'); ?>
                            </p>
                            <div class="access402-summary"><?php echo esc_html($summary); ?></div>
                        </section>
                        <aside class="access402-side">
                            <div class="access402-panel">
                                <p class="access402-panel-label"><?php esc_html_e('Payment', 'access402'); ?></p>
                                <p class="access402-panel-value">
                                    <?php echo esc_html($payment_badge !== '' ? $payment_badge : __('Configured in plugin settings', 'access402')); ?>
                                </p>
                            </div>
                            <div class="access402-panel">
                                <p class="access402-panel-label"><?php esc_html_e('Destination', 'access402'); ?></p>
                                <p class="access402-panel-value"><?php echo esc_html($target_path); ?></p>
                            </div>
                            <div class="access402-panel">
                                <p class="access402-panel-label"><?php esc_html_e('Wallets', 'access402'); ?></p>
                                <div id="access402-wallets" class="access402-wallets"></div>
                                <div id="access402-connected" class="access402-connected" hidden></div>
                                <button id="access402-pay-button" class="access402-pay-button" type="button" disabled>
                                    <?php esc_html_e('Connect a wallet to pay', 'access402'); ?>
                                </button>
                                <button id="access402-reload-button" class="access402-secondary-button" type="button"
                                    style="margin-top:12px;">
                                    <?php esc_html_e('Reload page', 'access402'); ?>
                                </button>
                                <div id="access402-status" class="access402-status" aria-live="polite"></div>
                                <p class="access402-helper">
                                    <?php
                                    printf(
                                        esc_html__('Need a supported wallet extension? MetaMask and Coinbase Wallet both work with injected EVM providers.', 'access402'),
                                        esc_html($facilitator !== '' ? $facilitator : __('the active facilitator', 'access402'))
                                    );
                                    ?>
                                </p>
                            </div>
                        </aside>
                    </div>
                </div>
            </div>
            <script>
                window.access402Checkout = <?php echo $json; ?>;
            </script>
            <script type="module" src="<?php echo $module_url; ?>"></script>
        </body>

        </html>
        <?php

        return (string) ob_get_clean();
    }
}

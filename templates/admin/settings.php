<?php

declare(strict_types=1);
?>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="access402-stack">
    <input type="hidden" name="action" value="access402_save_settings" />
    <?php wp_nonce_field('access402_save_settings'); ?>

    <div class="access402-card">
        <div class="access402-card-header">
            <div>
                <h2><?php esc_html_e('Payment Mode', 'access402'); ?></h2>
                <p><?php esc_html_e('Switch which wallet and credentials Access402 uses at runtime.', 'access402'); ?></p>
            </div>
        </div>
        <label class="access402-toggle">
            <input type="checkbox" name="test_mode" value="1" <?php checked(! empty($settings['test_mode'])); ?> />
            <span></span>
            <strong><?php esc_html_e('Test mode', 'access402'); ?></strong>
        </label>
    </div>

    <div class="access402-card">
        <div class="access402-card-header">
            <div>
                <h2><?php esc_html_e('Provider', 'access402'); ?></h2>
                <p><?php esc_html_e('Live mode uses Coinbase CDP. Test mode automatically uses the public x402.org facilitator unless both test CDP credentials are present.', 'access402'); ?></p>
            </div>
            <span class="access402-static-pill"><?php esc_html_e('Auto by mode', 'access402'); ?></span>
        </div>

        <div class="access402-split-grid">
            <?php foreach (['test' => __('Test', 'access402'), 'live' => __('Live', 'access402')] as $mode => $label) : ?>
                <section class="access402-subcard access402-mode-panel <?php echo $mode === (\Access402\Support\Helpers::active_mode($settings)) ? 'is-active' : ''; ?>" data-mode-panel="<?php echo esc_attr($mode); ?>">
                    <div class="access402-subcard-head">
                        <h3><?php echo esc_html($label); ?></h3>
                        <span class="access402-badge access402-badge-<?php echo esc_attr($settings[$mode . '_connection_status']); ?>" data-connection-badge="<?php echo esc_attr($mode); ?>">
                            <?php echo esc_html($connection_statuses[$settings[$mode . '_connection_status']] ?? $connection_statuses['not_tested']); ?>
                        </span>
                    </div>
                    <p class="access402-inline-note">
                        <?php
                        echo esc_html(
                            $mode === 'test'
                                ? __('Leave test credentials empty to use the signup-free x402.org facilitator on Base Sepolia. Add both fields only if you want to test the authenticated CDP facilitator instead.', 'access402')
                                : __('Live mode requires a CDP Secret API Key and Secret before Access402 can verify and settle payments.', 'access402')
                        );
                        ?>
                    </p>
                    <label class="access402-field">
                        <span><?php echo esc_html($label . ' ' . __('API key', 'access402')); ?></span>
                        <input type="password" name="<?php echo esc_attr($mode); ?>_api_key" value="<?php echo esc_attr((string) $settings[$mode . '_api_key']); ?>" autocomplete="off" />
                    </label>
                    <label class="access402-field">
                        <span><?php echo esc_html($label . ' ' . __('API secret', 'access402')); ?></span>
                        <input type="password" name="<?php echo esc_attr($mode); ?>_api_secret" value="<?php echo esc_attr((string) $settings[$mode . '_api_secret']); ?>" autocomplete="off" />
                    </label>
                    <button type="button" class="button" data-test-connection="<?php echo esc_attr($mode); ?>">
                        <?php esc_html_e('Test connection', 'access402'); ?>
                    </button>
                    <p class="access402-inline-note" data-connection-message="<?php echo esc_attr($mode); ?>"></p>
                    <input type="hidden" name="<?php echo esc_attr($mode); ?>_connection_status" value="<?php echo esc_attr((string) $settings[$mode . '_connection_status']); ?>" data-connection-input="<?php echo esc_attr($mode); ?>" />
                </section>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="access402-card">
        <div class="access402-card-header">
            <div>
                <h2><?php esc_html_e('Payment Configuration', 'access402'); ?></h2>
                <p><?php esc_html_e('Set the global defaults that every rule inherits unless a rule explicitly overrides them.', 'access402'); ?></p>
            </div>
        </div>
        <div class="access402-form-grid">
            <label class="access402-field">
                <span><?php esc_html_e('Default currency', 'access402'); ?></span>
                <select name="default_currency" data-default-currency>
                    <?php foreach ($currency_options as $value => $label) : ?>
                        <option value="<?php echo esc_attr($value); ?>" <?php selected($settings['default_currency'], $value); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="access402-field">
                <span><?php esc_html_e('Default network', 'access402'); ?></span>
                <input type="text" value="<?php echo esc_attr($network); ?>" readonly data-default-network-display />
                <input type="hidden" name="default_network" value="<?php echo esc_attr($network); ?>" data-default-network-input />
            </label>
            <label class="access402-field">
                <span><?php esc_html_e('Default price', 'access402'); ?></span>
                <input type="number" step="0.00000001" min="0" name="default_price" value="<?php echo esc_attr((string) $settings['default_price']); ?>" placeholder="0.50" />
            </label>
            <label class="access402-field">
                <span><?php esc_html_e('Default unlock behavior', 'access402'); ?></span>
                <select name="default_unlock_behavior">
                    <?php foreach ($unlock_options as $value => $label) : ?>
                        <option value="<?php echo esc_attr($value); ?>" <?php selected($settings['default_unlock_behavior'], $value); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
    </div>

    <div class="access402-card">
        <div class="access402-card-header">
            <div>
                <h2><?php esc_html_e('Wallets', 'access402'); ?></h2>
                <p><?php esc_html_e('Wallet validation follows the network resolved from the selected currency.', 'access402'); ?></p>
            </div>
        </div>
        <div class="access402-split-grid">
            <?php foreach (['test' => __('Test wallet', 'access402'), 'live' => __('Live wallet', 'access402')] as $mode => $label) : ?>
                <label class="access402-field">
                    <span><?php echo esc_html($label); ?></span>
                    <input type="text" name="<?php echo esc_attr($mode); ?>_wallet" value="<?php echo esc_attr((string) $settings[$mode . '_wallet']); ?>" data-wallet-field="<?php echo esc_attr($mode); ?>" />
                    <small class="access402-inline-note" data-wallet-note="<?php echo esc_attr($mode); ?>"></small>
                </label>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="access402-card">
        <div class="access402-card-header">
            <div>
                <h2><?php esc_html_e('Plugin Behavior', 'access402'); ?></h2>
                <p><?php esc_html_e('Keep operational logging lightweight but available when you need to understand request flow.', 'access402'); ?></p>
            </div>
        </div>
        <label class="access402-toggle">
            <input type="checkbox" name="enable_logging" value="1" <?php checked(! empty($settings['enable_logging'])); ?> />
            <span></span>
            <strong><?php esc_html_e('Enable logging', 'access402'); ?></strong>
        </label>
    </div>

    <div class="access402-actions">
        <button type="submit" class="button button-primary button-hero"><?php esc_html_e('Save settings', 'access402'); ?></button>
    </div>
</form>

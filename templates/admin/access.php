<?php

declare(strict_types=1);
?>
<div class="access402-stack">
    <div class="access402-card">
        <div class="access402-card-header">
            <div>
                <h2><?php esc_html_e('Role Bypass', 'access402'); ?></h2>
                <p><?php esc_html_e('Selected WordPress roles bypass payment globally before any rule is evaluated.', 'access402'); ?></p>
            </div>
        </div>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="access402-role-grid">
            <input type="hidden" name="action" value="access402_save_access_settings" />
            <?php wp_nonce_field('access402_save_access_settings'); ?>
            <?php foreach ($roles as $key => $role) : ?>
                <label class="access402-checkcard">
                    <input type="checkbox" name="bypass_roles[]" value="<?php echo esc_attr((string) $key); ?>" <?php checked(in_array($key, $selected_roles, true)); ?> />
                    <span><?php echo esc_html(translate_user_role((string) $role['name'])); ?></span>
                </label>
            <?php endforeach; ?>
            <div class="access402-actions">
                <button type="submit" class="button button-primary"><?php esc_html_e('Save role bypass', 'access402'); ?></button>
            </div>
        </form>
    </div>

    <div class="access402-card">
        <div class="access402-card-header access402-card-header-wide">
            <div>
                <h2><?php esc_html_e('Trusted Wallets', 'access402'); ?></h2>
                <p><?php esc_html_e('Trusted wallets bypass payment globally when their address is present in the request context.', 'access402'); ?></p>
            </div>
            <button type="button" class="button button-primary" data-open-panel="wallet"><?php esc_html_e('Add wallet', 'access402'); ?></button>
        </div>
        <table class="widefat fixed striped access402-table">
            <thead>
                <tr>
                    <th><?php esc_html_e('Label', 'access402'); ?></th>
                    <th><?php esc_html_e('Wallet address', 'access402'); ?></th>
                    <th><?php esc_html_e('Type', 'access402'); ?></th>
                    <th><?php esc_html_e('Status', 'access402'); ?></th>
                    <th><?php esc_html_e('Actions', 'access402'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ($trusted_wallets === []) : ?>
                    <tr><td colspan="5"><?php esc_html_e('No trusted wallets yet.', 'access402'); ?></td></tr>
                <?php else : ?>
                    <?php foreach ($trusted_wallets as $record) : ?>
                        <tr>
                            <td><?php echo esc_html((string) ($record['label'] ?: '—')); ?></td>
                            <td><code><?php echo esc_html((string) $record['wallet_address']); ?></code></td>
                            <td><?php echo esc_html($wallet_types[$record['wallet_type']] ?? $record['wallet_type']); ?></td>
                            <td><span class="access402-badge access402-badge-<?php echo esc_attr((string) $record['status']); ?>"><?php echo esc_html(ucfirst((string) $record['status'])); ?></span></td>
                            <td class="access402-row-actions">
                                <button type="button" class="button-link" data-edit-wallet="<?php echo esc_attr((string) $record['id']); ?>"><?php esc_html_e('Edit', 'access402'); ?></button>
                                <button type="button" class="button-link" data-toggle-wallet="<?php echo esc_attr((string) $record['id']); ?>" data-next-status="<?php echo esc_attr($record['status'] === 'active' ? 'disabled' : 'active'); ?>">
                                    <?php echo $record['status'] === 'active' ? esc_html__('Disable', 'access402') : esc_html__('Enable', 'access402'); ?>
                                </button>
                                <button type="button" class="button-link-delete" data-delete-wallet="<?php echo esc_attr((string) $record['id']); ?>"><?php esc_html_e('Delete', 'access402'); ?></button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="access402-card">
        <div class="access402-card-header access402-card-header-wide">
            <div>
                <h2><?php esc_html_e('Trusted IPs', 'access402'); ?></h2>
                <p><?php esc_html_e('Trusted IPs bypass payment globally. v1 supports single IPs only.', 'access402'); ?></p>
            </div>
            <button type="button" class="button button-primary" data-open-panel="ip"><?php esc_html_e('Add IP', 'access402'); ?></button>
        </div>
        <table class="widefat fixed striped access402-table">
            <thead>
                <tr>
                    <th><?php esc_html_e('Label', 'access402'); ?></th>
                    <th><?php esc_html_e('IP address', 'access402'); ?></th>
                    <th><?php esc_html_e('Status', 'access402'); ?></th>
                    <th><?php esc_html_e('Actions', 'access402'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ($trusted_ips === []) : ?>
                    <tr><td colspan="4"><?php esc_html_e('No trusted IPs yet.', 'access402'); ?></td></tr>
                <?php else : ?>
                    <?php foreach ($trusted_ips as $record) : ?>
                        <tr>
                            <td><?php echo esc_html((string) ($record['label'] ?: '—')); ?></td>
                            <td><code><?php echo esc_html((string) $record['ip_address']); ?></code></td>
                            <td><span class="access402-badge access402-badge-<?php echo esc_attr((string) $record['status']); ?>"><?php echo esc_html(ucfirst((string) $record['status'])); ?></span></td>
                            <td class="access402-row-actions">
                                <button type="button" class="button-link" data-edit-ip="<?php echo esc_attr((string) $record['id']); ?>"><?php esc_html_e('Edit', 'access402'); ?></button>
                                <button type="button" class="button-link" data-toggle-ip="<?php echo esc_attr((string) $record['id']); ?>" data-next-status="<?php echo esc_attr($record['status'] === 'active' ? 'disabled' : 'active'); ?>">
                                    <?php echo $record['status'] === 'active' ? esc_html__('Disable', 'access402') : esc_html__('Enable', 'access402'); ?>
                                </button>
                                <button type="button" class="button-link-delete" data-delete-ip="<?php echo esc_attr((string) $record['id']); ?>"><?php esc_html_e('Delete', 'access402'); ?></button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="access402-panel-backdrop" data-panel-backdrop hidden></div>
<aside class="access402-panel" data-panel="wallet" hidden>
    <div class="access402-panel-header">
        <div>
            <h2><?php esc_html_e('Trusted Wallet', 'access402'); ?></h2>
            <p><?php esc_html_e('Add or update a globally trusted wallet.', 'access402'); ?></p>
        </div>
        <button type="button" class="button-link" data-close-panel><?php esc_html_e('Close', 'access402'); ?></button>
    </div>
    <form class="access402-panel-body" data-wallet-form>
        <input type="hidden" name="id" value="0" />
        <label class="access402-field">
            <span><?php esc_html_e('Label', 'access402'); ?></span>
            <input type="text" name="label" />
        </label>
        <label class="access402-field">
            <span><?php esc_html_e('Wallet address', 'access402'); ?></span>
            <input type="text" name="wallet_address" required />
        </label>
        <label class="access402-field">
            <span><?php esc_html_e('Wallet type', 'access402'); ?></span>
            <select name="wallet_type">
                <?php foreach ($wallet_types as $value => $label) : ?>
                    <option value="<?php echo esc_attr($value); ?>"><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="access402-toggle">
            <input type="checkbox" name="status" value="active" checked />
            <span></span>
            <strong><?php esc_html_e('Entry is active', 'access402'); ?></strong>
        </label>
        <div class="access402-panel-actions">
            <button type="submit" class="button button-primary"><?php esc_html_e('Save wallet', 'access402'); ?></button>
        </div>
    </form>
</aside>

<aside class="access402-panel" data-panel="ip" hidden>
    <div class="access402-panel-header">
        <div>
            <h2><?php esc_html_e('Trusted IP', 'access402'); ?></h2>
            <p><?php esc_html_e('Add or update a globally trusted IP address.', 'access402'); ?></p>
        </div>
        <button type="button" class="button-link" data-close-panel><?php esc_html_e('Close', 'access402'); ?></button>
    </div>
    <form class="access402-panel-body" data-ip-form>
        <input type="hidden" name="id" value="0" />
        <label class="access402-field">
            <span><?php esc_html_e('Label', 'access402'); ?></span>
            <input type="text" name="label" />
        </label>
        <label class="access402-field">
            <span><?php esc_html_e('IP address', 'access402'); ?></span>
            <input type="text" name="ip_address" required />
        </label>
        <label class="access402-toggle">
            <input type="checkbox" name="status" value="active" checked />
            <span></span>
            <strong><?php esc_html_e('Entry is active', 'access402'); ?></strong>
        </label>
        <div class="access402-panel-actions">
            <button type="submit" class="button button-primary"><?php esc_html_e('Save IP', 'access402'); ?></button>
        </div>
    </form>
</aside>

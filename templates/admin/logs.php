<?php

declare(strict_types=1);
?>
<div class="access402-card">
    <div class="access402-card-header">
        <div>
            <h2><?php esc_html_e('Logs', 'access402'); ?></h2>
            <p><?php esc_html_e('Review request activity across allowed, bypassed, payment-required, and error decisions.', 'access402'); ?></p>
        </div>
    </div>

    <form method="get" class="access402-toolbar">
        <input type="hidden" name="page" value="access402" />
        <input type="hidden" name="tab" value="logs" />
        <input type="search" name="search_path" placeholder="<?php esc_attr_e('Search path', 'access402'); ?>" value="<?php echo esc_attr($search_path); ?>" />
        <select name="decision">
            <option value=""><?php esc_html_e('All decisions', 'access402'); ?></option>
            <?php foreach ($decision_options as $value => $label) : ?>
                <option value="<?php echo esc_attr($value); ?>" <?php selected($decision, $value); ?>><?php echo esc_html($label); ?></option>
            <?php endforeach; ?>
        </select>
        <select name="mode">
            <option value=""><?php esc_html_e('All modes', 'access402'); ?></option>
            <?php foreach ($mode_options as $value => $label) : ?>
                <option value="<?php echo esc_attr($value); ?>" <?php selected($mode, $value); ?>><?php echo esc_html($label); ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="button"><?php esc_html_e('Filter', 'access402'); ?></button>
    </form>

    <table class="widefat fixed striped access402-table">
        <thead>
            <tr>
                <th><?php esc_html_e('Time', 'access402'); ?></th>
                <th><?php esc_html_e('Path', 'access402'); ?></th>
                <th><?php esc_html_e('Matched rule', 'access402'); ?></th>
                <th><?php esc_html_e('Decision', 'access402'); ?></th>
                <th><?php esc_html_e('Wallet', 'access402'); ?></th>
                <th><?php esc_html_e('Mode', 'access402'); ?></th>
                <th><?php esc_html_e('Message', 'access402'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if ($records === []) : ?>
                <tr><td colspan="7"><?php esc_html_e('No logs match the current filters.', 'access402'); ?></td></tr>
            <?php else : ?>
                <?php foreach ($records as $record) : ?>
                    <tr>
                        <td><?php echo esc_html((string) $record['logged_at']); ?></td>
                        <td><code><?php echo esc_html((string) $record['path']); ?></code></td>
                        <td><?php echo esc_html((string) ($record['matched_rule_name'] ?: '—')); ?></td>
                        <td><span class="access402-badge access402-badge-<?php echo esc_attr((string) $record['decision']); ?>"><?php echo esc_html($decision_options[$record['decision']] ?? $record['decision']); ?></span></td>
                        <td><?php echo esc_html((string) ($record['wallet_address'] ?: '—')); ?></td>
                        <td><span class="access402-badge access402-badge-mode"><?php echo esc_html($mode_options[$record['mode']] ?? $record['mode']); ?></span></td>
                        <td><?php echo esc_html((string) ($record['message'] ?: '—')); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <?php if ($pagination['total_pages'] > 1) : ?>
        <div class="tablenav">
            <div class="tablenav-pages">
                <?php echo wp_kses_post(paginate_links([
                    'base'      => add_query_arg('paged', '%#%'),
                    'format'    => '',
                    'current'   => $pagination['current'],
                    'total'     => $pagination['total_pages'],
                    'prev_text' => '&laquo;',
                    'next_text' => '&raquo;',
                ])); ?>
            </div>
        </div>
    <?php endif; ?>
</div>

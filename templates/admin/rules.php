<?php

declare(strict_types=1);
?>
<div class="access402-stack">
    <div class="access402-card">
        <div class="access402-card-header access402-card-header-wide">
            <div>
                <h2><?php esc_html_e('Rules', 'access402'); ?></h2>
                <p><?php esc_html_e('Rules are evaluated from top to bottom. Table order is precedence.', 'access402'); ?></p>
            </div>
            <button type="button" class="button button-primary" data-open-panel="rule"><?php esc_html_e('Add Rule', 'access402'); ?></button>
        </div>

        <form method="get" class="access402-toolbar">
            <input type="hidden" name="page" value="access402" />
            <input type="hidden" name="tab" value="rules" />
            <input type="search" name="s" placeholder="<?php esc_attr_e('Search rules', 'access402'); ?>" value="<?php echo esc_attr($search); ?>" />
            <select name="status">
                <option value=""><?php esc_html_e('All statuses', 'access402'); ?></option>
                <?php foreach ($status_options as $value => $label) : ?>
                    <option value="<?php echo esc_attr($value); ?>" <?php selected($status, $value); ?>><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="button"><?php esc_html_e('Filter', 'access402'); ?></button>
        </form>

        <div class="access402-toolbar">
            <select data-bulk-action>
                <option value=""><?php esc_html_e('Bulk action', 'access402'); ?></option>
                <option value="enable"><?php esc_html_e('Enable', 'access402'); ?></option>
                <option value="disable"><?php esc_html_e('Disable', 'access402'); ?></option>
                <option value="delete"><?php esc_html_e('Delete', 'access402'); ?></option>
            </select>
            <button type="button" class="button" data-apply-bulk><?php esc_html_e('Apply', 'access402'); ?></button>
        </div>

        <?php if ($records === []) : ?>
            <div class="access402-empty-state">
                <h3><?php esc_html_e('No rules yet', 'access402'); ?></h3>
                <p><?php esc_html_e('Create your first path rule to start protecting premium pages, downloads, or API routes.', 'access402'); ?></p>
                <button type="button" class="button button-primary" data-open-panel="rule"><?php esc_html_e('Create first rule', 'access402'); ?></button>
            </div>
        <?php else : ?>
            <table class="widefat fixed striped access402-table">
                <thead>
                    <tr>
                        <td class="check-column"><input type="checkbox" data-select-all-rules /></td>
                        <th><?php esc_html_e('Order', 'access402'); ?></th>
                        <th><?php esc_html_e('Name', 'access402'); ?></th>
                        <th><?php esc_html_e('Path / URL pattern', 'access402'); ?></th>
                        <th><?php esc_html_e('Price', 'access402'); ?></th>
                        <th><?php esc_html_e('Unlock behavior', 'access402'); ?></th>
                        <th><?php esc_html_e('Status', 'access402'); ?></th>
                        <th><?php esc_html_e('Hits', 'access402'); ?></th>
                        <th><?php esc_html_e('Last matched', 'access402'); ?></th>
                        <th><?php esc_html_e('Actions', 'access402'); ?></th>
                    </tr>
                </thead>
                <tbody data-rule-table-body>
                    <?php foreach ($records as $record) : ?>
                        <tr draggable="true" data-rule-id="<?php echo esc_attr((string) $record['id']); ?>">
                            <th class="check-column"><input type="checkbox" value="<?php echo esc_attr((string) $record['id']); ?>" data-rule-checkbox /></th>
                            <td><span class="access402-drag-handle">⋮⋮</span> <?php echo esc_html((string) $record['sort_order']); ?></td>
                            <td>
                                <strong><?php echo esc_html((string) $record['name']); ?></strong>
                                <div class="access402-microcopy"><?php echo esc_html((string) $record['summary']); ?></div>
                            </td>
                            <td><code><?php echo esc_html((string) $record['path_pattern']); ?></code></td>
                            <td><?php echo $record['price_override'] !== null && $record['price_override'] !== '' ? esc_html(\Access402\Support\Helpers::format_decimal((string) $record['price_override']) . ' ' . $global_settings['default_currency']) : esc_html__('Global default', 'access402'); ?></td>
                            <td><?php echo esc_html($record['unlock_behavior_override'] ? ($unlock_options[$record['unlock_behavior_override']] ?? $record['unlock_behavior_override']) : __('Global default', 'access402')); ?></td>
                            <td><span class="access402-badge access402-badge-<?php echo esc_attr((string) $record['status']); ?>"><?php echo esc_html($status_options[$record['status']] ?? $record['status']); ?></span></td>
                            <td><?php echo esc_html((string) $record['hits_count']); ?></td>
                            <td><?php echo esc_html((string) ($record['last_matched_at'] ?: '—')); ?></td>
                            <td class="access402-row-actions">
                                <button type="button" class="button-link" data-edit-rule="<?php echo esc_attr((string) $record['id']); ?>"><?php esc_html_e('Edit', 'access402'); ?></button>
                                <button type="button" class="button-link" data-toggle-rule="<?php echo esc_attr((string) $record['id']); ?>" data-next-status="<?php echo esc_attr($record['status'] === 'active' ? 'disabled' : 'active'); ?>">
                                    <?php echo $record['status'] === 'active' ? esc_html__('Disable', 'access402') : esc_html__('Enable', 'access402'); ?>
                                </button>
                                <button type="button" class="button-link-delete" data-delete-rule="<?php echo esc_attr((string) $record['id']); ?>"><?php esc_html_e('Delete', 'access402'); ?></button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
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
        <?php endif; ?>
    </div>
</div>

<div class="access402-panel-backdrop" data-panel-backdrop hidden></div>
<aside class="access402-panel" data-panel="rule" hidden>
    <div class="access402-panel-header">
        <div>
            <h2 data-rule-panel-title><?php esc_html_e('Add Rule', 'access402'); ?></h2>
            <p><?php esc_html_e('Use global defaults first, and override only what this path truly needs.', 'access402'); ?></p>
        </div>
        <button type="button" class="button-link" data-close-panel><?php esc_html_e('Close', 'access402'); ?></button>
    </div>
    <form class="access402-panel-body" data-rule-form>
        <input type="hidden" name="id" value="0" />
        <label class="access402-field">
            <span><?php esc_html_e('Name', 'access402'); ?></span>
            <input type="text" name="name" required />
        </label>
        <label class="access402-field">
            <span><?php esc_html_e('Path / URL pattern', 'access402'); ?></span>
            <input type="text" name="path_pattern" required />
            <small>/premium/*<br />/downloads/report.pdf<br />/wp-json/myplugin/v1/*</small>
        </label>
        <label class="access402-field">
            <span><?php esc_html_e('Price override', 'access402'); ?></span>
            <input type="number" step="0.00000001" min="0" name="price_override" placeholder="0.50" />
            <small><?php esc_html_e('Leave empty to use the global default price.', 'access402'); ?></small>
        </label>
        <label class="access402-field">
            <span><?php esc_html_e('Unlock behavior override', 'access402'); ?></span>
            <select name="unlock_behavior_override">
                <option value="__global"><?php esc_html_e('Use global default', 'access402'); ?></option>
                <?php foreach ($unlock_options as $value => $label) : ?>
                    <option value="<?php echo esc_attr($value); ?>"><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="access402-toggle">
            <input type="checkbox" name="status" value="active" checked />
            <span></span>
            <strong><?php esc_html_e('Rule is active', 'access402'); ?></strong>
        </label>
        <div class="access402-summary-card">
            <strong><?php esc_html_e('Rule summary', 'access402'); ?></strong>
            <p data-rule-summary><?php esc_html_e('Start typing to preview how this rule will resolve at runtime.', 'access402'); ?></p>
        </div>
        <div class="access402-panel-actions">
            <button type="submit" class="button button-primary"><?php esc_html_e('Save rule', 'access402'); ?></button>
        </div>
    </form>
</aside>

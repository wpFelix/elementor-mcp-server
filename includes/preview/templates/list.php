<?php

// SPDX-FileCopyrightText: 2026 Elementor MCP <dev@elementormcp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace ElementorMCP\Preview\Admin;

use ElementorMCP\Preview\Store;

if (!defined('ABSPATH')) {
    exit();
}

if (!current_user_can_manage()) {
    wp_die(esc_html__('Not allowed.', domain: 'elementor-mcp'), title: '', args: ['response' => 403]);
}

$records = Store\all();
$pending = array_values(array_filter(
    $records,
    static fn(array $record): bool => ($record['status'] ?? '') === Store\STATUS_PENDING,
));
$resolved = array_values(array_filter(
    $records,
    static fn(array $record): bool => ($record['status'] ?? '') !== Store\STATUS_PENDING,
));

?>
<?php \elementor_mcp_render_admin_header(esc_html__('Changes an agent has proposed but not made', domain: 'elementor-mcp')); ?>
<div class="wrap elementor-mcp-wrap">
    <div class="elementor-mcp-panel">
        <h2><?php esc_html_e('Waiting for review', domain: 'elementor-mcp'); ?></h2>

        <?php if ($pending === []) { ?>
            <p class="elementor-mcp-muted">
                <?php esc_html_e('Nothing is waiting. When an agent calls Elementor MCP Preview before a write, the proposed change appears here with its diff, and nothing is written until you apply it.', domain: 'elementor-mcp'); ?>
            </p>
        <?php } else { ?>
            <table class="widefat striped elementor-mcp-preview-table">
                <thead>
                    <tr>
                        <th scope="col"><?php esc_html_e('Target', domain: 'elementor-mcp'); ?></th>
                        <th scope="col"><?php esc_html_e('Ability', domain: 'elementor-mcp'); ?></th>
                        <th scope="col"><?php esc_html_e('Changes', domain: 'elementor-mcp'); ?></th>
                        <th scope="col"><?php esc_html_e('Proposed by', domain: 'elementor-mcp'); ?></th>
                        <th scope="col"><?php esc_html_e('Age', domain: 'elementor-mcp'); ?></th>
                        <th scope="col"><?php esc_html_e('Review', domain: 'elementor-mcp'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pending as $record) {
                        $id = (string) ($record['preview_id'] ?? '');
                        $target = is_array($record['target'] ?? null) ? $record['target'] : [];
                        $diff = is_array($record['diff'] ?? null) ? $record['diff'] : [];
                        $agent = is_array($record['agent'] ?? null) ? $record['agent'] : [];
                        $destroys = ($diff['destroys'] ?? false) === true;
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html((string) ($target['label'] ?? '')); ?></strong>
                                <?php if ((int) ($target['id'] ?? 0) > 0) { ?>
                                    <span class="elementor-mcp-muted">
                                        <?php echo esc_html(sprintf('#%d %s', (int) $target['id'], (string) ($target['post_type'] ?? ''))); ?>
                                    </span>
                                <?php } ?>
                            </td>
                            <td><code><?php echo esc_html((string) ($record['ability'] ?? '')); ?></code></td>
                            <td>
                                <?php
                                $count = (int) ($diff['changed_count'] ?? 0);
                                echo esc_html(sprintf(
                                    /* translators: %d: number of changed fields */
                                    _n('%d field', '%d fields', $count, 'elementor-mcp'),
                                    $count,
                                ));
                                ?>
                                <?php if ($destroys) { ?>
                                    <span class="elementor-mcp-badge elementor-mcp-badge--destructive">
                                        <?php esc_html_e('Deletes data', domain: 'elementor-mcp'); ?>
                                    </span>
                                <?php } ?>
                            </td>
                            <td>
                                <?php
                                $client = trim((string) ($agent['label'] ?? ''));
                                echo esc_html($client !== '' ? $client : __('Unknown client', domain: 'elementor-mcp'));
                                ?>
                                <span class="elementor-mcp-muted">
                                    <?php echo esc_html((string) (is_array($record['created_by'] ?? null) ? ($record['created_by']['login'] ?? '') : '')); ?>
                                </span>
                            </td>
                            <td><?php echo esc_html(relative_time((string) ($record['created_at'] ?? ''))); ?></td>
                            <td>
                                <a class="button button-primary" href="<?php echo esc_url(add_query_arg(['page' => PAGE_SLUG, 'preview' => $id], admin_url('admin.php'))); ?>">
                                    <?php esc_html_e('Review', domain: 'elementor-mcp'); ?>
                                </a>
                            </td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
        <?php } ?>
    </div>

    <?php if ($resolved !== []) { ?>
        <div class="elementor-mcp-panel">
            <h2><?php esc_html_e('Recently resolved', domain: 'elementor-mcp'); ?></h2>
            <table class="widefat striped elementor-mcp-preview-table">
                <thead>
                    <tr>
                        <th scope="col"><?php esc_html_e('Target', domain: 'elementor-mcp'); ?></th>
                        <th scope="col"><?php esc_html_e('Ability', domain: 'elementor-mcp'); ?></th>
                        <th scope="col"><?php esc_html_e('Outcome', domain: 'elementor-mcp'); ?></th>
                        <th scope="col"><?php esc_html_e('Age', domain: 'elementor-mcp'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (array_slice($resolved, offset: 0, length: 20) as $record) {
                        $target = is_array($record['target'] ?? null) ? $record['target'] : [];
                        $status = (string) ($record['status'] ?? '');
                        ?>
                        <tr>
                            <td><?php echo esc_html((string) ($target['label'] ?? '')); ?></td>
                            <td><code><?php echo esc_html((string) ($record['ability'] ?? '')); ?></code></td>
                            <td><span class="elementor-mcp-status elementor-mcp-status--<?php echo esc_attr($status); ?>"><?php echo esc_html(status_label($status)); ?></span></td>
                            <td><?php echo esc_html(relative_time((string) ($record['created_at'] ?? ''))); ?></td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
    <?php } ?>
</div>

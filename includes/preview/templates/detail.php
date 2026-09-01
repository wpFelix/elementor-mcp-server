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

/** @var array<string, mixed> $record Supplied by render_page(). */
$id = (string) ($record['preview_id'] ?? '');
$status = (string) ($record['status'] ?? '');
$target = is_array($record['target'] ?? null) ? $record['target'] : [];
$diff = is_array($record['diff'] ?? null) ? $record['diff'] : [];
$agent = is_array($record['agent'] ?? null) ? $record['agent'] : [];
$created_by = is_array($record['created_by'] ?? null) ? $record['created_by'] : [];
$entries = is_array($diff['entries'] ?? null) ? $diff['entries'] : [];
$unpredicted = is_array($diff['unpredicted'] ?? null) ? $diff['unpredicted'] : [];
$side_effects = is_array($record['side_effects'] ?? null) ? $record['side_effects'] : [];
$warnings = is_array($record['warnings'] ?? null) ? $record['warnings'] : [];
$is_pending = $status === Store\STATUS_PENDING;

?>
<?php \elementor_mcp_render_admin_header(esc_html__('Review a proposed change', domain: 'elementor-mcp')); ?>
<div class="wrap elementor-mcp-wrap">
    <p>
        <a href="<?php echo esc_url(add_query_arg(['page' => PAGE_SLUG], admin_url('admin.php'))); ?>">
            &larr; <?php esc_html_e('All previews', domain: 'elementor-mcp'); ?>
        </a>
    </p>

    <div class="elementor-mcp-panel">
        <h2>
            <?php echo esc_html((string) ($target['label'] ?? __('Untitled', domain: 'elementor-mcp'))); ?>
            <span class="elementor-mcp-status elementor-mcp-status--<?php echo esc_attr($status); ?>">
                <?php echo esc_html(status_label($status)); ?>
            </span>
        </h2>

        <table class="elementor-mcp-preview-meta">
            <tr>
                <th scope="row"><?php esc_html_e('Ability', domain: 'elementor-mcp'); ?></th>
                <td><code><?php echo esc_html((string) ($record['ability'] ?? '')); ?></code></td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Proposed by', domain: 'elementor-mcp'); ?></th>
                <td>
                    <?php
                    $client = trim((string) ($agent['label'] ?? ''));
                    $version = trim((string) ($agent['client_version'] ?? ''));
                    echo esc_html($client !== '' ? $client : __('Unknown client', domain: 'elementor-mcp'));
                    if ($version !== '') {
                        echo ' ' . esc_html($version);
                    }
                    ?>
                    <span class="elementor-mcp-muted">
                        <?php
                        printf(
                            /* translators: 1: WordPress user login, 2: authentication method */
                            esc_html__('as %1$s, via %2$s', domain: 'elementor-mcp'),
                            esc_html((string) ($created_by['login'] ?? '')),
                            esc_html((string) ($agent['method'] ?? 'direct')),
                        );
                        ?>
                    </span>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Created', domain: 'elementor-mcp'); ?></th>
                <td><?php echo esc_html(relative_time((string) ($record['created_at'] ?? ''))); ?></td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Expires', domain: 'elementor-mcp'); ?></th>
                <td><?php echo esc_html(relative_time((string) ($record['expires_at'] ?? ''))); ?></td>
            </tr>
        </table>
    </div>

    <?php foreach ($warnings as $warning) { ?>
        <div class="notice notice-warning inline"><p><?php echo esc_html((string) $warning); ?></p></div>
    <?php } ?>

    <?php if (($diff['destroys'] ?? false) === true) { ?>
        <div class="notice notice-error inline">
            <p><strong><?php esc_html_e('This removes data.', domain: 'elementor-mcp'); ?></strong>
            <?php esc_html_e('The fields below marked "removed" will no longer exist after this is applied.', domain: 'elementor-mcp'); ?></p>
        </div>
    <?php } ?>

    <div class="elementor-mcp-panel">
        <h2>
            <?php
            $count = (int) ($diff['changed_count'] ?? 0);
            echo esc_html(sprintf(
                /* translators: %d: number of changed fields */
                _n('%d field would change', '%d fields would change', $count, 'elementor-mcp'),
                $count,
            ));
            ?>
        </h2>

        <?php if ($entries === []) { ?>
            <p class="elementor-mcp-muted"><?php esc_html_e('Nothing would change. The values this call sends already match what is stored.', domain: 'elementor-mcp'); ?></p>
        <?php } else { ?>
            <div class="elementor-mcp-diff-scroll">
                <table class="widefat striped elementor-mcp-diff">
                    <thead>
                        <tr>
                            <th scope="col"><?php esc_html_e('Field', domain: 'elementor-mcp'); ?></th>
                            <th scope="col"><?php esc_html_e('Now', domain: 'elementor-mcp'); ?></th>
                            <th scope="col"><?php esc_html_e('After', domain: 'elementor-mcp'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($entries as $entry) {
                            $op = (string) ($entry['op'] ?? 'changed');
                            ?>
                            <tr class="elementor-mcp-diff--<?php echo esc_attr($op); ?>">
                                <td>
                                    <code><?php echo esc_html((string) ($entry['path_label'] ?? '')); ?></code>
                                    <span class="elementor-mcp-badge elementor-mcp-badge--<?php echo esc_attr($op); ?>"><?php echo esc_html($op); ?></span>
                                    <?php if (($entry['value_truncated'] ?? false) === true) { ?>
                                        <span class="elementor-mcp-muted"><?php esc_html_e('(shortened for display)', domain: 'elementor-mcp'); ?></span>
                                    <?php } ?>
                                </td>
                                <td><pre class="elementor-mcp-diff-value elementor-mcp-diff-value--before"><?php echo esc_html((string) ($entry['before'] ?? '')); ?></pre></td>
                                <td><pre class="elementor-mcp-diff-value elementor-mcp-diff-value--after"><?php echo esc_html((string) ($entry['after'] ?? '')); ?></pre></td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
            <?php if (($diff['truncated'] ?? false) === true) { ?>
                <p class="elementor-mcp-muted">
                    <?php
                    printf(
                        /* translators: %d: number of additional changed fields not shown */
                        esc_html__('%d more changed field(s) are not shown here. They will still be written.', domain: 'elementor-mcp'),
                        (int) ($diff['dropped_count'] ?? 0),
                    );
                    ?>
                </p>
            <?php } ?>
        <?php } ?>
    </div>

    <?php if ($unpredicted !== [] || $side_effects !== []) { ?>
        <div class="elementor-mcp-panel">
            <h2><?php esc_html_e('What this diff cannot show', domain: 'elementor-mcp'); ?></h2>
            <ul class="elementor-mcp-list">
                <?php foreach ($unpredicted as $note) { ?>
                    <li>
                        <code><?php echo esc_html((string) ($note['path_label'] ?? '')); ?></code>
                        &mdash; <?php echo esc_html((string) ($note['reason'] ?? '')); ?>
                    </li>
                <?php } ?>
                <?php foreach ($side_effects as $effect) { ?>
                    <li><?php echo esc_html((string) $effect); ?></li>
                <?php } ?>
            </ul>
        </div>
    <?php } ?>

    <?php if ($is_pending) { ?>
        <div class="elementor-mcp-panel elementor-mcp-panel--actions">
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="elementor-mcp-inline-form">
                <input type="hidden" name="action" value="elementor_mcp_preview_apply">
                <input type="hidden" name="preview_id" value="<?php echo esc_attr($id); ?>">
                <?php wp_nonce_field('elementor_mcp_preview_apply_' . $id); ?>
                <button type="submit" class="button button-primary"><?php esc_html_e('Apply this change', domain: 'elementor-mcp'); ?></button>
            </form>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="elementor-mcp-inline-form">
                <input type="hidden" name="action" value="elementor_mcp_preview_discard">
                <input type="hidden" name="preview_id" value="<?php echo esc_attr($id); ?>">
                <?php wp_nonce_field('elementor_mcp_preview_discard_' . $id); ?>
                <button type="submit" class="button"><?php esc_html_e('Discard', domain: 'elementor-mcp'); ?></button>
            </form>

            <p class="elementor-mcp-muted">
                <?php esc_html_e('If the target has changed since this preview was made, applying is refused rather than overwriting the newer version.', domain: 'elementor-mcp'); ?>
            </p>
        </div>
    <?php } elseif ($status === Store\STATUS_CONFLICTED) { ?>
        <div class="notice notice-error inline">
            <p><?php esc_html_e('The target changed after this preview was created, so it can no longer be applied. Nothing was written. Ask the agent to run the preview again against the current state.', domain: 'elementor-mcp'); ?></p>
        </div>
    <?php } ?>
</div>

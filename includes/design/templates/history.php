<?php

// SPDX-FileCopyrightText: 2026 Elementor MCP <dev@elementormcp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template file: require()d inside a namespaced render function, so every variable is function-scoped, never global. The prefix sniff cannot see across the include boundary. Reads are type-checked and escaped on output.

use ElementorMCP\Design\Contract;
use ElementorMCP\Design\Parser;
use ElementorMCP\Design\Revisions;

if (!defined('ABSPATH')) {
    exit();
}

/** @var WP_Post $post */
/** @var bool $is_active */
/** @var string $action_url */

$history = Revisions\history($post);
$history_enabled = wp_revisions_enabled($post);
$date_format = (string) get_option('date_format') . ' ' . (string) get_option('time_format');
?>
<section class="elementor-mcp-detail-block elementor-mcp-history">
    <h2><?php esc_html_e('History', domain: 'elementor-mcp'); ?><?php if ($history !== []): ?>
        <span class="elementor-mcp-detail-count"><?php echo esc_html((string) count($history)); ?></span>
    <?php endif; ?></h2>
    <p class="elementor-mcp-history-intro"><?php esc_html_e(
        'Elementor MCP keeps at most five snapshots. Restoring one creates a new current version, so the change remains reversible.',
        domain: 'elementor-mcp',
    ); ?></p>

    <?php if (!$history_enabled): ?>
        <p class="elementor-mcp-history-empty"><?php esc_html_e(
            'Revision history is disabled by the WordPress configuration.',
            domain: 'elementor-mcp',
        ); ?></p>
    <?php endif; ?>
    <?php if ($history_enabled && $history === []): ?>
        <p class="elementor-mcp-history-empty"><?php esc_html_e('No previous versions yet.', domain: 'elementor-mcp'); ?></p>
    <?php endif; ?>
    <?php if ($history !== []): ?>
        <ol class="elementor-mcp-history-list">
            <?php foreach ($history as $revision):
                $inspection = Contract\inspect($revision->post_content);
                $ready = $inspection['readiness']['ready'];
                $can_restore = !$is_active || $ready;
                $timestamp = strtotime($revision->post_modified_gmt . ' UTC');
                $date = $timestamp !== false ? (string) wp_date($date_format, $timestamp) : $revision->post_modified;
                $author = get_userdata((int) $revision->post_author);
                $author_name = $author instanceof WP_User ? $author->display_name : __('System', domain: 'elementor-mcp');
                $parsed = Parser\parse($revision->post_content);
                ?>
                <li class="elementor-mcp-history-item">
                    <div class="elementor-mcp-history-copy">
                        <strong class="elementor-mcp-history-date"><?php echo esc_html($date); ?></strong>
                        <span class="elementor-mcp-history-meta"><?php echo
                            esc_html(sprintf(
                                /* translators: 1: design name, 2: revision author */
                                __('%1$s · by %2$s', domain: 'elementor-mcp'),
                                $parsed['name'] !== '' ? $parsed['name'] : $post->post_title,
                                $author_name,
                            ))
                        ; ?></span>
                    </div>
                    <span class="elementor-mcp-history-state <?php echo $ready ? 'is-ready' : 'is-incomplete'; ?>"><?php echo
                        esc_html($ready ? __('Ready', domain: 'elementor-mcp') : __('Incomplete', domain: 'elementor-mcp'))
                    ; ?></span>
                    <form method="post" action="<?php echo
                        esc_url($action_url)
                    ; ?>" onsubmit="return confirm('<?php echo
                        esc_js(__('Restore this design revision?', domain: 'elementor-mcp'))
                    ; ?>');">
                        <?php wp_nonce_field('elementor_mcp_design_restore_' . $revision->ID); ?>
                        <input type="hidden" name="action" value="elementor_mcp_design_restore" />
                        <input type="hidden" name="design_id" value="<?php echo (int) $post->ID; ?>" />
                        <input type="hidden" name="revision_id" value="<?php echo (int) $revision->ID; ?>" />
                        <button type="submit" class="button" <?php disabled(!$can_restore); ?> title="<?php echo
                            esc_attr(
                                $can_restore
                                    ? __('Restore this revision', domain: 'elementor-mcp')
                                    : Contract\activation_error($inspection),
                            )
                        ; ?>"><?php esc_html_e('Restore', domain: 'elementor-mcp'); ?></button>
                    </form>
                </li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>
</section>

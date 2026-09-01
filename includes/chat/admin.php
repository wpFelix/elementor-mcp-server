<?php

// SPDX-FileCopyrightText: 2026 Elementor MCP <dev@elementormcp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * Elementor MCP Chat: the admin screen — menu entry, assets, and page render.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Add Chat to the menu, but only where Chat can actually run.
 *
 * elementor_mcp_chat_is_enabled() is a filter and nothing else — it says whether the
 * site owner has switched Chat off, not whether the machinery behind it exists.
 * So the entry appeared on every install, including the majority that have no AI
 * provider configured and every site below WordPress 7, where the page can do
 * nothing but explain that it cannot work. A permanent menu item whose only
 * content is an apology is worse than no menu item.
 *
 * elementor_mcp_chat_status() is a handful of class_exists() calls, so asking it on
 * every admin_menu costs nothing.
 */
function elementor_mcp_register_chat_menu(): void
{
    if (!elementor_mcp_chat_is_enabled() || elementor_mcp_chat_status()['available'] !== true) {
        return;
    }

    add_submenu_page(
        parent_slug: 'elementor-mcp-connect',
        page_title: elementor_mcp_nav_label('elementor-mcp-chat'),
        menu_title: elementor_mcp_nav_label('elementor-mcp-chat'),
        capability: elementor_mcp_manage_capability(),
        menu_slug: ELEMENTOR_MCP_CHAT_PAGE,
        callback: 'elementor_mcp_render_chat_page',
    );
}

function elementor_mcp_enqueue_chat_assets(string $hook): void
{
    if (!elementor_mcp_chat_is_enabled() || $hook !== 'elementor-mcp_page_' . ELEMENTOR_MCP_CHAT_PAGE) {
        return;
    }

    $asset_file = dirname(__DIR__) . '/assets/chat/index.asset.php';
    // @mago-expect analysis:mixed-assignment
    $asset = is_file($asset_file) ? (require $asset_file) : ['dependencies' => [], 'version' => ELEMENTOR_MCP_VERSION];
    if (!is_array($asset)) {
        $asset = ['dependencies' => [], 'version' => ELEMENTOR_MCP_VERSION];
    }

    /** @var list<string> $dependencies */
    $dependencies = is_array($asset['dependencies'] ?? null) ? $asset['dependencies'] : [];
    $version = is_string($asset['version'] ?? null) ? $asset['version'] : ELEMENTOR_MCP_VERSION;

    // phpcs:disable WordPress.WP.EnqueuedResourceParameters.NotInFooter -- `args: true` IS the in_footer flag; the sniff cannot read PHP named arguments.
    wp_enqueue_script(
        'elementor-mcp-chat',
        (string) ELEMENTOR_MCP_PLUGIN_URL . 'includes/assets/chat/index.js',
        $dependencies,
        $version,
        args: true,
    );
    // phpcs:enable WordPress.WP.EnqueuedResourceParameters.NotInFooter
    wp_enqueue_style(
        'elementor-mcp-chat',
        (string) ELEMENTOR_MCP_PLUGIN_URL . 'includes/assets/chat/style-index.tsx.css',
        ['wp-components'],
        $version,
    );
    wp_set_script_translations('elementor-mcp-chat', domain: 'elementor-mcp');
    $settings_json = wp_json_encode([
        'root' => esc_url_raw(rest_url(ELEMENTOR_MCP_CHAT_REST_NAMESPACE)),
        'nonce' => wp_create_nonce('wp_rest'),
        'status' => elementor_mcp_chat_status(),
        'connectorsUrl' => admin_url('options-connectors.php'),
        'consented' => elementor_mcp_chat_user_has_consented(),
        'backUrl' => add_query_arg(['page' => 'elementor-mcp-connect'], admin_url('admin.php')),
    ]);
    if (!is_string($settings_json)) {
        $settings_json = '{}';
    }

    wp_add_inline_script('elementor-mcp-chat', 'window.elementorMcpChat = ' . $settings_json . ';', position: 'before');
}

function elementor_mcp_render_chat_page(): void
{
    if (!elementor_mcp_chat_is_enabled() || !elementor_mcp_current_user_can_manage()) {
        return;
    }

    elementor_mcp_render_admin_header(legend: __('Chat', domain: 'elementor-mcp'));
    ?>
    <div class="wrap elementor-mcp-chat-wrap">
        <h1 class="screen-reader-text"><?php esc_html_e('Elementor MCP Chat', domain: 'elementor-mcp'); ?></h1>
        <div id="elementor-mcp-chat-root"></div>
    </div>
    <?php
}

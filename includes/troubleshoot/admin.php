<?php

// SPDX-FileCopyrightText: 2026 Elementor MCP <dev@elementormcp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace ElementorMCP\Troubleshoot\Admin;

if (!defined('ABSPATH')) {
    exit();
}

const PAGE_SLUG = 'elementor-mcp-troubleshoot';

/**
 * Register the standalone Troubleshoot page. The diagnostics are not a step in the connection
 * flow, so they live in their own submenu rather than inside the Connect page; the Connect page
 * links here when something is not working.
 */
function register_menu(): void
{
    add_submenu_page(
        parent_slug: 'elementor-mcp-connect',
        page_title: \elementor_mcp_nav_label('elementor-mcp-troubleshoot'),
        menu_title: \elementor_mcp_nav_label('elementor-mcp-troubleshoot'),
        capability: \elementor_mcp_manage_capability(),
        menu_slug: PAGE_SLUG,
        callback: __NAMESPACE__ . '\\render_page',
    );
}

/**
 * Best guess at which connection method to diagnose, since this page is reached outside the
 * Connect flow where the method was known. The strongest signal is the last authenticated MCP
 * request, which is recorded per method; failing that, the presence of MCP Application Passwords
 * or registered OAuth clients. Empty string means "unknown", and the panel then checks both.
 *
 * @return 'oauth'|'token'|'password'|''
 */
function detect_connection_method(): string
{
    // @mago-expect analysis:mixed-assignment
    $last = get_option('elementor_mcp_mcp_last_request', default_value: []);
    if (is_array($last)) {
        $seen = [];
        foreach (['oauth', 'token', 'password'] as $candidate) {
            $stamp = is_int($last[$candidate] ?? null) ? $last[$candidate] : 0;
            if ($stamp > 0) {
                $seen[$candidate] = $stamp;
            }
        }
        if ($seen !== []) {
            // The most recently used method, so a site that has since moved to a
            // second credential is diagnosed on the one it is actually using.
            arsort($seen);

            return (string) array_key_first($seen);
        }
    }

    if (function_exists('elementor_mcp_tokens_for_user') && \elementor_mcp_tokens_for_user(get_current_user_id()) !== []) {
        return 'token';
    }

    if (function_exists('elementor_mcp_get_mcp_passwords') && \elementor_mcp_get_mcp_passwords() !== []) {
        return 'password';
    }

    if (
        function_exists('ElementorMCP\\OAuth\\ClientValidation\\active_client_count')
        && \ElementorMCP\OAuth\ClientValidation\active_client_count() > 0
    ) {
        return 'oauth';
    }

    return '';
}

function render_page(): void
{
    if (!\elementor_mcp_current_user_can_manage()) {
        return;
    }
    $method = detect_connection_method();
    if (function_exists('elementor_mcp_render_admin_header')) {
        \elementor_mcp_render_admin_header();
    }
    ?>
    <div class="wrap">
        <h1 class="wp-heading-inline"><?php echo esc_html(\elementor_mcp_nav_label('elementor-mcp-troubleshoot')); ?></h1>
        <hr class="wp-header-end">
        <p class="description elementor-mcp-lede"><?php esc_html_e(
            'Run these checks when an AI client cannot connect. They probe this site the way a client does and point at what to fix.',
            domain: 'elementor-mcp',
        ); ?></p>
        <?php \ElementorMCP\Troubleshoot\UI\render_panel(
            context: 'troubleshoot',
            method: $method,
            with_method_picker: true,
        ); ?>
    </div>
    <?php
}

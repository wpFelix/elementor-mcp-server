<?php

// SPDX-FileCopyrightText: 2026 Elementor MCP <dev@elementormcp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Internal compatibility contract shared by metadata, startup gates, and agent context.
 */
define(constant_name: 'ELEMENTOR_MCP_VERSION', value: '1.0.0');
define(constant_name: 'ELEMENTOR_MCP_REST_API_VERSION', value: 1);
define(constant_name: 'ELEMENTOR_MCP_MINIMUM_WORDPRESS_VERSION', value: '6.9');
define(constant_name: 'ELEMENTOR_MCP_MINIMUM_ELEMENTOR_VERSION', value: '3.6.0');
define(
    constant_name: 'ELEMENTOR_MCP_ELEMENTOR_ATOMIC_BASE_CLASS',
    value: 'Elementor\\Modules\\AtomicWidgets\\Elements\\Base\\Atomic_Widget_Base',
);

/**
 * Return the feature signals implemented by this build.
 *
 * Features remain false until their owning implementation session lands. This makes an in-progress
 * build fail closed at the public compatibility gate instead of advertising a surface it cannot serve.
 *
 * @return array{
 *     abilities_bearer_auth: bool,
 *     agent_context: bool,
 *     rest_skills: bool,
 *     generalized_execution_shim: bool
 * }
 */
function elementor_mcp_rest_api_features(): array
{
    return [
        'abilities_bearer_auth' => true,
        'agent_context' => true,
        'rest_skills' => true,
        'generalized_execution_shim' => true,
        // Legacy MCP is served by the bundled adapter; modern MCP by
        // includes/mcp/. Both are true only because both are wired — see
        // elementor_mcp_supported_protocol_versions() for what is actually claimed.
        'mcp_legacy_protocol' => true,
        'mcp_modern_protocol' => true,
        // Not implemented, and advertised as false rather than omitted so a
        // client can tell "absent" from "unsupported": Elementor MCP has no
        // change-notification producer, so there is nothing to subscribe to.
        'mcp_subscriptions' => false,
        'mcp_tasks_extension' => false,
    ];
}

/**
 * MCP protocol revisions this build serves.
 *
 * @return list<string>
 */
function elementor_mcp_supported_protocol_versions(): array
{
    return \ElementorMCP\Mcp\SUPPORTED_VERSIONS;
}

/**
 * Return the installed WordPress version without coupling metadata to an HTTP request.
 */
function elementor_mcp_wordpress_version(): string
{
    return get_bloginfo('version');
}

/**
 * Whether this WordPress installation has the minimum Abilities API generation Elementor MCP supports.
 */
function elementor_mcp_wordpress_abilities_supported(?string $wordpress_version = null): bool
{
    $wordpress_version ??= elementor_mcp_wordpress_version();

    return $wordpress_version !== ''
    && version_compare($wordpress_version, ELEMENTOR_MCP_MINIMUM_WORDPRESS_VERSION, operator: '>=');
}

/**
 * Stable compatibility block published before and after authentication.
 *
 * @return array{
 *     plugin_version: string,
 *     rest_api_version: int,
 *     wordpress_version: string,
 *     minimum_wordpress_version: string,
 *     features: array<string, bool>
 * }
 */
function elementor_mcp_server_compatibility(): array
{
    return [
        'plugin_version' => ELEMENTOR_MCP_VERSION,
        'rest_api_version' => ELEMENTOR_MCP_REST_API_VERSION,
        'wordpress_version' => elementor_mcp_wordpress_version(),
        'minimum_wordpress_version' => ELEMENTOR_MCP_MINIMUM_WORDPRESS_VERSION,
        'features' => elementor_mcp_rest_api_features(),
        // Guarded: this block is published from startup gates that can run
        // before the MCP modules are loaded.
        'mcp_protocol_versions' => defined('ElementorMCP\\Mcp\\VERSION_MODERN')
            ? elementor_mcp_supported_protocol_versions()
            : [],
    ];
}

/**
 * Register the unsupported-WordPress administrator warning when the Abilities API cannot be used.
 */
function elementor_mcp_register_wordpress_compatibility_notice(): void
{
    if (elementor_mcp_wordpress_abilities_supported()) {
        return;
    }

    add_action('admin_notices', callback: 'elementor_mcp_render_wordpress_compatibility_notice');
    add_action('network_admin_notices', callback: 'elementor_mcp_render_wordpress_compatibility_notice');
}

/**
 * Explain why Ability registration and its REST shim were skipped.
 */
function elementor_mcp_render_wordpress_compatibility_notice(): void
{
    if (!elementor_mcp_current_user_can_manage()) {
        return;
    }

    wp_admin_notice(
        sprintf(
            /* translators: 1: minimum required WordPress version, 2: installed WordPress version */
            esc_html__(
                'Elementor MCP requires WordPress %1$s or newer for the Abilities API. WordPress %2$s is installed, so Elementor MCP Ability and REST registration is disabled.',
                domain: 'elementor-mcp',
            ),
            esc_html(ELEMENTOR_MCP_MINIMUM_WORDPRESS_VERSION),
            esc_html(elementor_mcp_wordpress_version()),
        ),
        ['type' => 'warning', 'dismissible' => false],
    );
}

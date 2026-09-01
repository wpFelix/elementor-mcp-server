<?php

// SPDX-FileCopyrightText: 2026 Elementor MCP <dev@elementormcp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

require_once __DIR__ . '/schema.php';

// Function definitions only — the module registers nothing until its loader runs.
require_once __DIR__ . '/wordpress/bootstrap.php';
require_once __DIR__ . '/elementor/bootstrap.php';

/**
 * Load the Ability execution REST shim only when this WordPress version supports Abilities.
 */
function elementor_mcp_boot_ability_rest_surface(): bool
{
    if (!elementor_mcp_wordpress_abilities_supported()) {
        return false;
    }

    require_once dirname(__DIR__) . '/rest/shim.php';
    add_action('rest_api_init', callback: 'elementor_mcp_register_ability_run_rest_shim');

    return true;
}

/**
 * Register Elementor MCP Ability hooks independently of protocol-adapter initialization.
 */
function elementor_mcp_register_ability_hooks(): bool
{
    if (!elementor_mcp_wordpress_abilities_supported()) {
        return false;
    }

    add_action('wp_abilities_api_categories_init', callback: 'elementor_mcp_register_ability_categories', priority: 20);
    add_action('wp_abilities_api_init', callback: 'elementor_mcp_register_builtin_abilities', priority: 20);

    return true;
}

/**
 * Keep policy enforcement active even when Elementor MCP is disabled so independently registered skill
 * or extension Abilities are removed according to the existing rules.
 */
function elementor_mcp_register_ability_policy_hook(): bool
{
    if (!elementor_mcp_wordpress_abilities_supported()) {
        return false;
    }

    add_action('wp_abilities_api_init', callback: 'elementor_mcp_apply_ability_policy', priority: PHP_INT_MAX);

    return true;
}

/**
 * Register categories owned by Elementor MCP's built-in Abilities.
 */
function elementor_mcp_register_ability_categories(): void
{
    if (!elementor_mcp_is_wordpress_org_edition()) {
        wp_register_ability_category('code-execution', [
            'label' => __('Code Execution', domain: 'elementor-mcp'),
            'description' => __('Abilities that execute code on the WordPress server.', domain: 'elementor-mcp'),
        ]);

        wp_register_ability_category('filesystem', [
            'label' => __('Filesystem', domain: 'elementor-mcp'),
            'description' => __('Server filesystem operations.', domain: 'elementor-mcp'),
        ]);

        wp_register_ability_category('admin-access', [
            'label' => __('Admin Access', domain: 'elementor-mcp'),
            'description' => __('Temporary browser access to WordPress admin.', domain: 'elementor-mcp'),
        ]);
    }

    if (!wp_has_ability_category('mcp-adapter')) {
        wp_register_ability_category('mcp-adapter', [
            'label' => __('MCP Adapter', domain: 'elementor-mcp'),
            'description' => __('Meta-abilities for MCP protocol bridging.', domain: 'elementor-mcp'),
        ]);
    }

    elementor_mcp_register_wordpress_ability_category();
    elementor_mcp_register_elementor_ability_category();

    wp_register_ability_category('changes', [
        'label' => __('Changes', domain: 'elementor-mcp'),
        'description' => __('Redacted change history and verified rollback operations.', domain: 'elementor-mcp'),
    ]);

    wp_register_ability_category('diagnostics', [
        'label' => __('Diagnostics', domain: 'elementor-mcp'),
        'description' => __(
            'Read-only operational, performance, security, and integration diagnostics.',
            domain: 'elementor-mcp',
        ),
    ]);

    wp_register_ability_category('preview', [
        'label' => __('Preview', domain: 'elementor-mcp'),
        'description' => __(
            'Compute what a write would change without performing it, then apply the reviewed result.',
            domain: 'elementor-mcp',
        ),
    ]);
}

/**
 * Register every built-in Ability. The optional adapter may consume these registrations later but
 * is not a prerequisite for them.
 */
function elementor_mcp_register_builtin_abilities(): void
{
    $dir = __DIR__ . '/';
    if (!elementor_mcp_is_wordpress_org_edition()) {
        require_once $dir . 'execute-php.php';
        require_once $dir . 'read-file.php';
        require_once $dir . 'write-file.php';
        require_once $dir . 'edit-file.php';
        require_once $dir . 'delete-file.php';
        require_once $dir . 'create-upload-link.php';
        require_once $dir . 'create-admin-access-link.php';
        require_once $dir . 'disable-file.php';
        require_once $dir . 'enable-file.php';
        require_once $dir . 'list-directory.php';
        require_once $dir . 'run-wp-cli.php';
    }
    require_once $dir . 'discover-abilities.php';
    require_once $dir . 'agent-context.php';
    require_once $dir . 'change-log.php';
    require_once $dir . 'diagnostics.php';
    require_once $dir . 'preview.php';
    elementor_mcp_load_wordpress_abilities();
    elementor_mcp_load_elementor_abilities();
}

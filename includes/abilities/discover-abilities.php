<?php

// SPDX-FileCopyrightText: 2026 Elementor MCP <dev@elementormcp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * Ability: Discover Abilities (Elementor MCP replacement).
 *
 * Replaces the MCP Adapter's bundled discover-abilities tool so the response
 * includes Elementor MCP's environment/usage instructions alongside the list of
 * abilities. These used to be sent via the MCP initialize handshake's
 * server_description, but some clients drop that; returning them here
 * guarantees the agent sees them on first tool discovery.
 */

if (!defined('ABSPATH')) {
    exit();
}

$elementor_mcp_existing_ability = wp_has_ability('mcp-adapter/discover-abilities')
    ? wp_get_ability('mcp-adapter/discover-abilities')
    : null;
if ($elementor_mcp_existing_ability !== null) {
    wp_unregister_ability('mcp-adapter/discover-abilities');
}

if (wp_has_ability('mcp-adapter/discover-abilities')) {
    return;
}

wp_register_ability('mcp-adapter/discover-abilities', [
    'label' => __('Discover Abilities', domain: 'elementor-mcp'),
    'description' => __(
        'Discover all available WordPress abilities in the system. Returns a list of all registered abilities with their basic information, plus Elementor MCP environment instructions.',
        domain: 'elementor-mcp',
    ),
    'category' => 'mcp-adapter',
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'elementor_mcp_instructions' => [
                'type' => 'string',
                'description' => 'Elementor MCP environment and usage guidance for the agent.',
            ],
            'abilities' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string'],
                        'label' => ['type' => 'string'],
                        'description' => ['type' => 'string'],
                    ],
                    'required' => ['name', 'label', 'description'],
                ],
            ],
        ],
        'required' => ['elementor_mcp_instructions', 'abilities'],
    ],
    'permission_callback' => static function (): bool|\WP_Error {
        if (!is_user_logged_in()) {
            return new \WP_Error('authentication_required', 'User must be authenticated to access this ability');
        }
        /** @var string $cap */
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- deliberately in the mcp_adapter_* filter family beside the adapter's own hooks; consumers already attach to this name.
        $cap = apply_filters('mcp_adapter_discover_abilities_capability', value: 'read');
        if (!current_user_can($cap)) {
            return new \WP_Error('insufficient_capability', sprintf('User lacks required capability: %s', $cap));
        }
        return true;
    },
    'execute_callback' => static function (): array {
        $ability_list = [];
        foreach (wp_get_abilities() as $ability) {
            $meta = $ability->get_meta();
            if (!($meta['mcp']['public'] ?? false)) {
                continue;
            }
            if (($meta['mcp']['type'] ?? 'tool') !== 'tool') {
                continue;
            }
            $ability_list[] = [
                'name' => $ability->get_name(),
                'label' => $ability->get_label(),
                'description' => $ability->get_description(),
            ];
        }

        $instructions = '';
        if (elementor_mcp_current_user_can_manage()) {
            $instructions = (string) apply_filters(
                'elementor_mcp_discover_abilities_instructions',
                elementor_mcp_build_server_instructions(),
            );
        }

        return [
            'elementor_mcp_instructions' => $instructions,
            'abilities' => $ability_list,
        ];
    },
    'meta' => [
        'annotations' => [
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
        'mcp' => [
            'public' => true,
            'type' => 'tool',
        ],
    ],
]);

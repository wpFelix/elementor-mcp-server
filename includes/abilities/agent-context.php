<?php

// SPDX-FileCopyrightText: 2026 Elementor MCP <dev@elementormcp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('elementor-mcp/agent-context', [
    'label' => __('Agent Context', domain: 'elementor-mcp'),
    'description' => __(
        'Return transport-neutral Elementor MCP site guidance, environment, and skill summaries.',
        domain: 'elementor-mcp',
    ),
    'input_schema' => ELEMENTOR_MCP_NO_INPUT_SCHEMA,
    'category' => 'code-execution',
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'server' => ['type' => 'object'],
            'instructions' => ['type' => 'string'],
            'skills' => ['type' => 'array', 'items' => ['type' => 'object']],
            'environment' => ['type' => 'object'],
        ],
        'required' => ['server', 'instructions', 'skills', 'environment'],
    ],
    'execute_callback' => 'elementor_mcp_build_agent_context',
    'permission_callback' => 'elementor_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'annotations' => [
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
        'mcp' => ['public' => true, 'type' => 'tool'],
    ],
]);

/**
 * Reuse the same instruction and skill source/filter pipeline as existing integrations.
 *
 * @return array{
 *     server: array<string, mixed>,
 *     instructions: string,
 *     skills: list<array{slug: string, description: string, source: string}>,
 *     environment: array{wordpress_version: string, php_version: string, locale: string}
 * }
 */
function elementor_mcp_build_agent_context(): array
{
    $instructions = (string) apply_filters(
        'elementor_mcp_discover_abilities_instructions',
        elementor_mcp_build_server_instructions(),
    );

    $skills = [];
    foreach (\ElementorMCP\Skills\Sources\discoverable('agentic') as $skill) {
        $skills[] = [
            'slug' => (string) ($skill['slug'] ?? ''),
            'description' => (string) ($skill['description'] ?? ''),
            'source' => (string) ($skill['source'] ?? ''),
        ];
    }

    return [
        'server' => elementor_mcp_server_compatibility(),
        'instructions' => $instructions,
        'skills' => $skills,
        'environment' => [
            'wordpress_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
            'locale' => get_locale(),
        ],
    ];
}

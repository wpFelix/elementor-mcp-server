<?php

// SPDX-FileCopyrightText: 2026 Elementor MCP <dev@elementormcp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace ElementorMCP\Design\Abilities\Delete;

use WP_Error;
use ElementorMCP\Design\Abilities;
use ElementorMCP\Design\Parser;
use ElementorMCP\Design\Store;

if (!defined('ABSPATH')) {
    exit();
}

function register(): void
{
    if (!function_exists('wp_register_ability')) {
        return;
    }

    wp_register_ability('elementor-mcp/delete-design', [
        'label' => __('Delete Design', domain: 'elementor-mcp'),
        'description' => __(
            'Delete a saved design system by slug. If the deleted design was active, the site is left with no active design.',
            domain: 'elementor-mcp',
        ),
        'category' => Abilities\CATEGORY,
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'slug' => [
                    'type' => 'string',
                    'description' => 'Slug of the design to delete.',
                ],
            ],
            'required' => ['slug'],
        ],
        'output_schema' => [
            'type' => 'object',
            'properties' => [
                'deleted' => ['type' => 'boolean'],
                'slug' => ['type' => 'string'],
                'was_active' => ['type' => 'boolean'],
            ],
            'required' => ['deleted'],
        ],
        'execute_callback' => static function (array $input): array|WP_Error {
            $slug = Parser\normalize_slug((string) ($input['slug'] ?? ''));
            if ($slug === '') {
                return new WP_Error('missing_slug', __('A slug is required.', domain: 'elementor-mcp'));
            }

            $result = Store\delete($slug);
            if (!$result['deleted']) {
                return new WP_Error('unknown_design', __('No saved design with that slug.', domain: 'elementor-mcp'));
            }

            return [
                'deleted' => true,
                'slug' => $slug,
                'was_active' => $result['was_active'],
            ];
        },
        'permission_callback' => 'elementor_mcp_permission_callback',
        'meta' => [
            'annotations' => [
                'readonly' => false,
                'destructive' => true,
                'idempotent' => false,
            ],
            'mcp' => ['public' => true, 'type' => 'tool'],
        ],
    ]);
}

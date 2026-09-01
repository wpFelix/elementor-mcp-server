<?php

// SPDX-FileCopyrightText: 2026 Elementor MCP <dev@elementormcp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace ElementorMCP\Design\Abilities\GetActive;

use ElementorMCP\Design\Abilities;
use ElementorMCP\Design\Contract;
use ElementorMCP\Design\Library;
use ElementorMCP\Design\Store;

if (!defined('ABSPATH')) {
    exit();
}

function register(): void
{
    if (!function_exists('wp_register_ability')) {
        return;
    }

    wp_register_ability('elementor-mcp/get-active-design', [
        'label' => __('Get Active Design', domain: 'elementor-mcp'),
        'description' => __(
            'Return the active site design system as raw DESIGN.md plus structured tokens, dials, guidance, provenance, and readiness. Read this before visual work and build within the returned contract.',
            domain: 'elementor-mcp',
        ),
        'category' => Abilities\CATEGORY,
        'input_schema' => [
            'type' => 'object',
            'default' => [],
            'properties' => new \stdClass(),
        ],
        'output_schema' => [
            'type' => 'object',
            'properties' => array_merge([
                'active' => ['type' => 'boolean'],
                'slug' => ['type' => 'string'],
                'name' => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'content' => ['type' => 'string'],
            ], Contract\ability_output_properties()),
            'required' => ['active'],
        ],
        'execute_callback' => static function (array $input): array {
            $slug = Store\get_active_slug();
            if ($slug === '') {
                return ['active' => false];
            }
            $record = Library\find($slug);
            if ($record === null) {
                return ['active' => false];
            }
            return array_merge([
                'active' => true,
                'slug' => $record['slug'],
                'name' => $record['name'],
                'description' => $record['description'],
                'content' => $record['content'],
            ], Contract\inspect($record['content']));
        },
        'permission_callback' => 'elementor_mcp_permission_callback',
        'meta' => [
            'annotations' => [
                'readonly' => true,
                'destructive' => false,
                'idempotent' => true,
            ],
            'mcp' => ['public' => true, 'type' => 'tool'],
        ],
    ]);
}

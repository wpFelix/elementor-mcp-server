<?php

// SPDX-FileCopyrightText: 2026 Elementor MCP <dev@elementormcp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace ElementorMCP\Design\Abilities\Save;

use WP_Error;
use ElementorMCP\Design\Abilities;
use ElementorMCP\Design\Contract;
use ElementorMCP\Design\Distinct;
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

    wp_register_ability('elementor-mcp/save-design', [
        'label' => __('Save Design', domain: 'elementor-mcp'),
        'description' => __(
            'Create or update a design system from raw DESIGN.md and return its structured contract. Incomplete new documents are saved as drafts but not activated; an incomplete write never overwrites the active design.',
            domain: 'elementor-mcp',
        ),
        'category' => Abilities\CATEGORY,
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'content' => [
                    'type' => 'string',
                    'description' => 'The full DESIGN.md document.',
                ],
                'slug' => [
                    'type' => 'string',
                    'description' => 'Optional explicit slug; defaults to a slug derived from the name.',
                ],
                'activate' => [
                    'type' => 'boolean',
                    'description' => 'If true, activate after saving only when readiness.ready is true.',
                ],
            ],
            'required' => ['content'],
        ],
        'output_schema' => [
            'type' => 'object',
            'properties' => array_merge([
                'saved' => ['type' => 'boolean'],
                'slug' => ['type' => 'string'],
                'name' => ['type' => 'string'],
                'activated' => ['type' => 'boolean'],
                'activation_blocked' => ['type' => 'boolean'],
            ], Contract\ability_output_properties()),
            'required' => ['saved'],
        ],
        'execute_callback' => __NAMESPACE__ . '\\execute',
        'permission_callback' => 'elementor_mcp_permission_callback',
        'meta' => [
            'annotations' => [
                'readonly' => false,
                'destructive' => false,
                'idempotent' => false,
            ],
            'mcp' => ['public' => true, 'type' => 'tool'],
        ],
    ]);
}

function execute(array $input): array|WP_Error
{
    $content = Parser\unescape_content((string) ($input['content'] ?? ''));
    if (strlen($content) > Parser\MAX_BYTES) {
        return new WP_Error('too_large', __('DESIGN.md exceeds the size limit.', domain: 'elementor-mcp'));
    }
    if (!Parser\is_valid($content)) {
        return new WP_Error('invalid_design', __(
            'Content is not a valid DESIGN.md (could not find a name — add YAML front matter or a # heading).',
            domain: 'elementor-mcp',
        ));
    }

    $inspection = Contract\inspect($content);
    $slug_input = ($input['slug'] ?? null) !== null ? (string) $input['slug'] : null;
    $parsed = Parser\parse($content);
    $prospective_slug = Parser\normalize_slug(
        $slug_input !== null && $slug_input !== '' ? $slug_input : $parsed['name'],
    );
    if (Store\get_active_slug() === $prospective_slug && !$inspection['readiness']['ready']) {
        return new WP_Error(
            'active_design_not_ready',
            __('The active design was not overwritten because the replacement is incomplete. ', domain: 'elementor-mcp')
                . Contract\activation_error($inspection),
        );
    }

    $result = Store\save($content, $slug_input, actor: 'agent');
    if ($result['slug'] === '') {
        return new WP_Error('no_slug', __('Could not derive a slug.', domain: 'elementor-mcp'));
    }

    $activate_requested = filter_var($input['activate'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $activate = $activate_requested && $inspection['readiness']['ready'];
    if ($activate) {
        Store\set_active($result['slug']);
    }

    // Whether this design is anything new. The contract above says the document
    // is complete and internally consistent; it cannot say that this is the
    // fourth site this quarter with a navy ground and the same two faces. That
    // only shows up against the rest of the library, and the moment a design is
    // proposed is the one moment somebody can still change their mind about it.
    $distinct = Distinct\check($content, $result['slug']);

    return array_merge([
        'saved' => true,
        'slug' => $result['slug'],
        'name' => $result['name'],
        'activated' => $activate,
        'activation_blocked' => $activate_requested && !$activate,
        'distinctiveness' => [
            'distinct' => $distinct['distinct'],
            'compared_against' => $distinct['compared'],
            'nearest' => $distinct['nearest'],
            'findings' => $distinct['findings'],
        ],
    ], $inspection);
}

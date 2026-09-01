<?php

// SPDX-FileCopyrightText: 2026 Elementor MCP <dev@elementormcp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('elementor-mcp/list-changes', [
    'label' => __('List Elementor MCP Changes', domain: 'elementor-mcp'),
    'description' => __(
        'Lists the verified Elementor MCP change ledger newest first, including ability, actor, the agent credential and client behind the write, risk, timing, rollback availability, and rollback state. Secrets are redacted at write time.',
        domain: 'elementor-mcp',
    ),
    'category' => 'changes',
    'input_schema' => [
        'type' => 'object',
        'default' => [],
        'properties' => ['limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50]],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => static function (array $input): array {
        $limit = min(200, max(1, (int) ($input['limit'] ?? 50)));
        $rows = array_slice(array: array_reverse(elementor_mcp_get_change_log()), offset: 0, length: $limit);
        return ['items' => array_map('elementor_mcp_change_public_summary', $rows), 'count' => count($rows)];
    },
    'permission_callback' => 'elementor_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

wp_register_ability('elementor-mcp/get-change', [
    'label' => __('Get Elementor MCP Change', domain: 'elementor-mcp'),
    'description' => __(
        'Returns one redacted Elementor MCP change record with rollback eligibility and verification details.',
        domain: 'elementor-mcp',
    ),
    'category' => 'changes',
    'input_schema' => [
        'type' => 'object',
        'properties' => ['change_id' => ['type' => 'string']],
        'required' => ['change_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => static function (array $input): array|WP_Error {
        $entry = elementor_mcp_get_change((string) $input['change_id']);
        return $entry ?? new WP_Error('elementor_mcp_change_not_found', __('Change record not found.', domain: 'elementor-mcp'));
    },
    'permission_callback' => 'elementor_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

wp_register_ability('elementor-mcp/rollback-change', [
    'label' => __('Rollback Elementor MCP Change', domain: 'elementor-mcp'),
    'description' => __(
        'Rolls back a reversible change and verifies the observed state against the stored before-image fingerprint. It never marks success when writes fail or verification differs. Requires explicit confirmation.',
        domain: 'elementor-mcp',
    ),
    'category' => 'changes',
    'input_schema' => [
        'type' => 'object',
        'properties' => ['change_id' => ['type' => 'string']],
        'required' => ['change_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => static fn(array $input): array|WP_Error => elementor_mcp_rollback_change(
        (string) $input['change_id'],
    ),
    'permission_callback' => 'elementor_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

/** @param array<string, mixed> $entry @return array<string, mixed> */
function elementor_mcp_change_public_summary(array $entry): array
{
    $rollback = is_array($entry['rollback'] ?? null) ? $entry['rollback'] : [];
    return [
        'id' => (string) ($entry['id'] ?? ''),
        'ability' => (string) ($entry['ability'] ?? ''),
        'risk' => (string) ($entry['risk'] ?? ''),
        'recorded_at' => (string) ($entry['recorded_at'] ?? ''),
        // @mago-expect analysis:invalid-type-cast -- Ledger durations are stored internally as floats.
        'duration_ms' => (float) ($entry['duration_ms'] ?? 0),
        'user' => $entry['user'] ?? [],
        'agent' => $entry['agent'] ?? [],
        'reversible' => ($rollback['reversible'] ?? false) === true,
        'rollback_reason' => (string) ($rollback['reason'] ?? ''),
        'rolled_back' => ($entry['rolled_back'] ?? false) === true,
        // Only present when the design gate found something. Omitted rather
        // than reported empty, so a clean ledger stays readable.
        'design' => is_array($entry['design'] ?? null) && $entry['design'] !== [] ? $entry['design'] : null,
    ];
}

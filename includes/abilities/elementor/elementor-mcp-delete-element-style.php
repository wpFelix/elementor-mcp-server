<?php

// SPDX-FileCopyrightText: 2026 Elementor MCP <dev@elementormcp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace ElementorMCP\Abilities\Elementor;

/**
 * Ability: Remove a single per-element style entry from an atomic element.
 *
 * Surgical tool for the case where only some style entries on an element
 * are bad (or are no longer wanted) and the caller does not want to
 * rewrite the full styles map via edit-element. To clear every style on
 * an element, use elementor-mcp/elementor-edit-element with replace=true and
 * styles={} instead.
 */

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('elementor-mcp/elementor-delete-element-style', [
    'label' => __('Delete Elementor Element Style', domain: 'elementor-mcp'),
    'description' => __(
        'Removes a single per-element style entry (identified by style_id) from an atomic v4 element. Surgical tool for pruning one style without rewriting the full styles map. Use elementor-mcp/elementor-edit-element with replace=true and styles={} if you want to clear every style on the element. Idempotent: deleting a style_id that is not present returns success with deleted=false.',
        domain: 'elementor-mcp',
    ),
    'category' => 'elementor',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'element_id' => ['type' => 'string'],
            'style_id' => [
                'type' => 'string',
                'description' => 'The id of the style entry to remove from the element\'s styles map.',
            ],
        ],
        'required' => ['post_id', 'element_id', 'style_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'deleted' => [
                'type' => 'boolean',
                'description' => 'True when the style was present and removed. False when the style_id was not on the element (idempotent no-op).',
            ],
            'error' => ['type' => 'string'],
        ],
        'required' => ['success'],
    ],
    'execute_callback' => 'ElementorMCP\Abilities\Elementor\elementor_delete_element_style',
    'permission_callback' => 'elementor_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true, 'type' => 'tool'],
        'annotations' => [
            'readonly' => false,
            'destructive' => true,
            'idempotent' => true,
        ],
    ],
]);

/**
 * @param array<string, mixed> $input
 * @return array{success: bool, deleted?: bool, error?: string}
 */
function elementor_delete_element_style(array $input): array
{
    $parsed = des_parse_input($input);
    if (array_key_exists('error', $parsed)) {
        return ['success' => false, 'error' => $parsed['error']];
    }

    /** @var array{post_id: int, element_id: string, style_id: string} $parsed */
    [$elements, $read_error] = el_read_page($parsed['post_id']);
    if ($elements === null) {
        return ['success' => false, 'error' => $read_error ?? 'Unknown error.'];
    }

    $target = el_find($elements, $parsed['element_id']);
    if ($target === null) {
        return [
            'success' => false,
            'error' => "Element '{$parsed['element_id']}' not found on post {$parsed['post_id']}.",
        ];
    }

    $guard = des_atomic_guard($target);
    if ($guard !== null) {
        return $guard;
    }

    /** @var array<string, mixed> $existing */
    $existing = is_array($target['styles'] ?? null) ? $target['styles'] : [];
    if (!array_key_exists($parsed['style_id'], $existing)) {
        return ['success' => true, 'deleted' => false];
    }

    el_mutate($elements, $parsed['element_id'], des_build_remove_mutator($parsed['style_id']));

    $write = el_write_page($parsed['post_id'], $elements);
    if (is_wp_error($write)) {
        return ['success' => false, 'error' => $write->get_error_message()];
    }

    return ['success' => true, 'deleted' => true];
}

/**
 * Parse and validate the raw RPC input for delete-element-style.
 *
 * @param array<string, mixed> $input
 * @return array{error: string}|array{post_id: int, element_id: string, style_id: string}
 */
function des_parse_input(array $input): array
{
    if (!class_exists('Elementor\\Plugin')) {
        return ['error' => 'Elementor is not active.'];
    }

    $post_id = (int) ($input['post_id'] ?? 0);
    if ($post_id <= 0 || !get_post($post_id)) {
        return ['error' => "Post {$post_id} not found."];
    }

    $element_id = (string) ($input['element_id'] ?? '');
    if ($element_id === '') {
        return ['error' => 'Parameter "element_id" is required.'];
    }

    $style_id = (string) ($input['style_id'] ?? '');
    if ($style_id === '') {
        return ['error' => 'Parameter "style_id" is required.'];
    }

    return ['post_id' => $post_id, 'element_id' => $element_id, 'style_id' => $style_id];
}

/**
 * Reject when the target element is not atomic — per-element styles are a
 * v4 concept. Returns the error response or null when the element is OK.
 *
 * @param array<string, mixed> $target
 * @return array{success: false, error: string}|null
 */
function des_atomic_guard(array $target): ?array
{
    $widget_type = el_element_widget_type($target);
    $schema = $widget_type === '' ? null : el_extract_schema($widget_type);
    if (!is_array($schema) || ($schema['is_atomic'] ?? false) !== true) {
        return [
            'success' => false,
            'error' => 'Per-element styles are a v4 atomic feature — the target element is not an atomic widget or atomic container.',
        ];
    }
    return null;
}

/**
 * Build the mutator that drops a single entry from the element's styles map.
 *
 * @return callable(array<string, mixed>): array<string, mixed>
 */
function des_build_remove_mutator(string $style_id): callable
{
    return static function (array $element) use ($style_id): array {
        /** @var array<string, mixed> $element */
        /** @var array<string, mixed> $styles_map */
        $styles_map = is_array($element['styles'] ?? null) ? $element['styles'] : [];
        unset($styles_map[$style_id]);
        $element['styles'] = $styles_map;
        return $element;
    };
}

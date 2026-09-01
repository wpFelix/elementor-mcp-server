<?php

// SPDX-FileCopyrightText: 2026 Elementor MCP <dev@elementormcp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace ElementorMCP\Abilities\Elementor;

/**
 * Abilities: Elementor v3 Global Styles — list / CRUD on Global Colors and
 * Global Typography stored in the active Elementor Kit. Each operation is its
 * own ability so input schemas stay sharp. The gsv3_* helpers below host the
 * actual logic.
 */

if (!defined('ABSPATH')) {
    exit();
}

const ELEMENTOR_MCP_GSV3_DIMENSION_SCHEMA = [
    'type' => 'object',
    'properties' => [
        'size' => [
            'type' => ['number', 'string'],
            'description' => 'Numeric value for standard units; raw CSS expression string (e.g. "clamp(1rem, 2vw, 2.5rem)") when unit is "custom".',
        ],
        'unit' => [
            'type' => 'string',
            'description' => 'Length unit. Standard: "px", "em", "rem", "vw", "vh", "%". Special: "custom" — pairs with size as a raw CSS string.',
        ],
    ],
];

wp_register_ability('elementor-mcp/elementor-list-v3-styles', [
    'label' => __('List Elementor v3 Global Styles', domain: 'elementor-mcp'),
    'description' => __(
        'Lists every Elementor v3 Global Color and Global Typography stored in the active Kit. Returns four arrays: system_colors, custom_colors, system_typography, custom_typography.',
        domain: 'elementor-mcp',
    ),
    'category' => 'elementor',
    'input_schema' => [
        'type' => 'object',
        'default' => [],
        'properties' => [],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'system_colors' => ['type' => 'array'],
            'custom_colors' => ['type' => 'array'],
            'system_typography' => ['type' => 'array'],
            'custom_typography' => ['type' => 'array'],
            'error' => ['type' => 'string'],
        ],
        'required' => ['success'],
    ],
    'execute_callback' => 'ElementorMCP\Abilities\Elementor\list_v3_styles',
    'permission_callback' => 'elementor_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true, 'type' => 'tool'],
        'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

wp_register_ability('elementor-mcp/elementor-create-v3-color', [
    'label' => __('Create Elementor v3 Global Color', domain: 'elementor-mcp'),
    'description' => __(
        'Creates a new custom Global Color in the active Elementor v3 Kit. Provide title and color (hex).',
        domain: 'elementor-mcp',
    ),
    'category' => 'elementor',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'title' => ['type' => 'string', 'description' => 'Display name for the color.'],
            'color' => ['type' => 'string', 'description' => 'Hex color value (e.g. "#ff0000").'],
        ],
        'required' => ['title', 'color'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'color' => ['type' => 'object'],
            'error' => ['type' => 'string'],
        ],
        'required' => ['success'],
    ],
    'execute_callback' => 'ElementorMCP\Abilities\Elementor\create_v3_color',
    'permission_callback' => 'elementor_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true, 'type' => 'tool'],
        'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);

wp_register_ability('elementor-mcp/elementor-edit-v3-color', [
    'label' => __('Edit Elementor v3 Global Color', domain: 'elementor-mcp'),
    'description' => __(
        'Edits an existing v3 Global Color in place. Pass color_id and any of title or color (hex) to update. The color id is preserved.',
        domain: 'elementor-mcp',
    ),
    'category' => 'elementor',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'color_id' => ['type' => 'string', 'description' => 'The _id of the global color to edit.'],
            'title' => ['type' => 'string', 'description' => 'New display name. Optional.'],
            'color' => ['type' => 'string', 'description' => 'New hex color value. Optional.'],
        ],
        'required' => ['color_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'color' => ['type' => 'object'],
            'error' => ['type' => 'string'],
        ],
        'required' => ['success'],
    ],
    'execute_callback' => 'ElementorMCP\Abilities\Elementor\edit_v3_color',
    'permission_callback' => 'elementor_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true, 'type' => 'tool'],
        'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => true],
    ],
]);

wp_register_ability('elementor-mcp/elementor-delete-v3-color', [
    'label' => __('Delete Elementor v3 Global Color', domain: 'elementor-mcp'),
    'description' => __(
        'Deletes a v3 Global Color from the active Kit by _id. System colors cannot be deleted.',
        domain: 'elementor-mcp',
    ),
    'category' => 'elementor',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'color_id' => ['type' => 'string', 'description' => 'The _id of the global color to delete.'],
        ],
        'required' => ['color_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'error' => ['type' => 'string'],
        ],
        'required' => ['success'],
    ],
    'execute_callback' => 'ElementorMCP\Abilities\Elementor\delete_v3_color',
    'permission_callback' => 'elementor_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true, 'type' => 'tool'],
        'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => true],
    ],
]);

wp_register_ability('elementor-mcp/elementor-create-v3-typography', [
    'label' => __('Create Elementor v3 Global Typography', domain: 'elementor-mcp'),
    'description' => __(
        'Creates a new custom Global Typography in the active Elementor v3 Kit. Provide title and any of font_family, font_size, font_weight, text_transform, font_style, text_decoration, line_height, letter_spacing. Size-based fields take {size, unit}: numeric size + standard unit (px/em/rem/vw/vh/%), OR string size + unit:"custom" for raw CSS like clamp(1rem, 2vw, 2.5rem).',
        domain: 'elementor-mcp',
    ),
    'category' => 'elementor',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'title' => ['type' => 'string', 'description' => 'Display name for the typography preset.'],
            'font_family' => ['type' => 'string', 'description' => 'Font family name (e.g. "Inter").'],
            'font_size' => ELEMENTOR_MCP_GSV3_DIMENSION_SCHEMA + ['description' => 'Font size {size, unit}.'],
            'font_weight' => ['type' => 'string', 'description' => 'Font weight (e.g. "400", "bold").'],
            'text_transform' => [
                'type' => 'string',
                'description' => 'Text transform (uppercase / lowercase / capitalize / none).',
            ],
            'font_style' => ['type' => 'string', 'description' => 'Font style (normal / italic / oblique).'],
            'text_decoration' => [
                'type' => 'string',
                'description' => 'Text decoration (none / underline / line-through).',
            ],
            'line_height' => ELEMENTOR_MCP_GSV3_DIMENSION_SCHEMA + ['description' => 'Line height {size, unit}.'],
            'letter_spacing' => ELEMENTOR_MCP_GSV3_DIMENSION_SCHEMA + ['description' => 'Letter spacing {size, unit}.'],
        ],
        'required' => ['title'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'typography' => ['type' => 'object'],
            'error' => ['type' => 'string'],
        ],
        'required' => ['success'],
    ],
    'execute_callback' => 'ElementorMCP\Abilities\Elementor\create_v3_typography',
    'permission_callback' => 'elementor_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true, 'type' => 'tool'],
        'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);

wp_register_ability('elementor-mcp/elementor-edit-v3-typography', [
    'label' => __('Edit Elementor v3 Global Typography', domain: 'elementor-mcp'),
    'description' => __(
        'Edits an existing v3 Global Typography preset in place. Pass typography_id and any of the typography fields you want to change. The id is preserved. Size-based fields take {size, unit}: numeric size + standard unit (px/em/rem/vw/vh/%), OR string size + unit:"custom" for raw CSS like clamp(1rem, 2vw, 2.5rem).',
        domain: 'elementor-mcp',
    ),
    'category' => 'elementor',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'typography_id' => ['type' => 'string', 'description' => 'The _id of the global typography to edit.'],
            'title' => ['type' => 'string', 'description' => 'New display name. Optional.'],
            'font_family' => ['type' => 'string'],
            'font_size' => ELEMENTOR_MCP_GSV3_DIMENSION_SCHEMA,
            'font_weight' => ['type' => 'string'],
            'text_transform' => ['type' => 'string'],
            'font_style' => ['type' => 'string'],
            'text_decoration' => ['type' => 'string'],
            'line_height' => ELEMENTOR_MCP_GSV3_DIMENSION_SCHEMA,
            'letter_spacing' => ELEMENTOR_MCP_GSV3_DIMENSION_SCHEMA,
        ],
        'required' => ['typography_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'typography' => ['type' => 'object'],
            'error' => ['type' => 'string'],
        ],
        'required' => ['success'],
    ],
    'execute_callback' => 'ElementorMCP\Abilities\Elementor\edit_v3_typography',
    'permission_callback' => 'elementor_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true, 'type' => 'tool'],
        'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => true],
    ],
]);

wp_register_ability('elementor-mcp/elementor-delete-v3-typography', [
    'label' => __('Delete Elementor v3 Global Typography', domain: 'elementor-mcp'),
    'description' => __(
        'Deletes a v3 Global Typography preset from the active Kit by _id. System typography presets cannot be deleted.',
        domain: 'elementor-mcp',
    ),
    'category' => 'elementor',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'typography_id' => ['type' => 'string', 'description' => 'The _id of the global typography to delete.'],
        ],
        'required' => ['typography_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'error' => ['type' => 'string'],
        ],
        'required' => ['success'],
    ],
    'execute_callback' => 'ElementorMCP\Abilities\Elementor\delete_v3_typography',
    'permission_callback' => 'elementor_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true, 'type' => 'tool'],
        'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => true],
    ],
]);

/**
 * Guard that ensures Elementor is loaded.
 *
 * @return array{success: false, error: string}|null
 */
function gsv3_runtime_guard(): ?array
{
    if (!class_exists('Elementor\\Plugin')) {
        return ['success' => false, 'error' => 'Elementor is not active.'];
    }
    return null;
}

/**
 * @return array<string, mixed>
 */
function list_v3_styles(): array
{
    $guard = gsv3_runtime_guard();
    return $guard ?? gsv3_list();
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function create_v3_color(array $input): array
{
    $guard = gsv3_runtime_guard();
    return $guard ?? gsv3_create_color($input);
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function edit_v3_color(array $input): array
{
    $guard = gsv3_runtime_guard();
    return $guard ?? gsv3_update_color($input);
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function delete_v3_color(array $input): array
{
    $guard = gsv3_runtime_guard();
    return $guard ?? gsv3_delete_color($input);
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function create_v3_typography(array $input): array
{
    $guard = gsv3_runtime_guard();
    return $guard ?? gsv3_create_typography($input);
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function edit_v3_typography(array $input): array
{
    $guard = gsv3_runtime_guard();
    return $guard ?? gsv3_update_typography($input);
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function delete_v3_typography(array $input): array
{
    $guard = gsv3_runtime_guard();
    return $guard ?? gsv3_delete_typography($input);
}

/**
 * Get the active Elementor Kit.
 *
 * @return \Elementor\Core\Kits\Documents\Kit
 */
function gsv3_get_kit(): \Elementor\Core\Kits\Documents\Kit
{
    /** @var \Elementor\Core\Kits\Documents\Kit $kit */
    return \Elementor\Plugin::$instance->kits_manager->get_active_kit();
}

/**
 * Persist a kit setting (replacing the whole setting) and refresh the caches.
 *
 * `update_settings()` persists via `array_replace_recursive` (Elementor core,
 * Document::update_settings), which MERGES a list setting by index — so it can
 * add or change an entry but CANNOT remove one: deleting the last color from
 * `custom_colors` leaves a shorter array that merges back onto the stored one
 * and the removed entry survives, while the ability still returns success. We
 * therefore set the value in memory and `save()` the full settings, which
 * replaces the kit's settings meta wholesale (create / edit are unaffected — a
 * replace is correct for them too). The CSS cache is cleared so the next render
 * regenerates the kit stylesheet without the deleted token.
 *
 * @param list<array<string, mixed>> $value
 */
function gsv3_persist_kit_setting(\Elementor\Core\Kits\Documents\Kit $kit, string $setting_key, array $value): void
{
    $kit->set_settings($setting_key, $value);
    $kit->save(['settings' => $kit->get_settings()]);
    \Elementor\Plugin::$instance->files_manager->clear_cache();
}

/**
 * Format a color entry for output.
 *
 * @param array<string, mixed> $item
 * @return array{id: string, title: string, color: string}
 */
function gsv3_format_color(array $item): array
{
    return [
        'id' => (string) ($item['_id'] ?? ''),
        'title' => (string) ($item['title'] ?? ''),
        'color' => (string) ($item['color'] ?? ''),
    ];
}

/**
 * Format a typography entry for output.
 *
 * @param array<string, mixed> $item
 * @return array<string, mixed>
 */
function gsv3_format_typography(array $item): array
{
    $result = [
        'id' => (string) ($item['_id'] ?? ''),
        'title' => (string) ($item['title'] ?? ''),
    ];

    $prefix = 'typography_';
    $fields = [
        'font_family',
        'font_size',
        'font_weight',
        'text_transform',
        'font_style',
        'text_decoration',
        'line_height',
        'letter_spacing',
        'word_spacing',
    ];

    foreach ($fields as $field) {
        $key = $prefix . $field;
        if (($item[$key] ?? null) !== null && $item[$key] !== '') {
            $result[$field] = $item[$key];
        }
    }

    return $result;
}

/**
 * List all global styles (system + custom colors and typography).
 *
 * @return array<string, mixed>
 */
function gsv3_list(): array
{
    $kit = gsv3_get_kit();

    /** @var list<array<string, mixed>> $system_colors */
    $system_colors = $kit->get_settings('system_colors') ?? [];
    /** @var list<array<string, mixed>> $custom_colors */
    $custom_colors = $kit->get_settings('custom_colors') ?? [];
    /** @var list<array<string, mixed>> $system_typography */
    $system_typography = $kit->get_settings('system_typography') ?? [];
    /** @var list<array<string, mixed>> $custom_typography */
    $custom_typography = $kit->get_settings('custom_typography') ?? [];

    return [
        'success' => true,
        'system_colors' => array_map('ElementorMCP\Abilities\Elementor\gsv3_format_color', $system_colors),
        'custom_colors' => array_map('ElementorMCP\Abilities\Elementor\gsv3_format_color', $custom_colors),
        'system_typography' => array_map('ElementorMCP\Abilities\Elementor\gsv3_format_typography', $system_typography),
        'custom_typography' => array_map('ElementorMCP\Abilities\Elementor\gsv3_format_typography', $custom_typography),
    ];
}

/**
 * Create a custom global color.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function gsv3_create_color(array $input): array
{
    $title = (string) ($input['title'] ?? '');
    if ($title === '') {
        return ['success' => false, 'error' => 'Parameter "title" is required for create_color.'];
    }

    $color = (string) ($input['color'] ?? '');
    if ($color === '') {
        return ['success' => false, 'error' => 'Parameter "color" is required for create_color.'];
    }

    $kit = gsv3_get_kit();

    /** @var list<array<string, mixed>> $custom_colors */
    $custom_colors = $kit->get_settings('custom_colors') ?? [];

    $new_id = bin2hex(random_bytes(4));
    $new_entry = [
        '_id' => $new_id,
        'title' => $title,
        'color' => $color,
    ];

    $custom_colors[] = $new_entry;
    gsv3_persist_kit_setting($kit, setting_key: 'custom_colors', value: $custom_colors);

    return [
        'success' => true,
        'color' => gsv3_format_color($new_entry),
    ];
}

/**
 * Create a custom global typography.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function gsv3_create_typography(array $input): array
{
    $title = (string) ($input['title'] ?? '');
    if ($title === '') {
        return ['success' => false, 'error' => 'Parameter "title" is required for create_typography.'];
    }

    $font_family = (string) ($input['font_family'] ?? '');
    if ($font_family === '') {
        return ['success' => false, 'error' => 'Parameter "font_family" is required for create_typography.'];
    }

    $kit = gsv3_get_kit();

    /** @var list<array<string, mixed>> $custom_typography */
    $custom_typography = $kit->get_settings('custom_typography') ?? [];

    $new_id = bin2hex(random_bytes(4));
    $new_entry = [
        '_id' => $new_id,
        'title' => $title,
        'typography_typography' => 'custom',
        'typography_font_family' => $font_family,
    ];

    gsv3_apply_typography_fields($new_entry, $input);

    $custom_typography[] = $new_entry;
    gsv3_persist_kit_setting($kit, setting_key: 'custom_typography', value: $custom_typography);

    return [
        'success' => true,
        'typography' => gsv3_format_typography($new_entry),
    ];
}

/**
 * Update an existing global color (system or custom).
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function gsv3_update_color(array $input): array
{
    $color_id = (string) ($input['color_id'] ?? '');
    if ($color_id === '') {
        return ['success' => false, 'error' => 'Parameter "color_id" is required for update_color.'];
    }

    $kit = gsv3_get_kit();

    // Try system colors first, then custom.
    foreach (['system_colors', 'custom_colors'] as $setting_key) {
        /** @var list<array<string, mixed>> $colors */
        $colors = $kit->get_settings($setting_key) ?? [];

        foreach ($colors as $index => $item) {
            if (($item['_id'] ?? '') !== $color_id) {
                continue;
            }

            if (($input['title'] ?? null) !== null) {
                $colors[$index]['title'] = (string) $input['title'];
            }
            if (($input['color'] ?? null) !== null) {
                $colors[$index]['color'] = (string) $input['color'];
            }

            gsv3_persist_kit_setting($kit, $setting_key, $colors);

            return [
                'success' => true,
                'color' => gsv3_format_color($colors[$index]),
            ];
        }
    }

    return ['success' => false, 'error' => "Color '{$color_id}' not found."];
}

/**
 * Update an existing global typography (system or custom).
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function gsv3_update_typography(array $input): array
{
    $typography_id = (string) ($input['typography_id'] ?? '');
    if ($typography_id === '') {
        return ['success' => false, 'error' => 'Parameter "typography_id" is required for update_typography.'];
    }

    $kit = gsv3_get_kit();

    foreach (['system_typography', 'custom_typography'] as $setting_key) {
        /** @var list<array<string, mixed>> $entries */
        $entries = $kit->get_settings($setting_key) ?? [];

        foreach ($entries as $index => $item) {
            if (($item['_id'] ?? '') !== $typography_id) {
                continue;
            }

            if (($input['title'] ?? null) !== null) {
                $entries[$index]['title'] = (string) $input['title'];
            }
            if (($input['font_family'] ?? null) !== null) {
                $entries[$index]['typography_font_family'] = (string) $input['font_family'];
                $entries[$index]['typography_typography'] = 'custom';
            }

            gsv3_apply_typography_fields($entries[$index], $input);

            gsv3_persist_kit_setting($kit, $setting_key, $entries);

            return [
                'success' => true,
                'typography' => gsv3_format_typography($entries[$index]),
            ];
        }
    }

    return ['success' => false, 'error' => "Typography '{$typography_id}' not found."];
}

/**
 * Delete a custom global color by ID.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function gsv3_delete_color(array $input): array
{
    $color_id = (string) ($input['color_id'] ?? '');
    if ($color_id === '') {
        return ['success' => false, 'error' => 'Parameter "color_id" is required for delete_color.'];
    }

    $kit = gsv3_get_kit();

    /** @var list<array<string, mixed>> $custom_colors */
    $custom_colors = $kit->get_settings('custom_colors') ?? [];

    $filtered = array_values(array_filter(
        $custom_colors,
        static fn(array $item): bool => ($item['_id'] ?? '') !== $color_id,
    ));

    if (count($filtered) === count($custom_colors)) {
        return ['success' => false, 'error' => "Custom color '{$color_id}' not found."];
    }

    gsv3_persist_kit_setting($kit, setting_key: 'custom_colors', value: $filtered);

    return ['success' => true];
}

/**
 * Delete a custom global typography by ID.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function gsv3_delete_typography(array $input): array
{
    $typography_id = (string) ($input['typography_id'] ?? '');
    if ($typography_id === '') {
        return ['success' => false, 'error' => 'Parameter "typography_id" is required for delete_typography.'];
    }

    $kit = gsv3_get_kit();

    /** @var list<array<string, mixed>> $custom_typography */
    $custom_typography = $kit->get_settings('custom_typography') ?? [];

    $filtered = array_values(array_filter(
        $custom_typography,
        static fn(array $item): bool => ($item['_id'] ?? '') !== $typography_id,
    ));

    if (count($filtered) === count($custom_typography)) {
        return ['success' => false, 'error' => "Custom typography '{$typography_id}' not found."];
    }

    gsv3_persist_kit_setting($kit, setting_key: 'custom_typography', value: $filtered);

    return ['success' => true];
}

/**
 * Apply optional typography fields from input to an entry array.
 *
 * @param array<string, mixed> $entry  Entry array (modified by reference).
 * @param array<string, mixed> $input  Input parameters.
 */
function gsv3_apply_typography_fields(array &$entry, array $input): void
{
    if (($input['font_weight'] ?? null) !== null) {
        $entry['typography_font_weight'] = (string) $input['font_weight'];
    }
    if (($input['text_transform'] ?? null) !== null) {
        $entry['typography_text_transform'] = (string) $input['text_transform'];
    }
    if (($input['font_style'] ?? null) !== null) {
        $entry['typography_font_style'] = (string) $input['font_style'];
    }
    if (($input['text_decoration'] ?? null) !== null) {
        $entry['typography_text_decoration'] = (string) $input['text_decoration'];
    }

    // Size-based fields: font_size, line_height, letter_spacing.
    $size_fields = ['font_size', 'line_height', 'letter_spacing'];
    foreach ($size_fields as $field) {
        if (($input[$field] ?? null) === null || !is_array($input[$field])) {
            continue;
        }

        /** @var array{size?: float|int|string, unit?: string} $size_data */
        $size_data = $input[$field];
        $unit = $size_data['unit'] ?? 'px';
        $size = $size_data['size'] ?? 0;

        $entry['typography_' . $field] = [
            // Elementor stores the raw CSS expression in `size` (as string) when unit === 'custom'.
            'size' => $unit === 'custom' ? (string) $size : $size,
            'unit' => $unit,
        ];
    }
}

<?php

// SPDX-FileCopyrightText: 2026 Elementor MCP <dev@elementormcp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace ElementorMCP\Abilities\Elementor;

/**
 * Abilities: Elementor v4 Global Classes — list / create / update / delete /
 * apply. Each operation is registered as its own ability so input schemas
 * stay sharp and discovery via the abilities list is direct. The shared
 * helpers (gc_*) below host the actual logic; the ability callbacks are
 * thin shells that guard the runtime and delegate.
 */

if (!defined('ABSPATH')) {
    exit();
}

const ELEMENTOR_MCP_GLOBAL_CLASSES_REPO = 'Elementor\\Modules\\GlobalClasses\\Global_Classes_Repository';

// Breakpoint id for the base (unconditional) style variant. Elementor's own
// style-parser rejects a base variant whose meta.breakpoint is null
// ("missing_or_invalid_value"), and its atomic styles manager registers
// "desktop" as the canonical base breakpoint (DEFAULT_BREAKPOINT). Writing the
// base with breakpoint "desktop" (not null) is what makes the v4 editor treat
// the values as explicitly defined instead of inherited. The frontend renders
// either way because group_by_breakpoint() maps null -> "desktop", but the
// editor validates variants through the parser, which is why null must not ship.
const GC_BASE_BREAKPOINT = 'desktop';

wp_register_ability('elementor-mcp/elementor-list-global-classes', [
    'label' => __('List Elementor Global Classes', domain: 'elementor-mcp'),
    'description' => __(
        'Lists every Elementor v4 Global Class registered on this site. Returns each class\'s id, label, type, and style variants.',
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
            'classes' => ['type' => 'array'],
            'order' => ['type' => 'array'],
            'error' => ['type' => 'string'],
        ],
        'required' => ['success'],
    ],
    'execute_callback' => 'ElementorMCP\Abilities\Elementor\list_global_classes',
    'permission_callback' => 'elementor_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true, 'type' => 'tool'],
        'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

wp_register_ability('elementor-mcp/elementor-create-global-class', [
    'label' => __('Create Elementor Global Class', domain: 'elementor-mcp'),
    'description' => __(
        'Creates a new Elementor v4 Global Class. The "styles" payload is a map of CSS property name → value. Scalar values are auto-wrapped against the v4 Style Schema (e.g. color:"#111" → {$$type:"color",value:"#111"}; padding:24 → {$$type:"size",value:{size:24,unit:"px"}}; padding:{block-start:24, inline-end:24, ...} → wrapped dimensions); long-form `{$$type, value}` shapes also work. After wrapping, the payload is validated against the authoritative Style Schema and rejected fail-hard with the list of valid properties on any mismatch. Call elementor-mcp/elementor-get-style-schema to discover which CSS properties exist and their exact shapes. Example (ergonomic): {"color":"#111", "padding":{"block-start":24,"inline-end":24,"block-end":24,"inline-start":24}}. Returns the new class id on success.',
        domain: 'elementor-mcp',
    ),
    'category' => 'elementor',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'label' => [
                'type' => 'string',
                'description' => 'Label for the new global class.',
            ],
            'styles' => [
                'type' => 'object',
                'description' => 'CSS property → prop-value map for the base (default) variant. Scalars are auto-wrapped per the Style Schema (e.g. "color":"#111", "font-size":24, "padding":{block-start:24,...}); the long-form `{$$type, value}` shape also works. Use only valid top-level CSS property names — e.g. `background-color` is NOT valid, use the `background` shape with a nested `color` field. Call elementor-mcp/elementor-get-style-schema to discover valid names and shapes.',
                'additionalProperties' => true,
            ],
            'variants' => [
                'type' => 'array',
                'description' => 'Optional additional style variants for responsive breakpoints and/or states. Each variant is an object with `meta` ({breakpoint: string|null, state: string|null}) and `styles` (same shape as top-level styles). The base variant (breakpoint:"desktop", state:null) is created from the top-level `styles` parameter; pass breakpoint:null here for a base override and it is normalized to "desktop". Use this to add tablet/mobile overrides or hover/active/focus states. Common breakpoints: "tablet", "mobile". Common states: "hover", "active", "focus".',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'meta' => [
                            'type' => 'object',
                            'properties' => [
                                'breakpoint' => ['type' => ['string', 'null']],
                                'state' => ['type' => ['string', 'null']],
                            ],
                        ],
                        'styles' => ['type' => 'object', 'additionalProperties' => true],
                    ],
                    'required' => ['meta', 'styles'],
                ],
            ],
        ],
        'required' => ['label', 'styles'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'class' => ['type' => 'object'],
            'error' => ['type' => 'string'],
            'unknown_properties' => ['type' => 'array', 'items' => ['type' => 'string']],
            'invalid_values' => ['type' => 'object'],
        ],
        'required' => ['success'],
    ],
    'execute_callback' => 'ElementorMCP\Abilities\Elementor\create_global_class',
    'permission_callback' => 'elementor_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true, 'type' => 'tool'],
        'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);

wp_register_ability('elementor-mcp/elementor-edit-global-class', [
    'label' => __('Edit Elementor Global Class', domain: 'elementor-mcp'),
    'description' => __(
        'Edits an existing Elementor v4 Global Class in place. Pass class_id and at least one of label (rename) or styles (replace the BASE variant\'s CSS — tablet/mobile/widescreen/state variants of this class are preserved). The class id is preserved so widgets that already reference it keep their binding. The "styles" payload follows the same auto-wrap + fail-hard validation as create-global-class: scalar values are wrapped against the Style Schema (e.g. color:"#111", padding:24), or pass the long-form `{$$type, value}` shape. Call elementor-mcp/elementor-get-style-schema to discover valid property names and shapes.',
        domain: 'elementor-mcp',
    ),
    'category' => 'elementor',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'class_id' => [
                'type' => 'string',
                'description' => 'The id of the global class to update.',
            ],
            'label' => [
                'type' => 'string',
                'description' => 'New label. Optional — pass at least one of label or styles.',
            ],
            'styles' => [
                'type' => 'object',
                'description' => 'CSS property → prop-value map that REPLACES the BASE variant only (breakpoint "desktop", no state). Existing tablet/mobile/widescreen/hover variants are preserved. Scalars are auto-wrapped per Style Schema (e.g. "color":"#111"); the long-form `{$$type, value}` shape also works. Optional — pass at least one of label or styles.',
                'additionalProperties' => true,
            ],
        ],
        'required' => ['class_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'class' => ['type' => 'object'],
            'error' => ['type' => 'string'],
            'unknown_properties' => ['type' => 'array', 'items' => ['type' => 'string']],
            'invalid_values' => ['type' => 'object'],
        ],
        'required' => ['success'],
    ],
    'execute_callback' => 'ElementorMCP\Abilities\Elementor\edit_global_class',
    'permission_callback' => 'elementor_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true, 'type' => 'tool'],
        'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => true],
    ],
]);

wp_register_ability('elementor-mcp/elementor-delete-global-class', [
    'label' => __('Delete Elementor Global Class', domain: 'elementor-mcp'),
    'description' => __(
        'Deletes an Elementor v4 Global Class by id. Widgets that still reference it will lose the styling but will not break — Elementor ignores missing class references at render.',
        domain: 'elementor-mcp',
    ),
    'category' => 'elementor',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'class_id' => [
                'type' => 'string',
                'description' => 'The id of the global class to delete.',
            ],
        ],
        'required' => ['class_id'],
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
    'execute_callback' => 'ElementorMCP\Abilities\Elementor\delete_global_class',
    'permission_callback' => 'elementor_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true, 'type' => 'tool'],
        'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => true],
    ],
]);

wp_register_ability('elementor-mcp/elementor-apply-global-class', [
    'label' => __('Apply Elementor Global Class', domain: 'elementor-mcp'),
    'description' => __(
        'Attaches an existing Elementor v4 Global Class to an atomic widget on a page. Validation runs server-side: if the target element does not exist, is not an atomic widget, or does not expose a "classes" control the call fails HARD with the compact schema of the target widget INLINE in the error so you can correct and retry. Global Classes are a v4-only concept; v3 widgets cannot accept them.',
        domain: 'elementor-mcp',
    ),
    'category' => 'elementor',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'class_id' => ['type' => 'string', 'description' => 'The id of the global class to attach.'],
            'post_id' => ['type' => 'integer', 'description' => 'The page/post id where the target element lives.'],
            'element_id' => [
                'type' => 'string',
                'description' => 'The Elementor element id of the atomic widget to attach the class to.',
            ],
        ],
        'required' => ['class_id', 'post_id', 'element_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'classes' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'On success: the element\'s full post-apply classes list so the caller can confirm the binding.',
            ],
            'already_present' => [
                'type' => 'boolean',
                'description' => 'On success: true when the class was already on the element — the call is a no-op rather than a new binding.',
            ],
            'widget_type' => ['type' => 'string'],
            'dropped_keys' => ['type' => 'array', 'items' => ['type' => 'string']],
            'invalid_values' => ['type' => 'array'],
            'schema' => ['type' => 'object'],
            'error' => ['type' => 'string'],
        ],
        'required' => ['success'],
    ],
    'execute_callback' => 'ElementorMCP\Abilities\Elementor\apply_global_class',
    'permission_callback' => 'elementor_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true, 'type' => 'tool'],
        'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);

/**
 * Guard that ensures Elementor and the Global Classes module are loaded.
 * Returns null when the runtime is ready, or an error response otherwise.
 *
 * @return array{success: false, error: string}|null
 */
function gc_runtime_guard(): ?array
{
    if (!class_exists('Elementor\\Plugin')) {
        return ['success' => false, 'error' => 'Elementor is not active.'];
    }

    if (!class_exists(ELEMENTOR_MCP_GLOBAL_CLASSES_REPO)) {
        return [
            'success' => false,
            'error' => 'Elementor Global Classes module is not available. Requires Elementor v4.',
        ];
    }

    return null;
}

/**
 * @return array<string, mixed>
 */
function list_global_classes(): array
{
    $guard = gc_runtime_guard();
    return $guard ?? gc_list();
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function create_global_class(array $input): array
{
    $guard = gc_runtime_guard();
    return $guard ?? gc_create($input);
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function edit_global_class(array $input): array
{
    $guard = gc_runtime_guard();
    return $guard ?? gc_update($input);
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function delete_global_class(array $input): array
{
    $guard = gc_runtime_guard();
    return $guard ?? gc_delete($input);
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function apply_global_class(array $input): array
{
    $guard = gc_runtime_guard();
    return $guard ?? gc_apply($input);
}

/**
 * List all global classes.
 *
 * @return array{success: bool, classes: list<array<string, mixed>>, order: array<array-key, mixed>}
 */
function gc_list(): array
{
    $repo = \Elementor\Modules\GlobalClasses\Global_Classes_Repository::make();
    $all = $repo->all();

    /** @var array<string, array<string, mixed>> $items */
    $items = $all->get_items()->all();
    $order = $all->get_order()->all();

    $classes = [];
    foreach ($items as $id => $item) {
        $classes[] = [
            'id' => $id,
            'label' => $item['label'] ?? '',
            'type' => $item['type'] ?? 'class',
            'variants' => $item['variants'] ?? [],
        ];
    }

    return ['success' => true, 'classes' => $classes, 'order' => $order];
}

/**
 * Mirror the kit's frontend global-classes labels + order onto their preview
 * twins so an agent-created class is visible AND styled in the Elementor editor,
 * not only on the frontend. The v4 editor reads labels/order from the PREVIEW
 * context with NO fallback to frontend (only class DATA falls back), and a
 * frontend-context write (Elementor's publish flow) even clears the preview
 * labels — so without this every global class shows as a raw `gc-*` id with no
 * CSS in the editor canvas, while the frontend renders fine. Runs after every
 * create / edit / delete so the two contexts stay in sync.
 */
function gc_sync_preview_context(): void
{
    if (!class_exists(\Elementor\Plugin::class)) {
        return;
    }
    $kit_id = (int) \Elementor\Plugin::$instance->kits_manager->get_active_id();
    if ($kit_id <= 0) {
        return;
    }
    /** @var mixed $labels */
    $labels = get_post_meta($kit_id, key: '_elementor_global_classes_labels', single: true);
    /** @var mixed $order */
    $order = get_post_meta($kit_id, key: '_elementor_global_classes_order', single: true);
    update_post_meta($kit_id, meta_key: '_elementor_global_classes_labels_preview', meta_value: $labels);
    update_post_meta($kit_id, meta_key: '_elementor_global_classes_order_preview', meta_value: $order);
}

/**
 * Create a new global class.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function gc_create(array $input): array
{
    $label = (string) ($input['label'] ?? '');
    if ($label === '') {
        return ['success' => false, 'error' => 'Parameter "label" is required for create.'];
    }

    /** @var array<string, mixed> $styles */
    $styles = is_array($input['styles'] ?? null) ? $input['styles'] : [];
    if ($styles === []) {
        return ['success' => false, 'error' => 'Parameter "styles" is required for create.'];
    }

    $variants_result = gc_assemble_variants($styles, $input);
    if (array_key_exists('error', $variants_result)) {
        return ['success' => false] + $variants_result;
    }

    $all_variants = $variants_result['variants'] ?? [];

    // Generate a unique ID (Elementor uses this format).
    $class_id = 'gc-' . bin2hex(random_bytes(8));

    $new_item = [
        'id' => $class_id,
        'type' => 'class',
        'label' => $label,
        'variants' => $all_variants,
    ];

    $repo = \Elementor\Modules\GlobalClasses\Global_Classes_Repository::make();
    $all = $repo->all();

    $items = $all->get_items()->all();
    $order = $all->get_order()->all();

    $items[$class_id] = $new_item;
    $order[] = $class_id;

    try {
        $repo->put($items, $order);
        gc_sync_preview_context();
    } catch (\Exception $e) {
        return ['success' => false, 'error' => 'Failed to save global class: ' . $e->getMessage()];
    }

    return ['success' => true, 'class' => $new_item];
}

/**
 * Update an existing global class. The caller must provide `class_id` and at
 * least one of `label` (rename) or `styles` (replace the variant's CSS). The
 * existing class id is preserved so any widget already referencing it keeps
 * its binding.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function gc_update(array $input): array
{
    $class_id = (string) ($input['class_id'] ?? '');
    if ($class_id === '') {
        return ['success' => false, 'error' => 'Parameter "class_id" is required for update.'];
    }

    if (!array_key_exists('label', $input) && !array_key_exists('styles', $input)) {
        return [
            'success' => false,
            'error' => 'Update requires at least one of "label" (rename) or "styles" (replace CSS).',
        ];
    }

    $repo = \Elementor\Modules\GlobalClasses\Global_Classes_Repository::make();
    $all = $repo->all();

    /** @var array<string, array<string, mixed>> $items */
    $items = $all->get_items()->all();
    $order = $all->get_order()->all();

    if (($items[$class_id] ?? null) === null) {
        return ['success' => false, 'error' => "Global class '{$class_id}' not found."];
    }

    $update_result = gc_apply_update_overrides($items[$class_id], $input);
    if (array_key_exists('error', $update_result)) {
        return ['success' => false] + $update_result;
    }

    $updated = $update_result['item'] ?? $items[$class_id];
    $items[$class_id] = $updated;

    try {
        $repo->put($items, $order);
        gc_sync_preview_context();
    } catch (\Exception $e) {
        return ['success' => false, 'error' => 'Failed to save global class: ' . $e->getMessage()];
    }

    return ['success' => true, 'class' => $updated];
}

/**
 * Apply the optional `label` and `styles` overrides from an update payload to
 * an existing class item. Returns the merged item or an error message when
 * any override is malformed.
 *
 * @param array<string, mixed> $existing
 * @param array<string, mixed> $input
 * @return array{item: array<string, mixed>}|array{error: string, unknown_properties?: list<string>, invalid_values?: array<string, mixed>}
 */
function gc_apply_update_overrides(array $existing, array $input): array
{
    if (array_key_exists('label', $input)) {
        $label = (string) ($input['label'] ?? '');
        if ($label === '') {
            return ['error' => '"label" cannot be empty when updating.'];
        }
        $existing['label'] = $label;
    }

    if (array_key_exists('styles', $input)) {
        /** @var array<string, mixed> $styles */
        $styles = is_array($input['styles']) ? $input['styles'] : [];
        if ($styles === []) {
            return ['error' => '"styles" must be a non-empty object of CSS property/value pairs.'];
        }

        $validation = el_validate_style_props($styles);
        if (!array_key_exists('props', $validation)) {
            /** @var array{error: string, unknown_properties?: list<string>, invalid_values?: array<string, mixed>} $validation */
            return $validation;
        }

        $existing['variants'] = gc_replace_base_variant($existing['variants'] ?? null, $validation['props']);
    }

    return ['item' => $existing];
}

/**
 * Replace only the base variant (breakpoint null / desktop, no state) of an
 * existing `variants` list with a new base carrying the given props. Every
 * other variant (tablet, mobile, widescreen, hover states, etc.) is
 * preserved. When the existing class has no base variant yet the new one
 * is prepended.
 *
 * This is the edit-in-place semantic the ability advertises: "replaces
 * the existing variant" (singular, the base), NOT "wipes the variant
 * list".
 *
 * @param mixed $existing_variants Raw value of the class's `variants`.
 * @param array<string, mixed> $base_props
 * @return list<array<string, mixed>>
 */
function gc_replace_base_variant(mixed $existing_variants, array $base_props): array
{
    $new_base = [
        'meta' => ['breakpoint' => GC_BASE_BREAKPOINT, 'state' => null],
        'props' => $base_props,
    ];

    if (!is_array($existing_variants) || $existing_variants === []) {
        return [$new_base];
    }

    /** @var list<array<string, mixed>> $variants */
    $variants = array_values($existing_variants);

    $base_index = -1;
    foreach (array_keys($variants) as $i) {
        $variant = $variants[$i];
        if (!is_array($variant['meta'] ?? null)) {
            continue;
        }
        /** @var mixed $bp */
        $bp = $variant['meta']['breakpoint'] ?? null;
        /** @var mixed $state */
        $state = $variant['meta']['state'] ?? null;
        $is_base_breakpoint = $bp === null || $bp === 'desktop';
        $is_base_state = $state === null || $state === '';
        if ($is_base_breakpoint && $is_base_state) {
            $base_index = $i;
            break;
        }
    }

    if ($base_index === -1) {
        array_unshift($variants, $new_base);
        return $variants;
    }

    $variants[$base_index] = $new_base;
    return $variants;
}

/**
 * Validate the base styles and assemble all variants (base + optional extras).
 *
 * @param array<string, mixed> $styles    Base styles map.
 * @param array<string, mixed> $input     Full input (may contain `variants` key).
 * @return array{variants: list<array<string, mixed>>}|array{error: string, unknown_properties?: list<string>, invalid_values?: array<string, mixed>}
 */
function gc_assemble_variants(array $styles, array $input): array
{
    $validation = el_validate_style_props($styles);
    if (!array_key_exists('props', $validation)) {
        /** @var array{error: string, unknown_properties?: list<string>, invalid_values?: array<string, mixed>} $validation */
        return $validation;
    }

    $all = [
        ['meta' => ['breakpoint' => GC_BASE_BREAKPOINT, 'state' => null], 'props' => $validation['props']],
    ];

    /** @var list<array<string, mixed>> $extra_raw */
    $extra_raw = is_array($input['variants'] ?? null) ? $input['variants'] : [];
    $extra_result = gc_build_extra_variants($extra_raw);
    if (array_key_exists('error', $extra_result)) {
        return $extra_result;
    }

    $extra_built = $extra_result['variants'] ?? [];

    return ['variants' => array_merge($all, $extra_built)];
}

/**
 * Validate and build extra variants (responsive breakpoints, hover states)
 * from the caller's `variants` array. Each entry must have `meta` (with
 * `breakpoint` and/or `state`) and `styles` (validated against Style Schema).
 *
 * @param list<array<string, mixed>> $raw_variants
 * @return array{variants: list<array<string, mixed>>}|array{error: string, unknown_properties?: list<string>, invalid_values?: array<string, mixed>}
 */
function gc_build_extra_variants(array $raw_variants): array
{
    if ($raw_variants === []) {
        return ['variants' => []];
    }

    $built = [];
    foreach ($raw_variants as $i => $variant) {
        $parsed = gc_parse_single_variant($variant, $i);
        if (array_key_exists('error', $parsed)) {
            return $parsed;
        }
        $v = $parsed['variant'] ?? [];
        $built[] = $v;
    }

    return ['variants' => $built];
}

/**
 * Parse and validate a single variant entry from the caller's `variants` array.
 *
 * @param array<string, mixed> $variant
 * @return array{variant: array<string, mixed>}|array{error: string, unknown_properties?: list<string>, invalid_values?: array<string, mixed>}
 */
function gc_parse_single_variant(array $variant, int $index): array
{
    /** @var array<string, mixed>|null $meta */
    $meta = is_array($variant['meta'] ?? null) ? $variant['meta'] : null;
    if ($meta === null) {
        return ['error' => sprintf('variants[%d].meta is required (object with breakpoint and/or state).', $index)];
    }

    /** @var array<string, mixed> $styles */
    $styles = is_array($variant['styles'] ?? null) ? $variant['styles'] : [];
    if ($styles === []) {
        return ['error' => sprintf('variants[%d].styles must be a non-empty object.', $index)];
    }

    $validation = el_validate_style_props($styles);
    if (!array_key_exists('props', $validation)) {
        $err = $validation['error'] ?? 'Invalid style payload.';
        $validation['error'] = sprintf('variants[%d]: %s', $index, $err);
        /** @var array{error: string, unknown_properties?: list<string>, invalid_values?: array<string, mixed>} $validation */
        return $validation;
    }

    // A null/absent breakpoint means the base variant. Elementor rejects a null
    // breakpoint, so normalize it to the canonical base id "desktop".
    $breakpoint = is_string($meta['breakpoint'] ?? null) ? $meta['breakpoint'] : GC_BASE_BREAKPOINT;
    $state = is_string($meta['state'] ?? null) ? $meta['state'] : null;

    return [
        'variant' => [
            'meta' => ['breakpoint' => $breakpoint, 'state' => $state],
            'props' => $validation['props'],
        ],
    ];
}

/**
 * Delete a global class.
 *
 * @param array<string, mixed> $input
 * @return array{success: bool, error?: string}
 */
function gc_delete(array $input): array
{
    $class_id = (string) ($input['class_id'] ?? '');
    if ($class_id === '') {
        return ['success' => false, 'error' => 'Parameter "class_id" is required for delete.'];
    }

    $repo = \Elementor\Modules\GlobalClasses\Global_Classes_Repository::make();
    $all = $repo->all();

    $items = $all->get_items()->all();
    $order = $all->get_order()->all();

    if (($items[$class_id] ?? null) === null) {
        return ['success' => false, 'error' => "Global class '{$class_id}' not found."];
    }

    unset($items[$class_id]);
    $order = array_values(array_filter($order, fn(string $id): bool => $id !== $class_id));

    try {
        $repo->put($items, $order);
        gc_sync_preview_context();
    } catch (\Exception $e) {
        return ['success' => false, 'error' => 'Failed to delete global class: ' . $e->getMessage()];
    }

    return ['success' => true];
}

/**
 * Apply a global class to a widget on a page.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function gc_apply(array $input): array
{
    $parsed = gc_parse_apply_input($input);
    if (array_key_exists('error', $parsed)) {
        return ['success' => false, 'error' => $parsed['error']];
    }
    /** @var array{class_id: string, post_id: int, element_id: string} $parsed */

    [$elements, $read_error] = el_read_page_required($parsed['post_id']);
    if ($elements === null) {
        return ['success' => false, 'error' => $read_error ?? 'Unknown error.'];
    }

    // Validate that the target element exists, has a known widget type, and
    // actually supports the "classes" prop — v3 widgets don't, so applying a
    // global class to one would create an orphan setting silently ignored by
    // Elementor at render time. Fail hard with the schema inline instead.
    $target_check = gc_validate_apply_target($elements, $parsed['element_id'], $parsed['post_id']);
    if (array_key_exists('error_response', $target_check)) {
        return $target_check['error_response'];
    }

    // Find and update the element.
    $updated = gc_apply_class_to_elements($elements, $parsed['element_id'], $parsed['class_id']);
    if ($updated === null) {
        return [
            'success' => false,
            'error' => "Element '{$parsed['element_id']}' not found on page {$parsed['post_id']}.",
        ];
    }

    $save = el_write_page($parsed['post_id'], $updated['elements']);
    if (is_wp_error($save)) {
        return ['success' => false, 'error' => $save->get_error_message()];
    }

    return [
        'success' => true,
        'classes' => $updated['classes'],
        'already_present' => $updated['already_present'],
    ];
}

/**
 * Parse apply-global-class input into the normalized struct or an error
 * string. Class existence is verified here too so the orchestrator stays
 * thin enough to pass the complexity threshold after the richer response
 * we now build.
 *
 * @param array<string, mixed> $input
 * @return array{error: string}|array{class_id: string, post_id: int, element_id: string}
 */
function gc_parse_apply_input(array $input): array
{
    $class_id = (string) ($input['class_id'] ?? '');
    $post_id = (int) ($input['post_id'] ?? 0);
    $element_id = (string) ($input['element_id'] ?? '');

    if ($class_id === '') {
        return ['error' => 'Parameter "class_id" is required for apply.'];
    }
    if ($post_id <= 0) {
        return ['error' => 'Parameter "post_id" is required for apply.'];
    }
    if ($element_id === '') {
        return ['error' => 'Parameter "element_id" is required for apply.'];
    }

    $repo = \Elementor\Modules\GlobalClasses\Global_Classes_Repository::make();
    $items = $repo->all()->get_items()->all();
    if (($items[$class_id] ?? null) === null) {
        return ['error' => "Global class '{$class_id}' not found."];
    }

    return ['class_id' => $class_id, 'post_id' => $post_id, 'element_id' => $element_id];
}

/**
 * Validate that the target element for a global class apply operation is a
 * real v4 atomic widget that exposes the "classes" control. Returns
 * `{ok: true}` on success or `{error_response: ...}` on any failure, with
 * the compact content-only schema of the widget embedded inline so the
 * caller can see what controls are actually available.
 *
 * @param list<array<string, mixed>> $elements
 * @return array{ok: true}|array{error_response: array<string, mixed>}
 */
function gc_validate_apply_target(array $elements, string $element_id, int $post_id): array
{
    $target = el_find($elements, $element_id);
    if ($target === null) {
        return [
            'error_response' => [
                'success' => false,
                'error' => "Element '{$element_id}' not found on page {$post_id}.",
            ],
        ];
    }

    $widget_type = el_element_widget_type($target);
    if ($widget_type === '') {
        return [
            'error_response' => [
                'success' => false,
                'error' => "Target element '{$element_id}' has no resolvable widget type — cannot attach a global class to a broken element.",
            ],
        ];
    }

    $schema = el_extract_schema($widget_type);
    if ($schema === null) {
        return [
            'error_response' => [
                'success' => false,
                'error' => sprintf(
                    'Target element has widget_type "%s" which is not registered with Elementor on this site.',
                    $widget_type,
                ),
                'widget_type' => $widget_type,
            ],
        ];
    }

    if (($schema['is_atomic'] ?? false) !== true) {
        return [
            'error_response' => [
                'success' => false,
                'error' => sprintf(
                    "Global classes are a v4-only feature. Widget '%s' is a legacy v3 widget and cannot accept global classes. Convert it to its atomic equivalent first (see elementor-mcp/skill-get slug=elementor-convert-to-v4).",
                    $widget_type,
                ),
                'widget_type' => $widget_type,
                'is_atomic' => false,
            ],
        ];
    }

    $controls = el_schema_controls($schema);
    if ($controls === null || !array_key_exists('classes', $controls)) {
        $element_type = $widget_type === ELEMENTOR_MCP_COMPACT_SCHEMA_CONTAINER_KEY ? 'container' : 'widget';
        return [
            'error_response' => build_single_element_error_response($element_type, $widget_type, [
                'ok' => false,
                'settings' => [],
                'dropped' => ['classes'],
                'invalid' => [],
            ]),
        ];
    }

    return ['ok' => true];
}

/**
 * Recursively walk elements to find and apply a global class to a specific element.
 *
 * Returns the updated elements list if the element was found and updated, or null if not found.
 *
 * Returns the updated tree plus the post-update classes list and a flag
 * telling the caller whether the class was already present — the flag lets
 * `gc_apply` distinguish "newly applied" from "already on the element" in
 * its response, which agents otherwise can't tell apart.
 *
 * @param list<array<string, mixed>> $elements
 * @return array{elements: list<array<string, mixed>>, classes: list<string>, already_present: bool}|null
 */
function gc_apply_class_to_elements(array $elements, string $element_id, string $class_id): ?array
{
    foreach ($elements as &$element) {
        if (($element['id'] ?? '') === $element_id) {
            /** @var array<string, mixed> $settings */
            $settings = $element['settings'] ?? [];

            // Get current classes prop (Elementor stores it as $$type/value).
            /** @var list<string> $current_classes */
            $current_classes = $settings['classes']['value'] ?? [];
            $already_present = in_array($class_id, $current_classes, strict: true);

            if (!$already_present) {
                $current_classes[] = $class_id;
            }

            $settings['classes'] = [
                '$$type' => 'classes',
                'value' => $current_classes,
            ];

            $element['settings'] = $settings;

            return [
                'elements' => $elements,
                'classes' => $current_classes,
                'already_present' => $already_present,
            ];
        }

        // Recurse into children.
        /** @var list<array<string, mixed>>|null $children */
        $children = ($element['elements'] ?? null) !== null && is_array($element['elements'])
            ? $element['elements']
            : null;
        if ($children !== null) {
            $updated = gc_apply_class_to_elements($children, $element_id, $class_id);
            if ($updated !== null) {
                $element['elements'] = $updated['elements'];

                return [
                    'elements' => $elements,
                    'classes' => $updated['classes'],
                    'already_present' => $updated['already_present'],
                ];
            }
        }
    }

    return null;
}

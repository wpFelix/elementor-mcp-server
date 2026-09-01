<?php

// SPDX-FileCopyrightText: 2026 Elementor MCP <dev@elementormcp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace ElementorMCP\Abilities\Elementor;

/**
 * Abilities: Elementor v4 Global Variables (Design Tokens) — list / get /
 * create / edit / delete. Each operation is its own ability so input
 * schemas stay sharp. The var_* helpers below host the actual logic.
 */

if (!defined('ABSPATH')) {
    exit();
}

const ELEMENTOR_MCP_VARIABLES_REPO = 'Elementor\\Modules\\Variables\\Storage\\Variables_Repository';

wp_register_ability('elementor-mcp/elementor-list-variables', [
    'label' => __('List Elementor Global Variables', domain: 'elementor-mcp'),
    'description' => __(
        'Lists every Elementor v4 Global Variable (design token) defined on this site. Each entry includes id, label, type (color / font / size), and value.',
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
            'variables' => ['type' => 'array'],
            'error' => ['type' => 'string'],
        ],
        'required' => ['success'],
    ],
    'execute_callback' => 'ElementorMCP\Abilities\Elementor\list_variables',
    'permission_callback' => 'elementor_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true, 'type' => 'tool'],
        'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

wp_register_ability('elementor-mcp/elementor-get-variable', [
    'label' => __('Get Elementor Global Variable', domain: 'elementor-mcp'),
    'description' => __(
        'Reads a single Elementor v4 Global Variable by its id. Returns id, label, type, and value.',
        domain: 'elementor-mcp',
    ),
    'category' => 'elementor',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'variable_id' => [
                'type' => 'string',
                'description' => 'The id of the global variable to read.',
            ],
        ],
        'required' => ['variable_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'variable' => ['type' => 'object'],
            'error' => ['type' => 'string'],
        ],
        'required' => ['success'],
    ],
    'execute_callback' => 'ElementorMCP\Abilities\Elementor\get_variable',
    'permission_callback' => 'elementor_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true, 'type' => 'tool'],
        'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

wp_register_ability('elementor-mcp/elementor-create-variable', [
    'label' => __('Create Elementor Global Variable', domain: 'elementor-mcp'),
    'description' => __(
        'Creates a new Elementor v4 Global Variable (design token). Provide a label (no spaces, max 50 chars), a type (color / font / size), and a value. The value format depends on the type: hex like "#ff0000" for color, font family name like "Inter" for font, and for size either a CSS dimension like "24px" / "1.5rem" or a CSS expression like "clamp(1rem, 2vw, 2rem)" / "calc(100vh - 4rem)" / "min(...)" / "max(...)" / "var(--token)".',
        domain: 'elementor-mcp',
    ),
    'category' => 'elementor',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'label' => [
                'type' => 'string',
                'description' => 'Variable label, no spaces, max 50 chars (e.g. "PrimaryRed", "BodyFont").',
            ],
            'type' => [
                'type' => 'string',
                'description' => 'Variable type.',
                'enum' => ['color', 'font', 'size'],
            ],
            'value' => [
                'type' => 'string',
                'description' => 'Variable value. Format depends on `type`: hex for color, font family name for font, and for size either a CSS dimension ("24px", "1.5rem") or a CSS expression ("clamp(1rem, 2vw, 2rem)", "calc(100vh - 4rem)", "min(...)", "max(...)", "var(--other-token)").',
            ],
        ],
        'required' => ['label', 'type', 'value'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'variable' => ['type' => 'object'],
            'error' => ['type' => 'string'],
        ],
        'required' => ['success'],
    ],
    'execute_callback' => 'ElementorMCP\Abilities\Elementor\create_variable',
    'permission_callback' => 'elementor_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true, 'type' => 'tool'],
        'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);

wp_register_ability('elementor-mcp/elementor-edit-variable', [
    'label' => __('Edit Elementor Global Variable', domain: 'elementor-mcp'),
    'description' => __(
        'Edits an existing Elementor v4 Global Variable in place. Pass variable_id and at least one of label or value to change. The variable id is preserved so widgets and classes that reference it keep their binding. Type cannot be changed (delete and recreate if you need to switch type).',
        domain: 'elementor-mcp',
    ),
    'category' => 'elementor',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'variable_id' => [
                'type' => 'string',
                'description' => 'The id of the global variable to edit.',
            ],
            'label' => [
                'type' => 'string',
                'description' => 'New label. Optional — pass at least one of label or value.',
            ],
            'value' => [
                'type' => 'string',
                'description' => 'New value. Format depends on the variable\'s type. Optional — pass at least one of label or value.',
            ],
        ],
        'required' => ['variable_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'variable' => ['type' => 'object'],
            'error' => ['type' => 'string'],
        ],
        'required' => ['success'],
    ],
    'execute_callback' => 'ElementorMCP\Abilities\Elementor\edit_variable',
    'permission_callback' => 'elementor_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true, 'type' => 'tool'],
        'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => true],
    ],
]);

wp_register_ability('elementor-mcp/elementor-delete-variable', [
    'label' => __('Delete Elementor Global Variable', domain: 'elementor-mcp'),
    'description' => __(
        'Deletes an Elementor v4 Global Variable by id. Soft delete — the entity is marked as removed but the underlying record remains in the kit for audit purposes.',
        domain: 'elementor-mcp',
    ),
    'category' => 'elementor',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'variable_id' => [
                'type' => 'string',
                'description' => 'The id of the global variable to delete.',
            ],
        ],
        'required' => ['variable_id'],
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
    'execute_callback' => 'ElementorMCP\Abilities\Elementor\delete_variable',
    'permission_callback' => 'elementor_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true, 'type' => 'tool'],
        'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => true],
    ],
]);

/**
 * Guard that ensures Elementor and the Variables module are loaded.
 *
 * @return array{success: false, error: string}|null
 */
function var_runtime_guard(): ?array
{
    if (!class_exists('Elementor\\Plugin')) {
        return ['success' => false, 'error' => 'Elementor is not active.'];
    }

    if (!class_exists(ELEMENTOR_MCP_VARIABLES_REPO)) {
        return [
            'success' => false,
            'error' => 'Elementor Variables module is not available. Requires Elementor v4 with the e_variables experiment active.',
        ];
    }

    return null;
}

/**
 * @return array<string, mixed>
 */
function list_variables(): array
{
    $guard = var_runtime_guard();
    return $guard ?? var_list();
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function get_variable(array $input): array
{
    $guard = var_runtime_guard();
    return $guard ?? var_get($input);
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function create_variable(array $input): array
{
    $guard = var_runtime_guard();
    return $guard ?? var_create($input);
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function edit_variable(array $input): array
{
    $guard = var_runtime_guard();
    return $guard ?? var_edit($input);
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function delete_variable(array $input): array
{
    $guard = var_runtime_guard();
    return $guard ?? var_delete($input);
}

/**
 * Get the Variables repository instance.
 *
 * @return \Elementor\Modules\Variables\Storage\Variables_Repository
 */
function var_get_repo(): \Elementor\Modules\Variables\Storage\Variables_Repository
{
    $kit = \Elementor\Plugin::$instance->kits_manager->get_active_kit();
    return new \Elementor\Modules\Variables\Storage\Variables_Repository($kit);
}

/**
 * Map user-facing type names to internal Elementor type keys.
 *
 * @return array<string, string>
 */
function var_type_map(): array
{
    return [
        'color' => 'global-color-variable',
        'font' => 'global-font-variable',
        'size' => 'global-size-variable',
    ];
}

/**
 * Map internal Elementor type keys to user-facing type names.
 *
 * Both `global-size-variable` and `global-custom-size-variable` map back to
 * "size" — the split is an Elementor storage-adapter detail that agents
 * should not have to know about.
 *
 * @return array<string, string>
 */
function var_reverse_type_map(): array
{
    return [
        'global-color-variable' => 'color',
        'global-font-variable' => 'font',
        'global-size-variable' => 'size',
        \Elementor\Modules\Variables\Adapters\Prop_Type_Adapter::GLOBAL_CUSTOM_SIZE_VARIABLE_KEY => 'size',
    ];
}

/**
 * List all global variables.
 *
 * @return array{success: bool, variables: list<array<string, mixed>>}
 */
function var_list(): array
{
    $repo = var_get_repo();
    $collection = $repo->load();
    $serialized = $collection->serialize(true);
    $reverse_map = var_reverse_type_map();

    $variables = [];
    /** @var array<string, array<string, mixed>> $data */
    $data = $serialized['data'] ?? [];

    foreach ($data as $id => $item) {
        if (($item['deleted_at'] ?? null) !== null) {
            continue;
        }

        $internal_type = (string) ($item['type'] ?? '');
        $variables[] = [
            'id' => $id,
            'label' => $item['label'] ?? '',
            'type' => $reverse_map[$internal_type] ?? $internal_type,
            'value' => $item['value'] ?? '',
        ];
    }

    return ['success' => true, 'variables' => $variables];
}

/**
 * Validate required create parameters and resolve the internal type.
 *
 * @param array<string, mixed> $input
 * @return array{0: string, 1: string, 2: string, 3: string}|array{success: bool, error: string}
 */
function var_validate_create_input(array $input): array
{
    $label = (string) ($input['label'] ?? '');
    if ($label === '') {
        return ['success' => false, 'error' => 'Parameter "label" is required for create.'];
    }

    $type = (string) ($input['type'] ?? '');
    if ($type === '') {
        return ['success' => false, 'error' => 'Parameter "type" is required for create.'];
    }

    $type_map = var_type_map();
    if (!array_key_exists($type, $type_map)) {
        return ['success' => false, 'error' => "Invalid type: {$type}. Must be one of: color, font, size."];
    }

    $value = (string) ($input['value'] ?? '');
    if ($value === '') {
        return ['success' => false, 'error' => 'Parameter "value" is required for create.'];
    }

    $value_error = var_validate_value_for_type($type, $value);
    if ($value_error !== null) {
        return ['success' => false, 'error' => $value_error];
    }

    $internal_type = $type === 'size' ? var_resolve_size_internal_type($value) : $type_map[$type];

    return [$label, $type, $internal_type, $value];
}

/**
 * Per-type syntactic check for a variable's value. Returns an error string
 * when the value is malformed (so the ability can reject before persisting
 * a token that would render as garbage), or null when it passes.
 *
 * The public-facing `type` vocabulary is `color` / `font` / `size`; these
 * match the ability description's promise so agents get the error they
 * expect. `font` is intentionally lax — family names are free-form.
 */
function var_validate_value_for_type(string $type, string $value): ?string
{
    return match ($type) {
        'color' => var_validate_color_value($value),
        'size' => var_validate_size_value($value),
        default => null,
    };
}

/**
 * Accept `#RGB`, `#RRGGBB`, or `#RRGGBBAA`. Reject anything else (including
 * CSS named colors like "red" — they don't survive the token pipeline).
 */
function var_validate_color_value(string $value): ?string
{
    if (preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $value) !== 1) {
        return sprintf(
            'Color value must be a hex string like "#ff0000", "#f00", or "#ff0000cc" (3 / 6 / 8 hex digits after the "#"). Got: %s',
            $value,
        );
    }
    return null;
}

/**
 * Classify a size variable value as either a literal numeric dimension
 * (number + unit) or a CSS function expression. Drives both validation
 * (reject malformed input) and storage routing — Elementor stores the two
 * shapes through different Adapter paths: `global-size-variable` for
 * numeric (wrapped as `{size, unit}`), `global-custom-size-variable` for
 * CSS expressions (wrapped as `{size: <string>, unit: 'custom'}`).
 *
 * Accepted shapes:
 *  - `<number><unit>` with unit from the CSS v4 length/percentage family
 *    accepted by Atomic_Styles. Bare numbers (`0`, `1.5`) pass too — they
 *    mean "unitless" and are valid for properties like line-height.
 *  - A CSS function expression starting with `clamp(`, `calc(`, `min(`,
 *    `max(`, `var(`, or `env(`. Elementor's storage adapter wraps these
 *    with `unit: 'custom'` so the value survives the round-trip and the
 *    Elementor Pro Size input renders it under the Custom unit choice.
 *    We only check parens balance to catch obvious truncations.
 *
 * @return array{kind: 'numeric'|'custom-css'|null, error: ?string}
 */
function var_classify_size_value(string $value): array
{
    if (preg_match(pattern: '/^(clamp|calc|min|max|var|env)\s*\(/i', subject: $value) === 1) {
        $depth = 0;
        $len = strlen($value);
        for ($i = 0; $i < $len; $i++) {
            if ($value[$i] === '(') {
                $depth++;
                continue;
            }
            if ($value[$i] !== ')') {
                continue;
            }
            $depth--;
            if ($depth < 0) {
                return ['kind' => null, 'error' => sprintf('Unbalanced parentheses in size value: %s', $value)];
            }
        }
        if ($depth !== 0) {
            return ['kind' => null, 'error' => sprintf('Unbalanced parentheses in size value: %s', $value)];
        }
        return ['kind' => 'custom-css', 'error' => null];
    }

    $valid_units = ['px', 'em', 'rem', 'vw', 'vh', 'vmin', 'vmax', 'ch', '%', 'pt', 'pc', 'ex', 'fr'];
    $matches = [];
    if (preg_match(pattern: '/^-?\d+(?:\.\d+)?([a-z%]*)$/i', subject: $value, matches: $matches) !== 1) {
        return [
            'kind' => null,
            'error' => sprintf(
                'Size value must be a CSS dimension like "24px" or "1.5rem", or a CSS expression like "clamp(1rem, 2vw, 2rem)", "calc(100vh - 4rem)", "min(2rem, 5vw)", "max(1rem, 1vw)", "var(--token)". Got: %s',
                $value,
            ),
        ];
    }
    $unit = strtolower($matches[1]);
    if ($unit !== '' && !in_array(needle: $unit, haystack: $valid_units, strict: true)) {
        return [
            'kind' => null,
            'error' => sprintf('Size unit "%s" is not valid. Use one of: %s.', $unit, implode(', ', $valid_units)),
        ];
    }
    return ['kind' => 'numeric', 'error' => null];
}

function var_validate_size_value(string $value): ?string
{
    return var_classify_size_value($value)['error'];
}

/**
 * Pick the internal Elementor variable type for a size value. CSS function
 * expressions go through `global-custom-size-variable` (Adapter wraps with
 * `unit: 'custom'` so the Pro Size input renders correctly); numeric values
 * stay on the regular `global-size-variable` path.
 */
function var_resolve_size_internal_type(string $value): string
{
    return var_classify_size_value($value)['kind'] === 'custom-css'
        ? \Elementor\Modules\Variables\Adapters\Prop_Type_Adapter::GLOBAL_CUSTOM_SIZE_VARIABLE_KEY
        : 'global-size-variable';
}

/**
 * Save a collection and clear the Elementor file cache.
 *
 * @return array{success: bool, error: string}|null Null on success, error array on failure.
 */
function var_save(
    \Elementor\Modules\Variables\Storage\Variables_Repository $repo,
    \Elementor\Modules\Variables\Storage\Variables_Collection $collection,
): ?array {
    /** @var false|int|string $watermark */
    $watermark = $repo->save($collection);
    if ($watermark === false) {
        return ['success' => false, 'error' => 'Failed to save variable.'];
    }

    \Elementor\Plugin::$instance->files_manager->clear_cache();

    return null;
}

/**
 * Create a new global variable.
 *
 * @param array<string, mixed> $input
 * @return array{success: bool, variable?: array<string, mixed>, error?: string}
 */
function var_create(array $input): array
{
    $validated = var_validate_create_input($input);
    if (($validated['success'] ?? null) === false) {
        /** @var array{success: bool, error: string} $validated */
        return $validated;
    }

    /** @var array{0: string, 1: string, 2: string, 3: string} $validated */
    [$label, $type, $internal_type, $value] = $validated;

    $repo = var_get_repo();
    $collection = $repo->load();

    try {
        $collection->assert_limit_not_reached();
        $collection->assert_label_is_unique($label);
    } catch (\Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }

    $id = $collection->next_id();
    $variable = \Elementor\Modules\Variables\Storage\Entities\Variable::create_new([
        'id' => $id,
        'type' => $internal_type,
        'label' => $label,
        'value' => $value,
        'order' => $collection->get_next_order(),
    ]);

    // Older Elementor Pro builds (<= 3.35) don't expose Variable::validate(); guard the call
    // so we degrade gracefully. The repository still validates on save.
    if (method_exists($variable, 'validate')) {
        try {
            $variable->validate();
        } catch (\Exception $e) {
            return ['success' => false, 'error' => 'Validation failed: ' . $e->getMessage()];
        }
    }

    $collection->add_variable($variable);

    $error = var_save($repo, $collection);
    if ($error !== null) {
        return $error;
    }

    $reverse_map = var_reverse_type_map();

    return [
        'success' => true,
        'variable' => [
            'id' => $id,
            'label' => $label,
            'type' => $reverse_map[$internal_type] ?? $type,
            'value' => $value,
        ],
    ];
}

/**
 * Delete a global variable by ID (soft delete).
 *
 * @param array<string, mixed> $input
 * @return array{success: bool, error?: string}
 */
function var_delete(array $input): array
{
    $variable_id = (string) ($input['variable_id'] ?? '');
    if ($variable_id === '') {
        return ['success' => false, 'error' => 'Parameter "variable_id" is required for delete.'];
    }

    $repo = var_get_repo();
    $collection = $repo->load();

    try {
        $variable = $collection->find_or_fail($variable_id);
    } catch (\Exception $e) {
        return ['success' => false, 'error' => "Variable '{$variable_id}' not found."];
    }

    $variable->soft_delete();

    $error = var_save($repo, $collection);
    if ($error !== null) {
        return $error;
    }

    return ['success' => true];
}

/**
 * Edit an existing global variable in place. Accepts variable_id and at
 * least one of label or value. Type cannot be changed.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function var_edit(array $input): array
{
    $variable_id = (string) ($input['variable_id'] ?? '');
    if ($variable_id === '') {
        return ['success' => false, 'error' => 'Parameter "variable_id" is required for edit.'];
    }

    $changes_or_error = var_collect_edit_changes($input);
    if (array_key_exists('error', $changes_or_error)) {
        return ['success' => false, 'error' => $changes_or_error['error']];
    }

    $changes = $changes_or_error['changes'] ?? [];
    $repo = var_get_repo();
    $collection = $repo->load();
    $variable = var_resolve_target($collection, $variable_id);
    if (is_array($variable)) {
        return $variable;
    }

    if (array_key_exists('value', $changes) && is_string($changes['value'])) {
        /** @var mixed $internal_type */
        $internal_type = $variable->type();
        $public_type = is_string($internal_type) ? var_reverse_type_map()[$internal_type] ?? '' : '';
        $value_error = var_validate_value_for_type($public_type, $changes['value']);
        if ($value_error !== null) {
            return ['success' => false, 'error' => $value_error];
        }
        // For size variables, the storage type may need to flip between the
        // numeric and custom-CSS adapter paths depending on the new value's
        // shape. Elementor permits this transition inside `apply_changes`.
        if ($public_type === 'size') {
            $changes['type'] = var_resolve_size_internal_type($changes['value']);
        }
    }

    try {
        $variable->apply_changes($changes);
    } catch (\Exception $e) {
        return ['success' => false, 'error' => 'Validation failed: ' . $e->getMessage()];
    }

    $error = var_save($repo, $collection);
    if ($error !== null) {
        return $error;
    }

    return ['success' => true, 'variable' => var_serialize_entity($variable_id, $variable)];
}

/**
 * Collect the label/value overrides from an edit payload.
 *
 * @param array<string, mixed> $input
 * @return array{changes: array<string, mixed>}|array{error: string}
 */
function var_collect_edit_changes(array $input): array
{
    $has_label = array_key_exists('label', $input);
    $has_value = array_key_exists('value', $input);
    if (!$has_label && !$has_value) {
        return ['error' => 'Edit requires at least one of "label" or "value".'];
    }

    $changes = [];
    if ($has_label) {
        $label = (string) ($input['label'] ?? '');
        if ($label === '') {
            return ['error' => '"label" cannot be empty when editing.'];
        }
        $changes['label'] = $label;
    }
    if ($has_value) {
        $value = (string) ($input['value'] ?? '');
        if ($value === '') {
            return ['error' => '"value" cannot be empty when editing.'];
        }
        $changes['value'] = $value;
    }

    return ['changes' => $changes];
}

/**
 * Resolve a variable entity by id, returning either the entity or an error
 * response when the variable is missing or already soft-deleted.
 *
 * @return \Elementor\Modules\Variables\Storage\Entities\Variable|array{success: false, error: string}
 */
function var_resolve_target(
    \Elementor\Modules\Variables\Storage\Variables_Collection $collection,
    string $variable_id,
): \Elementor\Modules\Variables\Storage\Entities\Variable|array {
    try {
        $variable = $collection->find_or_fail($variable_id);
    } catch (\Exception $e) {
        return ['success' => false, 'error' => "Variable '{$variable_id}' not found."];
    }

    if ($variable->is_deleted()) {
        return ['success' => false, 'error' => "Variable '{$variable_id}' has been deleted."];
    }

    return $variable;
}

/**
 * Serialize an entity into the public response shape.
 *
 * @return array<string, mixed>
 */
function var_serialize_entity(
    string $variable_id,
    \Elementor\Modules\Variables\Storage\Entities\Variable $variable,
): array {
    $reverse_map = var_reverse_type_map();
    $internal_type = (string) $variable->type();

    return [
        'id' => $variable_id,
        'label' => $variable->label(),
        'type' => $reverse_map[$internal_type] ?? $internal_type,
        'value' => $variable->value(),
    ];
}

/**
 * Get a specific global variable by ID.
 *
 * @param array<string, mixed> $input
 * @return array{success: bool, variable?: array<string, mixed>, error?: string}
 */
function var_get(array $input): array
{
    $variable_id = (string) ($input['variable_id'] ?? '');
    if ($variable_id === '') {
        return ['success' => false, 'error' => 'Parameter "variable_id" is required for get.'];
    }

    $repo = var_get_repo();
    $collection = $repo->load();

    try {
        $variable = $collection->find_or_fail($variable_id);
    } catch (\Exception $e) {
        return ['success' => false, 'error' => "Variable '{$variable_id}' not found."];
    }

    if ($variable->is_deleted()) {
        return ['success' => false, 'error' => "Variable '{$variable_id}' not found."];
    }

    $reverse_map = var_reverse_type_map();
    $internal_type = (string) $variable->type();

    return [
        'success' => true,
        'variable' => [
            'id' => $variable_id,
            'label' => $variable->label(),
            'type' => $reverse_map[$internal_type] ?? $internal_type,
            'value' => $variable->value(),
        ],
    ];
}

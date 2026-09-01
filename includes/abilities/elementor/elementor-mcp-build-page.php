<?php

// SPDX-FileCopyrightText: 2026 Elementor MCP <dev@elementormcp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace ElementorMCP\Abilities\Elementor;

/**
 * Elementor: build a whole page from a compact description.
 *
 * Everything here is reachable through add-element already — this exists because
 * the shape of the call matters. Composing a landing page one element at a time
 * is a dozen round trips, each carrying its own parent id and position, and the
 * agent has to hold the layout in its head between them. A single nested
 * description is how a person would say it, and the failure mode is better too:
 * the whole page is validated and written once, so a rejected widget leaves no
 * half-built page behind.
 *
 * The input is deliberately looser than Elementor's own document format. Each
 * node is {type, settings, styles, children} — where `type` is a widget name or
 * a container type — and this file translates that into the element shape
 * add-element already knows how to insert and validate. Anyone who wants the
 * real format still has set-content.
 */

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('elementor-mcp/elementor-build-page', [
    'label' => __('Build Elementor Page', domain: 'elementor-mcp'),
    'description' => __(
        'Builds a complete Elementor page from one nested description, in a single call. Each node is {type, settings, styles, children}: type is a widget name (heading, image, e-heading) or a container type (container, e-flexbox, e-div-block), settings are that element\'s controls, and children nest the same shape. Ids are generated throughout. By default the page is replaced, which is what "build me a landing page" means; pass replace=false to append instead. The whole document is validated before anything is written, so a rejected widget aborts the build and returns that widget\'s compact schema inline rather than leaving a half-finished page. For a single element use elementor-mcp/elementor-add-element; for an exact Elementor document use elementor-mcp/elementor-set-content.',
        domain: 'elementor-mcp',
    ),
    'category' => 'elementor',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer', 'description' => 'Page to build.'],
            'content' => [
                'type' => 'array',
                'description' => 'Top-level nodes, each {type, settings, styles, children}. A node may also carry `grammar`: the name of a section composition from elementor-mcp/list-layout-grammars — editorial-split, overlap-card, bleed-left, poster-stack, index-detail, offset-pair. The grammar becomes a global class on the section and its named parts are applied to the children in order, so a composition arrives with its responsive behaviour already defined instead of being hand-tuned per page. A child may name its own `part` to override the positional match.',
                'items' => ['type' => 'object'],
            ],
            'replace' => [
                'type' => 'boolean',
                'description' => 'Replace the existing content. Defaults to true.',
            ],
            'dry_run' => [
                'type' => 'boolean',
                'default' => false,
                'description' => 'Validate the whole description and report what would be written, without touching the page. Use it before a large build: every node is checked, and the first malformed one names itself, so a page is never left half-written by a description that was wrong at element ninety.',
            ],
        ],
        'required' => ['post_id', 'content'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'created' => ['type' => 'integer', 'description' => 'Elements written, including nested ones.'],
            'dry_run' => ['type' => 'boolean', 'description' => 'True when nothing was written.'],
            'would_create' => ['type' => 'integer', 'description' => 'Elements a real run would write, including nested ones.'],
            'top_level' => ['type' => 'integer', 'description' => 'Top-level nodes in the description.'],
            'view_url' => ['type' => 'string', 'description' => 'The public page. Open it and look at what you built before reporting the job done.'],
            'preview_url' => ['type' => 'string', 'description' => 'Viewable even while the page is a draft.'],
            'edit_url' => ['type' => 'string'],
            'error' => ['type' => 'string'],
        ],
        'required' => ['success'],
    ],
    'execute_callback' => 'ElementorMCP\Abilities\Elementor\elementor_build_page',
    'permission_callback' => 'elementor_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true, 'type' => 'tool'],
        // The default replaces the page's existing content.
        'annotations' => [
            'instructions' => 'Open view_url (or preview_url on a draft) and look at the rendered page before telling anyone the build is finished. Reading back the element tree confirms what you wrote, not what it looks like: a page can carry every element you intended and still land with two sections in the same rhythm, a hero that collapses at one breakpoint, or an accent nobody can read. Judge the result, then fix it.',
            'readonly' => false,
            'destructive' => true,
            'idempotent' => true,
        ],
    ],
]);

/**
 * @param array<string, mixed> $input
 * @return array{success: bool, created?: int, dry_run?: bool, would_create?: int, top_level?: int, error?: string}
 */
function elementor_build_page(array $input): array
{
    $post_id = (int) ($input['post_id'] ?? 0);
    $replace = ($input['replace'] ?? true) !== false;

    /** @var mixed $content */
    $content = $input['content'] ?? null;
    if (!is_array($content) || $content === []) {
        return ['success' => false, 'error' => 'Parameter "content" must list at least one element.'];
    }

    $created = 0;
    $nodes = [];
    // Read once for the whole build: the map is the same for every node, and a
    // lookup per node would re-read the class list dozens of times.
    $spec_roles = function_exists(__NAMESPACE__ . '\spec_class_map') ? spec_class_map() : [];
    /** @var mixed $node */
    foreach ($content as $node) {
        if (!is_array($node)) {
            return ['success' => false, 'error' => 'Every entry in "content" must be an object.'];
        }
        // Roles from a compiled reproduction spec, resolved before the node is
        // built. Class ids are generated and differ per site, so a description
        // carrying them only works on the install it was written against; a
        // role name works anywhere the spec has been compiled. This has to run
        // first because the builder drops keys it does not recognise, and a
        // role read afterwards is a role that has already been thrown away.
        if (function_exists(__NAMESPACE__ . '\el_apply_spec_roles') && $spec_roles !== []) {
            $with_roles = el_apply_spec_roles($node, $spec_roles);
            if ($with_roles instanceof \WP_Error) {
                return ['success' => false, 'error' => $with_roles->get_error_message()];
            }
            $node = $with_roles;
        }

        $built = el_build_node($node, $created);
        if (is_string($built)) {
            return ['success' => false, 'error' => $built];
        }

        // A node may name a composition instead of hand-tuning containers. The
        // grammar's global class is created once and reused, and its named
        // parts are matched to this node's children in order.
        $grammar = is_array($node) ? trim((string) ($node['grammar'] ?? '')) : '';
        if ($grammar !== '') {
            // Grammars compile to global classes, which older Elementor
            // versions have no machinery for. Say so rather than fatalling on
            // a function that was never loaded.
            if (!function_exists(__NAMESPACE__ . '\el_apply_grammar')) {
                return [
                    'success' => false,
                    'error' => 'Section grammars need Elementor global classes, which this Elementor version does not provide. Build the section with explicit containers instead.',
                ];
            }
            $with_grammar = el_apply_grammar($built, $grammar);
            if ($with_grammar instanceof \WP_Error) {
                return ['success' => false, 'error' => $with_grammar->get_error_message()];
            }
            $built = $with_grammar;
        }

        // The same v3 normalisation elementor-mcp/elementor-add-element runs, so the
        // two abilities answer the same description the same way. A container
        // carrying `boxed_width` is split into the full-bleed outer and
        // width-constrained inner pair v4 needs; through add-element that
        // happened already, through build-page it did not, and the identical
        // description produced a full-width section on one path and a boxed one
        // on the other.
        if (function_exists(__NAMESPACE__ . '\el_split_boxed_containers')) {
            $built = el_split_boxed_containers($built);
        }
        if (function_exists(__NAMESPACE__ . '\el_translate_v3_container_settings')) {
            $built = el_translate_v3_container_settings($built);
        }

        $nodes[] = $built;
    }

    // A dry run answers with the verdict the write would reach, which means
    // running the write's own validation and stopping short of the disk.
    // Parsing the node descriptions is not enough: it proves each node looks
    // like an element and says nothing about whether the styles on it are
    // values Elementor accepts, so a description carrying a flat CSS map
    // instead of style entries passed the dry run and was then refused in full
    // by the write. The call whose whole purpose is "tell me before I commit"
    // has to be the one that can.
    if (($input['dry_run'] ?? false) === true) {
        $existing_for_check = [];
        if (!$replace) {
            [$current] = el_read_page($post_id);
            $existing_for_check = is_array($current) ? $current : [];
        }

        $checked = elementor_prepare_content([...$existing_for_check, ...$nodes]);
        if (($checked['success'] ?? false) !== true) {
            return [...$checked, 'dry_run' => true];
        }

        return [
            'success' => true,
            'dry_run' => true,
            'would_create' => $created,
            'top_level' => count($nodes),
        ];
    }

    // Routed through set-content rather than repeated inserts: it validates the
    // whole document server-side and returns the offending widget's schema
    // inline, which is the behaviour that makes a failed build correctable in
    // one more call instead of leaving elements already written.
    $existing = [];
    if (!$replace) {
        [$current, $error] = el_read_page($post_id);
        if ($current === null) {
            return ['success' => false, 'error' => $error ?? 'Unknown error.'];
        }
        $existing = $current;
    }

    // set-content names the document "content", not "elements". Passing the
    // wrong key is silently accepted as an empty document, so this is written
    // against its actual contract rather than the shape used inside the tree
    // helpers.
    /** @var array{success: bool, error?: string} $result */
    $result = elementor_set_content([
        'post_id' => $post_id,
        'content' => [...$existing, ...$nodes],
    ]);

    if (($result['success'] ?? false) === true) {
        $result['created'] = $created;
        $result = [...$result, ...el_look_at_it($post_id)];
    }

    return $result;
}

/**
 * Translate one description node into an Elementor element.
 *
 * Returns an error string rather than throwing, so a malformed node names itself
 * in the response instead of surfacing as a generic failure several layers up.
 *
 * @param array<string, mixed> $node
 * @return array<string, mixed>|string
 */
function el_build_node(array $node, int &$created): array|string
{
    $type = (string) ($node['type'] ?? '');
    if ($type === '') {
        return 'Every node needs a "type": a widget name, or container / e-flexbox / e-div-block.';
    }

    /** @var array<string, mixed> $settings */
    $settings = is_array($node['settings'] ?? null) ? $node['settings'] : [];
    /** @var array<string, mixed> $styles */
    $styles = is_array($node['styles'] ?? null) ? $node['styles'] : [];

    $is_container = $type === 'container' || in_array($type, ELEMENTOR_MCP_ATOMIC_CONTAINER_TYPES, strict: true);

    $element = [
        'id' => el_generate_id(),
        'elType' => $is_container ? $type : 'widget',
        'settings' => $settings,
        'elements' => [],
    ];
    if (!$is_container) {
        $element['widgetType'] = $type;
    }
    if ($styles !== []) {
        $element['styles'] = $styles;
    }
    if (is_string($node['element_id'] ?? null) && $node['element_id'] !== '') {
        $element['id'] = (string) $node['element_id'];
    }

    $created++;

    /** @var mixed $children */
    $children = $node['children'] ?? null;
    if (is_array($children)) {
        $built_children = [];
        /** @var mixed $child */
        foreach ($children as $child) {
            if (!is_array($child)) {
                return 'Every entry in "children" must be an object.';
            }
            $built = el_build_node($child, $created);
            if (is_string($built)) {
                return $built;
            }
            $built_children[] = $built;
        }
        $element['elements'] = $built_children;
    }

    return $element;
}

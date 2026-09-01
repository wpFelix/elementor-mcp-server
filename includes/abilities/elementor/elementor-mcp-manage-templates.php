<?php

// SPDX-FileCopyrightText: 2026 Elementor MCP <dev@elementormcp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace ElementorMCP\Abilities\Elementor;

use WP_Error;
use WP_Query;

/**
 * Elementor: the template library and the Theme Builder documents.
 *
 * Elementor stores every saved template, and every Theme Builder document, as an
 * `elementor_library` post whose `_elementor_template_type` meta says what it is:
 * `page` and `section` for the library, `header`, `footer`, `archive`,
 * `single-post` and the rest for the Theme Builder. They are the same storage,
 * so they are the same three verbs — list, save, apply — and separating them
 * would have meant two near-identical modules that drift.
 *
 * This is the part of the surface an agency actually buys. Building a page from
 * scratch is a demo; reusing the section a client already approved, and editing
 * the header that appears on every page, is the work. Until now the header was
 * unreachable: it is not a page, so nothing that takes a post_id found it.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Template types Elementor recognises, split by where they surface.
 *
 * The Theme Builder list is the subset whose display is governed by conditions
 * rather than by being inserted into a page, which is why they are worth naming
 * separately in the tool description even though the storage is identical.
 *
 * @return array{library: list<string>, theme: list<string>}
 */
function el_template_types(): array
{
    return [
        'library' => ['page', 'section', 'container', 'widget'],
        'theme' => ['header', 'footer', 'archive', 'single', 'single-post', 'single-page', 'search-results', 'error-404', 'product', 'product-archive', 'loop-item'],
    ];
}

wp_register_ability('elementor-mcp/elementor-list-templates', [
    'label' => __('List Elementor Templates', domain: 'elementor-mcp'),
    'description' => __(
        'Lists Elementor library templates and Theme Builder documents — saved pages and sections, plus headers, footers, archives, single templates and 404s. Each entry carries the template_id, which is an ordinary post id: pass it to elementor-mcp/elementor-get-content to read the document, to elementor-mcp/elementor-edit-element to change it, or to elementor-mcp/elementor-apply-template to place it on a page. This is the only way to reach a Theme Builder document, since a header is not a page and will never appear in a post listing. Filter with type (header, footer, section, page, …) or scope ("theme" for Theme Builder documents, "library" for saved templates).',
        domain: 'elementor-mcp',
    ),
    'category' => 'elementor',
    'input_schema' => [
        'type' => 'object',
        'default' => [],
        'properties' => [
            'type' => ['type' => 'string', 'description' => 'Exact template type, e.g. header, footer, section, page.'],
            'scope' => ['type' => 'string', 'description' => '"theme", "library", or omit for both.'],
            'search' => ['type' => 'string', 'description' => 'Match against the template title.'],
            'limit' => ['type' => 'integer', 'description' => 'Maximum templates to return. Defaults to 50.'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'templates' => ['type' => 'array'],
            'total' => ['type' => 'integer'],
            'error' => ['type' => 'string'],
        ],
        'required' => ['success'],
    ],
    'execute_callback' => 'ElementorMCP\Abilities\Elementor\elementor_list_templates',
    'permission_callback' => 'elementor_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true, 'type' => 'tool'],
        'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

/**
 * @param array<string, mixed> $input
 * @return array{success: bool, templates?: list<array<string, mixed>>, total?: int, error?: string}
 */
function elementor_list_templates(array $input): array
{
    if (!class_exists('Elementor\\Plugin')) {
        return ['success' => false, 'error' => 'Elementor is not active.'];
    }

    $type = (string) ($input['type'] ?? '');
    $scope = (string) ($input['scope'] ?? '');
    $search = (string) ($input['search'] ?? '');
    $limit = is_numeric($input['limit'] ?? null) ? max(1, min(200, (int) $input['limit'])) : 50;

    $args = [
        'post_type' => 'elementor_library',
        'post_status' => ['publish', 'draft', 'private'],
        'posts_per_page' => $limit,
        'orderby' => 'modified',
        'order' => 'DESC',
        // The query runs for an authenticated administrator over MCP, where no
        // sticky-post or main-query behaviour applies.
        'ignore_sticky_posts' => true,
        'no_found_rows' => true,
    ];
    if ($search !== '') {
        $args['s'] = $search;
    }

    $types = el_template_types();
    if ($type !== '') {
        $wanted = [$type];
    } elseif ($scope === 'theme') {
        $wanted = $types['theme'];
    } elseif ($scope === 'library') {
        $wanted = $types['library'];
    } else {
        $wanted = [];
    }

    if ($wanted !== []) {
        // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bounded by posts_per_page on a small custom post type; there is no other index for template type.
        $args['meta_query'] = [
            ['key' => '_elementor_template_type', 'value' => $wanted, 'compare' => 'IN'],
        ];
    }

    $query = new WP_Query($args);
    $templates = [];
    foreach ($query->posts as $post) {
        if (!$post instanceof \WP_Post) {
            continue;
        }
        $template_type = (string) get_post_meta($post->ID, key: '_elementor_template_type', single: true);
        $templates[] = [
            'template_id' => $post->ID,
            'title' => $post->post_title,
            'type' => $template_type,
            'scope' => in_array($template_type, $types['theme'], strict: true) ? 'theme' : 'library',
            'status' => $post->post_status,
            'modified' => $post->post_modified_gmt,
        ];
    }

    return ['success' => true, 'templates' => $templates, 'total' => count($templates)];
}

wp_register_ability('elementor-mcp/elementor-save-template', [
    'label' => __('Save Elementor Template', domain: 'elementor-mcp'),
    'description' => __(
        'Saves a whole page, or one element and everything under it, as a reusable Elementor template. Pass post_id alone to save the entire document; add element_id to save just that subtree, which is how a section a client approved becomes something you can drop onto the next page. The saved copy gets fresh ids, so applying it repeatedly never collides with the original or with earlier copies. type defaults to "section" for a subtree and "page" for a whole document; pass header, footer, archive or single to create a Theme Builder document instead — note that a new Theme Builder document has no display conditions and shows nowhere until they are set in Elementor.',
        domain: 'elementor-mcp',
    ),
    'category' => 'elementor',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer', 'description' => 'Document to copy from.'],
            'element_id' => [
                'type' => 'string',
                'description' => 'Save only this element and its children. Omit to save the whole document.',
            ],
            'title' => ['type' => 'string', 'description' => 'Name for the saved template.'],
            'type' => [
                'type' => 'string',
                'description' => 'Template type. Defaults to section for a subtree, page for a document.',
            ],
        ],
        'required' => ['post_id', 'title'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'template_id' => ['type' => 'integer'],
            'error' => ['type' => 'string'],
        ],
        'required' => ['success'],
    ],
    'execute_callback' => 'ElementorMCP\Abilities\Elementor\elementor_save_template',
    'permission_callback' => 'elementor_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true, 'type' => 'tool'],
        // Saving twice creates two templates.
        'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);

/**
 * @param array<string, mixed> $input
 * @return array{success: bool, template_id?: int, error?: string}
 */
function elementor_save_template(array $input): array
{
    if (!class_exists('Elementor\\Plugin')) {
        return ['success' => false, 'error' => 'Elementor is not active.'];
    }

    $post_id = (int) ($input['post_id'] ?? 0);
    if ($post_id <= 0 || !get_post($post_id)) {
        return ['success' => false, 'error' => "Post {$post_id} not found."];
    }

    $title = trim((string) ($input['title'] ?? ''));
    if ($title === '') {
        return ['success' => false, 'error' => 'Parameter "title" is required.'];
    }

    [$elements, $error] = el_read_page($post_id);
    if ($elements === null) {
        return ['success' => false, 'error' => $error ?? 'Unknown error.'];
    }

    $element_id = (string) ($input['element_id'] ?? '');
    if ($element_id !== '') {
        $node = el_find($elements, $element_id);
        if ($node === null) {
            return ['success' => false, 'error' => "Element '{$element_id}' not found on post {$post_id}."];
        }
        $tree = [$node];
        $default_type = 'section';
    } else {
        $tree = $elements;
        $default_type = 'page';
    }

    // Fresh ids on the saved copy, for the same reason duplicate-element
    // regenerates them: a template applied to the page it came from would
    // otherwise install a second element answering to an id already in use.
    $copied = [];
    foreach ($tree as $node) {
        $copied[] = el_ensure_tree_ids(el_strip_tree_ids($node));
    }

    $type = (string) ($input['type'] ?? '');
    if ($type === '') {
        $type = $default_type;
    }

    $template_id = wp_insert_post([
        'post_title' => $title,
        'post_type' => 'elementor_library',
        'post_status' => 'publish',
    ], true);

    if ($template_id instanceof WP_Error) {
        return ['success' => false, 'error' => $template_id->get_error_message()];
    }

    $encoded = wp_json_encode($copied);
    if (!is_string($encoded)) {
        wp_delete_post($template_id, true);

        return ['success' => false, 'error' => 'Could not encode the template document.'];
    }

    // wp_slash: WordPress unslashes meta on the way in, and the document is
    // JSON full of quotes. Without it every quote loses its backslash and the
    // stored template no longer parses.
    update_post_meta($template_id, meta_key: '_elementor_data', meta_value: wp_slash($encoded));
    update_post_meta($template_id, meta_key: '_elementor_template_type', meta_value: $type);
    update_post_meta($template_id, meta_key: '_elementor_edit_mode', meta_value: 'builder');

    return ['success' => true, 'template_id' => (int) $template_id];
}

wp_register_ability('elementor-mcp/elementor-apply-template', [
    'label' => __('Apply Elementor Template', domain: 'elementor-mcp'),
    'description' => __(
        'Copies a saved template into a page. The template\'s elements are inserted with fresh ids, so the page owns its copy and later edits to either one do not affect the other — this is an import, not a link. By default the template is appended to the end of the document; pass parent_id to insert it inside a container, position for a specific index, or replace=true to discard the page\'s current content first. Applying a Theme Builder document (a header, a footer) to a page copies its elements onto that page, which is rarely what is wanted — a header is displayed by its conditions, not by being pasted somewhere.',
        domain: 'elementor-mcp',
    ),
    'category' => 'elementor',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer', 'description' => 'Page to apply the template to.'],
            'template_id' => ['type' => 'integer', 'description' => 'Template to copy from.'],
            'parent_id' => ['type' => 'string', 'description' => 'Container to insert into. Omit for the root.'],
            'position' => ['type' => 'integer', 'description' => 'Zero-based index. Omit to append.'],
            'replace' => [
                'type' => 'boolean',
                'description' => 'Discard the page\'s existing content before inserting. Defaults to false.',
            ],
        ],
        'required' => ['post_id', 'template_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'inserted' => ['type' => 'integer'],
            'error' => ['type' => 'string'],
        ],
        'required' => ['success'],
    ],
    'execute_callback' => 'ElementorMCP\Abilities\Elementor\elementor_apply_template',
    'permission_callback' => 'elementor_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true, 'type' => 'tool'],
        // replace=true discards the page's current content.
        'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

/**
 * @param array<string, mixed> $input
 * @return array{success: bool, inserted?: int, error?: string}
 */
function elementor_apply_template(array $input): array
{
    $post_id = (int) ($input['post_id'] ?? 0);
    $template_id = (int) ($input['template_id'] ?? 0);
    $parent_id = is_string($input['parent_id'] ?? null) && $input['parent_id'] !== ''
        ? (string) $input['parent_id']
        : null;
    $position = is_numeric($input['position'] ?? null) ? (int) $input['position'] : -1;
    $replace = ($input['replace'] ?? false) === true;

    if ($template_id <= 0 || !get_post($template_id)) {
        return ['success' => false, 'error' => "Template {$template_id} not found."];
    }

    [$template_elements, $template_error] = el_read_page($template_id);
    if ($template_elements === null) {
        return ['success' => false, 'error' => $template_error ?? 'Unknown error reading the template.'];
    }
    if ($template_elements === []) {
        return ['success' => false, 'error' => "Template {$template_id} has no Elementor content."];
    }

    $inserted = 0;
    $result = el_structural_write(
        $post_id,
        static function (array &$elements) use (
            $template_elements,
            $parent_id,
            $position,
            $replace,
            $post_id,
            &$inserted,
        ): ?string {
            if ($parent_id !== null && el_find($elements, $parent_id) === null) {
                return "Parent '{$parent_id}' not found on post {$post_id}.";
            }

            if ($replace && $parent_id === null) {
                $elements = [];
            }

            // Inserted in order: each node goes after the previous one, so a
            // multi-section template keeps its own sequence instead of arriving
            // reversed, which is what repeated insertion at one index produces.
            $offset = 0;
            foreach ($template_elements as $node) {
                $copy = el_ensure_tree_ids(el_strip_tree_ids($node));
                $at = $position < 0 ? -1 : $position + $offset;
                $failure = el_insert_into_tree($elements, $copy, $parent_id, $at, $post_id);
                if (is_string($failure)) {
                    return $failure;
                }
                $offset++;
                $inserted++;
            }

            return null;
        },
    );

    if (($result['success'] ?? false) === true) {
        $result['inserted'] = $inserted;
    }

    return $result;
}

<?php

// SPDX-FileCopyrightText: 2026 Elementor MCP <dev@elementormcp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace ElementorMCP\Abilities\Elementor;

use WP_Error;

/**
 * Ability: empty an Elementor document without deleting the post.
 *
 * The honest way to start a page over. Setting an empty element tree through
 * elementor-mcp/elementor-set-content leaves Elementor's own scaffolding behind —
 * the generated CSS file, the cached page assets, the element cache — so the
 * next render can still serve fragments of a page that no longer exists.
 *
 * The post itself, its title, its URL and every other piece of WordPress
 * content survive. Only the Elementor document is cleared, and the change
 * ledger holds a full before-image, so this is undoable.
 */

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('elementor-mcp/elementor-delete-page-content', [
    'label' => __('Delete Elementor Page Content', domain: 'elementor-mcp'),
    'description' => __(
        'Clears all Elementor content from a post, leaving the post itself, its title and its URL in place. Use this to start a page over rather than deleting and recreating it, which would change the post id every link on the site points at. Elementor\'s generated CSS, page assets and element cache are cleared with it, so nothing from the old page survives into the next render. By default the post also stops being an Elementor page and falls back to the theme\'s rendering of its WordPress content; pass keep_builder_mode to leave it as an empty Elementor canvas ready to build into. This is destructive and requires confirmation. The change ledger records the full document, so it can be rolled back.',
        domain: 'elementor-mcp',
    ),
    'category' => 'elementor',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer', 'description' => 'The Elementor document to empty.'],
            'keep_builder_mode' => [
                'type' => 'boolean',
                'default' => false,
                'description' => 'Leave the post flagged as an Elementor page, so it opens as an empty canvas instead of reverting to the theme.',
            ],
            'keep_page_settings' => [
                'type' => 'boolean',
                'default' => true,
                'description' => 'Keep page settings such as the layout template and background. Set false to clear those too.',
            ],
        ],
        'required' => ['post_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'elements_removed' => ['type' => 'integer', 'description' => 'Top-level elements the document held before it was cleared.'],
            'still_elementor' => ['type' => 'boolean', 'description' => 'Whether the post is still flagged as an Elementor page.'],
            'page_settings_kept' => ['type' => 'boolean'],
        ],
        'required' => ['post_id', 'elements_removed'],
    ],
    'execute_callback' => 'ElementorMCP\\Abilities\\Elementor\\elementor_delete_page_content',
    'permission_callback' => 'elementor_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true, 'type' => 'tool'],
        'annotations' => [
            'instructions' => 'Read the document with elementor-mcp/elementor-get-content first if any of it is worth keeping — this empties the whole page in one call.',
            'readonly' => false,
            'destructive' => true,
            'idempotent' => true,
        ],
    ],
]);

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function elementor_delete_page_content(array $input): array|WP_Error
{
    $post_id = (int) ($input['post_id'] ?? 0);
    if (get_post($post_id) === null) {
        return new WP_Error(
            'elementor_post_not_found',
            sprintf(
                /* translators: %d: post id */
                __('No post with id %d exists.', domain: 'elementor-mcp'),
                $post_id,
            ),
            ['status' => 404],
        );
    }

    /** @var mixed $raw */
    $raw = get_post_meta($post_id, key: '_elementor_data', single: true);
    /** @var mixed $decoded */
    $decoded = is_string($raw) ? json_decode($raw, associative: true) : $raw;
    $elements_removed = is_array($decoded) ? count($decoded) : 0;

    // An already-empty document is refused rather than reported as a
    // successful clear of nothing. After this ability runs the meta holds the
    // string '[]', which is not empty as a string but is an empty document —
    // so the count, not the raw value, is what decides.
    if ($elements_removed === 0) {
        return new WP_Error(
            'elementor_no_content',
            sprintf(
                /* translators: %d: post id */
                __('Post %d has no Elementor content to clear.', domain: 'elementor-mcp'),
                $post_id,
            ),
            ['status' => 422],
        );
    }

    $keep_builder_mode = ($input['keep_builder_mode'] ?? false) === true;
    $keep_page_settings = ($input['keep_page_settings'] ?? true) !== false;

    // An empty JSON array rather than a deleted key: Elementor treats a missing
    // _elementor_data and an empty one differently on the editor's first open,
    // and the empty array is the state the editor itself writes.
    update_post_meta($post_id, meta_key: '_elementor_data', meta_value: wp_slash('[]'));

    if (!$keep_builder_mode) {
        delete_post_meta($post_id, meta_key: '_elementor_edit_mode');
    }
    if (!$keep_page_settings) {
        delete_post_meta($post_id, meta_key: '_elementor_page_settings');
    }

    // Everything Elementor derives from the document. Left behind, the CSS file
    // keeps styling elements that no longer exist and the element cache keeps
    // serving their markup.
    delete_post_meta($post_id, meta_key: '_elementor_css');
    delete_post_meta($post_id, meta_key: '_elementor_page_assets');
    delete_post_meta($post_id, meta_key: '_elementor_element_cache');

    if (class_exists('Elementor\\Core\\Files\\CSS\\Post')) {
        $css = new \Elementor\Core\Files\CSS\Post($post_id);
        $css->delete();
    }

    return [
        'post_id' => $post_id,
        'elements_removed' => $elements_removed,
        'still_elementor' => $keep_builder_mode,
        'page_settings_kept' => $keep_page_settings,
    ];
}

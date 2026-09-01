<?php

// SPDX-FileCopyrightText: 2026 Elementor MCP <dev@elementormcp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace ElementorMCP\Abilities\Elementor;

use WP_Error;

/**
 * Ability: find an openly-licensed image and put it on the page, in one call.
 *
 * Search, import and place are three separate abilities in the free plugin and
 * stay that way — a search whose results you want to choose from is a different
 * job from an import. But "the hero needs a photograph of a mountain" is one
 * intention, and making an agent spend three round trips on it is three chances
 * to lose the attribution string between them.
 *
 * Attribution is the reason this composition is worth writing rather than
 * leaving to the caller. Every Creative Commons licence except CC0 and the
 * public domain mark requires it, and the attribution text Openverse returns is
 * the thing that satisfies that requirement. Carried here into the attachment's
 * caption and returned in the response, it survives the trip. Assembled by hand
 * across three calls, it is the field that gets dropped.
 */

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('elementor-mcp/elementor-place-stock-image', [
    'label' => __('Place Stock Image in Elementor', domain: 'elementor-mcp'),
    'description' => __(
        'Searches Openverse for an openly-licensed image, imports the chosen result into the media library with its attribution intact, and sets it on an Elementor element — an image widget, or any element whose setting takes an image. One call instead of search, import and edit. The licence and the ready-made attribution string come back in the response and are written into the attachment\'s caption, because every Creative Commons licence except CC0 requires attribution and that string is what satisfies it. Pass result_index to take a later search result rather than the first. To choose from a list yourself, use elementor-mcp/search-images and elementor-mcp/import-media-url separately.',
        domain: 'elementor-mcp',
    ),
    'category' => 'elementor',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer', 'description' => 'The Elementor document holding the element.'],
            'element_id' => ['type' => 'string', 'description' => 'The element to set the image on, from elementor-mcp/elementor-find-elements.'],
            'query' => ['type' => 'string', 'minLength' => 1, 'description' => 'What to search for, e.g. "misty mountain range".'],
            'setting' => [
                'type' => 'string',
                'default' => 'image',
                'description' => 'Which of the element\'s settings takes the image. "image" for an image widget; a background or icon-box setting otherwise.',
            ],
            'result_index' => [
                'type' => 'integer',
                'default' => 0,
                'minimum' => 0,
                'maximum' => 19,
                'description' => 'Which search result to use. 0 is the first.',
            ],
            'license' => [
                'type' => 'string',
                'description' => 'Restrict to one licence, e.g. "cc0" to avoid an attribution obligation entirely.',
            ],
            'orientation' => [
                'type' => 'string',
                'description' => 'Preferred aspect, passed through to the search.',
            ],
        ],
        'required' => ['post_id', 'element_id', 'query'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'element_id' => ['type' => 'string'],
            'attachment_id' => ['type' => 'integer'],
            'url' => ['type' => 'string'],
            'title' => ['type' => 'string'],
            'license' => ['type' => 'string'],
            'attribution' => ['type' => 'string', 'description' => 'Ready-made credit line. Keep it with the image.'],
            'attribution_required' => ['type' => 'boolean'],
            'results_available' => ['type' => 'integer'],
            'warnings' => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
        'required' => ['post_id', 'element_id', 'attachment_id', 'url'],
    ],
    'execute_callback' => 'ElementorMCP\\Abilities\\Elementor\\elementor_place_stock_image',
    'permission_callback' => 'elementor_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true, 'type' => 'tool'],
        'annotations' => [
            'instructions' => 'Carry the returned attribution into the page — a caption, or the image widget\'s caption setting — unless the licence is cc0 or pdm.',
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function elementor_place_stock_image(array $input): array|WP_Error
{
    foreach (['elementor-mcp/search-images', 'elementor-mcp/import-media-url'] as $required) {
        if (wp_get_ability($required) === null) {
            return new WP_Error(
                'elementor_mcp_free_ability_missing',
                sprintf(
                    /* translators: %s: ability name */
                    __('This composition needs %s from the free Elementor MCP plugin, which is not registered.', domain: 'elementor-mcp'),
                    $required,
                ),
                ['status' => 400],
            );
        }
    }

    $post_id = (int) ($input['post_id'] ?? 0);
    $element_id = trim((string) ($input['element_id'] ?? ''));
    $query = trim((string) ($input['query'] ?? ''));
    $setting = trim((string) ($input['setting'] ?? 'image'));
    $index = max(0, (int) ($input['result_index'] ?? 0));

    if ($query === '' || $element_id === '') {
        return new WP_Error(
            'elementor_stock_missing_input',
            __('query and element_id are both required.', domain: 'elementor-mcp'),
            ['status' => 422],
        );
    }

    $search_input = ['query' => $query, 'per_page' => min(20, $index + 5)];
    if (($input['license'] ?? '') !== '') {
        $search_input['license'] = (string) $input['license'];
    }
    if (($input['orientation'] ?? '') !== '') {
        $search_input['orientation'] = (string) $input['orientation'];
    }

    /** @var mixed $found */
    $found = wp_get_ability('elementor-mcp/search-images')->execute($search_input);
    if (is_wp_error($found)) {
        return $found;
    }
    /** @var mixed $results_raw */
    $results_raw = is_array($found) ? ($found['results'] ?? []) : [];
    $results = is_array($results_raw) ? array_values($results_raw) : [];

    if ($results === []) {
        return new WP_Error(
            'elementor_stock_no_results',
            sprintf(
                /* translators: %s: search query */
                __('Openverse returned no images for "%s".', domain: 'elementor-mcp'),
                $query,
            ),
            ['status' => 404],
        );
    }
    if (!isset($results[$index])) {
        return new WP_Error(
            'elementor_stock_index_out_of_range',
            sprintf(
                /* translators: 1: requested index, 2: number of results */
                __('Result %1$d was requested but only %2$d came back.', domain: 'elementor-mcp'),
                $index,
                count($results),
            ),
            ['status' => 422, 'results_available' => count($results)],
        );
    }

    /** @var array<string, mixed> $chosen */
    $chosen = is_array($results[$index]) ? $results[$index] : [];
    $source_url = (string) ($chosen['url'] ?? '');
    if ($source_url === '') {
        return new WP_Error(
            'elementor_stock_result_unusable',
            __('That search result carries no image url.', domain: 'elementor-mcp'),
            ['status' => 502],
        );
    }

    $title = (string) ($chosen['title'] ?? $query);
    $attribution = (string) ($chosen['attribution'] ?? '');
    $license = strtolower((string) ($chosen['license'] ?? ''));
    // CC0 and the public domain mark are the two that carry no attribution
    // obligation. Everything else on Openverse does.
    $attribution_required = $attribution !== '' && !in_array($license, ['cc0', 'pdm'], strict: true);

    /** @var mixed $imported */
    $imported = wp_get_ability('elementor-mcp/import-media-url')->execute([
        'url' => $source_url,
        'title' => $title,
        // The caption is where the credit survives being handed around: it
        // travels with the attachment rather than living only in this response.
        'caption' => $attribution,
        'alt' => $title,
        'parent' => $post_id,
    ]);
    if (is_wp_error($imported)) {
        return $imported;
    }

    $attachment_id = 0;
    if (is_array($imported)) {
        $attachment_id = (int) ($imported['attachment_id'] ?? $imported['id'] ?? 0);
    }
    if ($attachment_id <= 0) {
        return new WP_Error(
            'elementor_stock_import_failed',
            __('The image was fetched but no attachment id came back.', domain: 'elementor-mcp'),
            ['status' => 500],
        );
    }

    $attachment_url = (string) wp_get_attachment_url($attachment_id);

    /** @var mixed $edited */
    $edited = elementor_edit_element([
        'post_id' => $post_id,
        'element_id' => $element_id,
        'settings' => [
            $setting => ['id' => $attachment_id, 'url' => $attachment_url],
        ],
    ]);
    if (is_wp_error($edited)) {
        // The image is in the library either way; say so rather than implying
        // the whole call failed and leaving an orphan nobody knows about.
        $edited->add_data(['attachment_id' => $attachment_id, 'url' => $attachment_url]);
        return $edited;
    }
    if (is_array($edited) && ($edited['success'] ?? true) === false) {
        return new WP_Error(
            'elementor_stock_place_failed',
            (string) ($edited['error'] ?? __('The element could not be updated.', domain: 'elementor-mcp')),
            ['status' => 422, 'attachment_id' => $attachment_id, 'url' => $attachment_url],
        );
    }

    /** @var list<string> $warnings */
    $warnings = [];
    if ($attribution_required) {
        $warnings[] = sprintf(
            /* translators: %s: licence code, e.g. by-sa */
            __('This image is licensed %s, which requires the attribution to be shown with it. It is stored in the attachment caption.', domain: 'elementor-mcp'),
            $license,
        );
    }

    return [
        'post_id' => $post_id,
        'element_id' => $element_id,
        'attachment_id' => $attachment_id,
        'url' => $attachment_url,
        'title' => $title,
        'license' => $license,
        'attribution' => $attribution,
        'attribution_required' => $attribution_required,
        'results_available' => count($results),
        'warnings' => $warnings,
    ];
}

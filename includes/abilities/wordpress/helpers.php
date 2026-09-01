<?php

// SPDX-FileCopyrightText: 2026 Elementor MCP <dev@elementormcp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace ElementorMCP\Abilities\WordPress;

use WP_Error;
use WP_Post_Type;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Elementor-content guard for the generic WordPress wrapper abilities.
 *
 * Elementor stores its editable document in protected post meta, so the
 * generic wrappers must not hand-write that storage or silently replace the
 * rendered document through post_content. The Elementor-specific abilities
 * own those writes and keep the page editable in Elementor.
 */

/**
 * Elementor storage meta keys, mapped to the Elementor surface that owns them.
 * Compared case-insensitively because WordPress meta keys are stored verbatim
 * while MySQL's default collation is case-insensitive. Ordinary post meta must
 * stay writable.
 *
 * @var array<string, string>
 */
const ELEMENTOR_MCP_BUILDER_STORAGE_META_KEYS = [
    '_elementor_data' => 'Elementor',
    '_elementor_edit_mode' => 'Elementor',
    '_elementor_page_settings' => 'Elementor',
    '_elementor_css' => 'Elementor',
];

/**
 * The ability that owns Elementor content. Consulted through builder_remedy(),
 * which drops the name when the ability is not registered on this site.
 *
 * @var array<string, string>
 */
const ELEMENTOR_MCP_BUILDER_CONTENT_ABILITIES = [
    'Elementor' => 'elementor-mcp/elementor-set-content',
];

/**
 * Elementor stores its element tree in postmeta and renders it instead of
 * post_content. The exact ownership marker below prevents a generic post write
 * from becoming a silent no-op.
 *
 * @var array<string, array{0: string, 1: string|null}>
 */
const ELEMENTOR_MCP_POSTMETA_BUILDER_OWNERS = [
    'Elementor' => ['_elementor_edit_mode', 'builder'],
];

/**
 * Run both builder guards against a wrapper ability's input (after alias
 * normalization): the `content` field against the markup markers and the
 * `meta` map against the storage-key blocklist. Returns the rejection error,
 * or null when the write may proceed.
 *
 * @param array<string, mixed> $input
 */
function wordpress_builder_write_guard(array $input): ?WP_Error
{
    if (array_key_exists('content', $input)) {
        $guard = wordpress_builder_content_error((string) $input['content']);
        if ($guard !== null) {
            return $guard;
        }
    }

    if (is_array($input['meta'] ?? null)) {
        /** @var array<string, mixed> $meta_map */
        $meta_map = $input['meta'];
        return wordpress_builder_meta_error($meta_map);
    }

    return null;
}

/**
 * True when a normalized wrapper input carries a non-empty post_content write.
 *
 * @param array<string, mixed> $input
 */
function wordpress_has_nonempty_content(array $input): bool
{
    return array_key_exists('content', $input) && (string) $input['content'] !== '';
}

/**
 * Name the Elementor surface that owns a post, or null when none does.
 *
 * @return array{builder: string, ability: string}|null
 */
function wordpress_postmeta_builder_owner(int $post_id): ?array
{
    foreach (ELEMENTOR_MCP_POSTMETA_BUILDER_OWNERS as $builder => [$meta_key, $expected]) {
        /** @var mixed $value */
        $value = get_post_meta($post_id, $meta_key, single: true);
        if (!is_scalar($value)) {
            continue;
        }
        $value = (string) $value;

        // '0' is excluded because a disabled flag is often stored, not deleted.
        $owns = $expected === null ? ($value !== '' && $value !== '0') : $value === $expected;

        if ($owns) {
            return ['builder' => $builder, 'ability' => ELEMENTOR_MCP_BUILDER_CONTENT_ABILITIES[$builder] ?? ''];
        }
    }

    return null;
}

/**
 * Require explicit user confirmation before a generic wrapper writes non-empty
 * post_content to an Elementor-owned post.
 *
 * Without this the write is a silent no-op with a success response: see the
 * comment on ELEMENTOR_MCP_POSTMETA_BUILDER_OWNERS. The opt-in is deliberately its own
 * flag so an agent cannot infer permission from an unrelated confirmation, and
 * the refusal names Elementor's own ability when it is installed.
 *
 * Update-only. On create there is no post to own yet, and Elementor's tree is
 * attached afterwards.
 *
 * @param array<string, mixed> $input Normalized wrapper input.
 */
function wordpress_postmeta_builder_content_gate(array $input, int $post_id): ?WP_Error
{
    if (!wordpress_has_nonempty_content($input)) {
        return null;
    }
    if (($input['allow_raw_content_on_builder_post'] ?? false) === true) {
        return null;
    }

    $owner = wordpress_postmeta_builder_owner($post_id);
    if ($owner === null) {
        return null;
    }

    return new WP_Error(
        'builder_owned_post_content_needs_confirmation',
        sprintf(
            'Post %1$d is built with %2$s, which stores its layout in postmeta and renders that '
            . 'instead of post_content. Writing post_content here would be saved by WordPress and '
            . 'change nothing visitors see, and %2$s overwrites it from its own tree the next time '
            . 'the page is saved in its editor. To change the page, %3$s. Only if the user wants the '
            . 'underlying post_content itself changed — for feeds, search results, or a future '
            . 'without %2$s — re-call with allow_raw_content_on_builder_post: true, and only after '
            . 'they have EXPLICITLY confirmed; do not set that flag on your own.',
            $post_id,
            $owner['builder'],
            builder_remedy($owner['ability'], $owner['builder']),
        ),
        ['status' => 422],
    );
}

/**
 * Keep an in-band audit trace after a confirmed raw-content write to a post a
 * Elementor owns.
 *
 * @param array<string, mixed> $input Normalized wrapper input.
 * @return list<string>
 */
function wordpress_postmeta_builder_content_warnings(array $input, int $post_id): array
{
    if (
        !wordpress_has_nonempty_content($input)
        || ($input['allow_raw_content_on_builder_post'] ?? false) !== true
    ) {
        return [];
    }

    $owner = wordpress_postmeta_builder_owner($post_id);
    if ($owner === null) {
        return [];
    }

    return [
        sprintf(
            'AUDIT — Raw post_content was written to %1$s-owned post %2$d after explicit confirmation. '
                . 'It does not change what the page renders, and %1$s will overwrite it from its own tree '
                . 'on the next save in its editor.',
            $owner['builder'],
            $post_id,
        ),
    ];
}

/**
 * Elementor stores its page tree outside post_content, so ordinary WordPress
 * content has no alternate-builder marker to reject here.
 */
function wordpress_builder_content_error(string $content): ?WP_Error
{
    return null;
}

/**
 * Reject a meta map that hand-writes Elementor's storage keys. Returns the
 * rejection error, or null when every key is ordinary post meta.
 *
 * @param array<string, mixed> $meta
 */
function wordpress_builder_meta_error(array $meta): ?WP_Error
{
    foreach (array_keys($meta) as $key) {
        $builder = ELEMENTOR_MCP_BUILDER_STORAGE_META_KEYS[strtolower($key)] ?? null;
        if ($builder === null) {
            continue;
        }
        return new WP_Error(
            'builder_meta_rejected',
            sprintf(
                '"%1$s" is %2$s storage. Never hand-write Elementor storage meta through '
                . 'this ability: %3$s, so the data stays consistent and the page stays editable '
                . 'in Elementor.',
                $key,
                $builder,
                builder_remedy(ELEMENTOR_MCP_BUILDER_CONTENT_ABILITIES[$builder] ?? '', $builder),
            ),
            ['status' => 422],
        );
    }
    return null;
}

/**
 * Registration-time gate for every WordPress-core ability.
 *
 * Deliberately coarse. It answers only "may this connection reach the core
 * surface at all", and the authoritative decision is made inside each execute
 * callback against the specific post type, taxonomy, or object being touched —
 * a permission callback cannot see those, because the target only exists in the
 * input. Gating registration on `manage_options` instead would be both too
 * loose (an administrator still needs the per-type check) and too tight (an
 * Editor holding `edit_posts` could not use the content wrappers at all).
 *
 * The `elementor_mcp_is_enabled()` half is not optional: without it these abilities
 * would keep executing after an administrator switches Elementor MCP off.
 */
function wordpress_core_permission(): bool
{
    return \elementor_mcp_is_enabled() && is_user_logged_in();
}

/**
 * The same gate, plus one specific capability checked up front.
 *
 * Used where a whole ability is meaningless without the capability — comment
 * moderation, for instance — so the refusal happens before any work.
 */
function wordpress_core_permission_for(string $capability): bool
{
    return wordpress_core_permission() && current_user_can($capability);
}

/**
 * Reject URLs whose scheme WordPress does not consider safe.
 *
 * Menu items and similar records store a raw URL that is later rendered into an
 * href. `javascript:`, `data:`, and `vbscript:` URLs there are stored XSS, so
 * the scheme is validated against WordPress's own allowlist rather than a
 * hand-written denylist, and protocol-relative or path-relative values are
 * accepted because they carry no scheme at all.
 */
function wordpress_unsafe_url_error(string $url, string $field = 'url'): ?WP_Error
{
    $trimmed = trim($url);
    if ($trimmed === '') {
        return null;
    }

    $sanitized = esc_url_raw($trimmed);
    if ($sanitized === '') {
        return new WP_Error(
            'unsafe_url',
            sprintf(
                'The %1$s value is not an acceptable URL. Allowed schemes: %2$s.',
                $field,
                implode(', ', wp_allowed_protocols()),
            ),
            ['status' => 422],
        );
    }

    // esc_url_raw() strips a disallowed scheme rather than rejecting it, which
    // would silently rewrite "javascript:alert(1)" into something storable.
    // Comparing schemes catches that instead of trusting the rewrite.
    $original_scheme = strtolower((string) wp_parse_url($trimmed, PHP_URL_SCHEME));
    $sanitized_scheme = strtolower((string) wp_parse_url($sanitized, PHP_URL_SCHEME));
    if ($original_scheme !== '' && $original_scheme !== $sanitized_scheme) {
        return new WP_Error(
            'unsafe_url_scheme',
            sprintf(
                'The %1$s value uses the disallowed scheme "%2$s". Allowed schemes: %3$s.',
                $field,
                $original_scheme,
                implode(', ', wp_allowed_protocols()),
            ),
            ['status' => 422],
        );
    }

    return null;
}

/**
 * Content statuses the core wrappers accept.
 *
 * @var list<string>
 */
const ELEMENTOR_MCP_CORE_CONTENT_STATUSES = ['publish', 'draft', 'pending', 'private', 'future'];

/**
 * Statuses that make content publicly reachable and therefore need `publish_posts`.
 *
 * `future` is included because a scheduled post publishes itself without a second
 * capability check, and `private` because it is a published post with a restricted
 * audience — WordPress maps both through the publish capability.
 *
 * @var list<string>
 */
const ELEMENTOR_MCP_CORE_PUBLISHING_STATUSES = ['publish', 'future', 'private'];

/**
 * Post types the generic content wrappers must never touch.
 *
 * Each is either an internal WordPress bookkeeping type or a type owned by a
 * dedicated ability that validates its structure. Writing them through the
 * generic wrapper produces rows the owning subsystem cannot read back.
 *
 * @var list<string>
 */
const ELEMENTOR_MCP_NON_AGENT_POST_TYPES = [
    'attachment',
    'revision',
    'nav_menu_item',
    'custom_css',
    'customize_changeset',
    'oembed_cache',
    'user_request',
    'wp_block',
    'wp_template',
    'wp_template_part',
    'wp_global_styles',
    'wp_navigation',
];

/**
 * Reject post types that are not appropriate for remote agent use.
 *
 * Two rules, in order: the explicit blocklist above, then a visibility test. A
 * type that is neither `public` nor `show_in_rest` is plugin-private storage —
 * exposing it through a generic wrapper leaks internal state and lets an agent
 * write rows the owning plugin never validates.
 */
function wordpress_post_type_is_agent_facing(string $post_type): ?WP_Error
{
    if (in_array($post_type, ELEMENTOR_MCP_NON_AGENT_POST_TYPES, strict: true)) {
        return new WP_Error(
            'post_type_not_agent_facing',
            sprintf(
                'Post type "%s" is internal WordPress storage or is owned by a dedicated ability. '
                . 'Use the ability built for it rather than the generic content wrapper.',
                $post_type,
            ),
            ['status' => 403],
        );
    }

    $object = get_post_type_object($post_type);
    if (!$object instanceof WP_Post_Type) {
        return new WP_Error(
            'invalid_post_type',
            sprintf('Post type "%s" is not registered.', $post_type),
            ['status' => 404],
        );
    }

    if ($object->public !== true && $object->show_in_rest !== true) {
        return new WP_Error(
            'post_type_not_public',
            sprintf(
                'Post type "%s" is registered as private (neither public nor show_in_rest), so it is not exposed to agents.',
                $post_type,
            ),
            ['status' => 403],
        );
    }

    return null;
}

/**
 * Resolve a requested status draft-first.
 *
 * Anything that is not an explicitly enumerated status — absent, null, blank,
 * wrong type, or an unrecognised string — becomes `draft`. The schema already
 * enumerates the valid values, but the wrappers are also reachable through the
 * REST shim and through rollback replay, so the invariant is enforced here too:
 * content is never published by omission or by a malformed value.
 */
function wordpress_resolve_create_status(mixed $status): string
{
    if (!is_string($status)) {
        return 'draft';
    }

    $normalized = strtolower(trim($status));

    return in_array($normalized, ELEMENTOR_MCP_CORE_CONTENT_STATUSES, strict: true) ? $normalized : 'draft';
}

/**
 * Enforce the post type's own capability object before a create.
 *
 * `edit_posts` is deliberately not assumed: a custom post type may declare an
 * entirely separate capability set, so every check reads from the registered
 * type's `cap` object. Publishing and author reassignment are checked separately
 * because they are distinct grants — an author-level account can create a draft
 * but may not publish it or attribute it to someone else.
 *
 * @param array<string, mixed> $input Normalized input, with `status` already resolved.
 */
function wordpress_create_capability_error(string $post_type, array $input): ?WP_Error
{
    $object = get_post_type_object($post_type);
    if (!$object instanceof WP_Post_Type) {
        return new WP_Error(
            'invalid_post_type',
            sprintf('Post type "%s" is not registered.', $post_type),
            ['status' => 404],
        );
    }

    $capabilities = $object->cap;

    if (!current_user_can((string) $capabilities->create_posts)) {
        return new WP_Error(
            'cannot_create_post',
            sprintf('You are not allowed to create content of type "%s".', $post_type),
            ['status' => 403],
        );
    }

    $status = wordpress_resolve_create_status($input['status'] ?? null);
    if (
        in_array($status, ELEMENTOR_MCP_CORE_PUBLISHING_STATUSES, strict: true)
        && !current_user_can((string) $capabilities->publish_posts)
    ) {
        return new WP_Error(
            'cannot_publish_post',
            sprintf(
                'You are not allowed to publish content of type "%s". Create it as a draft instead.',
                $post_type,
            ),
            ['status' => 403],
        );
    }

    $author = isset($input['author']) ? (int) $input['author'] : 0;
    if (
        $author > 0
        && $author !== get_current_user_id()
        && !current_user_can((string) $capabilities->edit_others_posts)
    ) {
        return new WP_Error(
            'cannot_assign_author',
            'You are not allowed to attribute content to another user.',
            ['status' => 403],
        );
    }

    return null;
}

/**
 * Enforce the publish grant when an update changes a post's status.
 *
 * Only a transition into a publicly reachable status is gated. Editing a
 * published post, or moving it back to draft, needs edit access alone — which
 * the caller has already been checked for.
 *
 * @param \WP_Post $post
 * @param array<string, mixed> $input Normalized input, short field names.
 */
function wordpress_update_status_capability_error(object $post, array $input): ?WP_Error
{
    if (!array_key_exists('status', $input)) {
        return null;
    }

    $requested = wordpress_resolve_create_status($input['status']);
    if ($requested === (string) $post->post_status) {
        return null;
    }
    if (!in_array($requested, ELEMENTOR_MCP_CORE_PUBLISHING_STATUSES, strict: true)) {
        return null;
    }

    $object = get_post_type_object((string) $post->post_type);
    if (!$object instanceof WP_Post_Type) {
        return new WP_Error(
            'invalid_post_type',
            sprintf('Post type "%s" is not registered.', (string) $post->post_type),
            ['status' => 404],
        );
    }

    if (!current_user_can((string) $object->cap->publish_posts)) {
        return new WP_Error(
            'cannot_publish_post',
            sprintf(
                'You are not allowed to publish content of type "%s". Leave the status unchanged or set it to draft.',
                (string) $post->post_type,
            ),
            ['status' => 403],
        );
    }

    return null;
}

/**
 * Register a WordPress-core ability, skipping names another plugin already owns.
 *
 * These abilities shipped in Elementor MCP Pro before this release. A site still running an
 * older Pro alongside this build would otherwise register each name twice, so the
 * first registration wins and the duplicate is dropped without a fatal or a
 * `_doing_it_wrong()` notice. Free registers on `wp_abilities_api_init` at priority
 * 20, ahead of Pro's integration loaders, so Free's definition is the surviving one.
 *
 * @param array<string, mixed> $args
 */
function register_core_ability(string $name, array $args): void
{
    if (function_exists('wp_has_ability') && wp_has_ability($name)) {
        return;
    }

    wp_register_ability($name, $args);
}

/**
 * Name the ability that owns Elementor content, but only when it is registered.
 *
 * The guard ships in Free, where the named Pro ability may not exist.
 * Pointing an agent at an ability it cannot call wastes a round trip, so the
 * fallback states the constraint instead of prescribing an unavailable tool.
 */
function builder_remedy(string $ability, string $builder): string
{
    if (function_exists('wp_has_ability') && wp_has_ability($ability)) {
        return sprintf('use %s (and Elementor\'s other abilities) instead', $ability);
    }

    return sprintf(
        'edit this content in the %1$s editor instead — Elementor MCP Pro adds typed %1$s abilities that write it safely',
        $builder,
    );
}

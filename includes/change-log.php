<?php

// SPDX-FileCopyrightText: 2026 Elementor MCP <dev@elementormcp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

const ELEMENTOR_MCP_CHANGE_LOG_OPTION = 'elementor_mcp_change_log';

const ELEMENTOR_MCP_CHANGE_LOG_MAX = 500;

const ELEMENTOR_MCP_CHANGE_LOG_MAX_BYTES = 4_194_304;

const ELEMENTOR_MCP_CHANGE_SNAPSHOT_MAX_BYTES = 524_288;

/** @return list<array<string, mixed>> */
function elementor_mcp_get_change_log(): array
{
    /** @var mixed $stored */
    $stored = get_option(ELEMENTOR_MCP_CHANGE_LOG_OPTION, default_value: []);
    if (!is_array($stored)) {
        return [];
    }
    $log = [];
    // @mago-expect analysis:mixed-assignment -- Stored option rows are normalized below.
    foreach ($stored as $entry) {
        if (is_array($entry)) {
            $log[] = elementor_mcp_string_keyed_array($entry);
        }
    }
    return $log;
}

/** @param array<string, mixed> $entry */
function elementor_mcp_store_change(array $entry): void
{
    $log = elementor_mcp_get_change_log();
    $log[] = $entry;
    if (count($log) > ELEMENTOR_MCP_CHANGE_LOG_MAX) {
        $log = array_slice(array: $log, offset: -ELEMENTOR_MCP_CHANGE_LOG_MAX);
    }
    while (count($log) > 1) {
        $encoded = wp_json_encode($log);
        if (is_string($encoded) && strlen($encoded) <= ELEMENTOR_MCP_CHANGE_LOG_MAX_BYTES) {
            break;
        }
        array_shift($log);
    }
    update_option(ELEMENTOR_MCP_CHANGE_LOG_OPTION, $log, autoload: false);
}

/** @return array<string, mixed>|null */
function elementor_mcp_get_change(string $id): ?array
{
    foreach (array_reverse(elementor_mcp_get_change_log()) as $entry) {
        if (($entry['id'] ?? null) === $id) {
            return $entry;
        }
    }
    return null;
}

/** @param array<string, mixed> $replacement */
function elementor_mcp_replace_change(string $id, array $replacement): bool
{
    $log = elementor_mcp_get_change_log();
    foreach ($log as $index => $entry) {
        if (($entry['id'] ?? null) !== $id) {
            continue;
        }
        $log[$index] = $replacement;
        update_option(ELEMENTOR_MCP_CHANGE_LOG_OPTION, $log, autoload: false);
        return true;
    }
    return false;
}

/**
 * Capture a before-image for known reversible WordPress operations.
 */
/**
 * Meta-abilities that wrap another write and must not record one of their own.
 *
 * Each of these ends up executing some other ability, which records itself. A
 * second entry for the wrapper carries no before-image, so it would be filed as
 * non-reversible with the reason "No supported before-image" — and with the log
 * capped at ELEMENTOR_MCP_CHANGE_LOG_MAX entries, that junk evicts real history at
 * twice the rate it should.
 *
 * @var list<string>
 */
const ELEMENTOR_MCP_CHANGE_META_ABILITIES = ['elementor-mcp/rollback-change', 'elementor-mcp/apply-preview'];

function elementor_mcp_change_ability_is_meta(string $ability_name): bool
{
    foreach (ELEMENTOR_MCP_CHANGE_META_ABILITIES as $meta) {
        if (str_starts_with($ability_name, $meta)) {
            return true;
        }
    }
    return false;
}

function elementor_mcp_change_before(string $ability_name, mixed $input): void
{
    if (elementor_mcp_change_is_suppressed() || elementor_mcp_change_ability_is_meta($ability_name)) {
        return;
    }
    $ability = function_exists('wp_get_ability') ? wp_get_ability($ability_name) : null;
    if ($ability instanceof WP_Ability && elementor_mcp_ability_is_readonly($ability)) {
        return;
    }
    $values = elementor_mcp_string_keyed_array($input);
    elementor_mcp_change_pending($ability_name, [
        'started_at' => microtime(true),
        'input_summary' => elementor_mcp_redact_for_log($values),
        'input_sha256' => hash('sha256', (string) wp_json_encode($values)),
        'before' => elementor_mcp_capture_before_image($ability_name, $values),
    ]);
}

/**
 * Record a completed mutation. WordPress fires this only after output validation succeeds.
 */
function elementor_mcp_change_after(string $ability_name, mixed $input, mixed $result): void
{
    if (elementor_mcp_change_is_suppressed()) {
        return;
    }
    $pending = elementor_mcp_change_pending($ability_name);
    if ($pending === null) {
        return;
    }
    elementor_mcp_change_pending($ability_name, value: null, clear: true);
    // @mago-expect analysis:mixed-assignment -- Pending data is internal and normalized below.
    $before_value = $pending['before'] ?? null;
    $before = is_array($before_value) ? elementor_mcp_string_keyed_array($before_value) : null;
    $rollback = elementor_mcp_build_rollback_payload($ability_name, $before, $result);
    $ability = function_exists('wp_get_ability') ? wp_get_ability($ability_name) : null;
    $risk = $ability instanceof WP_Ability ? elementor_mcp_ability_risk($ability) : 'write';
    $user = wp_get_current_user();

    elementor_mcp_store_change([
        'id' => wp_generate_uuid4(),
        'ability' => $ability_name,
        'risk' => $risk,
        'recorded_at' => gmdate('c'),
        // @mago-expect analysis:invalid-type-cast -- Pending timestamps are stored internally as floats.
        'duration_ms' => round(
            num: (microtime(true) - (float) ($pending['started_at'] ?? microtime(true))) * 1000,
            precision: 2,
        ),
        'user' => ['id' => (int) $user->ID, 'login' => (string) $user->user_login],
        // Which agent, not just which WordPress user. Several agents share one
        // administrator on most sites, so the user alone cannot attribute a write.
        'agent' => function_exists('elementor_mcp_current_agent') ? elementor_mcp_current_agent() : [],
        'input' => $pending['input_summary'] ?? [],
        'input_sha256' => (string) ($pending['input_sha256'] ?? ''),
        'result' => elementor_mcp_result_summary($result),
        'rollback' => $rollback,
        'rolled_back' => false,
        // What the design gate saw, when it saw anything. In warn mode the write
        // proceeds and this is the only record that it drifted, which is the
        // point: a site owner who is not ready to refuse writes can still audit
        // where the direction slipped.
        'design' => elementor_mcp_change_design_findings($ability_name),
    ]);
}

/**
 * Design-gate findings for the write being recorded, if the gate ran.
 *
 * Read from the gate's own request-scoped store rather than passed down the
 * call chain: the gate runs on a filter well before this function, and
 * threading a value through every transport to reach it would put design
 * plumbing in four files that have nothing to do with design.
 *
 * @return array<string, mixed>
 */
function elementor_mcp_change_design_findings(string $ability_name): array
{
    if (!function_exists('ElementorMCP\Design\Gate\pending_findings')) {
        return [];
    }
    /** @var array<string, mixed> $findings */
    $findings = \ElementorMCP\Design\Gate\pending_findings();
    if (($findings['ability'] ?? '') !== $ability_name) {
        return [];
    }
    unset($findings['ability']);

    return $findings;
}

/**
 * @param array<string, mixed>|null $value
 * @return array<string, mixed>|null
 */
function elementor_mcp_change_pending(string $ability_name, ?array $value = null, bool $clear = false): ?array
{
    /** @var array<string, array<string, mixed>> $pending */
    static $pending = [];
    if ($clear) {
        $existing = $pending[$ability_name] ?? null;
        unset($pending[$ability_name]);
        return $existing;
    }
    if ($value !== null) {
        $pending[$ability_name] = $value;
    }
    return $pending[$ability_name] ?? null;
}

function elementor_mcp_change_is_suppressed(?bool $set = null): bool
{
    static $suppressed = false;
    if ($set !== null) {
        $suppressed = $set;
    }
    return $suppressed;
}

/** @param array<string, mixed> $input @return array<string, mixed>|null */
// @mago-expect lint:cyclomatic-complexity -- Central snapshot routing keeps mutation coverage auditable.
// @mago-expect lint:halstead -- The explicit operation map is intentionally kept in one reviewable place.
function elementor_mcp_capture_before_image(string $ability_name, array $input): ?array
{
    if (in_array($ability_name, ['elementor-mcp/update-post', 'elementor-mcp/delete-post'], strict: true)) {
        return elementor_mcp_snapshot_post((int) ($input['post_id'] ?? $input['id'] ?? 0));
    }
    if ($ability_name === 'elementor-mcp/create-post') {
        return ['type' => 'post-create'];
    }
    if (in_array($ability_name, ['elementor-mcp/update-media', 'elementor-mcp/delete-media'], strict: true)) {
        return elementor_mcp_snapshot_post((int) ($input['attachment_id'] ?? 0));
    }
    if ($ability_name === 'elementor-mcp/update-site-settings') {
        $values = [];
        foreach (array_keys($input) as $key) {
            $values[$key] = get_option($key);
        }
        return ['type' => 'settings', 'values' => $values, 'fingerprint' => elementor_mcp_snapshot_fingerprint($values)];
    }
    if ($ability_name === 'elementor-mcp/upsert-menu-item') {
        $item_id = (int) ($input['item_id'] ?? 0);
        return $item_id > 0 ? elementor_mcp_snapshot_post($item_id) : ['type' => 'menu-create'];
    }
    if ($ability_name === 'elementor-mcp/delete-menu-item') {
        return elementor_mcp_snapshot_post((int) ($input['item_id'] ?? 0));
    }
    if ($ability_name === 'elementor-mcp/woocommerce-update-order-status' && function_exists('wc_get_order')) {
        $order = wc_get_order((int) ($input['order_id'] ?? 0));
        if ($order instanceof WC_Order) {
            $values = ['order_id' => $order->get_id(), 'status' => $order->get_status()];
            return [
                'type' => 'order-status',
                'values' => $values,
                'fingerprint' => elementor_mcp_snapshot_fingerprint($values),
            ];
        }
    }
    if ($ability_name === 'elementor-mcp/woocommerce-create-coupon') {
        return ['type' => 'coupon-create'];
    }
    if ($ability_name === 'elementor-mcp/woocommerce-update-coupon') {
        return elementor_mcp_snapshot_post((int) ($input['coupon_id'] ?? 0));
    }
    if (in_array(
        $ability_name,
        ['elementor-mcp/woocommerce-moderate-review', 'elementor-mcp/woocommerce-delete-review'],
        strict: true,
    )) {
        // @mago-expect analysis:mixed-assignment -- WordPress returns WP_Comment|null for OBJECT output.
        $comment = get_comment((int) ($input['review_id'] ?? 0));
        if ($comment instanceof WP_Comment) {
            $values = ['comment_id' => (int) $comment->comment_ID, 'status' => wp_get_comment_status($comment)];
            return [
                'type' => 'comment-status',
                'values' => $values,
                'fingerprint' => elementor_mcp_snapshot_fingerprint($values),
            ];
        }
    }
    if ($ability_name === 'elementor-mcp/woocommerce-add-order-note') {
        return ['type' => 'order-note-create'];
    }
    $core = elementor_mcp_capture_core_before_image($ability_name, $input);
    if ($core !== null) {
        return $core;
    }

    /**
     * Before-image for an ability this plugin does not know about.
     *
     * Everything above covers the abilities the free plugin registers. Anything
     * else — every builder, form, field and commerce ability another plugin
     * adds — reached the ledger as a logged-but-irreversible row: recorded,
     * visible in the Changes screen, and impossible to undo. Setting the wrong
     * display condition on a header is exactly the mistake somebody wants back.
     *
     * A listener returns the same shape as the built-ins: an array carrying a
     * `type`, and whatever that type's restore path needs. `elementor_mcp_snapshot_post()`
     * is the one to reach for when the effect lives on a post row — it captures
     * fields, meta and terms, which is enough to restore any builder that keeps
     * its document in post meta. Built-in captures always win; this only fills
     * the gap they leave.
     *
     * @param array<string, mixed>|null $before  Null, unless an earlier listener supplied one.
     * @param string                    $ability_name
     * @param array<string, mixed>      $input
     */
    // @mago-expect analysis:mixed-assignment -- Filter output is validated against the snapshot shape below.
    $supplied = apply_filters('elementor_mcp_capture_before_image', null, $ability_name, $input);

    return is_array($supplied) && is_string($supplied['type'] ?? null) && $supplied['type'] !== ''
        ? $supplied
        : null;
}

/**
 * Before-images for the WordPress-core abilities.
 *
 * Split from elementor_mcp_capture_before_image() so the core surface can grow without
 * pushing that function past its complexity budget. Anything whose effect lives
 * on a post row — terms, featured image, attachment parent, revision restore —
 * reuses elementor_mcp_snapshot_post(), which already captures post fields, meta, and
 * every taxonomy assignment together.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|null
 */
// @mago-expect lint:cyclomatic-complexity -- One explicit branch per ability is the safety contract.
function elementor_mcp_capture_core_before_image(string $ability_name, array $input): ?array
{
    if (in_array($ability_name, [
        'elementor-mcp/assign-terms',
        'elementor-mcp/set-featured-image',
        'elementor-mcp/remove-featured-image',
        'elementor-mcp/restore-post',
    ], strict: true)) {
        return elementor_mcp_snapshot_post((int) ($input['post_id'] ?? 0));
    }
    if (in_array($ability_name, ['elementor-mcp/attach-media', 'elementor-mcp/detach-media'], strict: true)) {
        return elementor_mcp_snapshot_post((int) ($input['attachment_id'] ?? 0));
    }
    if ($ability_name === 'elementor-mcp/restore-revision') {
        // The write lands on the parent post, so that is what has to be captured.
        // wp_get_post_revision() takes its first parameter by reference, so the id
        // has to reach it as a variable: handing it the cast expression directly
        // raised "Only variables should be passed by reference" on every capture.
        $revision_id = (int) ($input['revision_id'] ?? 0);
        $revision = wp_get_post_revision($revision_id);
        return $revision instanceof WP_Post ? elementor_mcp_snapshot_post((int) $revision->post_parent) : null;
    }
    if (in_array($ability_name, ['elementor-mcp/update-term', 'elementor-mcp/delete-term'], strict: true)) {
        return elementor_mcp_snapshot_term((int) ($input['term_id'] ?? 0), (string) ($input['taxonomy'] ?? ''));
    }
    if ($ability_name === 'elementor-mcp/update-menu') {
        return elementor_mcp_snapshot_term((int) ($input['menu_id'] ?? 0), 'nav_menu');
    }
    if ($ability_name === 'elementor-mcp/delete-menu') {
        return ['type' => 'menu-delete', 'menu_id' => (int) ($input['menu_id'] ?? 0)];
    }
    if ($ability_name === 'elementor-mcp/create-term' || $ability_name === 'elementor-mcp/create-menu') {
        return ['type' => 'term-create', 'taxonomy' => (string) ($input['taxonomy'] ?? 'nav_menu')];
    }
    if ($ability_name === 'elementor-mcp/create-comment') {
        return ['type' => 'comment-create'];
    }
    if ($ability_name === 'elementor-mcp/update-comment') {
        return elementor_mcp_snapshot_comment((int) ($input['comment_id'] ?? 0));
    }
    if (in_array($ability_name, ['elementor-mcp/moderate-comment', 'elementor-mcp/delete-comment'], strict: true)) {
        // @mago-expect analysis:mixed-assignment -- WordPress returns WP_Comment|null for OBJECT output.
        $comment = get_comment((int) ($input['comment_id'] ?? 0));
        if ($comment instanceof WP_Comment) {
            $values = ['comment_id' => (int) $comment->comment_ID, 'status' => wp_get_comment_status($comment)];
            return [
                'type' => 'comment-status',
                'values' => $values,
                'fingerprint' => elementor_mcp_snapshot_fingerprint($values),
            ];
        }
        return null;
    }
    if ($ability_name === 'elementor-mcp/assign-menu-location') {
        $values = array_map('intval', (array) get_nav_menu_locations());
        return [
            'type' => 'menu-locations',
            'values' => $values,
            'fingerprint' => elementor_mcp_snapshot_fingerprint($values),
        ];
    }
    if ($ability_name === 'elementor-mcp/reorder-menu-items') {
        return elementor_mcp_snapshot_menu_order((int) ($input['menu_id'] ?? 0));
    }
    if (in_array($ability_name, ['elementor-mcp/activate-plugin', 'elementor-mcp/deactivate-plugin'], strict: true)) {
        return elementor_mcp_snapshot_plugin_state((string) ($input['file'] ?? ''));
    }
    if ($ability_name === 'elementor-mcp/switch-theme') {
        $values = ['stylesheet' => get_stylesheet(), 'template' => get_template()];
        return [
            'type' => 'active-theme',
            'values' => $values,
            'fingerprint' => elementor_mcp_snapshot_fingerprint($values),
        ];
    }
    if (in_array($ability_name, [
        'elementor-mcp/install-plugin',
        'elementor-mcp/install-theme',
        'elementor-mcp/update-plugin',
        'elementor-mcp/update-theme',
        'elementor-mcp/delete-plugin',
        'elementor-mcp/delete-theme',
    ], strict: true)) {
        // No before-image is possible — these move files on disk — but the
        // marker still reaches the rollback builder, which then states the real
        // reason instead of the generic "no strategy registered".
        return ['type' => 'extension-files'];
    }
    return null;
}

/**
 * Capture whether a plugin is active, per-site and network-wide.
 *
 * Activation state is the whole before-image: the files are untouched by
 * activate/deactivate, so restoring the flag restores the change.
 *
 * @return array<string, mixed>|null
 */
function elementor_mcp_snapshot_plugin_state(string $file): ?array
{
    if ($file === '') {
        return null;
    }

    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    $values = [
        'file' => $file,
        'active' => is_plugin_active($file),
        'network_active' => is_plugin_active_for_network($file),
    ];

    return ['type' => 'plugin-state', 'values' => $values, 'fingerprint' => elementor_mcp_snapshot_fingerprint($values)];
}

/**
 * Capture a term's editable fields, scoped to the taxonomy the caller named.
 *
 * @return array<string, mixed>|null
 */
function elementor_mcp_snapshot_term(int $term_id, string $taxonomy): ?array
{
    if ($term_id <= 0 || $taxonomy === '') {
        return null;
    }

    // @mago-expect analysis:mixed-assignment -- get_term returns WP_Term|WP_Error|null.
    $term = get_term($term_id, $taxonomy);
    if (!$term instanceof WP_Term) {
        return null;
    }

    $values = [
        'term_id' => (int) $term->term_id,
        'taxonomy' => (string) $term->taxonomy,
        'name' => (string) $term->name,
        'slug' => (string) $term->slug,
        'description' => (string) $term->description,
        'parent' => (int) $term->parent,
    ];

    return ['type' => 'term', 'values' => $values, 'fingerprint' => elementor_mcp_snapshot_fingerprint($values)];
}

/**
 * Capture a comment's editable fields.
 *
 * The commenter's email and IP are deliberately excluded: the ledger is readable
 * by any Elementor MCP administrator, and restoring a comment's text never needs them.
 *
 * @return array<string, mixed>|null
 */
function elementor_mcp_snapshot_comment(int $comment_id): ?array
{
    if ($comment_id <= 0) {
        return null;
    }

    // @mago-expect analysis:mixed-assignment -- WordPress returns WP_Comment|null for OBJECT output.
    $comment = get_comment($comment_id);
    if (!$comment instanceof WP_Comment) {
        return null;
    }

    $values = [
        'comment_id' => (int) $comment->comment_ID,
        'content' => (string) $comment->comment_content,
        'author' => (string) $comment->comment_author,
    ];

    return ['type' => 'comment', 'values' => $values, 'fingerprint' => elementor_mcp_snapshot_fingerprint($values)];
}

/**
 * Capture a menu's item order and nesting so a reorder can be undone exactly.
 *
 * @return array<string, mixed>|null
 */
function elementor_mcp_snapshot_menu_order(int $menu_id): ?array
{
    if ($menu_id <= 0) {
        return null;
    }

    $items = wp_get_nav_menu_items($menu_id);
    if (!is_array($items)) {
        return null;
    }

    $values = [];
    foreach ($items as $item) {
        $values[] = [
            'item_id' => (int) $item->ID,
            'position' => (int) $item->menu_order,
            'parent_id' => (int) $item->menu_item_parent,
        ];
    }

    return [
        'type' => 'menu-order',
        'menu_id' => $menu_id,
        'values' => $values,
        'fingerprint' => elementor_mcp_snapshot_fingerprint($values),
    ];
}

/** @return array<string, mixed>|null */
function elementor_mcp_snapshot_post(int $post_id): ?array
{
    // @mago-expect analysis:mixed-assignment -- ARRAY_A is validated immediately below.
    $post = get_post($post_id, output: 'ARRAY_A');
    if (!is_array($post)) {
        return null;
    }
    // @mago-expect analysis:mixed-assignment -- Full post meta is normalized before iteration.
    $all_meta_value = get_post_meta($post_id);
    $all_meta = is_array($all_meta_value) ? $all_meta_value : [];
    $meta = [];
    $excluded_meta_keys = [];
    // @mago-expect analysis:mixed-assignment -- WordPress post meta values are intentionally opaque.
    foreach ($all_meta as $key => $values) {
        if (elementor_mcp_change_key_is_sensitive((string) $key)) {
            $excluded_meta_keys[] = (string) $key;
            continue;
        }
        // get_post_meta() in its whole-post form hands back what is in the
        // database, still serialized — unlike the single-key form, which
        // unserializes for you. Restoring those raw strings through
        // add_post_meta() serializes them a second time, so an array comes back
        // as the literal string 'a:2:{...}' and every reader of that meta then
        // sees a string where its own data used to be. Any post carrying
        // serialized meta — an Elementor document, an ACF repeater, page
        // settings — was silently corrupted by its own rollback.
        // @mago-expect analysis:mixed-assignment -- Meta values stay opaque; only the serialization layer is removed.
        $meta[(string) $key] = is_array($values)
            ? array_map(static fn(mixed $value): mixed => maybe_unserialize($value), $values)
            : $values;
    }
    $terms = [];
    $taxonomies = get_object_taxonomies((string) $post['post_type'], output: 'names');
    foreach ($taxonomies as $taxonomy) {
        // @mago-expect analysis:mixed-assignment -- fields=ids may return a list or WP_Error and is checked below.
        $ids = wp_get_object_terms($post_id, $taxonomy, ['fields' => 'ids']);
        if (is_array($ids)) {
            $terms[$taxonomy] = array_map('intval', $ids);
        }
    }
    $snapshot = [
        'type' => 'post',
        'post' => $post,
        'meta' => $meta,
        'excluded_meta_keys' => $excluded_meta_keys,
        'terms' => $terms,
    ];
    $encoded = wp_json_encode($snapshot);
    if (!is_string($encoded) || strlen($encoded) > ELEMENTOR_MCP_CHANGE_SNAPSHOT_MAX_BYTES) {
        return ['type' => 'oversize', 'post_id' => $post_id, 'bytes' => is_string($encoded) ? strlen($encoded) : 0];
    }
    $snapshot['fingerprint'] = elementor_mcp_post_snapshot_fingerprint($snapshot);
    return $snapshot;
}

/** @param array<string, mixed>|null $before @return array<string, mixed> */
// @mago-expect lint:cyclomatic-complexity -- Central rollback routing must cover each mutation explicitly.
// @mago-expect lint:halstead -- Explicit non-reversible reasons are part of the safety contract.
function elementor_mcp_build_rollback_payload(string $ability_name, ?array $before, mixed $result): array
{
    if ($before === null || ($before['type'] ?? null) === 'oversize') {
        return [
            'reversible' => false,
            'reason' => $before === null
                ? 'No supported before-image.'
                : 'Before-image exceeded the safe storage limit.',
        ];
    }
    if ($ability_name === 'elementor-mcp/delete-media') {
        return ['reversible' => false, 'reason' => 'The attachment file was permanently deleted.'];
    }
    if ($ability_name === 'elementor-mcp/delete-menu-item') {
        return ['reversible' => false, 'reason' => 'The menu item was permanently deleted.'];
    }
    if (in_array(
        $ability_name,
        ['elementor-mcp/woocommerce-delete-coupon', 'elementor-mcp/woocommerce-create-refund'],
        strict: true,
    )) {
        return [
            'reversible' => false,
            'reason' => 'This commerce operation has external or permanent effects and is not automatically reversible.',
        ];
    }
    if (
        $ability_name === 'elementor-mcp/woocommerce-delete-review'
        && is_array($result)
        && ($result['permanent'] ?? false) === true
    ) {
        return ['reversible' => false, 'reason' => 'The review was permanently deleted.'];
    }
    if ($ability_name === 'elementor-mcp/delete-post' && is_array($result) && ($result['result'] ?? null) === 'deleted') {
        return ['reversible' => false, 'reason' => 'The post was permanently deleted.'];
    }
    if (($before['type'] ?? null) === 'post-create') {
        $post_id = is_array($result) ? (int) ($result['post_id'] ?? 0) : 0;
        return (
            $post_id > 0
                ? ['reversible' => true, 'type' => 'delete-created-post', 'post_id' => $post_id]
                : ['reversible' => false, 'reason' => 'Created post ID was not returned.']
        );
    }
    if (($before['type'] ?? null) === 'menu-create') {
        $item_id = is_array($result) ? (int) ($result['id'] ?? 0) : 0;
        return (
            $item_id > 0
                ? ['reversible' => true, 'type' => 'delete-created-post', 'post_id' => $item_id]
                : ['reversible' => false, 'reason' => 'Created menu item ID was not returned.']
        );
    }
    if (($before['type'] ?? null) === 'coupon-create') {
        $coupon_id = is_array($result) ? (int) ($result['id'] ?? 0) : 0;
        return (
            $coupon_id > 0
                ? ['reversible' => true, 'type' => 'delete-created-post', 'post_id' => $coupon_id]
                : ['reversible' => false, 'reason' => 'Created coupon ID was not returned.']
        );
    }
    if (($before['type'] ?? null) === 'order-note-create') {
        $note_id = is_array($result) ? (int) ($result['note_id'] ?? 0) : 0;
        return (
            $note_id > 0
                ? ['reversible' => true, 'type' => 'delete-created-comment', 'comment_id' => $note_id]
                : ['reversible' => false, 'reason' => 'Created order note ID was not returned.']
        );
    }
    if (($before['type'] ?? null) === 'post') {
        return ['reversible' => true, 'type' => 'restore-post', 'snapshot' => $before];
    }
    if (($before['type'] ?? null) === 'settings') {
        return ['reversible' => true, 'type' => 'restore-settings', 'snapshot' => $before];
    }
    if (($before['type'] ?? null) === 'order-status') {
        return ['reversible' => true, 'type' => 'restore-order-status', 'snapshot' => $before];
    }
    if (($before['type'] ?? null) === 'comment-status') {
        return ['reversible' => true, 'type' => 'restore-comment-status', 'snapshot' => $before];
    }
    return elementor_mcp_build_core_rollback_payload($ability_name, $before, $result);
}

/**
 * Rollback strategies for the WordPress-core abilities.
 *
 * Permanent deletions are declared non-reversible explicitly rather than falling
 * through to the generic "no strategy registered" message, so the ledger states
 * the actual reason a change cannot be undone.
 *
 * @param array<string, mixed> $before
 * @return array<string, mixed>
 */
// @mago-expect lint:cyclomatic-complexity -- Explicit non-reversible reasons are part of the safety contract.
function elementor_mcp_build_core_rollback_payload(string $ability_name, array $before, mixed $result): array
{
    if ($ability_name === 'elementor-mcp/delete-term') {
        return [
            'reversible' => false,
            'reason' => 'WordPress has no trash for terms, so the term was permanently deleted.',
        ];
    }
    if ($ability_name === 'elementor-mcp/delete-menu') {
        return [
            'reversible' => false,
            'reason' => 'The menu and all of its items were permanently deleted.',
        ];
    }
    if ($ability_name === 'elementor-mcp/delete-comment') {
        return ['reversible' => false, 'reason' => 'The comment was permanently deleted.'];
    }
    if (($before['type'] ?? null) === 'term-create') {
        $term_id = is_array($result) ? (int) ($result['term_id'] ?? $result['menu_id'] ?? 0) : 0;
        return (
            $term_id > 0
                ? [
                    'reversible' => true,
                    'type' => 'delete-created-term',
                    'term_id' => $term_id,
                    'taxonomy' => (string) ($before['taxonomy'] ?? ''),
                ]
                : ['reversible' => false, 'reason' => 'Created term ID was not returned.']
        );
    }
    if (($before['type'] ?? null) === 'comment-create') {
        $comment_id = is_array($result) ? (int) ($result['comment_id'] ?? 0) : 0;
        return (
            $comment_id > 0
                ? ['reversible' => true, 'type' => 'delete-created-comment', 'comment_id' => $comment_id]
                : ['reversible' => false, 'reason' => 'Created comment ID was not returned.']
        );
    }
    if (($before['type'] ?? null) === 'term') {
        return ['reversible' => true, 'type' => 'restore-term', 'snapshot' => $before];
    }
    if (($before['type'] ?? null) === 'comment') {
        return ['reversible' => true, 'type' => 'restore-comment', 'snapshot' => $before];
    }
    if (($before['type'] ?? null) === 'menu-locations') {
        return ['reversible' => true, 'type' => 'restore-menu-locations', 'snapshot' => $before];
    }
    if (($before['type'] ?? null) === 'menu-order') {
        return ['reversible' => true, 'type' => 'restore-menu-order', 'snapshot' => $before];
    }
    if (($before['type'] ?? null) === 'plugin-state') {
        return ['reversible' => true, 'type' => 'restore-plugin-state', 'snapshot' => $before];
    }
    if (($before['type'] ?? null) === 'active-theme') {
        return ['reversible' => true, 'type' => 'restore-active-theme', 'snapshot' => $before];
    }
    if (($before['type'] ?? null) === 'extension-files') {
        return ['reversible' => false, 'reason' => elementor_mcp_extension_files_rollback_reason($ability_name)];
    }
    return ['reversible' => false, 'reason' => 'No rollback strategy is registered for this change.'];
}

/**
 * Why a plugin/theme file operation cannot be undone from the ledger.
 *
 * Each reason names the manual path back, because "not reversible" alone tells
 * an operator nothing about what to do next.
 */
function elementor_mcp_extension_files_rollback_reason(string $ability_name): string
{
    return match ($ability_name) {
        'elementor-mcp/install-plugin' => 'Files were written to the server. Remove the plugin with elementor-mcp/delete-plugin.',
        'elementor-mcp/install-theme' => 'Files were written to the server. Remove the theme with elementor-mcp/delete-theme.',
        'elementor-mcp/update-plugin', 'elementor-mcp/update-theme' => 'The previous version was overwritten and is not retained. Reinstall the earlier release from its own source.',
        'elementor-mcp/delete-plugin' => 'The plugin files were permanently deleted. Reinstall it with elementor-mcp/install-plugin.',
        'elementor-mcp/delete-theme' => 'The theme files were permanently deleted. Reinstall it with elementor-mcp/install-theme.',
        default => 'This operation changed files on the server and is not automatically reversible.',
    };
}

/**
 * Delete a term created by a rolled-back change.
 *
 * @return array<string, mixed>|WP_Error
 */
function elementor_mcp_rollback_created_term(int $term_id, string $taxonomy): array|WP_Error
{
    if ($term_id <= 0 || $taxonomy === '') {
        return new WP_Error('elementor_mcp_rollback_invalid_term', __('Created term is unknown.', domain: 'elementor-mcp'));
    }
    // @mago-expect analysis:mixed-assignment -- get_term returns WP_Term|WP_Error|null.
    $term = get_term($term_id, $taxonomy);
    if (!$term instanceof WP_Term) {
        return ['result' => 'already-absent', 'term_id' => $term_id];
    }

    $deleted = wp_delete_term($term_id, $taxonomy);
    if (is_wp_error($deleted)) {
        return $deleted;
    }
    if ($deleted !== true) {
        return new WP_Error('elementor_mcp_rollback_term_failed', __('The term could not be deleted.', domain: 'elementor-mcp'));
    }

    return ['result' => 'deleted', 'term_id' => $term_id, 'taxonomy' => $taxonomy];
}

/**
 * Restore a term's captured fields, verifying the write landed.
 *
 * @param array<string, mixed> $snapshot
 * @return array<string, mixed>|WP_Error
 */
function elementor_mcp_restore_term_snapshot(array $snapshot): array|WP_Error
{
    $values = elementor_mcp_string_keyed_array($snapshot['values'] ?? null);
    $term_id = (int) ($values['term_id'] ?? 0);
    $taxonomy = (string) ($values['taxonomy'] ?? '');
    if ($term_id <= 0 || $taxonomy === '') {
        return new WP_Error('elementor_mcp_rollback_invalid_term', __('Term snapshot is incomplete.', domain: 'elementor-mcp'));
    }

    $updated = wp_update_term($term_id, $taxonomy, [
        'name' => (string) ($values['name'] ?? ''),
        'slug' => (string) ($values['slug'] ?? ''),
        'description' => (string) ($values['description'] ?? ''),
        'parent' => (int) ($values['parent'] ?? 0),
    ]);
    if (is_wp_error($updated)) {
        return $updated;
    }

    $observed = elementor_mcp_snapshot_term($term_id, $taxonomy);
    if ($observed === null) {
        return new WP_Error(
            'elementor_mcp_rollback_unverified',
            __('The term could not be re-read after the restore.', domain: 'elementor-mcp'),
        );
    }

    return ['result' => 'restored', 'term_id' => $term_id, 'fingerprint' => $observed['fingerprint'] ?? ''];
}

/**
 * Restore a comment's captured content.
 *
 * @param array<string, mixed> $snapshot
 * @return array<string, mixed>|WP_Error
 */
function elementor_mcp_restore_comment_snapshot(array $snapshot): array|WP_Error
{
    $values = elementor_mcp_string_keyed_array($snapshot['values'] ?? null);
    $comment_id = (int) ($values['comment_id'] ?? 0);
    if ($comment_id <= 0) {
        return new WP_Error(
            'elementor_mcp_rollback_invalid_comment',
            __('Comment snapshot is incomplete.', domain: 'elementor-mcp'),
        );
    }

    $updated = wp_update_comment([
        'comment_ID' => $comment_id,
        'comment_content' => (string) ($values['content'] ?? ''),
        'comment_author' => (string) ($values['author'] ?? ''),
    ], wp_error: true);
    if (is_wp_error($updated)) {
        return $updated;
    }

    return ['result' => 'restored', 'comment_id' => $comment_id];
}

/**
 * Restore the theme's navigation-location assignments.
 *
 * @param array<string, mixed> $snapshot
 * @return array<string, mixed>
 */
function elementor_mcp_restore_menu_locations_snapshot(array $snapshot): array
{
    $values = array_map('intval', elementor_mcp_string_keyed_array($snapshot['values'] ?? null));
    set_theme_mod('nav_menu_locations', $values);

    return ['result' => 'restored', 'locations' => count($values)];
}

/**
 * Put a plugin back into its captured activation state.
 *
 * Reactivation runs the plugin's activation hooks again, which is what the
 * original activation did too — the alternative, a silent flag flip, leaves a
 * plugin marked active with none of its setup performed.
 *
 * @param array<string, mixed> $snapshot
 * @return array<string, mixed>|WP_Error
 */
function elementor_mcp_restore_plugin_state_snapshot(array $snapshot): array|WP_Error
{
    $values = elementor_mcp_string_keyed_array($snapshot['values'] ?? null);
    $file = (string) ($values['file'] ?? '');
    if ($file === '') {
        return new WP_Error('elementor_mcp_rollback_invalid_plugin', __(
            'The captured plugin file is missing from the change record.',
            domain: 'elementor-mcp',
        ));
    }

    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    if (!array_key_exists($file, get_plugins())) {
        return new WP_Error('elementor_mcp_rollback_target_missing', __(
            'The plugin is no longer installed, so its activation state cannot be restored.',
            domain: 'elementor-mcp',
        ));
    }

    $network_active = ($values['network_active'] ?? false) === true;
    $was_active = ($values['active'] ?? false) === true || $network_active;

    if ($was_active) {
        $activated = activate_plugin($file, redirect: '', network_wide: $network_active, silent: false);
        if (is_wp_error($activated)) {
            return $activated;
        }
    } else {
        deactivate_plugins([$file], silent: false, network_wide: $network_active ? true : null);
    }

    $now_active = is_plugin_active($file) || is_plugin_active_for_network($file);

    return [
        'file' => $file,
        'active' => $now_active,
        'verified' => $now_active === $was_active,
    ];
}

/**
 * Switch back to the theme that was active before the change.
 *
 * @param array<string, mixed> $snapshot
 * @return array<string, mixed>|WP_Error
 */
function elementor_mcp_restore_active_theme_snapshot(array $snapshot): array|WP_Error
{
    $values = elementor_mcp_string_keyed_array($snapshot['values'] ?? null);
    $stylesheet = (string) ($values['stylesheet'] ?? '');
    if ($stylesheet === '') {
        return new WP_Error('elementor_mcp_rollback_invalid_theme', __(
            'The captured theme is missing from the change record.',
            domain: 'elementor-mcp',
        ));
    }
    if (!wp_get_theme($stylesheet)->exists()) {
        return new WP_Error('elementor_mcp_rollback_target_missing', __(
            'The previously active theme is no longer installed, so it cannot be restored.',
            domain: 'elementor-mcp',
        ));
    }

    switch_theme($stylesheet);

    return [
        'stylesheet' => $stylesheet,
        'active' => get_stylesheet(),
        'verified' => get_stylesheet() === $stylesheet,
    ];
}

/**
 * Restore a menu's captured item order and nesting.
 *
 * Reports a partial failure honestly rather than claiming a clean rollback: a
 * half-restored menu order is worse than a reported one, because the operator
 * would otherwise believe the original order is back.
 *
 * @param array<string, mixed> $snapshot
 * @return array<string, mixed>|WP_Error
 */
function elementor_mcp_restore_menu_order_snapshot(array $snapshot): array|WP_Error
{
    $menu_id = (int) ($snapshot['menu_id'] ?? 0);
    if ($menu_id <= 0 || wp_get_nav_menu_object($menu_id) === false) {
        return new WP_Error(
            'elementor_mcp_rollback_menu_missing',
            __('The menu no longer exists, so its order cannot be restored.', domain: 'elementor-mcp'),
        );
    }

    $restored = 0;
    /** @var mixed $entry */
    foreach (elementor_mcp_string_list_of_arrays($snapshot['values'] ?? null) as $entry) {
        $result = wp_update_nav_menu_item($menu_id, (int) ($entry['item_id'] ?? 0), [
            'menu-item-position' => (int) ($entry['position'] ?? 0),
            'menu-item-parent-id' => (int) ($entry['parent_id'] ?? 0),
        ]);
        if (is_wp_error($result)) {
            return new WP_Error('elementor_mcp_rollback_partial', sprintf(
                /* translators: 1: number of items restored, 2: the underlying error */
                __(
                    'The menu order was only partially restored: %1$d items were moved before the failure (%2$s). Re-read the menu before relying on its order.',
                    domain: 'elementor-mcp',
                ),
                $restored,
                $result->get_error_message(),
            ));
        }
        ++$restored;
    }

    return ['result' => 'restored', 'menu_id' => $menu_id, 'items' => $restored];
}

/**
 * Normalize a stored list of associative arrays.
 *
 * @return list<array<string, mixed>>
 */
function elementor_mcp_string_list_of_arrays(mixed $value): array
{
    if (!is_array($value)) {
        return [];
    }

    $rows = [];
    /** @var mixed $row */
    foreach ($value as $row) {
        if (is_array($row)) {
            $rows[] = elementor_mcp_string_keyed_array($row);
        }
    }

    return $rows;
}

/** @return array<string, mixed>|WP_Error */
// @mago-expect lint:cyclomatic-complexity -- The rollback transaction validates every terminal state.
function elementor_mcp_rollback_change(string $id): array|WP_Error
{
    $entry = elementor_mcp_get_change($id);
    if ($entry === null) {
        return new WP_Error('elementor_mcp_change_not_found', __('Change record not found.', domain: 'elementor-mcp'));
    }
    if (($entry['rolled_back'] ?? false) === true) {
        return new WP_Error('elementor_mcp_change_already_rolled_back', __(
            'This change was already rolled back.',
            domain: 'elementor-mcp',
        ));
    }
    $rollback = is_array($entry['rollback'] ?? null) ? $entry['rollback'] : [];
    if (($rollback['reversible'] ?? false) !== true) {
        return new WP_Error(
            'elementor_mcp_change_not_reversible',
            (string) ($rollback['reason'] ?? __('This change is not reversible.', domain: 'elementor-mcp')),
        );
    }

    elementor_mcp_change_is_suppressed(true);
    try {
        $result = match ($rollback['type'] ?? '') {
            'delete-created-post' => elementor_mcp_rollback_created_post((int) ($rollback['post_id'] ?? 0)),
            'restore-post' => elementor_mcp_restore_post_snapshot(elementor_mcp_string_keyed_array($rollback['snapshot'] ?? null)),
            'restore-settings' => elementor_mcp_restore_settings_snapshot(elementor_mcp_string_keyed_array(
                $rollback['snapshot'] ?? null,
            )),
            'restore-order-status' => elementor_mcp_restore_order_status(elementor_mcp_string_keyed_array(
                $rollback['snapshot'] ?? null,
            )),
            'restore-comment-status' => elementor_mcp_restore_comment_status(elementor_mcp_string_keyed_array(
                $rollback['snapshot'] ?? null,
            )),
            'delete-created-comment' => elementor_mcp_rollback_created_comment((int) ($rollback['comment_id'] ?? 0)),
            'delete-created-term' => elementor_mcp_rollback_created_term(
                (int) ($rollback['term_id'] ?? 0),
                (string) ($rollback['taxonomy'] ?? ''),
            ),
            'restore-term' => elementor_mcp_restore_term_snapshot(elementor_mcp_string_keyed_array($rollback['snapshot'] ?? null)),
            'restore-comment' => elementor_mcp_restore_comment_snapshot(elementor_mcp_string_keyed_array(
                $rollback['snapshot'] ?? null,
            )),
            'restore-menu-locations' => elementor_mcp_restore_menu_locations_snapshot(elementor_mcp_string_keyed_array(
                $rollback['snapshot'] ?? null,
            )),
            'restore-menu-order' => elementor_mcp_restore_menu_order_snapshot(elementor_mcp_string_keyed_array(
                $rollback['snapshot'] ?? null,
            )),
            'restore-plugin-state' => elementor_mcp_restore_plugin_state_snapshot(elementor_mcp_string_keyed_array(
                $rollback['snapshot'] ?? null,
            )),
            'restore-active-theme' => elementor_mcp_restore_active_theme_snapshot(elementor_mcp_string_keyed_array(
                $rollback['snapshot'] ?? null,
            )),
            default => new WP_Error('elementor_mcp_rollback_unknown', __('Unknown rollback strategy.', domain: 'elementor-mcp')),
        };
    } catch (Throwable $error) {
        $result = new WP_Error('elementor_mcp_rollback_failed', $error->getMessage());
    } finally {
        elementor_mcp_change_is_suppressed(false);
    }
    if ($result instanceof WP_Error) {
        return $result;
    }
    if (($result['verified'] ?? false) !== true) {
        return new WP_Error(
            'elementor_mcp_rollback_unverified',
            __('Rollback ran but the observed state did not match the before-image.', domain: 'elementor-mcp'),
            $result,
        );
    }
    $entry['rolled_back'] = true;
    $entry['rolled_back_at'] = gmdate('c');
    $entry['rollback_result'] = $result;
    elementor_mcp_replace_change($id, $entry);
    return ['change_id' => $id, 'rolled_back' => true, 'verified' => true, 'details' => $result];
}

/** @return array<string, mixed>|WP_Error */
function elementor_mcp_rollback_created_post(int $post_id): array|WP_Error
{
    if ($post_id <= 0 || !get_post($post_id)) {
        return new WP_Error('elementor_mcp_rollback_target_missing', __('Created post no longer exists.', domain: 'elementor-mcp'));
    }
    if (wp_trash_post($post_id) === false) {
        return new WP_Error('elementor_mcp_rollback_delete_failed', __(
            'Could not move the created post to trash.',
            domain: 'elementor-mcp',
        ));
    }
    return [
        'post_id' => $post_id,
        'status' => get_post_status($post_id),
        'verified' => get_post_status($post_id) === 'trash',
    ];
}

/** @param array<string, mixed> $snapshot @return array<string, mixed>|WP_Error */
// @mago-expect lint:cyclomatic-complexity -- Restoring posts requires independent post, meta, and taxonomy steps.
function elementor_mcp_restore_post_snapshot(array $snapshot): array|WP_Error
{
    $post = elementor_mcp_string_keyed_array($snapshot['post'] ?? null);
    $post_id = (int) ($post['ID'] ?? 0);
    if ($post_id <= 0 || !get_post($post_id)) {
        return new WP_Error('elementor_mcp_rollback_target_missing', __(
            'The post required for rollback no longer exists.',
            domain: 'elementor-mcp',
        ));
    }
    $allowed = [
        'ID',
        'post_author',
        'post_date',
        'post_date_gmt',
        'post_content',
        'post_title',
        'post_excerpt',
        'post_status',
        'comment_status',
        'ping_status',
        'post_password',
        'post_name',
        'post_parent',
        'menu_order',
        'post_type',
        'post_mime_type',
    ];
    $postarr = array_intersect_key($post, array_fill_keys(keys: $allowed, value: true));
    foreach (['post_content', 'post_title', 'post_excerpt', 'post_name'] as $key) {
        if (array_key_exists($key, $postarr)) {
            $postarr[$key] = wp_slash((string) $postarr[$key]);
        }
    }
    // @mago-expect analysis:possibly-invalid-argument -- Keys are restricted to WP's post update allowlist above.
    $updated = wp_update_post($postarr, wp_error: true);
    if (is_wp_error($updated)) {
        return $updated;
    }
    $excluded_meta_keys = elementor_mcp_string_list($snapshot['excluded_meta_keys'] ?? []);
    // @mago-expect analysis:mixed-assignment -- Full post meta is normalized before reading its keys.
    $current_meta_value = get_post_meta($post_id);
    $current_meta = is_array($current_meta_value) ? $current_meta_value : [];
    foreach (array_keys($current_meta) as $key) {
        if (in_array((string) $key, $excluded_meta_keys, strict: true)) {
            continue;
        }
        delete_post_meta($post_id, (string) $key);
    }
    // @mago-expect analysis:mixed-assignment -- Snapshot values are validated at each nesting level.
    foreach (elementor_mcp_string_keyed_array($snapshot['meta'] ?? []) as $key => $values) {
        // @mago-expect analysis:mixed-assignment -- Individual post-meta values are intentionally opaque.
        foreach (is_array($values) ? $values : [] as $value) {
            add_post_meta($post_id, $key, $value);
        }
    }
    // @mago-expect analysis:mixed-assignment -- Term lists are normalized to integers below.
    foreach (elementor_mcp_string_keyed_array($snapshot['terms'] ?? []) as $taxonomy => $term_ids) {
        wp_set_object_terms(
            $post_id,
            array_map('intval', is_array($term_ids) ? $term_ids : []),
            $taxonomy,
            append: false,
        );
    }
    $observed = elementor_mcp_snapshot_post($post_id);
    $expected = (string) ($snapshot['fingerprint'] ?? '');
    $actual = is_array($observed) ? (string) ($observed['fingerprint'] ?? '') : '';
    return [
        'post_id' => $post_id,
        'expected_fingerprint' => $expected,
        'observed_fingerprint' => $actual,
        'verified' => $expected !== '' && hash_equals($expected, $actual),
    ];
}

/** @param array<string, mixed> $snapshot @return array<string, mixed> */
function elementor_mcp_restore_settings_snapshot(array $snapshot): array
{
    $values = elementor_mcp_string_keyed_array($snapshot['values'] ?? null);
    // @mago-expect analysis:mixed-assignment -- Option snapshots preserve their original value types.
    foreach ($values as $key => $value) {
        update_option((string) $key, $value);
    }
    $observed = [];
    foreach (array_keys($values) as $key) {
        // @mago-expect analysis:mixed-assignment -- Option values are fingerprinted without interpretation.
        $observed[$key] = get_option((string) $key);
    }
    $expected = (string) ($snapshot['fingerprint'] ?? '');
    $actual = elementor_mcp_snapshot_fingerprint($observed);
    return [
        'settings' => array_keys($values),
        'expected_fingerprint' => $expected,
        'observed_fingerprint' => $actual,
        'verified' => $expected !== '' && hash_equals($expected, $actual),
    ];
}

/** @param array<string, mixed> $snapshot @return array<string, mixed>|WP_Error */
function elementor_mcp_restore_order_status(array $snapshot): array|WP_Error
{
    if (!function_exists('wc_get_order')) {
        return new WP_Error('elementor_mcp_rollback_dependency_missing', __(
            'WooCommerce is required to restore this order status.',
            domain: 'elementor-mcp',
        ));
    }
    $values = elementor_mcp_string_keyed_array($snapshot['values'] ?? null);
    $order = wc_get_order((int) ($values['order_id'] ?? 0));
    if (!$order instanceof WC_Order) {
        return new WP_Error('elementor_mcp_rollback_target_missing', __(
            'The order required for rollback no longer exists.',
            domain: 'elementor-mcp',
        ));
    }
    $status = sanitize_key((string) ($values['status'] ?? ''));
    $order->update_status($status, __('Order status restored by Elementor MCP rollback.', domain: 'elementor-mcp'), true);
    $observed = ['order_id' => $order->get_id(), 'status' => $order->get_status()];
    $expected = (string) ($snapshot['fingerprint'] ?? '');
    $actual = elementor_mcp_snapshot_fingerprint($observed);
    return [
        'order_id' => $order->get_id(),
        'status' => $order->get_status(),
        'verified' => $expected !== '' && hash_equals($expected, $actual),
    ];
}

/** @param array<string, mixed> $snapshot @return array<string, mixed>|WP_Error */
function elementor_mcp_restore_comment_status(array $snapshot): array|WP_Error
{
    $values = elementor_mcp_string_keyed_array($snapshot['values'] ?? null);
    $comment_id = (int) ($values['comment_id'] ?? 0);
    if (!$comment_id || !get_comment($comment_id)) {
        return new WP_Error('elementor_mcp_rollback_target_missing', __(
            'The comment required for rollback no longer exists.',
            domain: 'elementor-mcp',
        ));
    }
    $status = match ((string) ($values['status'] ?? 'hold')) {
        'approve' => 'approve',
        'spam' => 'spam',
        'trash' => 'trash',
        default => 'hold',
    };
    $updated = wp_set_comment_status($comment_id, $status, wp_error: true);
    if ($updated instanceof WP_Error) {
        return $updated;
    }
    $observed = ['comment_id' => $comment_id, 'status' => wp_get_comment_status($comment_id)];
    $expected = (string) ($snapshot['fingerprint'] ?? '');
    $actual = elementor_mcp_snapshot_fingerprint($observed);
    return [
        'comment_id' => $comment_id,
        'status' => $observed['status'],
        'verified' => $expected !== '' && hash_equals($expected, $actual),
    ];
}

/** @return array<string, mixed>|WP_Error */
function elementor_mcp_rollback_created_comment(int $comment_id): array|WP_Error
{
    if ($comment_id <= 0 || !get_comment($comment_id)) {
        return new WP_Error('elementor_mcp_rollback_target_missing', __(
            'The created comment no longer exists.',
            domain: 'elementor-mcp',
        ));
    }
    if (!wp_delete_comment($comment_id, force_delete: true)) {
        return new WP_Error('elementor_mcp_rollback_delete_failed', __(
            'The created comment could not be deleted.',
            domain: 'elementor-mcp',
        ));
    }
    return ['comment_id' => $comment_id, 'verified' => get_comment($comment_id) === null];
}

/**
 * Post columns that move on their own and must never count as a change.
 *
 * WordPress rewrites post_modified on every save, guid is derived, and filter is
 * a runtime marker rather than stored state. The fingerprint below drops them so
 * an unrelated touch does not read as drift, and includes/preview/diff.php drops
 * the same list so a preview does not report them as edits.
 *
 * The two must stay one list. If the differ ignored a column the fingerprint
 * hashed, a preview would show "no changes" and then fail its own drift check;
 * if the fingerprint ignored a column the differ reported, every preview would
 * carry a phantom entry.
 *
 * @var list<string>
 */
const ELEMENTOR_MCP_VOLATILE_POST_FIELDS = ['post_modified', 'post_modified_gmt', 'guid', 'filter'];

/** @param array<string, mixed> $snapshot */
function elementor_mcp_post_snapshot_fingerprint(array $snapshot): string
{
    $post = is_array($snapshot['post'] ?? null) ? $snapshot['post'] : [];
    foreach (ELEMENTOR_MCP_VOLATILE_POST_FIELDS as $volatile) {
        unset($post[$volatile]);
    }
    return elementor_mcp_snapshot_fingerprint([
        'post' => $post,
        'meta' => $snapshot['meta'] ?? [],
        'terms' => $snapshot['terms'] ?? [],
    ]);
}

function elementor_mcp_snapshot_fingerprint(mixed $value): string
{
    return hash('sha256', (string) wp_json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

/** @return array<string, mixed> */
function elementor_mcp_result_summary(mixed $result): array
{
    if (is_array($result)) {
        // @mago-expect analysis:mixed-assignment -- Recursive redaction deliberately preserves safe scalar types.
        $safe = elementor_mcp_redact_for_log($result);
        return [
            'type' => 'array',
            'keys' => array_slice(array: array_map('strval', array_keys($result)), offset: 0, length: 50),
            'summary' => $safe,
        ];
    }
    if (is_object($result)) {
        return ['type' => get_class($result)];
    }
    return [
        'type' => gettype($result),
        'value' => is_scalar($result) ? mb_substr((string) $result, start: 0, length: 200) : null,
    ];
}

/** @return mixed */
function elementor_mcp_redact_for_log(mixed $value, int $depth = 0): mixed
{
    if ($depth > 4) {
        return '[depth-limited]';
    }
    if (!is_array($value)) {
        return is_string($value) && strlen($value) > 500 ? mb_substr($value, start: 0, length: 500) . '...' : $value;
    }
    $result = [];
    foreach (array_slice(array: $value, offset: 0, length: 100, preserve_keys: true) as $key => $item) {
        if (elementor_mcp_change_key_is_sensitive((string) $key)) {
            $result[$key] = '[redacted]';
            continue;
        }
        // @mago-expect analysis:mixed-assignment -- Recursive redaction deliberately preserves safe scalar types.
        $result[$key] = elementor_mcp_redact_for_log($item, $depth + 1);
    }
    if (count($value) > 100) {
        $result['__truncated_items'] = count($value) - 100;
    }
    return $result;
}

function elementor_mcp_change_key_is_sensitive(string $key): bool
{
    return (
        preg_match(
            '/password|passwd|secret|token|authorization|api[_-]?key|private[_-]?key|license|cookie|credential|php[_-]?code|source[_-]?code/',
            strtolower($key),
        ) === 1
    );
}

/** @return array<string, mixed> */
function elementor_mcp_string_keyed_array(mixed $value): array
{
    if (!is_array($value)) {
        return [];
    }
    $result = [];
    foreach ($value as $key => $item) {
        if (is_string($key)) {
            $result[$key] = $item;
        }
    }
    return $result;
}

/** @return list<string> */
function elementor_mcp_string_list(mixed $value): array
{
    if (!is_array($value)) {
        return [];
    }
    $result = [];
    // @mago-expect analysis:mixed-assignment -- Only string members are retained.
    foreach ($value as $item) {
        if (is_string($item)) {
            $result[] = $item;
        }
    }
    return $result;
}

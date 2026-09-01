<?php

// SPDX-FileCopyrightText: 2026 Elementor MCP <dev@elementormcp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

// phpcs:disable WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- Every state-changing request on this screen verifies a nonce via check_admin_referer() before acting; the sniff cannot trace that across function boundaries. Reads are type-checked, whitelist-compared, and escaped on output.

namespace ElementorMCP\Design\Admin;

if (!defined('ABSPATH')) {
    exit();
}

const PAGE_SLUG = 'elementor-mcp-design';

function capability(): string
{
    return \elementor_mcp_manage_capability();
}

function current_user_can_manage(): bool
{
    return \elementor_mcp_current_user_can_manage();
}

function register_menu(): void
{
    add_submenu_page(
        parent_slug: 'elementor-mcp-connect',
        page_title: \elementor_mcp_nav_label('elementor-mcp-design'),
        menu_title: \elementor_mcp_nav_label('elementor-mcp-design'),
        capability: capability(),
        menu_slug: PAGE_SLUG,
        callback: __NAMESPACE__ . '\\render_page',
    );
}

/** Place the Design entry immediately after Skills (`elementor-mcp-skills`). */
// @mago-expect lint:no-global
function reorder_submenu(): void
{
    global $submenu;
    if (!is_array($submenu ?? null) || !is_array($submenu['elementor-mcp-connect'] ?? null)) {
        return;
    }
    /** @var array<int, array<int, string>> $entries */
    $entries = $submenu['elementor-mcp-connect'];
    $self = null;
    foreach ($entries as $key => $entry) {
        if (($entry[2] ?? null) !== PAGE_SLUG) {
            continue;
        }
        $self = $entry;
        unset($entries[$key]);
        break;
    }
    if ($self === null) {
        return;
    }
    $reordered = [];
    $inserted = false;
    foreach ($entries as $entry) {
        $reordered[] = $entry;
        if (!$inserted && ($entry[2] ?? null) === 'elementor-mcp-skills') {
            $reordered[] = $self;
            $inserted = true;
        }
    }
    if (!$inserted) {
        $reordered[] = $self;
    }
    $submenu['elementor-mcp-connect'] = $reordered;
}

function render_page(): void
{
    if (!current_user_can_manage()) {
        wp_die(esc_html__('You do not have permission to manage the design system.', domain: 'elementor-mcp'));
    }
    if (($_GET['view'] ?? null) !== null) {
        require __DIR__ . '/templates/detail.php';
        return;
    }
    if (($_GET['design'] ?? null) !== null) {
        require __DIR__ . '/templates/edit.php';
        return;
    }
    if (($_GET['import'] ?? null) !== null) {
        require __DIR__ . '/templates/import.php';
        return;
    }
    require __DIR__ . '/templates/panel.php';
}

function register_post_handlers(): void
{
    add_action('admin_post_elementor_mcp_design_activate', __NAMESPACE__ . '\\handle_activate');
    add_action('admin_post_elementor_mcp_design_import', __NAMESPACE__ . '\\handle_import');
    add_action('admin_post_elementor_mcp_design_save', __NAMESPACE__ . '\\handle_save');
    add_action('admin_post_elementor_mcp_design_duplicate', __NAMESPACE__ . '\\handle_duplicate');
    add_action('admin_post_elementor_mcp_design_use_starter', __NAMESPACE__ . '\\handle_use_starter');
    add_action('admin_post_elementor_mcp_design_restore', __NAMESPACE__ . '\\handle_restore');
    add_action('admin_post_elementor_mcp_design_delete', __NAMESPACE__ . '\\handle_delete');
}

function enqueue_assets(string $hook): void
{
    if ($hook !== 'elementor-mcp_page_' . PAGE_SLUG) {
        return;
    }
    wp_enqueue_style(
        'elementor-mcp-design-admin',
        (string) ELEMENTOR_MCP_PLUGIN_URL . 'includes/design/assets/admin.css',
        [],
        \elementor_mcp_asset_version('includes/design/assets/admin.css'),
    );
    // phpcs:disable WordPress.WP.EnqueuedResourceParameters.NotInFooter -- `args: true` IS the in_footer flag; the sniff cannot read PHP named arguments.
    wp_enqueue_script(
        'elementor-mcp-design-admin',
        (string) ELEMENTOR_MCP_PLUGIN_URL . 'includes/design/assets/admin.js',
        [],
        \elementor_mcp_asset_version('includes/design/assets/admin.js'),
        args: true,
    );
    // phpcs:enable WordPress.WP.EnqueuedResourceParameters.NotInFooter
    wp_add_inline_script(
        'elementor-mcp-design-admin',
        'window.elementorMcpDesignFontNote = '
        . (string) wp_json_encode([
            /* translators: %s: comma separated list of font names */
            'missing' => __(
                'This preview is not showing the design\'s real fonts (%s). The site itself displays them normally. To see them here, press:',
                domain: 'elementor-mcp',
            ),
            'load' => __('Preview with Google Fonts', domain: 'elementor-mcp'),
            /* translators: %s: the font name Google Fonts does not carry */
            'notOnGoogle' => __('Google Fonts does not have %s.', domain: 'elementor-mcp'),
            'copied' => __('Copied', domain: 'elementor-mcp'),
        ])
        . ';',
        position: 'before',
    );
    // Fonts the site itself hosts (theme.json, Font Library) render faithfully
    // in the previews; everything is served same-origin.
    add_action('admin_head', static function (): void {
        if (function_exists('wp_print_font_faces')) {
            wp_print_font_faces();
        }
    });
    if (($_GET['design'] ?? null) === null) {
        return;
    }
    $settings = wp_enqueue_code_editor(['type' => 'text/markdown']);
    if ($settings !== false) {
        wp_add_inline_script('code-editor', sprintf(
            'jQuery(function($){ var el=document.getElementById("elementor-mcp-design-content"); if(el&&window.wp&&wp.codeEditor){ var inst=wp.codeEditor.initialize(el,%s); window.elementorMcpDesignEditor=inst&&inst.codemirror?inst.codemirror:null; window.dispatchEvent(new CustomEvent("elementor-mcp-design-editor-ready")); } });',
            (string) wp_json_encode($settings),
        ));
    }
}

function require_capability_and_nonce(string $nonce_action): void
{
    if (!current_user_can_manage()) {
        wp_die(esc_html__('Not allowed.', domain: 'elementor-mcp'), title: '', args: ['response' => 403]);
    }
    check_admin_referer($nonce_action);
}

/** @param array<string, int|string> $args */
function redirect_with_notice(string $type, string $message, array $args = []): void
{
    set_transient(
        'elementor_mcp_design_admin_notice_' . get_current_user_id(),
        ['type' => $type, 'message' => $message],
        expiration: 30,
    );
    wp_safe_redirect(add_query_arg(array_merge(['page' => PAGE_SLUG], $args), admin_url('admin.php')));
    exit();
}

function handle_activate(): void
{
    require_capability_and_nonce('elementor_mcp_design_activate');
    $slug_raw = $_POST['slug'] ?? '';
    $slug = \ElementorMCP\Design\Parser\normalize_slug(is_string($slug_raw) ? $slug_raw : '');
    $record = $slug !== '' ? \ElementorMCP\Design\Library\find($slug) : null;
    if ($record === null) {
        redirect_with_notice('error', __('That design does not exist.', domain: 'elementor-mcp'));
        return;
    }
    $inspection = \ElementorMCP\Design\Contract\inspect($record['content']);
    if (!$inspection['readiness']['ready']) {
        redirect_with_notice('error', \ElementorMCP\Design\Contract\activation_error($inspection));
        return;
    }
    \ElementorMCP\Design\Store\set_active($slug);
    \ElementorMCP\Design\Notices\set_pending_reload_notice();
    redirect_with_notice('success', __('Design activated.', domain: 'elementor-mcp'));
}

/**
 * Resolve import content from either the uploaded file or the pasted textarea.
 * Returns null and redirects on upload validation errors; returns empty string
 * when no file was uploaded (caller falls through to empty-content guard).
 *
 * @param array<string, mixed>|null $file
 */
function resolve_import_content(?array $file): ?string
{
    if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $pasted = $_POST['design_content'] ?? '';
        return is_string($pasted) ? wp_unslash($pasted) : '';
    }
    $name = is_string($file['name'] ?? null) ? $file['name'] : '';
    $tmp = is_string($file['tmp_name'] ?? null) ? $file['tmp_name'] : '';
    if (!str_ends_with(strtolower($name), '.md')) {
        redirect_with_notice('error', __('Please upload a .md file.', domain: 'elementor-mcp'));
        return null;
    }
    if ((int) ($file['size'] ?? 0) > \ElementorMCP\Design\Parser\MAX_BYTES) {
        redirect_with_notice('error', __('File too large (max 1 MB).', domain: 'elementor-mcp'));
        return null;
    }
    $raw = $tmp !== '' && is_readable($tmp) ? file_get_contents($tmp) : false;
    if ($raw === false) {
        redirect_with_notice('error', __('Could not read the uploaded file.', domain: 'elementor-mcp'));
        return null;
    }
    return $raw;
}

function handle_import(): void
{
    require_capability_and_nonce('elementor_mcp_design_import');

    $file = $_FILES['design_file'] ?? null;
    $content = resolve_import_content(is_array($file) ? $file : null);
    if ($content === null) {
        return;
    }

    if (trim($content) === '') {
        redirect_with_notice('error', __('Nothing to import. Upload a file or paste a DESIGN.md.', domain: 'elementor-mcp'));
        return;
    }
    if (strlen($content) > \ElementorMCP\Design\Parser\MAX_BYTES) {
        redirect_with_notice('error', __('DESIGN.md exceeds the size limit (max 1 MB).', domain: 'elementor-mcp'));
        return;
    }
    if (!\ElementorMCP\Design\Parser\is_valid($content)) {
        redirect_with_notice('error', __(
            'Not a valid DESIGN.md (could not find a name: add YAML front matter or a # heading).',
            domain: 'elementor-mcp',
        ));
        return;
    }

    $inspection = \ElementorMCP\Design\Contract\inspect($content);
    $parsed = \ElementorMCP\Design\Parser\parse($content);
    $prospective_slug = \ElementorMCP\Design\Parser\normalize_slug($parsed['name']);
    if (\ElementorMCP\Design\Store\get_active_slug() === $prospective_slug && !$inspection['readiness']['ready']) {
        redirect_with_notice(
            'error',
            __('The active design was not overwritten because the import is incomplete. ', domain: 'elementor-mcp')
                . \ElementorMCP\Design\Contract\activation_error($inspection),
        );
        return;
    }

    $result = \ElementorMCP\Design\Store\save($content, slug: null, actor: 'user');
    if ($result['slug'] === '') {
        redirect_with_notice('error', __('Could not save the imported design.', domain: 'elementor-mcp'));
        return;
    }
    $activation_blocked = maybe_activate_import($result['slug'], $inspection['readiness']);
    $message = import_success_message($result, $content);
    if ($activation_blocked) {
        $message .=
            ' '
            . __('Saved, but not activated: ', domain: 'elementor-mcp')
            . \ElementorMCP\Design\Contract\activation_error($inspection);
    }
    redirect_with_notice($activation_blocked ? 'warning' : 'success', $message);
}

/**
 * Activate a newly imported design when requested and ready. Returns true when
 * the request was intentionally blocked by the semantic contract.
 *
 * @param array{ready: bool, sync_ready: bool, errors: list<string>, warnings: list<string>} $readiness
 */
function maybe_activate_import(string $slug, array $readiness): bool
{
    if (($_POST['activate'] ?? null) === null) {
        return false;
    }
    if (!$readiness['ready']) {
        return true;
    }
    \ElementorMCP\Design\Store\set_active($slug);
    \ElementorMCP\Design\Notices\set_pending_reload_notice();
    return false;
}

/** @param array{slug: string, name: string} $result */
function import_success_message(array $result, string $content): string
{
    $message = sprintf(
        /* translators: %s: design name */
        __('Imported "%s".', domain: 'elementor-mcp'),
        $result['name'] !== '' ? $result['name'] : $result['slug'],
    );
    $waivers = \ElementorMCP\Design\Preflight\waivers($content);
    if ($waivers !== []) {
        $message .= sprintf(
            /* translators: %s: list of anti-slop rules this design waives */
            __(' Allows: %s.', domain: 'elementor-mcp'),
            implode(' · ', $waivers),
        );
    }
    return $message;
}

function read_design_id(): int
{
    $raw = $_POST['design_id'] ?? $_GET['design'] ?? null;
    return is_scalar($raw) ? (int) $raw : 0;
}

function load_user_design(int $post_id): \WP_Post
{
    // @mago-expect analysis:mixed-assignment
    $post = get_post($post_id);
    if (!$post instanceof \WP_Post || $post->post_type !== \ElementorMCP\Design\Cpt\POST_TYPE) {
        wp_die(esc_html__('Design not found.', domain: 'elementor-mcp'));
    }
    /** @var \WP_Post $post */
    return $post;
}

function handle_save(): void
{
    $post_id = read_design_id();
    require_capability_and_nonce('elementor_mcp_design_save_' . $post_id);
    $post = load_user_design($post_id);

    $content_raw = $_POST['content'] ?? '';
    $content = is_string($content_raw) ? wp_unslash($content_raw) : '';
    if (strlen($content) > \ElementorMCP\Design\Parser\MAX_BYTES) {
        redirect_with_notice('error', __('DESIGN.md exceeds the size limit.', domain: 'elementor-mcp'));
        return;
    }
    if (!\ElementorMCP\Design\Parser\is_valid($content)) {
        redirect_with_notice('error', __(
            'Not a valid DESIGN.md (could not find a name: add YAML front matter or a # heading).',
            domain: 'elementor-mcp',
        ));
        return;
    }

    $parsed = \ElementorMCP\Design\Parser\parse($content);
    $new_slug = \ElementorMCP\Design\Parser\normalize_slug($parsed['name'] !== '' ? $parsed['name'] : $post->post_name);
    if ($new_slug === '') {
        redirect_with_notice('error', __('Could not derive a slug from the name.', domain: 'elementor-mcp'));
        return;
    }

    $was_active = \ElementorMCP\Design\Store\get_active_slug() === $post->post_name;
    $inspection = \ElementorMCP\Design\Contract\inspect($content);
    if ($was_active && !$inspection['readiness']['ready']) {
        redirect_with_notice(
            'error',
            __('The active design was not changed because the edited version is incomplete. ', domain: 'elementor-mcp')
                . \ElementorMCP\Design\Contract\activation_error($inspection),
        );
        return;
    }
    \ElementorMCP\Design\Revisions\snapshot_current($post);
    $updated = wp_update_post([
        'ID' => $post_id,
        // wp_update_post() unslashes every string it is given, so the title
        // needs the same slashing the content already gets.
        'post_title' => wp_slash($parsed['name'] !== '' ? $parsed['name'] : $new_slug),
        'post_name' => $new_slug,
        'post_content' => wp_slash($content),
    ], wp_error: true);
    if (is_wp_error($updated)) {
        redirect_with_notice('error', __('The design could not be saved.', domain: 'elementor-mcp'));
        return;
    }
    update_post_meta($post_id, \ElementorMCP\Design\Cpt\META_LAST_ACTOR, meta_value: 'user');
    // WordPress may append a suffix if the slug collides (design -> design-2);
    // read the slug it actually stored so the active pointer never drifts.
    // @mago-expect analysis:mixed-assignment
    $saved_post = get_post($post_id);
    $actual_slug =
        $saved_post instanceof \WP_Post && $saved_post->post_name !== '' ? $saved_post->post_name : $new_slug;
    if ($was_active) {
        \ElementorMCP\Design\Store\set_active($actual_slug);
        \ElementorMCP\Design\Notices\set_pending_reload_notice();
    }

    set_transient(
        'elementor_mcp_design_admin_notice_' . get_current_user_id(),
        ['type' => 'success', 'message' => __('Design saved.', domain: 'elementor-mcp')],
        expiration: 30,
    );
    wp_safe_redirect(add_query_arg(['page' => PAGE_SLUG, 'design' => $post_id], admin_url('admin.php')));
    exit();
}

function unique_user_slug(string $base): string
{
    $base = \ElementorMCP\Design\Parser\normalize_slug($base);
    if ($base === '') {
        $base = 'design';
    }
    $slug = $base;
    $n = 2;
    while (\ElementorMCP\Design\Store\find_user_post($slug) !== null) {
        $slug = $base . '-' . $n;
        $n++;
    }
    return $slug;
}

function handle_duplicate(): void
{
    require_capability_and_nonce('elementor_mcp_design_duplicate');
    $slug_raw = $_POST['slug'] ?? '';
    $slug = \ElementorMCP\Design\Parser\normalize_slug(is_string($slug_raw) ? $slug_raw : '');
    $source = $slug !== '' ? \ElementorMCP\Design\Library\find($slug) : null;
    if ($source === null) {
        redirect_with_notice('error', __('That design does not exist.', domain: 'elementor-mcp'));
        return;
    }
    $new_slug = unique_user_slug($slug . '-copy');
    $result = \ElementorMCP\Design\Store\save($source['content'], $new_slug, actor: 'user');
    if ($result['slug'] === '') {
        redirect_with_notice('error', __('Could not duplicate the design.', domain: 'elementor-mcp'));
        return;
    }
    $post = \ElementorMCP\Design\Store\find_user_post($result['slug']);
    set_transient(
        'elementor_mcp_design_admin_notice_' . get_current_user_id(),
        ['type' => 'success', 'message' => __('Design duplicated. Edit your copy.', domain: 'elementor-mcp')],
        expiration: 30,
    );
    $args = ['page' => PAGE_SLUG];
    if ($post instanceof \WP_Post) {
        $args['design'] = $post->ID;
    }
    wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
    exit();
}

function handle_restore(): void
{
    $post_id = read_design_id();
    $revision_raw = $_POST['revision_id'] ?? 0;
    $revision_id = is_scalar($revision_raw) ? (int) $revision_raw : 0;
    require_capability_and_nonce('elementor_mcp_design_restore_' . $revision_id);
    $post = load_user_design($post_id);

    $revision = \ElementorMCP\Design\Revisions\find($post, $revision_id);
    if ($revision === null) {
        redirect_with_notice('error', __('That revision does not belong to this design.', domain: 'elementor-mcp'), [
            'view' => $post->post_name,
        ]);
        return;
    }

    $result = \ElementorMCP\Design\Revisions\restore($post, $revision, actor: 'user');
    if (is_wp_error($result)) {
        redirect_with_notice('error', $result->get_error_message(), ['view' => $post->post_name]);
        return;
    }

    if (\ElementorMCP\Design\Store\get_active_slug() === $post->post_name) {
        \ElementorMCP\Design\Notices\set_pending_reload_notice();
    }
    redirect_with_notice('success', __('Design revision restored.', domain: 'elementor-mcp'), [
        'view' => $post->post_name,
    ]);
}

function handle_delete(): void
{
    $post_id = read_design_id();
    require_capability_and_nonce('elementor_mcp_design_delete_' . $post_id);
    $post = load_user_design($post_id);

    if (\ElementorMCP\Design\Store\get_active_slug() === $post->post_name) {
        delete_option(\ElementorMCP\Design\Cpt\OPTION_ACTIVE);
        \ElementorMCP\Design\Notices\set_pending_reload_notice();
    }
    wp_delete_post($post_id, force_delete: true);
    redirect_with_notice('success', __('Design deleted.', domain: 'elementor-mcp'));
}

/**
 * Copy a starter direction into this site's own library.
 *
 * A copy, never a reference. The starter is a place to begin, and the moment a
 * site adopts one it becomes that site's document to edit, rename and argue
 * with. Nothing is shared back, so a later change to the shipped starters cannot
 * alter a live site's design.
 *
 * Deliberately does not activate it. Adopting a palette somebody else chose and
 * putting it straight into production is the failure this whole module exists to
 * prevent; the copy opens in the editor so the reasoning gets rewritten for this
 * business first.
 */
function handle_use_starter(): void
{
    require_capability_and_nonce('elementor_mcp_design_use_starter');

    $slug_raw = $_POST['slug'] ?? '';
    $starter = \ElementorMCP\Design\Examples\find(is_string($slug_raw) ? $slug_raw : '');
    if ($starter === null) {
        redirect_with_notice('error', __('That starter kit does not exist.', domain: 'elementor-mcp'));
        return;
    }

    $new_slug = unique_user_slug($starter['slug']);
    $result = \ElementorMCP\Design\Store\save($starter['content'], $new_slug, actor: 'user');
    if ($result['slug'] === '') {
        redirect_with_notice('error', __('Could not copy that starter kit.', domain: 'elementor-mcp'));
        return;
    }

    $post = \ElementorMCP\Design\Store\find_user_post($result['slug']);
    set_transient(
        'elementor_mcp_design_admin_notice_' . get_current_user_id(),
        [
            'type' => 'success',
            'message' => __(
                'Copied into your designs. Change the palette and the wording to suit this business, then activate it. A starter used unchanged will look like a starter.',
                domain: 'elementor-mcp',
            ),
        ],
        expiration: 30,
    );

    $args = ['page' => PAGE_SLUG];
    if ($post instanceof \WP_Post) {
        $args['design'] = $post->ID;
    }
    wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
    exit();
}

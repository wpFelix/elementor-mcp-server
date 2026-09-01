<?php

// SPDX-FileCopyrightText: 2026 Elementor MCP <dev@elementormcp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

// phpcs:disable WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- Every state-changing request on this screen verifies a nonce via check_admin_referer() before acting; the sniff cannot trace that across function boundaries. Reads are type-checked, whitelist-compared, and escaped on output.

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Collect every registered ability, grouped by ability prefix.
 *
 * Disabled abilities are usually absent from the registry after the policy hook,
 * so persisted disabled rules are merged back in as placeholder rows.
 *
 * @return array<string, list<array{name: string, label: string, description: string, category: string, mcp: string, mcp_type: string, source: string, status: string, disabled: bool, profile_blocked: bool, protected: bool}>>
 */
function elementor_mcp_collect_ability_hub_rows(): array
{
    if (!function_exists('wp_get_abilities')) {
        return [];
    }

    $rules = elementor_mcp_get_ability_rules();
    $groups = [];
    $seen = [];

    foreach (wp_get_abilities() as $ability) {
        $row = elementor_mcp_build_registered_ability_row($ability, $rules);
        if ($row === null) {
            continue;
        }
        $seen[$row['name']] = true;
        $groups[elementor_mcp_ability_prefix($row['name'])][] = $row;
    }

    $groups = elementor_mcp_append_disabled_ability_rows($groups, $rules, $seen);

    foreach ($groups as $source => $rows) {
        usort($rows, static fn(array $a, array $b): int => [$a['name']] <=> [$b['name']]);
        $groups[$source] = $rows;
    }
    uksort($groups, static function (string $a, string $b): int {
        $rank = elementor_mcp_ability_hub_group_rank($a) <=> elementor_mcp_ability_hub_group_rank($b);
        return $rank !== 0 ? $rank : strcasecmp($a, $b);
    });

    return $groups;
}

/**
 * Build a hub row for a registered ability, or null when it is hidden or not exposed.
 *
 * @param array<string, array{disabled: bool}> $rules
 * @return array{name: string, label: string, description: string, category: string, mcp: string, mcp_type: string, source: string, status: string, disabled: bool, profile_blocked: bool, protected: bool}|null
 */
function elementor_mcp_build_registered_ability_row(WP_Ability $ability, array $rules): ?array
{
    $name = $ability->get_name();
    if (elementor_mcp_ability_is_hub_hidden($name)) {
        return null;
    }
    $meta = $ability->get_meta();
    if (!elementor_mcp_ability_is_exposed($meta)) {
        return null;
    }

    $protected = elementor_mcp_ability_is_hub_protected($name);
    $disabled = !$protected && ($rules[$name]['disabled'] ?? false);
    $profile_blocked =
        !$disabled
        && function_exists('elementor_mcp_safety_profile_allows_ability')
        && !elementor_mcp_safety_profile_allows_ability($ability);
    $status = __('Enabled', domain: 'elementor-mcp');
    if ($disabled) {
        $status = __('Disabled', domain: 'elementor-mcp');
    } elseif ($profile_blocked) {
        $status = __('Blocked by profile', domain: 'elementor-mcp');
    }
    $category_slug = $ability->get_category();
    $category = $category_slug !== '' ? wp_get_ability_category($category_slug) : null;

    return [
        'name' => $name,
        'label' => $ability->get_label(),
        'description' => $ability->get_description(),
        'category' => $category !== null ? $category->get_label() : $category_slug,
        'mcp' => elementor_mcp_format_ability_mcp_meta($meta),
        'mcp_type' => elementor_mcp_ability_mcp_type($meta),
        // Which plugin registered this. Empty for the free plugin's own; add-ons
        // name themselves through the filter, which is how a paying customer can
        // see what their licence actually contributed to this list.
        'source' => elementor_mcp_ability_source_label($ability),
        'status' => $status,
        'disabled' => $disabled,
        'profile_blocked' => $profile_blocked,
        'protected' => $protected,
    ];
}

/**
 * Merge persisted disabled rules back in as placeholder rows for abilities that
 * are no longer registered (disabled abilities are absent after the policy hook).
 *
 * @param array<string, list<array{name: string, label: string, description: string, category: string, mcp: string, mcp_type: string, source: string, status: string, disabled: bool, profile_blocked: bool, protected: bool}>> $groups
 * @param array<string, array{disabled: bool}> $rules
 * @param array<string, bool> $seen
 * @return array<string, list<array{name: string, label: string, description: string, category: string, mcp: string, mcp_type: string, source: string, status: string, disabled: bool, profile_blocked: bool, protected: bool}>>
 */
function elementor_mcp_append_disabled_ability_rows(array $groups, array $rules, array $seen): array
{
    foreach ($rules as $name => $rule) {
        if (elementor_mcp_ability_is_hub_hidden($name) || array_key_exists($name, $seen) || !$rule['disabled']) {
            continue;
        }
        $groups[elementor_mcp_ability_prefix($name)][] = [
            'name' => $name,
            'label' => __('Previously registered ability', domain: 'elementor-mcp'),
            'description' => '',
            'category' => '',
            'mcp' => __('Unknown', domain: 'elementor-mcp'),
            'mcp_type' => '',
            'source' => '',
            'status' => __('Disabled', domain: 'elementor-mcp'),
            'disabled' => true,
            'profile_blocked' => false,
            'protected' => elementor_mcp_ability_is_hub_protected($name),
        ];
    }

    return $groups;
}

/**
 * Which plugin registered an ability, for display beside it.
 *
 * Add-ons answer on the `elementor_mcp_ability_source_label` filter. Elementor MCP Pro
 * registers its abilities under the same `elementor-mcp/` namespace as the free ones,
 * so nothing in the name distinguishes them — without this, a customer paying
 * for roughly a thousand extra abilities sees one undifferentiated list and no
 * evidence of what the licence bought.
 *
 * Empty means the free plugin's own, which needs no label.
 */
function elementor_mcp_ability_source_label(WP_Ability $ability): string
{
    /**
     * Filter the source label shown beside an ability.
     *
     * @param string      $label   Empty for abilities the free plugin registered.
     * @param WP_Ability  $ability The ability being labelled.
     */
    // Positional, and it must stay that way: apply_filters() takes `...$args`
    // variadically, and a variadic parameter cannot be passed by name — doing so
    // is a fatal "Unknown named parameter $args", not a style problem.
    // @mago-expect lint:literal-named-argument
    /** @var mixed $label */
    $label = apply_filters('elementor_mcp_ability_source_label', '', $ability);

    return is_string($label) ? $label : '';
}

function elementor_mcp_ability_prefix(string $ability_name): string
{
    $parts = explode('/', $ability_name, limit: 2);
    return $parts[0] !== '' ? $parts[0] : __('Other', domain: 'elementor-mcp');
}

/**
 * The ability name without its provider prefix. The provider is already the
 * group header, so repeating it on every row is noise.
 */
function elementor_mcp_ability_display_slug(string $ability_name): string
{
    $parts = explode('/', $ability_name, limit: 2);
    return ($parts[1] ?? '') !== '' ? $parts[1] : $ability_name;
}

/**
 * Sort rank for an ability group header: the "elementor-mcp" provider first, then
 * every other provider (the caller breaks ties alphabetically).
 */
function elementor_mcp_ability_hub_group_rank(string $source): int
{
    return $source === 'elementor-mcp' ? 0 : 1;
}

function elementor_mcp_ability_is_hub_hidden(string $ability_name): bool
{
    return (
        str_starts_with($ability_name, 'mcp-adapter/')
        || in_array($ability_name, elementor_mcp_always_on_ability_names(), strict: true)
    );
}

/**
 * An ability is exposed when its MCP metadata marks it public.
 *
 * @param array<string, mixed> $meta
 */
function elementor_mcp_ability_is_exposed(array $meta): bool
{
    /** @var mixed $mcp */
    $mcp = $meta['mcp'] ?? null;
    return is_array($mcp) && ($mcp['public'] ?? false) === true;
}

/**
 * @param array<string, mixed> $meta
 */
function elementor_mcp_format_ability_mcp_meta(array $meta): string
{
    /** @var mixed $mcp */
    $mcp = $meta['mcp'] ?? null;
    if (!is_array($mcp)) {
        return __('Unknown', domain: 'elementor-mcp');
    }

    return (string) ($mcp['type'] ?? 'tool');
}

/**
 * Raw MCP exposure type ('tool', 'resource' or 'prompt') for pill logic, kept
 * separate from the translated display label.
 *
 * @param array<string, mixed> $meta
 */
function elementor_mcp_ability_mcp_type(array $meta): string
{
    /** @var mixed $mcp */
    $mcp = $meta['mcp'] ?? null;
    if (!is_array($mcp)) {
        return 'tool';
    }
    /** @var mixed $type */
    $type = $mcp['type'] ?? '';
    return $type === 'resource' || $type === 'prompt' ? $type : 'tool';
}

function elementor_mcp_handle_ability_hub_actions(): void
{
    if (($_POST['elementor_mcp_ability_hub_action'] ?? null) === null) {
        return;
    }

    if (!elementor_mcp_current_user_can_manage()) {
        return;
    }

    check_admin_referer('elementor_mcp_ability_hub_action');

    $action = is_string($_POST['elementor_mcp_ability_hub_action'] ?? null)
        ? sanitize_key(wp_unslash($_POST['elementor_mcp_ability_hub_action']))
        : '';

    if ($action === 'bulk_update') {
        elementor_mcp_handle_ability_hub_bulk_action();
        return;
    }

    $ability_name = is_string($_POST['ability_name'] ?? null)
        ? elementor_mcp_sanitize_requested_ability_name($_POST['ability_name'])
        : '';

    if (!elementor_mcp_is_valid_ability_name($ability_name)) {
        wp_safe_redirect(admin_url('admin.php?page=elementor-mcp-abilities&elementor_mcp_result=invalid'));
        exit();
    }

    $rules = elementor_mcp_get_ability_rules();
    $rules[$ability_name] ??= ['disabled' => false];

    $rules = elementor_mcp_apply_ability_hub_action_to_rules($rules, $ability_name, $action);

    elementor_mcp_update_ability_rules($rules);
    wp_safe_redirect(admin_url('admin.php?page=elementor-mcp-abilities&elementor_mcp_result=updated'));
    exit();
}

/**
 * AJAX endpoint for the single-row enable/disable toggle. Mirrors the POST path
 * but responds with JSON so the page does not reload (preserving open sections).
 * The browser falls back to the plain form submit if this request fails.
 */
function elementor_mcp_handle_ability_toggle_ajax(): void
{
    if (!elementor_mcp_current_user_can_manage()) {
        wp_send_json_error(['message' => __('Permission denied.', domain: 'elementor-mcp')], status_code: 403);
    }

    if (!check_ajax_referer('elementor_mcp_ability_hub_action', query_arg: false, stop: false)) {
        wp_send_json_error(['message' => __(
            'Your session expired. Reload the page.',
            domain: 'elementor-mcp',
        )], status_code: 403);
    }

    $ability_name = is_string($_POST['ability_name'] ?? null)
        ? elementor_mcp_sanitize_requested_ability_name($_POST['ability_name'])
        : '';

    if (!elementor_mcp_is_valid_ability_name($ability_name) || elementor_mcp_ability_is_hub_hidden($ability_name)) {
        wp_send_json_error(['message' => __('Invalid ability name.', domain: 'elementor-mcp')], status_code: 400);
    }

    if (elementor_mcp_ability_is_hub_protected($ability_name)) {
        wp_send_json_error(['message' => __('This ability cannot be changed.', domain: 'elementor-mcp')], status_code: 403);
    }

    $rules = elementor_mcp_get_ability_rules();
    $rules[$ability_name] ??= ['disabled' => false];
    $rules = elementor_mcp_toggle_ability_disabled_rule($rules, $ability_name);
    elementor_mcp_update_ability_rules($rules);

    $disabled = $rules[$ability_name]['disabled'] === true;
    wp_send_json_success([
        'disabled' => $disabled,
        'status' => $disabled ? __('Disabled', domain: 'elementor-mcp') : __('Enabled', domain: 'elementor-mcp'),
        'button' => $disabled ? __('Enable', domain: 'elementor-mcp') : __('Disable', domain: 'elementor-mcp'),
    ]);
}

function elementor_mcp_handle_ability_hub_bulk_action(): void
{
    $bulk_action = elementor_mcp_get_ability_hub_bulk_action();
    $ability_names = elementor_mcp_get_ability_hub_bulk_ability_names();
    if ($bulk_action === '') {
        wp_safe_redirect(admin_url('admin.php?page=elementor-mcp-abilities&elementor_mcp_result=missing_bulk_action'));
        exit();
    }
    if ($ability_names === []) {
        wp_safe_redirect(admin_url('admin.php?page=elementor-mcp-abilities&elementor_mcp_result=nothing_selected'));
        exit();
    }

    $rules = elementor_mcp_get_ability_rules();
    foreach ($ability_names as $ability_name) {
        $rules[$ability_name] ??= ['disabled' => false];
        $rules = elementor_mcp_apply_ability_hub_bulk_action_to_rules($rules, $ability_name, $bulk_action);
    }

    elementor_mcp_update_ability_rules($rules);
    wp_safe_redirect(admin_url('admin.php?page=elementor-mcp-abilities&elementor_mcp_result=bulk_updated'));
    exit();
}

function elementor_mcp_get_ability_hub_bulk_action(): string
{
    $top_action = is_string($_POST['bulk_action'] ?? null) ? sanitize_key(wp_unslash($_POST['bulk_action'])) : '';
    $bottom_action = is_string($_POST['bulk_action2'] ?? null) ? sanitize_key(wp_unslash($_POST['bulk_action2'])) : '';
    $action = $top_action !== '-1' && $top_action !== '' ? $top_action : $bottom_action;

    return in_array($action, ['enable', 'disable'], strict: true) ? $action : '';
}

/**
 * @return list<string>
 */
function elementor_mcp_get_ability_hub_bulk_ability_names(): array
{
    $raw_names = is_array($_POST['ability_names'] ?? null) ? $_POST['ability_names'] : [];

    $ability_names = [];
    foreach ($raw_names as $raw_name) {
        if (!is_string($raw_name)) {
            continue;
        }
        $ability_name = elementor_mcp_sanitize_requested_ability_name($raw_name);
        if (!elementor_mcp_is_valid_ability_name($ability_name) || elementor_mcp_ability_is_hub_hidden($ability_name)) {
            continue;
        }
        $ability_names[] = $ability_name;
    }

    return array_values(array_unique($ability_names));
}

/**
 * @param array<string, array{disabled: bool}> $rules
 * @return array<string, array{disabled: bool}>
 */
function elementor_mcp_apply_ability_hub_bulk_action_to_rules(array $rules, string $ability_name, string $action): array
{
    if (elementor_mcp_ability_is_hub_protected($ability_name)) {
        return $rules;
    }

    if ($action === 'enable') {
        $rules[$ability_name]['disabled'] = false;
        return $rules;
    }

    if ($action === 'disable') {
        $rules[$ability_name]['disabled'] = true;
    }

    return $rules;
}

/**
 * @param array<string, array{disabled: bool}> $rules
 * @return array<string, array{disabled: bool}>
 */
function elementor_mcp_apply_ability_hub_action_to_rules(array $rules, string $ability_name, string $action): array
{
    if ($action === 'toggle_disabled') {
        return elementor_mcp_toggle_ability_disabled_rule($rules, $ability_name);
    }

    return $rules;
}

/**
 * @param array<string, array{disabled: bool}> $rules
 * @return array<string, array{disabled: bool}>
 */
function elementor_mcp_toggle_ability_disabled_rule(array $rules, string $ability_name): array
{
    if (elementor_mcp_ability_is_hub_protected($ability_name)) {
        return $rules;
    }

    $rules[$ability_name]['disabled'] = !$rules[$ability_name]['disabled'];
    return $rules;
}

function elementor_mcp_handle_sandbox_actions()
{
    if (!elementor_mcp_current_user_can_manage()) {
        return;
    }

    $action = $_GET['action'] ?? null;
    $file_param = $_GET['file'] ?? null;

    if (!is_string($action) || !is_string($file_param)) {
        return;
    }

    $file = basename($file_param);
    if (!check_admin_referer('elementor_mcp_manage_file_' . $file)) {
        return;
    }

    $path = elementor_mcp_get_sandbox_dir(true) . $file;
    if (!file_exists($path)) {
        return;
    }

    $result = match ($action) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.WP.AlternativeFunctions.rename_rename,WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.WP.AlternativeFunctions.file_system_operations_fclose,WordPress.WP.AlternativeFunctions.file_system_operations_fread,WordPress.WP.AlternativeFunctions.file_system_operations_fwrite,WordPress.WP.AlternativeFunctions.file_system_operations_chmod,WordPress.WP.AlternativeFunctions.file_system_operations_mkdir,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir,WordPress.WP.AlternativeFunctions.file_system_operations_readfile,WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- WP_Filesystem is not usable here: it takes credentials from an interactive admin form, which a REST/MCP request has no way to show. This inspects the sandbox directory Elementor MCP owns, to report what is in it.
        'delete' => unlink($path),
        // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.WP.AlternativeFunctions.rename_rename,WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.WP.AlternativeFunctions.file_system_operations_fclose,WordPress.WP.AlternativeFunctions.file_system_operations_fread,WordPress.WP.AlternativeFunctions.file_system_operations_fwrite,WordPress.WP.AlternativeFunctions.file_system_operations_chmod,WordPress.WP.AlternativeFunctions.file_system_operations_mkdir,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir,WordPress.WP.AlternativeFunctions.file_system_operations_readfile,WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- WP_Filesystem is not usable here: it takes credentials from an interactive admin form, which a REST/MCP request has no way to show. This inspects the sandbox directory Elementor MCP owns, to report what is in it.
        'disable' => str_ends_with($file, '.php') && rename($path, $path . '.disabled'),
        // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.WP.AlternativeFunctions.rename_rename,WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.WP.AlternativeFunctions.file_system_operations_fclose,WordPress.WP.AlternativeFunctions.file_system_operations_fread,WordPress.WP.AlternativeFunctions.file_system_operations_fwrite,WordPress.WP.AlternativeFunctions.file_system_operations_chmod,WordPress.WP.AlternativeFunctions.file_system_operations_mkdir,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir,WordPress.WP.AlternativeFunctions.file_system_operations_readfile,WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- WP_Filesystem is not usable here: it takes credentials from an interactive admin form, which a REST/MCP request has no way to show. This inspects the sandbox directory Elementor MCP owns, to report what is in it.
        'enable' => str_ends_with($file, '.disabled') && rename($path, substr($path, offset: 0, length: -9)),
        // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.WP.AlternativeFunctions.rename_rename,WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.WP.AlternativeFunctions.file_system_operations_fclose,WordPress.WP.AlternativeFunctions.file_system_operations_fread,WordPress.WP.AlternativeFunctions.file_system_operations_fwrite,WordPress.WP.AlternativeFunctions.file_system_operations_chmod,WordPress.WP.AlternativeFunctions.file_system_operations_mkdir,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir,WordPress.WP.AlternativeFunctions.file_system_operations_readfile,WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- WP_Filesystem is not usable here: it takes credentials from an interactive admin form, which a REST/MCP request has no way to show. This inspects the sandbox directory Elementor MCP owns, to report what is in it.
        'exit_safe_mode' => $file === '.crashed' && unlink($path),
        default => false,
    };

    if ($result) {
        wp_safe_redirect(admin_url('admin.php?page=elementor-mcp-sandbox&elementor_mcp_result=' . $action));
        exit();
    }
}

function elementor_mcp_render_sandbox_page(): void
{
    if (!elementor_mcp_current_user_can_manage()) {
        return;
    }

    $result_message = match ($_GET['elementor_mcp_result'] ?? null) {
        'delete' => __('File deleted.', domain: 'elementor-mcp'),
        'disable' => __('File disabled.', domain: 'elementor-mcp'),
        'enable' => __('File enabled.', domain: 'elementor-mcp'),
        'exit_safe_mode' => __(
            'Safe mode deactivated. Sandbox files will load on the next request.',
            domain: 'elementor-mcp',
        ),
        default => null,
    };
    $sandbox_dir = elementor_mcp_get_sandbox_dir(true);
    $is_crashed = file_exists($sandbox_dir . '.crashed');

    elementor_mcp_render_admin_header();
    ?>
    <div class="wrap">
        <h1 class="wp-heading-inline"><?php echo esc_html(elementor_mcp_nav_label('elementor-mcp-sandbox')); ?></h1>
        <hr class="wp-header-end" />

        <?php if ($result_message !== null): ?>
            <div class="notice notice-success is-dismissible"><p><?php echo esc_html($result_message); ?></p></div>
        <?php endif; ?>

        <?php if ($is_crashed): ?>
            <div class="notice notice-error">
                <p>
                    <strong><?php esc_html_e('Safe mode is active.', domain: 'elementor-mcp'); ?></strong>
                    <?php esc_html_e(
                        'A sandbox file caused a fatal error on a previous request. All sandbox files are suspended until you fix or delete the broken file and exit safe mode.',
                        domain: 'elementor-mcp',
                    ); ?>
                </p>
                <p>
                    <?php

                    $exit_url = wp_nonce_url(
                        admin_url('admin.php?page=elementor-mcp-sandbox&action=exit_safe_mode&file=.crashed'),
                        action: 'elementor_mcp_manage_file_.crashed',
                    );
                    ?>
                    <a href="<?php echo esc_url($exit_url); ?>" class="button button-primary"><?php esc_html_e(
                        'Exit Safe Mode',
                        domain: 'elementor-mcp',
                    ); ?></a>
                </p>
            </div>
        <?php endif; ?>

        <?php elementor_mcp_render_sandbox_list($sandbox_dir); ?>
    </div>
    <?php
}

/**
 * Render the file list as a card section.
 * Layout mirrors the Skills admin page so the two pages feel consistent.
 */
function elementor_mcp_render_sandbox_list(string $sandbox_dir): void
{
    $files = elementor_mcp_get_sandbox_files($sandbox_dir);
    $sandbox_status = file_exists($sandbox_dir . '.crashed') ? 'suspended' : 'active';
    ?>
    <section class="elementor-mcp-sandbox-section">
        <div class="elementor-mcp-sandbox-header">
            <h2><?php esc_html_e('Files', domain: 'elementor-mcp'); ?>
                <span class="count"><?php echo (int) count($files); ?></span>
            </h2>
        </div>
        <?php if ($files === []): ?>
            <div class="elementor-mcp-sandbox-empty"><?php esc_html_e(
                'No sandbox files yet. AI agents will place generated files here.',
                domain: 'elementor-mcp',
            ); ?></div>
        <?php endif; ?>
        <?php if ($files !== []): ?>
            <?php elementor_mcp_render_sandbox_rows($sandbox_dir, $files, $sandbox_status); ?>
        <?php endif; ?>
    </section>
    <?php
}

/**
 * @return list<string>
 */
function elementor_mcp_get_sandbox_files(string $sandbox_dir): array
{
    $scanned_files = is_dir($sandbox_dir) ? scandir($sandbox_dir) : false;
    $files = $scanned_files !== false ? array_diff($scanned_files, ['.', '..', '.loading', '.crashed']) : [];

    return array_values(array_filter($files, static fn(string $file): bool => !is_dir($sandbox_dir . $file)));
}

/**
 * @param list<string> $files
 */
function elementor_mcp_render_sandbox_rows(string $sandbox_dir, array $files, string $sandbox_status): void
{
    $format = elementor_mcp_get_datetime_format();
    $base_url = admin_url('admin.php?page=elementor-mcp-sandbox');
    ?>
    <div class="elementor-mcp-sandbox-rows">
        <?php foreach ($files as $file): ?>
            <?php elementor_mcp_render_sandbox_row($sandbox_dir, $file, $sandbox_status, $format, $base_url); ?>
        <?php endforeach; ?>
    </div>
    <?php
}

function elementor_mcp_render_sandbox_row(
    string $sandbox_dir,
    string $file,
    string $sandbox_status,
    string $format,
    string $base_url,
): void {
    $path = $sandbox_dir . $file;
    $file_status = elementor_mcp_get_sandbox_file_status($file, $sandbox_status);
    $display_name = $file_status === 'disabled' ? substr($file, offset: 0, length: -9) : $file;
    $ext = strtolower(pathinfo($display_name, PATHINFO_EXTENSION));
    $mtime = filemtime($path);
    $wp_date = $mtime !== false ? wp_date($format, $mtime) : false;
    $modified = $wp_date !== false ? $wp_date : __('Unknown', domain: 'elementor-mcp');

    $delete_url = wp_nonce_url(
        $base_url . '&action=delete&file=' . urlencode($file),
        action: 'elementor_mcp_manage_file_' . $file,
    );
    ?>
    <div class="<?php echo esc_attr('elementor-mcp-sandbox-row is-' . $file_status); ?>">
        <?php elementor_mcp_render_sandbox_toggle($file, $file_status, $ext, $base_url); ?>

        <div class="elementor-mcp-sandbox-main">
            <span class="slug"><?php echo esc_html($display_name); ?></span>
            <span class="meta"><?php echo esc_html($modified); ?></span>
        </div>

        <div class="elementor-mcp-sandbox-pills">
            <?php elementor_mcp_render_sandbox_pills($ext, $file_status); ?>
        </div>

        <div class="elementor-mcp-sandbox-actions">
            <a
                href="<?php echo esc_url($delete_url); ?>"
                class="action-btn action-btn--danger"
                onclick="return confirm('<?php echo
                    esc_js(__('Are you sure you want to delete this file?', domain: 'elementor-mcp'))
                ; ?>');"
            ><?php esc_html_e('Delete', domain: 'elementor-mcp'); ?></a>
        </div>
    </div>
    <?php
}

function elementor_mcp_get_sandbox_file_status(string $file, string $sandbox_status): string
{
    if ($sandbox_status === 'suspended') {
        return 'suspended';
    }

    if (str_ends_with($file, '.disabled')) {
        return 'disabled';
    }

    return 'on';
}

function elementor_mcp_render_sandbox_toggle(string $file, string $file_status, string $ext, string $base_url): void
{
    if ($file_status === 'suspended' || $file_status !== 'disabled' && $ext !== 'php') {
        ?>
        <span class="elementor-mcp-sandbox-check" aria-hidden="true"></span>
        <?php

        return;
    }

    $is_disabled = $file_status === 'disabled';
    $toggle_action = $is_disabled ? 'enable' : 'disable';
    $toggle_url = wp_nonce_url(
        $base_url . '&action=' . $toggle_action . '&file=' . urlencode($file),
        action: 'elementor_mcp_manage_file_' . $file,
    );
    ?>
    <a
        href="<?php echo esc_url($toggle_url); ?>"
        class="elementor-mcp-sandbox-toggle"
        title="<?php echo
            $is_disabled ? esc_attr__('Enable', domain: 'elementor-mcp') : esc_attr__('Disable', domain: 'elementor-mcp')
        ; ?>"
        aria-label="<?php echo
            $is_disabled ? esc_attr__('Enable file', domain: 'elementor-mcp') : esc_attr__('Disable file', domain: 'elementor-mcp')
        ; ?>"
    ><span class="elementor-mcp-sandbox-check"></span></a>
    <?php
}

function elementor_mcp_render_sandbox_pills(string $ext, string $file_status): void
{
    if ($ext !== '') { ?>
        <span class="pill ext-<?php echo esc_attr($ext); ?>"><?php echo esc_html($ext); ?></span>
        <?php }

    if ($file_status === 'suspended') {
        ?>
        <span class="pill warn"><?php esc_html_e('Suspended', domain: 'elementor-mcp'); ?></span>
        <?php

        return;
    }

    if ($file_status === 'disabled') { ?>
        <span class="pill"><?php esc_html_e('Disabled', domain: 'elementor-mcp'); ?></span>
        <?php }
}

function elementor_mcp_render_settings_page()
{
    if (!elementor_mcp_current_user_can_manage()) {
        return;
    }

    $ability_groups = elementor_mcp_collect_ability_hub_rows();
    $result = is_string($_GET['elementor_mcp_result'] ?? null) ? sanitize_key(wp_unslash($_GET['elementor_mcp_result'])) : null;
    ?>
    <?php elementor_mcp_render_admin_header(); ?>
    <div
        class="wrap elementor-mcp-hub"
        data-alloff-label="<?php esc_attr_e('All disabled', domain: 'elementor-mcp'); ?>"
        data-confirm-disable="<?php

        /* translators: %d: number of abilities selected for disabling */
        esc_attr_e('Disable the %d selected abilities? You can re-enable them anytime.', domain: 'elementor-mcp');
        ?>"
    >
        <div class="wrap-title">
            <div>
                <h1><?php echo esc_html(elementor_mcp_nav_label('elementor-mcp-abilities')); ?></h1>
                <p class="description"><?php printf(
                    /* translators: %s: link to the Preferences page */
                    esc_html__(
                        'Manage every ability exposed to AI agents. This lists abilities registered by Elementor MCP and any other plugin that uses the WordPress Abilities API, grouped by provider. Disabled abilities are removed from registry discovery and MCP execution while AI Abilities are enabled on the %s page.',
                        domain: 'elementor-mcp',
                    ),
                    '<a href="'
                    . esc_url(admin_url('admin.php?page=elementor-mcp-settings'))
                    . '">'
                    . esc_html__('Preferences', domain: 'elementor-mcp')
                    . '</a>',
                ); ?></p>
            </div>
        </div>
        <?php elementor_mcp_render_ability_hub_result_notice($result); ?>
        <?php if (function_exists('elementor_mcp_get_safety_profile')): ?>
            <?php $profile = elementor_mcp_get_safety_profile(); ?>
            <?php $profiles = elementor_mcp_safety_profiles(); ?>
            <div class="notice notice-info inline"><p>
                <?php echo
                    esc_html(sprintf(
                        /* translators: 1: safety profile label, 2: safety profile description */
                        __('Active safety profile: %1$s. %2$s', domain: 'elementor-mcp'),
                        $profiles[$profile]['label'],
                        $profiles[$profile]['description'],
                    ))
                ; ?>
            </p></div>
        <?php endif; ?>
        <?php if ($ability_groups === []): ?>
            <div class="notice notice-info"><p><?php esc_html_e(
                'No abilities are currently registered.',
                domain: 'elementor-mcp',
            ); ?></p></div>
        <?php endif; ?>
        <?php if ($ability_groups !== []): ?>
            <form id="elementor-mcp-abilities-bulk" method="post">
                <?php wp_nonce_field('elementor_mcp_ability_hub_action'); ?>
                <input type="hidden" name="elementor_mcp_ability_hub_action" value="bulk_update" />
            </form>
            <?php elementor_mcp_render_ability_bulk_actions('top'); ?>
        <?php endif; ?>
        <?php $expanded_source = array_key_first($ability_groups); ?>
        <?php $seen_core = false; ?>
        <?php $divider_done = false; ?>
        <?php foreach ($ability_groups as $source => $abilities): ?>
            <?php $is_core = elementor_mcp_ability_hub_group_rank($source) === 0; ?>
            <?php if (!$is_core && $seen_core && !$divider_done): ?>
                <?php elementor_mcp_render_ability_other_plugins_divider(); ?>
                <?php $divider_done = true; ?>
            <?php endif; ?>
            <?php $seen_core = $seen_core || $is_core; ?>
            <?php elementor_mcp_render_ability_group_section($source, $abilities, $expanded_source); ?>
        <?php endforeach; ?>
        <?php if ($ability_groups !== []): ?>
            <?php elementor_mcp_render_ability_bulk_actions('bottom'); ?>
        <?php endif; ?>
    </div>
    <?php
}

function elementor_mcp_render_ability_hub_result_notice(?string $result): void
{
    $notice = match ($result) {
        'updated' => ['success', __('Ability rule updated.', domain: 'elementor-mcp')],
        'bulk_updated' => ['success', __('Ability rules updated.', domain: 'elementor-mcp')],
        'invalid' => ['error', __('Invalid ability name.', domain: 'elementor-mcp')],
        'missing_bulk_action' => ['error', __('Choose a bulk action.', domain: 'elementor-mcp')],
        'nothing_selected' => ['error', __('Select at least one ability.', domain: 'elementor-mcp')],
        default => null,
    };

    if ($notice === null) {
        return;
    }
    ?>
    <div class="<?php echo esc_attr('notice notice-' . $notice[0] . ' is-dismissible'); ?>">
        <p><?php echo esc_html($notice[1]); ?></p>
    </div>
    <?php
}

/**
 * Divider that separates Elementor MCP's own abilities from those registered by
 * other plugins, so a provider like "jet-engine" reads clearly as the plugin's.
 */
function elementor_mcp_render_ability_other_plugins_divider(): void
{ ?>
    <h2 class="elementor-mcp-hub-divider"><?php esc_html_e('Registered by other plugins', domain: 'elementor-mcp'); ?></h2>
    <?php }

/**
 * @param list<array{name: string, label: string, description: string, category: string, mcp: string, mcp_type: string, source: string, status: string, disabled: bool, profile_blocked: bool, protected: bool}> $abilities
 * @param string|null $expanded_source Group key that should render expanded.
 */
function elementor_mcp_render_ability_group_section(string $source, array $abilities, ?string $expanded_source): void
{ ?>
    <details class="elementor-mcp-hub-section"<?php echo $source === $expanded_source ? ' open' : ''; ?>>
        <summary class="elementor-mcp-hub-header">
            <?php elementor_mcp_render_ability_select_all(sprintf(
                /* translators: %s: provider name */
                __('Select all abilities from %s', domain: 'elementor-mcp'),
                $source,
            )); ?>
            <h2><?php echo esc_html($source); ?>
                <?php elementor_mcp_render_ability_header_meta($abilities); ?>
            </h2>
        </summary>
        <?php elementor_mcp_render_ability_group_body($abilities); ?>
    </details>
    <?php }

/**
 * Render a section header's count and, when every ability in it is disabled, an
 * "All disabled" pill. The count shows `enabled / total` while some are off and
 * the bare total when all are enabled. hub.js keeps both in sync after an
 * AJAX toggle.
 *
 * @param list<array{name: string, label: string, description: string, category: string, mcp: string, mcp_type: string, source: string, status: string, disabled: bool, profile_blocked: bool, protected: bool}> $abilities
 */
function elementor_mcp_render_ability_header_meta(array $abilities): void
{
    $total = count($abilities);
    $enabled = 0;
    foreach ($abilities as $ability) {
        if ($ability['disabled']) {
            continue;
        }
        $enabled++;
    }
    ?>
    <span class="count"><?php echo
        esc_html($enabled === $total ? (string) $total : $enabled . ' / ' . $total)
    ; ?></span>
    <?php if ($enabled === 0 && $total > 0): ?>
        <span class="pill status is-disabled elementor-mcp-hub-alloff"><?php

        esc_html_e('All disabled', domain: 'elementor-mcp'); ?></span>
    <?php endif; ?>
    <?php
}

/**
 * Render the "select all" checkbox shown in a provider or category header. It
 * toggles every row checkbox within its section client-side (see hub.js); the
 * actual enable/disable still goes through the existing bulk action + nonce.
 */
function elementor_mcp_render_ability_select_all(string $label): void
{ ?>
    <label class="elementor-mcp-hub-select-all">
        <span class="screen-reader-text"><?php echo esc_html($label); ?></span>
        <input type="checkbox" class="elementor-mcp-hub-select-all-input" />
    </label>
    <?php }

/**
 * Render a provider group's body: category sub-sections when there is more than
 * one category, otherwise a flat row list.
 *
 * @param list<array{name: string, label: string, description: string, category: string, mcp: string, mcp_type: string, source: string, status: string, disabled: bool, profile_blocked: bool, protected: bool}> $abilities
 */
function elementor_mcp_render_ability_group_body(array $abilities): void
{
    $by_category = elementor_mcp_group_abilities_by_category($abilities);
    if (count($by_category) > 1) {
        foreach ($by_category as $category => $rows) {
            elementor_mcp_render_ability_category_subsection($category, $rows);
        }
        return;
    }
    ?>
    <div class="elementor-mcp-hub-rows">
        <?php foreach ($abilities as $ability): ?>
            <?php elementor_mcp_render_ability_hub_row($ability); ?>
        <?php endforeach; ?>
    </div>
    <?php
}

/**
 * Group hub rows by their category label. Uncategorized rows sort last.
 *
 * @param list<array{name: string, label: string, description: string, category: string, mcp: string, mcp_type: string, source: string, status: string, disabled: bool, profile_blocked: bool, protected: bool}> $abilities
 * @return array<string, list<array{name: string, label: string, description: string, category: string, mcp: string, mcp_type: string, source: string, status: string, disabled: bool, profile_blocked: bool, protected: bool}>>
 */
function elementor_mcp_group_abilities_by_category(array $abilities): array
{
    $groups = [];
    foreach ($abilities as $ability) {
        $groups[$ability['category']][] = $ability;
    }

    uksort($groups, static function (string $a, string $b): int {
        if ($a === '' || $b === '') {
            return $a === '' ? 1 : -1;
        }
        return strcasecmp($a, $b);
    });

    return $groups;
}

/**
 * @param list<array{name: string, label: string, description: string, category: string, mcp: string, mcp_type: string, source: string, status: string, disabled: bool, profile_blocked: bool, protected: bool}> $rows
 */
function elementor_mcp_render_ability_category_subsection(string $category, array $rows): void
{
    $label = $category !== '' ? $category : __('Other', domain: 'elementor-mcp');
    ?>
    <details class="elementor-mcp-hub-subsection">
        <summary class="elementor-mcp-hub-subheader">
            <?php elementor_mcp_render_ability_select_all(sprintf(
                /* translators: %s: category name */
                __('Select all abilities in %s', domain: 'elementor-mcp'),
                $label,
            )); ?>
            <h3><?php echo esc_html($label); ?>
                <?php elementor_mcp_render_ability_header_meta($rows); ?>
            </h3>
        </summary>
        <div class="elementor-mcp-hub-rows">
            <?php foreach ($rows as $ability): ?>
                <?php elementor_mcp_render_ability_hub_row($ability); ?>
            <?php endforeach; ?>
        </div>
    </details>
    <?php
}

/**
 * @param array{name: string, label: string, description: string, category: string, mcp: string, mcp_type: string, source: string, status: string, disabled: bool, profile_blocked: bool, protected: bool} $ability
 */
function elementor_mcp_render_ability_hub_row(array $ability): void
{
    $row_class = 'elementor-mcp-hub-row ' . ($ability['disabled'] ? 'is-off' : 'is-on');
    $row_class .= $ability['profile_blocked'] ? ' is-profile-blocked' : '';
    $row_class .= $ability['protected'] ? ' is-protected' : '';
    ?>
    <div class="<?php echo esc_attr($row_class); ?>">
        <label class="elementor-mcp-hub-select">
            <span class="screen-reader-text"><?php echo
                esc_html(sprintf(
                    /* translators: %s: ability name */
                    __('Select %s', domain: 'elementor-mcp'),
                    $ability['name'],
                ))
            ; ?></span>
            <input
                type="checkbox"
                name="ability_names[]"
                value="<?php echo esc_attr($ability['name']); ?>"
                form="elementor-mcp-abilities-bulk"
            />
        </label>

        <?php elementor_mcp_render_ability_hub_main($ability); ?>

        <?php elementor_mcp_render_ability_hub_pills($ability); ?>
        <?php elementor_mcp_render_ability_toggle_action($ability); ?>
    </div>
    <?php
}

/**
 * Render the ability's slug and description. When a description is available the
 * row becomes expandable (CSS-only <details>) to reveal the full text and its
 * safety annotations; placeholder rows without a description stay flat.
 *
 * @param array{name: string, label: string, description: string, category: string, mcp: string, mcp_type: string, source: string, status: string, disabled: bool, profile_blocked: bool, protected: bool} $ability
 */
function elementor_mcp_render_ability_hub_main(array $ability): void
{
    if ($ability['description'] === '') {
        ?>
        <div class="elementor-mcp-hub-main elementor-mcp-hub-main--plain">
            <span class="slug" title="<?php echo esc_attr($ability['name']); ?>"><?php echo
                esc_html(elementor_mcp_ability_display_slug($ability['name']))
            ; ?></span>
            <span class="desc"><?php echo esc_html($ability['label']); ?></span>
        </div>
        <?php

        return;
    }
    ?>
    <details class="elementor-mcp-hub-main">
        <summary class="elementor-mcp-hub-summary">
            <span class="slug" title="<?php echo esc_attr($ability['name']); ?>"><?php echo
                esc_html(elementor_mcp_ability_display_slug($ability['name']))
            ; ?></span>
            <span class="desc"><?php echo esc_html($ability['description']); ?></span>
        </summary>
        <div class="elementor-mcp-hub-detail">
            <p class="desc-full"><?php echo esc_html($ability['description']); ?></p>
        </div>
    </details>
    <?php
}

/**
 * @param array{name: string, label: string, description: string, category: string, mcp: string, mcp_type: string, source: string, status: string, disabled: bool, profile_blocked: bool, protected: bool} $ability
 */
function elementor_mcp_render_ability_hub_pills(array $ability): void
{ ?>
    <div class="elementor-mcp-hub-pills">
        <?php if (in_array($ability['mcp_type'], ['prompt', 'resource'], strict: true)): ?>
            <span class="pill mcp"><?php echo esc_html($ability['mcp']); ?></span>
        <?php endif; ?>
        <span class="<?php echo
            esc_attr(
                'pill status ' . ($ability['disabled'] || $ability['profile_blocked'] ? 'is-disabled' : 'is-enabled'),
            )
        ; ?>">
            <?php echo esc_html($ability['status']); ?>
        </span>
        <?php if ($ability['source'] !== ''): ?>
            <span class="pill source"><?php echo esc_html($ability['source']); ?></span>
        <?php endif; ?>
        <?php if ($ability['protected']): ?>
            <span class="pill protected"><?php esc_html_e('Protected', domain: 'elementor-mcp'); ?></span>
        <?php endif; ?>
    </div>
    <?php }

/**
 * @param array{name: string, label: string, description: string, category: string, mcp: string, mcp_type: string, source: string, status: string, disabled: bool, profile_blocked: bool, protected: bool} $ability
 */
function elementor_mcp_render_ability_toggle_action(array $ability): void
{ ?>
    <div class="elementor-mcp-hub-actions">
        <?php if (!$ability['protected']): ?>
            <form method="post">
                <?php wp_nonce_field('elementor_mcp_ability_hub_action'); ?>
                <input type="hidden" name="elementor_mcp_ability_hub_action" value="toggle_disabled" />
                <input type="hidden" name="ability_name" value="<?php echo esc_attr($ability['name']); ?>" />
                <button type="submit" class="action-btn">
                    <?php echo
                        esc_html(
                            $ability['disabled'] ? __('Enable', domain: 'elementor-mcp') : __('Disable', domain: 'elementor-mcp'),
                        )
                    ; ?>
                </button>
            </form>
        <?php endif; ?>
    </div>
    <?php }

function elementor_mcp_render_ability_bulk_actions(string $position): void
{
    $suffix = $position === 'bottom' ? '2' : '';
    ?>
    <div class="tablenav <?php echo esc_attr($position); ?>">
        <div class="alignleft actions bulkactions">
            <label for="<?php echo
                esc_attr('elementor-mcp-bulk-action-selector-' . $position)
            ; ?>" class="screen-reader-text">
                <?php esc_html_e('Select bulk action', domain: 'elementor-mcp'); ?>
            </label>
            <select
                name="<?php echo esc_attr('bulk_action' . $suffix); ?>"
                id="<?php echo esc_attr('elementor-mcp-bulk-action-selector-' . $position); ?>"
                form="elementor-mcp-abilities-bulk"
            >
                <option value="-1"><?php esc_html_e('Bulk actions', domain: 'elementor-mcp'); ?></option>
                <option value="enable"><?php esc_html_e('Enable', domain: 'elementor-mcp'); ?></option>
                <option value="disable"><?php esc_html_e('Disable', domain: 'elementor-mcp'); ?></option>
            </select>
            <button type="submit" class="button action" form="elementor-mcp-abilities-bulk">
                <?php esc_html_e('Apply', domain: 'elementor-mcp'); ?>
            </button>
        </div>
        <br class="clear" />
    </div>
    <?php
}

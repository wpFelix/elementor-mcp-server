<?php

// SPDX-FileCopyrightText: 2026 Elementor MCP <dev@elementormcp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

// phpcs:disable WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Every state-changing request on this screen verifies a nonce via check_admin_referer() before acting; the sniff cannot trace that across function boundaries. Reads are type-checked, whitelist-compared, and escaped on output.

/**
 * Plugin Name: Elementor MCP
 * Plugin URI: https://elementormcp.com
 * Description: WordPress MCP server and Elementor MCP server with 41 typed page-building abilities, OAuth, safety profiles, approvals, rollback, and prompts.
 * Version: 1.0.0
 * Requires at least: 6.9
 * Requires PHP: 8.0
 * Update URI: https://elementormcp.com/elementor-mcp/
 * Author: Elementor MCP
 * Author URI: https://elementormcp.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: elementor-mcp
 * Copyright: Elementor MCP
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

if (!defined('ABSPATH')) {
    exit();
}

require_once __DIR__ . '/includes/compatibility.php';
require_once __DIR__ . '/includes/distribution.php';

define(constant_name: 'ELEMENTOR_MCP_MAX_EXECUTION_TIME', value: 30);
define(constant_name: 'ELEMENTOR_MCP_PLUGIN_FILE', value: __FILE__);
define('ELEMENTOR_MCP_PLUGIN_URL', plugin_dir_url(__FILE__));
define('ELEMENTOR_MCP_SANDBOX_DIR', WP_CONTENT_DIR . '/elementor-mcp-sandbox/');
define(constant_name: 'ELEMENTOR_MCP_VENDOR_AUTOLOAD', value: __DIR__ . '/vendor/autoload_packages.php');
define(constant_name: 'ELEMENTOR_MCP_MCP_ADAPTER_CLASS', value: 'WP\\MCP\\Core\\McpAdapter');

/**
 * Version a packaged asset with its modification time.
 *
 * The public plugin version remains 1.0.0, while changed CSS and JavaScript get
 * a fresh URL. This prevents browsers and proxies from holding an old admin
 * design after an in-place plugin update.
 */
function elementor_mcp_asset_version(string $relative_path): string
{
    $normalized = ltrim(str_replace('\\', '/', $relative_path), '/');
    $path = __DIR__ . '/' . $normalized;
    $modified = is_file($path) ? filemtime($path) : false;

    return $modified === false ? ELEMENTOR_MCP_VERSION : ELEMENTOR_MCP_VERSION . '.' . $modified;
}

/**
 * Load bundled Composer dependencies and report the common source-ZIP install mistake clearly.
 *
 * @return WP_Error|null
 */
function elementor_mcp_load_bundled_dependencies()
{
    if (!file_exists(ELEMENTOR_MCP_VENDOR_AUTOLOAD)) {
        return new WP_Error('elementor_mcp_missing_vendor', __(
            'Elementor MCP is installed without its bundled vendor directory. This usually means the GitHub/source ZIP was installed instead of the Elementor MCP release build ZIP. The MCP Adapter cannot load, so Elementor MCP will not register an MCP endpoint. Install the Elementor MCP release build ZIP before using Elementor MCP.',
            domain: 'elementor-mcp',
        ));
    }

    try {
        require_once ELEMENTOR_MCP_VENDOR_AUTOLOAD;
    } catch (\Throwable $e) {
        return new WP_Error('elementor_mcp_autoload_failed', sprintf(
            /* translators: %s: the autoloader error message */
            __(
                'Elementor MCP could not load its bundled Composer dependencies. The MCP Adapter cannot load, so Elementor MCP will not register an MCP endpoint. Reinstall the Elementor MCP release build ZIP. Error: %s',
                domain: 'elementor-mcp',
            ),
            $e->getMessage(),
        ));
    }

    if (!class_exists(ELEMENTOR_MCP_MCP_ADAPTER_CLASS)) {
        return new WP_Error('elementor_mcp_mcp_adapter_missing', sprintf(
            /* translators: %s: fully qualified MCP Adapter class name */
            __(
                'Elementor MCP loaded its Composer autoloader, but the MCP Adapter class (%s) is not available. Elementor MCP will not register an MCP endpoint. Reinstall the Elementor MCP release build ZIP.',
                domain: 'elementor-mcp',
            ),
            ELEMENTOR_MCP_MCP_ADAPTER_CLASS,
        ));
    }

    return null;
}

/**
 * Store a runtime MCP dependency error.
 */
function elementor_mcp_set_mcp_dependency_error(WP_Error $error): void
{
    elementor_mcp_mcp_dependency_error($error);
}

/**
 * Return the current MCP dependency error, if any.
 *
 * @return WP_Error|null
 */
function elementor_mcp_get_mcp_dependency_error()
{
    return elementor_mcp_mcp_dependency_error();
}

/**
 * Shared storage for the current MCP dependency error.
 *
 * @return WP_Error|null
 */
function elementor_mcp_mcp_dependency_error(?WP_Error $error = null)
{
    static $current = null;

    if ($error !== null) {
        $current = $error;
    }

    return $current;
}

/**
 * Whether the bundled MCP Adapter is available for Elementor MCP to initialize.
 */
function elementor_mcp_is_mcp_adapter_available(): bool
{
    return elementor_mcp_get_mcp_dependency_error() === null && class_exists(ELEMENTOR_MCP_MCP_ADAPTER_CLASS);
}

/**
 * Show a persistent admin error when Elementor MCP cannot expose MCP.
 */
function elementor_mcp_render_mcp_dependency_notice(): void
{
    if (!elementor_mcp_current_user_can_manage()) {
        return;
    }

    $page = $_GET['page'] ?? null;
    if (
        is_string($page)
        && in_array($page, ['elementor-mcp-connect', 'elementor-mcp-connections', 'elementor-mcp-abilities', 'elementor-mcp-chat', 'elementor-mcp-sandbox'], strict: true)
    ) {
        return;
    }

    $error = elementor_mcp_get_mcp_dependency_error();
    if ($error === null) {
        return;
    }

    wp_admin_notice(esc_html($error->get_error_message()), [
        'type' => 'error',
        'dismissible' => false,
    ]);
}

/**
 * Return a clear REST error at the MCP endpoint when the adapter cannot register its own route.
 */
function elementor_mcp_register_missing_mcp_endpoint(): void
{
    $error = elementor_mcp_get_mcp_dependency_error();
    if ($error === null) {
        return;
    }

    $routes = rest_get_server()->get_routes();
    $callback = static fn() => new WP_Error('elementor_mcp_mcp_adapter_unavailable', $error->get_error_message(), [
        'status' => 500,
    ]);

    foreach (['elementor-mcp', 'mcp-adapter-default-server'] as $route_slug) {
        if (array_key_exists('/mcp/' . $route_slug, $routes)) {
            continue;
        }
        register_rest_route('mcp', '/' . $route_slug, [
            'methods' => WP_REST_Server::ALLMETHODS,
            'callback' => $callback,
            'permission_callback' => '__return_true',
        ]);
    }
}

/**
 * Initialize the MCP Adapter and convert runtime failures into visible admin notices.
 */
function elementor_mcp_initialize_mcp_adapter(): bool
{
    if (!elementor_mcp_is_mcp_adapter_available()) {
        return false;
    }

    try {
        \WP\MCP\Core\McpAdapter::instance();
        return true;
    } catch (\Throwable $e) {
        elementor_mcp_set_mcp_dependency_error(
            new WP_Error('elementor_mcp_mcp_adapter_init_failed', sprintf(
                /* translators: %s: the adapter initialization error message */
                __(
                    'Elementor MCP found the MCP Adapter, but it failed during initialization. Elementor MCP will not register an MCP endpoint. Error: %s',
                    domain: 'elementor-mcp',
                ),
                $e->getMessage(),
            )),
        );
        return false;
    }
}

$elementor_mcp_dependency_error = elementor_mcp_load_bundled_dependencies();
if ($elementor_mcp_dependency_error !== null) {
    elementor_mcp_set_mcp_dependency_error($elementor_mcp_dependency_error);
}

require_once __DIR__ . '/includes/chat/schema.php';

add_action('admin_notices', callback: 'elementor_mcp_render_mcp_dependency_notice');
add_action('network_admin_notices', callback: 'elementor_mcp_render_mcp_dependency_notice');
add_action('plugins_loaded', callback: 'elementor_mcp_chat_schema_maybe_install');
add_action('rest_api_init', callback: 'elementor_mcp_register_missing_mcp_endpoint', priority: 999);

// Foundations, in dependency order: paths before the sandbox boundary that
// uses them, capabilities and environment before anything that gates on them.
require_once __DIR__ . '/includes/filesystem.php';
require_once __DIR__ . '/includes/sandbox/guards.php';
require_once __DIR__ . '/includes/capabilities.php';
require_once __DIR__ . '/includes/environment.php';
require_once __DIR__ . '/includes/agent-context.php';
require_once __DIR__ . '/includes/admin/nav.php';
require_once __DIR__ . '/includes/admin/ui.php';
require_once __DIR__ . '/includes/abilities/policy.php';
require_once __DIR__ . '/includes/safety.php';
require_once __DIR__ . '/includes/rate-limit.php';
require_once __DIR__ . '/includes/change-log.php';
require_once __DIR__ . '/includes/clients.php';
require_once __DIR__ . '/includes/connections.php';
require_once __DIR__ . '/includes/tokens.php';
require_once __DIR__ . '/includes/privacy.php';
require_once __DIR__ . '/includes/rest/transport-hardening.php';

add_filter('rest_pre_dispatch', callback: 'elementor_mcp_guard_rest_host', priority: 5, accepted_args: 3);
add_filter('rest_post_dispatch', callback: 'elementor_mcp_harden_rest_response', priority: 20, accepted_args: 3);

// Dual-era MCP. The bundled adapter serves the legacy, session-based revisions;
// these modules answer MCP 2026-07-28, which removed the handshake and sessions
// entirely. The dispatcher only claims a request that carries modern per-request
// _meta, so legacy traffic reaches the adapter untouched.
foreach (['protocol', 'errors', 'headers', 'results', 'discover', 'transport'] as $elementor_mcp_mcp_module) {
    require_once __DIR__ . '/includes/mcp/' . $elementor_mcp_mcp_module . '.php';
}
unset($elementor_mcp_mcp_module);
\ElementorMCP\Mcp\register_modern_transport();

require_once __DIR__ . '/includes/abilities/bootstrap.php';
// Self-hosted update checks, for builds distributed from elementormcp.com.
//
// Conditional because the WordPress.org build must not contain this file at all:
// the directory forbids a plugin serving its own updates from anywhere else, and
// this checker deliberately reports "no update" to stop .org overriding it —
// exactly the behaviour that gets a plugin pulled. The packaging script drops the
// file for the .org build, and this guard is what makes that safe.
if (file_exists(__DIR__ . '/includes/updater.php')) {
    require_once __DIR__ . '/includes/updater.php';
}
// Anonymous usage reporting. Guarded for the same reason as the updater above:
// scripts/package.sh deletes includes/telemetry/ from the .org build, so the
// directory's opt-in rule is satisfied by that build being incapable of
// reporting rather than by a flag someone could get wrong at runtime.
if (file_exists(__DIR__ . '/includes/telemetry/bootstrap.php')) {
    require_once __DIR__ . '/includes/telemetry/bootstrap.php';
}
require_once __DIR__ . '/includes/admin/abilities-hub.php';
require_once __DIR__ . '/includes/admin/connect/connect.php';
require_once __DIR__ . '/includes/admin/pro-upsell.php';
if (!elementor_mcp_is_wordpress_org_edition()) {
    require_once __DIR__ . '/includes/links/upload.php';
    require_once __DIR__ . '/includes/links/admin-access.php';
}
require_once __DIR__ . '/includes/skills/bootstrap.php';
require_once __DIR__ . '/includes/design/bootstrap.php';
require_once __DIR__ . '/includes/admin/sidebar.php';
require_once __DIR__ . '/includes/prompt-library/packs.php';
require_once __DIR__ . '/includes/prompt-library/admin.php';
require_once __DIR__ . '/includes/oauth/bootstrap.php';
require_once __DIR__ . '/includes/troubleshoot/bootstrap.php';
require_once __DIR__ . '/includes/admin/instructions.php';
require_once __DIR__ . '/includes/admin/settings.php';
require_once __DIR__ . '/includes/admin/dashboard.php';
require_once __DIR__ . '/includes/preview/bootstrap.php';

// Loaded last: the lifecycle routines call into chat-schema, safety, helpers,
// and the upsell, so every one of those must already be defined.
require_once __DIR__ . '/includes/lifecycle.php';
elementor_mcp_register_lifecycle_hooks();

add_action('plugins_loaded', callback: 'elementor_mcp_safety_maybe_install', priority: 4);
add_filter('mcp_adapter_pre_tool_call', callback: 'elementor_mcp_safety_pre_mcp_tool_call', priority: 5, accepted_args: 2);
add_action('wp_before_execute_ability', callback: 'elementor_mcp_change_before', priority: 10, accepted_args: 2);
add_action('wp_after_execute_ability', callback: 'elementor_mcp_change_after', priority: 10, accepted_args: 3);

\ElementorMCP\Context\boot_context_admin();
elementor_mcp_register_wordpress_compatibility_notice();
elementor_mcp_boot_ability_rest_surface();
elementor_mcp_register_ability_policy_hook();
require_once __DIR__ . '/includes/chat/chat.php';

add_action('admin_post_elementor_mcp_toggle_ai_abilities', callback: 'elementor_mcp_handle_admin_bar_toggle');
add_action('admin_post_elementor_mcp_download_mcpb', callback: 'elementor_mcp_handle_download_mcpb');

function elementor_mcp_inject_custom_instructions(mixed $instructions): mixed
{
    if (!is_string($instructions)) {
        return $instructions;
    }

    // Stay out while a Elementor MCP Pro that still manages custom instructions is
    // active: it injects its own copy (priority 5), so the base must not add a
    // second one. The base takes over once that Pro is gone or updated.
    if (\ElementorMCP\Context\legacy_pro_context_loaded()) {
        return $instructions;
    }

    if (\ElementorMCP\Context\instructions_custom_injection_suppressed()) {
        return $instructions;
    }

    if (!\ElementorMCP\Context\instructions_is_enabled()) {
        return $instructions;
    }

    $custom = \ElementorMCP\Context\instructions_get_content();
    if (trim($custom) === '') {
        return $instructions;
    }

    if (str_starts_with($instructions, $custom . "\n\n")) {
        return $instructions;
    }

    return $custom . "\n\n" . $instructions;
}

add_filter('elementor_mcp_discover_abilities_instructions', callback: 'elementor_mcp_inject_custom_instructions', priority: 6);

/**
 * Add the Elementor MCP AI Abilities status and toggle to the WordPress admin bar.
 */
function elementor_mcp_register_admin_bar_toggle(\WP_Admin_Bar $wp_admin_bar): void
{
    if (!elementor_mcp_current_user_can_manage()) {
        return;
    }

    $dependency_error = elementor_mcp_get_mcp_dependency_error();
    $configured_enabled = elementor_mcp_is_enabled();
    $active = $configured_enabled && $dependency_error === null;
    $can_enable = $configured_enabled || $dependency_error === null;
    $target = $configured_enabled ? 'off' : 'on';
    $toggle_url = wp_nonce_url(
        admin_url('admin-post.php?action=elementor_mcp_toggle_ai_abilities&elementor_mcp_target=' . $target),
        action: 'elementor_mcp_toggle_ai_abilities',
    );

    $wp_admin_bar->add_node([
        'id' => 'elementor-mcp-mcp-status',
        'title' => match (true) {
            $active => esc_html__('Elementor MCP ON', domain: 'elementor-mcp'),
            $configured_enabled => esc_html__('Elementor MCP ERROR', domain: 'elementor-mcp'),
            default => esc_html__('Elementor MCP', domain: 'elementor-mcp'),
        },
        'href' => admin_url('admin.php?page=elementor-mcp-connect'),
        'meta' => [
            'class' => match (true) {
                $active => 'elementor-mcp-mcp-on',
                $configured_enabled => 'elementor-mcp-mcp-error',
                default => 'elementor-mcp-mcp-off',
            },
        ],
    ]);

    $wp_admin_bar->add_node([
        'id' => 'elementor-mcp-mcp-status-label',
        'parent' => 'elementor-mcp-mcp-status',
        'title' => match (true) {
            $active => esc_html__('AI Abilities: On', domain: 'elementor-mcp'),
            $configured_enabled => esc_html__('AI Abilities: Error', domain: 'elementor-mcp'),
            default => esc_html__('AI Abilities: Off', domain: 'elementor-mcp'),
        },
    ]);

    if (!$can_enable) {
        $wp_admin_bar->add_node([
            'id' => 'elementor-mcp-mcp-unavailable',
            'parent' => 'elementor-mcp-mcp-status',
            'title' => esc_html__('AI Abilities unavailable', domain: 'elementor-mcp'),
            'href' => admin_url('admin.php?page=elementor-mcp-settings'),
        ]);
    }

    if ($can_enable) {
        $wp_admin_bar->add_node([
            'id' => 'elementor-mcp-mcp-toggle',
            'parent' => 'elementor-mcp-mcp-status',
            'title' => $configured_enabled
                ? esc_html__('Turn Off AI Abilities', domain: 'elementor-mcp')
                : esc_html__('Turn On AI Abilities', domain: 'elementor-mcp'),
            'href' => $toggle_url,
            'meta' => [
                'class' => $configured_enabled ? 'elementor-mcp-mcp-toggle-off' : 'elementor-mcp-mcp-toggle-on',
            ],
        ]);
    }

    $wp_admin_bar->add_node([
        'id' => 'elementor-mcp-mcp-connections',
        'parent' => 'elementor-mcp-mcp-status',
        'title' => esc_html__('Connections', domain: 'elementor-mcp'),
        'href' => admin_url('admin.php?page=elementor-mcp-connections'),
    ]);

    $wp_admin_bar->add_node([
        'id' => 'elementor-mcp-mcp-config',
        'parent' => 'elementor-mcp-mcp-status',
        'title' => esc_html__('Preferences', domain: 'elementor-mcp'),
        'href' => admin_url('admin.php?page=elementor-mcp-settings'),
    ]);
}

/**
 * Style the admin-bar status chip and require confirmation before enabling from the dropdown.
 */
function elementor_mcp_render_admin_bar_toggle_assets(): void
{
    if (!elementor_mcp_current_user_can_manage() || !is_admin_bar_showing()) {
        return;
    }

    $looks_production = elementor_mcp_looks_like_production();
    $confirm_message = $looks_production
        ? __(
            'This looks like a production site. AI Abilities are intended for staging or development sites. Continue anyway?',
            domain: 'elementor-mcp',
        )
        : __('AI agents will be able to execute PHP code and access the filesystem. Continue?', domain: 'elementor-mcp');
    ?>
    <style>
    #wp-admin-bar-elementor-mcp-mcp-status.elementor-mcp-mcp-on > .ab-item {
        background: #c00 !important;
        color: #fff !important;
    }
    #wp-admin-bar-elementor-mcp-mcp-status.elementor-mcp-mcp-error > .ab-item {
        background: #996800 !important;
        color: #fff !important;
    }
    #wp-admin-bar-elementor-mcp-mcp-status-label > .ab-item {
        cursor: default;
        font-weight: 600;
    }
    </style>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        var toggle = document.querySelector('#wp-admin-bar-elementor-mcp-mcp-toggle.elementor-mcp-mcp-toggle-on > .ab-item');
        if (!toggle) {
            return;
        }
        toggle.addEventListener('click', function (event) {
            if (!window.confirm(<?php echo wp_json_encode($confirm_message); ?>)) {
                event.preventDefault();
            }
        });
    });
    </script>
    <?php
}

add_action('admin_bar_menu', callback: 'elementor_mcp_register_admin_bar_toggle', priority: 999);
add_action('admin_head', callback: 'elementor_mcp_render_admin_bar_toggle_assets');
add_action('wp_head', callback: 'elementor_mcp_render_admin_bar_toggle_assets');

// Optional dev mock for the external-skills source. Gitignored. Loaded
// only when the constant is set (e.g. in wp-config.php) so it never ships
// to production builds.
if (
    defined('ELEMENTOR_MCP_DEV_MOCK_PRO')
    && constant('ELEMENTOR_MCP_DEV_MOCK_PRO') === true
    && file_exists(__DIR__ . '/includes/skills/dev-mock.php')
) {
    require_once __DIR__ . '/includes/skills/dev-mock.php';
}

// Add "Community" and "X" links to the plugin row meta on the Plugins page.
add_filter(
    'plugin_row_meta',
    /** @param string[] $plugin_meta */
    static function (array $plugin_meta, string $plugin_file): array {
        if ($plugin_file === plugin_basename(__FILE__)) {
            $plugin_meta[] =
                '<a href="https://www.facebook.com/groups/elementor-mcp" target="_blank" rel="noopener noreferrer">'
                . esc_html__('Community', domain: 'elementor-mcp')
                . '</a>';
            $plugin_meta[] =
                '<a href="https://x.com/ElementorMCP" target="_blank" rel="noopener noreferrer">'
                . esc_html__('X', domain: 'elementor-mcp')
                . '</a>';
        }
        return $plugin_meta;
    },
    priority: 10,
    accepted_args: 2,
);

// Suppress noisy admin notices on the Dashboard and Connections pages via CSS: hide notices that are not
// emitted by Elementor MCP or Elementor MCP Pro. Cheap and side-effect free, unlike iterating $wp_filter
// with Reflection (which causes memory blow-ups when Query Monitor captures every remove_action).
add_action('admin_head', static function () {
    if (!in_array(($_GET['page'] ?? null), ['elementor-mcp-connect', 'elementor-mcp-connections'], strict: true)) {
        return;
    }
    ?>
    <style id="elementor-mcp-suppress-foreign-notices">
        .wrap > .notice:not(.elementor-mcp-pro-notice):not(.elementor-mcp-keep),
        #wpbody-content > .notice:not(.elementor-mcp-pro-notice):not(.elementor-mcp-keep),
        #wpbody-content > .updated:not(.elementor-mcp-keep),
        #wpbody-content > .error:not(.elementor-mcp-keep) {
            display: none !important;
        }
    </style>
    <?php
});

// Handle form actions early (before headers are sent) for PRG redirect.
add_action('admin_init', static function () {
    $page = $_GET['page'] ?? null;
    if ($page === 'elementor-mcp-sandbox') {
        elementor_mcp_handle_sandbox_actions();
    }
    if (in_array($page, ['elementor-mcp-connect', 'elementor-mcp-connections'], strict: true)) {
        elementor_mcp_handle_revoke_password();
        elementor_mcp_handle_revoke_token();
        elementor_mcp_handle_dismiss_production_warning();
    }
    if ($page === 'elementor-mcp-abilities') {
        elementor_mcp_handle_ability_hub_actions();
    }
});

// Single-row toggle over AJAX so the page state (open sections) is preserved.
add_action('wp_ajax_elementor_mcp_toggle_ability', callback: 'elementor_mcp_handle_ability_toggle_ajax');

// Admin page stylesheets.
add_action('admin_enqueue_scripts', static function (string $hook): void {
    // The shared design system loads on every Elementor MCP screen. Screen-specific
    // sheets below depend on it, so it is registered first and named as their
    // dependency rather than relying on enqueue order.
    if (str_contains($hook, 'elementor-mcp')) {
        wp_enqueue_style(
            'elementor-mcp-admin',
            (string) ELEMENTOR_MCP_PLUGIN_URL . 'includes/assets/admin.css',
            [],
            elementor_mcp_asset_version('includes/assets/admin.css'),
        );
    }

    if ($hook === 'elementor-mcp_page_elementor-mcp-abilities') {
        wp_enqueue_style(
            'elementor-mcp-hub-admin',
            (string) ELEMENTOR_MCP_PLUGIN_URL . 'includes/assets/hub.css',
            ['elementor-mcp-admin'],
            elementor_mcp_asset_version('includes/assets/hub.css'),
        );
        // phpcs:disable WordPress.WP.EnqueuedResourceParameters.NotInFooter -- `args: true` IS the in_footer flag; the sniff cannot read PHP named arguments.
        wp_enqueue_script(
            'elementor-mcp-hub-admin',
            (string) ELEMENTOR_MCP_PLUGIN_URL . 'includes/assets/hub.js',
            [],
            elementor_mcp_asset_version('includes/assets/hub.js'),
            args: true,
        );

        // phpcs:enable WordPress.WP.EnqueuedResourceParameters.NotInFooter
    }

    if ($hook === 'elementor-mcp_page_elementor-mcp-sandbox') {
        wp_enqueue_style(
            'elementor-mcp-sandbox-admin',
            (string) ELEMENTOR_MCP_PLUGIN_URL . 'includes/assets/sandbox.css',
            ['elementor-mcp-admin'],
            elementor_mcp_asset_version('includes/assets/sandbox.css'),
        );
    }
});

// Register admin menus.
// Menu order uses spaced admin_menu priorities (multiples of 10) so entries
// can be positioned without post-hoc reordering.
add_action(
    'admin_menu',
    static function () {
        // Top-level menu item opens the operational Dashboard.
        add_menu_page(
            page_title: elementor_mcp_nav_label('elementor-mcp-connect'),
            menu_title: 'Elementor MCP',
            capability: elementor_mcp_manage_capability(),
            menu_slug: 'elementor-mcp-connect',
            callback: 'elementor_mcp_render_dashboard_page',
            icon_url: elementor_mcp_admin_menu_icon(),
            position: 3,
        );

        // Rename the auto-created first submenu entry to match the page title.
        add_submenu_page(
            parent_slug: 'elementor-mcp-connect',
            page_title: elementor_mcp_nav_label('elementor-mcp-connect'),
            menu_title: elementor_mcp_nav_label('elementor-mcp-connect'),
            capability: elementor_mcp_manage_capability(),
            menu_slug: 'elementor-mcp-connect',
            callback: 'elementor_mcp_render_dashboard_page',
        );

        add_submenu_page(
            parent_slug: 'elementor-mcp-connect',
            page_title: elementor_mcp_nav_label('elementor-mcp-connections'),
            menu_title: elementor_mcp_nav_label('elementor-mcp-connections'),
            capability: elementor_mcp_manage_capability(),
            menu_slug: 'elementor-mcp-connections',
            callback: 'elementor_mcp_render_connect_page',
        );
    },
    priority: 10,
);

// Abilities Hub — priority 25 places it after System Health (20). Context uses
// priority 30, so 25 keeps AI Tools between those screens without renumbering the rest.
add_action(
    'admin_menu',
    static function () {
        add_submenu_page(
            parent_slug: 'elementor-mcp-connect',
            page_title: elementor_mcp_nav_label('elementor-mcp-abilities'),
            menu_title: elementor_mcp_nav_label('elementor-mcp-abilities'),
            capability: elementor_mcp_manage_capability(),
            menu_slug: 'elementor-mcp-abilities',
            callback: 'elementor_mcp_render_settings_page',
        );
    },
    priority: 25,
);

// Sandbox sub-page — priority 50 places it after Context (30) and Skills (40).
add_action(
    'admin_menu',
    static function () {
        add_submenu_page(
            parent_slug: 'elementor-mcp-connect',
            page_title: elementor_mcp_nav_label('elementor-mcp-sandbox'),
            menu_title: elementor_mcp_nav_label('elementor-mcp-sandbox'),
            capability: elementor_mcp_manage_capability(),
            menu_slug: 'elementor-mcp-sandbox',
            callback: 'elementor_mcp_render_sandbox_page',
        );
    },
    priority: 50,
);

$is_enabled = elementor_mcp_is_enabled();

if (!$is_enabled && elementor_mcp_is_domain_mismatch()) {
    add_action('admin_notices', static function () {
        if (!elementor_mcp_current_user_can_manage()) {
            return;
        }
        /** @var string $locked */
        $locked = get_option('elementor_mcp_ai_abilities_domain', default_value: '');
        wp_admin_notice(
            sprintf(
                /* translators: %s: the domain the abilities were originally enabled on */
                esc_html__(
                    'Elementor MCP AI Abilities were disabled because the site domain changed (enabled on %s). Re-enable them from the Connect page if this is intentional.',
                    domain: 'elementor-mcp',
                ),
                '<code>' . esc_html($locked) . '</code>',
            ),
            ['type' => 'warning', 'dismissible' => true],
        );
    });
}

$elementor_mcp_abilities_supported = elementor_mcp_wordpress_abilities_supported();
$elementor_mcp_adapter_initialized = false;

if ($is_enabled && $elementor_mcp_abilities_supported) {
    elementor_mcp_register_ability_hooks();

    // MCP clients commonly leave sessions behind when they disconnect. Keep enough short-lived
    // sessions to avoid the adapter evicting active sessions when its default 32-session cap is reached.
    add_filter('mcp_adapter_session_max_per_user', static fn(): int => 128);
    add_filter('mcp_adapter_session_inactivity_timeout', static fn(): int => 4 * HOUR_IN_SECONDS);

    // Brand the default MCP server. Usage instructions are returned from the
    // discover-abilities tool instead of the initialize handshake.
    add_filter('mcp_adapter_default_server_config', static function (mixed $config): mixed {
        if (!is_array($config)) {
            return $config;
        }
        $config['server_id'] = 'elementor-mcp';
        $config['server_route'] = 'elementor-mcp';
        $config['server_name'] = 'Elementor MCP';
        // Without this the adapter's own default is used, and legacy clients
        // read that from initialize's serverInfo — it reported v1.0.0 for the
        // whole 1.1.0 cycle because only the mirror servers set a version.
        $config['server_version'] = 'v' . ELEMENTOR_MCP_VERSION;
        return $config;
    });

    // Register a legacy alias server at the old slug so configs that still point at
    // /wp-json/mcp/mcp-adapter-default-server keep working after the rename.
    add_action('mcp_adapter_init', callback: 'elementor_mcp_register_legacy_mcp_server', priority: 20);

    // Register the OAuth-only server at /mcp/elementor-mcp-oauth. Keeping the OAuth Bearer flow on a
    // route of its own means the OAuth middleware never touches the canonical /mcp/elementor-mcp
    // endpoint that the existing Application Password installs use. Gated on the same transport
    // check as the OAuth bootstrap so the endpoint never exists without its token/authorize peers.
    add_action('mcp_adapter_init', callback: 'elementor_mcp_register_oauth_mcp_server', priority: 20);

    // Initialize the optional bundled adapter after the transport-neutral Ability and REST hooks.
    // An adapter failure must not remove those hooks or make the REST Ability surface disappear.
    $elementor_mcp_adapter_initialized = elementor_mcp_initialize_mcp_adapter();
}

/**
 * Register a legacy alias of the canonical Elementor MCP MCP server at the pre-rename slug.
 *
 * The canonical server is registered under `/mcp/elementor-mcp`. Older client configs may still
 * point at `/mcp/mcp-adapter-default-server` from before the rename — this alias keeps them
 * working with identical behavior (same tools, same auto-discovered resources and prompts).
 */
function elementor_mcp_register_legacy_mcp_server(mixed $adapter): void
{
    if (!$adapter instanceof \WP\MCP\Core\McpAdapter) {
        return;
    }

    if ($adapter->get_server('elementor-mcp') === null) {
        return;
    }

    elementor_mcp_create_mirror_mcp_server(
        $adapter,
        server_id: 'mcp-adapter-default-server',
        route: 'mcp-adapter-default-server',
        name: 'Elementor MCP (legacy alias)',
        description: 'Legacy alias for the Elementor MCP MCP server. New client configurations should use /wp-json/mcp/elementor-mcp.',
    );
}

/**
 * Register the OAuth-authenticated Elementor MCP MCP server at `/mcp/elementor-mcp-oauth`.
 *
 * The OAuth Bearer flow lives on this dedicated route so the canonical `/mcp/elementor-mcp` endpoint —
 * used by the existing Application Password installs — is never seen by the OAuth challenge
 * middleware (see includes/oauth/middleware.php::is_mcp_route). Registered only when the OAuth
 * transport is permitted, mirroring includes/oauth/bootstrap.php so the endpoint never exists
 * without the token/authorize endpoints that make it usable.
 */
function elementor_mcp_register_oauth_mcp_server(mixed $adapter): void
{
    if (!$adapter instanceof \WP\MCP\Core\McpAdapter) {
        return;
    }

    if (!elementor_mcp_oauth_transport_allowed()) {
        return;
    }

    if ($adapter->get_server('elementor-mcp') === null) {
        return;
    }

    elementor_mcp_create_mirror_mcp_server(
        $adapter,
        server_id: 'elementor-mcp-oauth',
        route: 'elementor-mcp-oauth',
        name: 'Elementor MCP (OAuth)',
        description: 'OAuth-authenticated Elementor MCP MCP endpoint. Application Password clients use /wp-json/mcp/elementor-mcp.',
    );
}

/**
 * Create an MCP server that mirrors the canonical Elementor MCP server — same tools, resources, and
 * prompts — under a different id and route. Shared by the legacy alias and the OAuth endpoint so
 * neither drifts from the default server's exposed abilities.
 */
function elementor_mcp_create_mirror_mcp_server(
    \WP\MCP\Core\McpAdapter $adapter,
    string $server_id,
    string $route,
    string $name,
    string $description,
): void {
    $adapter->create_server(
        $server_id,
        'mcp',
        $route,
        $name,
        $description,
        // Derived, not hardcoded: this is the version legacy clients read from
        // initialize's serverInfo, and it silently drifted from the plugin
        // header once already.
        'v' . ELEMENTOR_MCP_VERSION,
        [\WP\MCP\Transport\HttpTransport::class],
        \WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,
        \WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler::class,
        [
            'mcp-adapter/discover-abilities',
            'mcp-adapter/get-ability-info',
            'mcp-adapter/execute-ability',
        ],
        elementor_mcp_discover_public_abilities('resource'),
        elementor_mcp_discover_public_abilities('prompt'),
    );
}

/**
 * Replicate DefaultServerFactory::discover_abilities_by_type for reuse on the legacy alias.
 *
 * @return list<string>
 */
function elementor_mcp_discover_public_abilities(string $type): array
{
    if (!function_exists('wp_get_abilities')) {
        return [];
    }

    $abilities = wp_get_abilities();
    $filtered = [];
    foreach ($abilities as $ability) {
        $meta = $ability->get_meta();
        if (!($meta['mcp']['public'] ?? false)) {
            continue;
        }
        $ability_type = (string) ($meta['mcp']['type'] ?? 'tool');
        if ($ability_type !== $type) {
            continue;
        }
        $filtered[] = $ability->get_name();
    }

    return $filtered;
}

if ($elementor_mcp_adapter_initialized) {
    // The `mcp-adapter/execute-ability` dispatcher wraps every ability return in
    // `{ success: true, data: <inner> }`. When the inner value is itself
    // `{ success: false, error: "..." }` the outer `success: true` masks a real
    // logical failure, and agents that check the top-level flag — a very
    // reasonable default — silently march past the error. Unwrap that shape
    // here so the adapter's backward-compat path (ToolsHandler) turns it into a
    // proper `isError: true` CallToolResult.
    //
    // ToolsHandler::create_error_result flattens the response to a bare
    // `content: [text(error)], structuredContent: null, isError: true` — every
    // sibling field on the ability's return is discarded. Validators attach
    // structured repair hints (`invalid_values`, `unknown_properties`,
    // `collision_paths`, `suggested_name`, `failed_paths`, `overwritten_paths`,
    // `errors`, `schemas`, `style_errors`, `dynamic_tag_errors`, `dropped_keys`,
    // `schema`, …) that the agent needs to self-correct without a
    // round-trip — so embed whatever else the ability returned as a JSON
    // suffix on the error message. The suffix rides inside the string and
    // survives the downstream flatten.
    add_filter(
        'mcp_adapter_tool_call_result',
        static function (mixed $result, array $args, string $tool_name): mixed {
            // Tool names are MCP-sanitized from ability slugs — `/` becomes `-`.
            if ($tool_name !== 'mcp-adapter-execute-ability') {
                return $result;
            }
            if (!is_array($result) || ($result['success'] ?? null) !== true) {
                return $result;
            }
            /** @var array<array-key, mixed>|null $data */
            $data = $result['data'] ?? null;
            if (!is_array($data) || ($data['success'] ?? null) !== false) {
                return $result;
            }
            /** @var string|null $error */
            $error = $data['error'] ?? null;
            if (!is_string($error) || trim($error) === '') {
                return $result;
            }

            $detail = $data;
            unset($detail['success'], $detail['error']);
            if ($detail !== []) {
                $encoded = wp_json_encode($detail, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if (is_string($encoded)) {
                    $data['error'] = $error . "\n\nStructured detail (JSON):\n" . $encoded;
                }
            }

            return $data;
        },
        priority: 10,
        accepted_args: 3,
    );

    // Fix empty "properties" in JSON Schema: PHP json_encode outputs [] instead of {}.
    // MCP clients reject tools with invalid schemas, so we fix this in the REST response.
    add_filter('rest_pre_echo_response', static function (mixed $result): mixed {
        if (!is_array($result)) {
            return $result;
        }
        /** @var \stdClass|null $resultObj */
        $resultObj = $result['result'] ?? null;
        if (!$resultObj instanceof \stdClass) {
            return $result;
        }
        /** @var list<array<string, mixed>>|null $tools */
        $tools = $resultObj->tools ?? null;
        if (!is_array($tools)) {
            return $result;
        }
        foreach ($tools as &$tool) {
            foreach (['inputSchema', 'outputSchema'] as $key) {
                /** @var array<string, mixed>|null $schema */
                $schema = $tool[$key] ?? null;
                if (!is_array($schema) || ($schema['properties'] ?? null) !== []) {
                    continue;
                }
                $schema['properties'] = new \stdClass();
                $tool[$key] = $schema;
            }
        }
        $resultObj->tools = $tools;
        return $result;
    });

    // Info notice if the standalone MCP Adapter plugin is still active.
    if (function_exists('is_plugin_active') && is_plugin_active('mcp-adapter/mcp-adapter.php')) {
        add_action('admin_notices', static function () {
            if (!elementor_mcp_current_user_can_manage()) {
                return;
            }
            wp_admin_notice(
                esc_html__(
                    'Elementor MCP bundles the MCP Adapter. You can safely deactivate the standalone MCP Adapter plugin.',
                    domain: 'elementor-mcp',
                ),
                [
                    'type' => 'info',
                    'dismissible' => true,
                ],
            );
        });
    }
}
add_filter(
    'mcp_adapter_tool_call_result',
    callback: 'elementor_mcp_enrich_disabled_ability_error',
    priority: 10,
    accepted_args: 2,
);

// Load sandbox plugins. The directory itself is created on activation and
// lazily by elementor_mcp_get_sandbox_dir(ensure_exists: true) at the moment an
// ability first writes to it — never on every request, which would stat (and
// on read-only filesystems, fail to create) wp-content on every page load.
if (!elementor_mcp_is_wordpress_org_edition()) {
    require_once __DIR__ . '/includes/sandbox/loader.php';
}

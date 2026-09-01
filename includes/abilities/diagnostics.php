<?php

// SPDX-FileCopyrightText: 2026 Elementor MCP <dev@elementormcp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('elementor-mcp/system-status', [
    'label' => __('System Status', domain: 'elementor-mcp'),
    'description' => __(
        'Return a concise WordPress, PHP, database, cron, cache, and Elementor MCP runtime status report.',
        domain: 'elementor-mcp',
    ),
    'input_schema' => ELEMENTOR_MCP_NO_INPUT_SCHEMA,
    'category' => 'diagnostics',
    'execute_callback' => 'elementor_mcp_system_status',
    'permission_callback' => static fn(): bool => current_user_can('manage_options'),
    'meta' => elementor_mcp_diagnostic_meta(),
]);

wp_register_ability('elementor-mcp/performance-audit', [
    'label' => __('Performance Audit', domain: 'elementor-mcp'),
    'description' => __(
        'Run bounded server-side performance checks without synthetic speed claims.',
        domain: 'elementor-mcp',
    ),
    'input_schema' => ELEMENTOR_MCP_NO_INPUT_SCHEMA,
    'category' => 'diagnostics',
    'execute_callback' => 'elementor_mcp_performance_audit',
    'permission_callback' => static fn(): bool => current_user_can('manage_options'),
    'meta' => elementor_mcp_diagnostic_meta(),
]);

wp_register_ability('elementor-mcp/security-audit', [
    'label' => __('Security Configuration Audit', domain: 'elementor-mcp'),
    'description' => __(
        'Inspect bounded WordPress hardening signals and outstanding updates; this is not a malware scan.',
        domain: 'elementor-mcp',
    ),
    'input_schema' => ELEMENTOR_MCP_NO_INPUT_SCHEMA,
    'category' => 'diagnostics',
    'execute_callback' => 'elementor_mcp_security_audit',
    'permission_callback' => static fn(): bool => current_user_can('manage_options'),
    'meta' => elementor_mcp_diagnostic_meta(),
]);

/** @return array<string, mixed> */
function elementor_mcp_diagnostic_meta(): array
{
    return [
        'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
        'mcp' => ['public' => true],
    ];
}

/** @return array<string, mixed> */
function elementor_mcp_system_status(): array
{
    // @mago-expect lint:no-global -- $wpdb is WordPress' database handle.
    global $wpdb;
    /** @var wpdb $wpdb */
    $cron = function_exists('_get_cron_array') ? _get_cron_array() : [];
    $now = time();
    $overdue = 0;
    foreach (array_keys($cron) as $timestamp) {
        if ((int) $timestamp < ($now - 300)) {
            ++$overdue;
        }
    }

    return [
        'generated_at' => gmdate('c'),
        'site' => [
            'url' => home_url('/'),
            'environment_type' => function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'unknown',
            'multisite' => is_multisite(),
            'https' => is_ssl() || str_starts_with(home_url('/'), 'https://'),
        ],
        'runtime' => [
            'wordpress' => get_bloginfo('version'),
            'php' => PHP_VERSION,
            'database_server' => method_exists($wpdb, 'db_server_info') ? $wpdb->db_server_info() : $wpdb->db_version(),
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => (int) ini_get('max_execution_time'),
        ],
        'cache' => [
            'persistent_object_cache' => wp_using_ext_object_cache(),
            'page_cache_constant' => defined('WP_CACHE') && WP_CACHE === true,
        ],
        'cron' => [
            'disabled' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON === true,
            'scheduled_timestamps' => count($cron),
            'overdue_timestamps' => $overdue,
        ],
        'elementor-mcp' => [
            'version' => defined('ELEMENTOR_MCP_VERSION') ? ELEMENTOR_MCP_VERSION : null,
            // The real function names. The previous probes named functions
            // that have never existed anywhere in this plugin, and the
            // function_exists guards meant nobody noticed: every site reported
            // enabled=false and profile=unknown while everything worked.
            'enabled' => function_exists('elementor_mcp_is_enabled') && elementor_mcp_is_enabled(),
            'safety_profile' => function_exists('elementor_mcp_get_safety_profile')
                ? elementor_mcp_get_safety_profile()
                : 'unknown',
            'change_records' => function_exists('elementor_mcp_get_change_log') ? count(elementor_mcp_get_change_log()) : 0,
        ],
    ];
}

/** @return array<string, mixed> */
function elementor_mcp_performance_audit(): array
{
    $autoloaded_options = wp_load_alloptions();
    $autoload_bytes = 0;
    // @mago-expect analysis:mixed-assignment -- Autoloaded option values can contain any serializable type.
    foreach ($autoloaded_options as $value) {
        $autoload_bytes += strlen(is_string($value) ? $value : serialize($value));
    }
    $autoload_count = count($autoloaded_options);

    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    // @mago-expect analysis:mixed-assignment -- WordPress option values are normalized immediately below.
    $active_plugins_option = get_option('active_plugins', []);
    $active_plugins = is_array($active_plugins_option) ? $active_plugins_option : [];
    $all_plugins = get_plugins();
    $autoload_status = 'pass';
    if ($autoload_bytes > 1_500_000) {
        $autoload_status = 'warning';
    } elseif ($autoload_bytes > 800_000) {
        $autoload_status = 'recommendation';
    }
    /** @var list<array<string, mixed>> $checks */
    $checks = [
        elementor_mcp_diagnostic_check(
            'persistent_object_cache',
            wp_using_ext_object_cache() ? 'pass' : 'recommendation',
            wp_using_ext_object_cache()
                ? 'A persistent object cache is active.'
                : 'No persistent object cache was detected.',
        ),
        elementor_mcp_diagnostic_check(
            'autoloaded_options',
            $autoload_status,
            sprintf('%d autoloaded options use %s.', $autoload_count, size_format($autoload_bytes, decimals: 1)),
            ['count' => $autoload_count, 'bytes' => $autoload_bytes],
        ),
        elementor_mcp_diagnostic_check(
            'active_plugins',
            count($active_plugins) > 50 ? 'recommendation' : 'pass',
            sprintf('%d of %d installed plugins are active.', count($active_plugins), count($all_plugins)),
        ),
        elementor_mcp_diagnostic_check(
            'php_memory_limit',
            wp_convert_hr_to_bytes((string) ini_get('memory_limit')) < 268_435_456 ? 'recommendation' : 'pass',
            sprintf('PHP memory_limit is %s.', (string) ini_get('memory_limit')),
        ),
    ];

    return [
        'status' => elementor_mcp_diagnostic_overall_status($checks),
        'generated_at' => gmdate('c'),
        'scope' => 'Bounded server configuration audit; no browser Core Web Vitals or load test was run.',
        'checks' => $checks,
    ];
}

/** @return array<string, mixed> */
// @mago-expect lint:cyclomatic-complexity -- One bounded report intentionally assembles independent checks.
function elementor_mcp_security_audit(): array
{
    require_once ABSPATH . 'wp-admin/includes/update.php';
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    // @mago-expect analysis:mixed-assignment -- WordPress transients are shape-checked before access.
    $plugin_updates = get_site_transient('update_plugins');
    // @mago-expect analysis:mixed-assignment -- WordPress transients are shape-checked before access.
    $theme_updates = get_site_transient('update_themes');
    // @mago-expect analysis:mixed-assignment -- WordPress transients are shape-checked before access.
    $core_updates = get_site_transient('update_core');
    $plugin_count = is_object($plugin_updates) && is_array($plugin_updates->response ?? null)
        ? count($plugin_updates->response)
        : 0;
    $theme_count = is_object($theme_updates) && is_array($theme_updates->response ?? null)
        ? count($theme_updates->response)
        : 0;
    $core_count = 0;
    if (is_object($core_updates) && is_array($core_updates->updates ?? null)) {
        /** @var mixed $update */
        foreach ($core_updates->updates as $update) {
            if (is_object($update) && ($update->response ?? '') === 'upgrade') {
                ++$core_count;
            }
        }
    }
    $admin_named_users = get_users([
        'login__in' => ['admin', 'administrator'],
        'role' => 'administrator',
        'fields' => 'ids',
        'number' => 3,
    ]);
    $updates_total = $core_count + $plugin_count + $theme_count;
    $home_uses_https = str_starts_with(home_url('/'), 'https://');
    $file_editor_disabled = defined('DISALLOW_FILE_EDIT') && DISALLOW_FILE_EDIT === true;
    $debug_display = defined('WP_DEBUG_DISPLAY') && WP_DEBUG_DISPLAY === true;
    $safety_profile = function_exists('elementor_mcp_get_safety_profile') ? elementor_mcp_get_safety_profile() : 'unknown';
    /** @var list<array<string, mixed>> $checks */
    $checks = [
        elementor_mcp_diagnostic_check(
            'https',
            $home_uses_https ? 'pass' : 'warning',
            $home_uses_https ? 'The public home URL uses HTTPS.' : 'The public home URL does not use HTTPS.',
        ),
        elementor_mcp_diagnostic_check(
            'file_editor',
            $file_editor_disabled ? 'pass' : 'recommendation',
            $file_editor_disabled
                ? 'The built-in plugin and theme file editors are disabled.'
                : 'The built-in plugin and theme file editors are not explicitly disabled.',
        ),
        elementor_mcp_diagnostic_check(
            'debug_display',
            !$debug_display ? 'pass' : 'warning',
            $debug_display
                ? 'WP_DEBUG_DISPLAY is enabled and may expose runtime details.'
                : 'WP_DEBUG_DISPLAY is not enabled.',
        ),
        elementor_mcp_diagnostic_check(
            'updates',
            $updates_total > 0 ? 'warning' : 'pass',
            $updates_total > 0
                ? sprintf(
                    '%d core, %d plugin, and %d theme updates are recorded as outstanding.',
                    $core_count,
                    $plugin_count,
                    $theme_count,
                )
                : 'No outstanding core, plugin, or theme updates are recorded in current transients.',
            ['core' => $core_count, 'plugins' => $plugin_count, 'themes' => $theme_count],
        ),
        elementor_mcp_diagnostic_check(
            'default_admin_login',
            $admin_named_users === [] ? 'pass' : 'recommendation',
            $admin_named_users === []
                ? 'No administrator account uses the common admin or administrator login.'
                : 'An administrator account uses a commonly targeted login name.',
        ),
        elementor_mcp_diagnostic_check(
            'elementor_mcp_safety_profile',
            $safety_profile === 'production' ? 'pass' : 'recommendation',
            sprintf('Elementor MCP safety profile is %s.', $safety_profile),
        ),
    ];

    return [
        'status' => elementor_mcp_diagnostic_overall_status($checks),
        'generated_at' => gmdate('c'),
        'scope' => 'Configuration and update posture only. This does not prove the site is secure and is not a malware, dependency-vulnerability, penetration, or external-header scan.',
        'checks' => $checks,
    ];
}

/** @param array<string, mixed> $details @return array<string, mixed> */
function elementor_mcp_diagnostic_check(string $id, string $status, string $message, array $details = []): array
{
    return ['id' => $id, 'status' => $status, 'message' => $message, 'details' => $details];
}

/** @param list<array<string, mixed>> $checks */
function elementor_mcp_diagnostic_overall_status(array $checks): string
{
    $statuses = array_map(static fn(array $check): string => (string) ($check['status'] ?? 'warning'), $checks);
    if (in_array('warning', $statuses, strict: true)) {
        return 'attention_required';
    }
    if (in_array('recommendation', $statuses, strict: true)) {
        return 'recommendations_available';
    }
    return 'healthy_within_scope';
}

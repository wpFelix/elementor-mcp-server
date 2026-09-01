<?php

// SPDX-FileCopyrightText: 2026 Elementor MCP <dev@elementormcp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

// phpcs:disable WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Every state-changing request on this screen verifies a nonce via check_admin_referer() before acting; the sniff cannot trace that across function boundaries. Reads are type-checked, whitelist-compared, and escaped on output.

/**
 * The Dashboard: what Elementor MCP is currently exposing, and who is using it.
 *
 * Until now the answer to "is anything actually connected?" was spread across
 * Configuration, Troubleshoot, and Connected Apps, and partly not shown at all.
 * This screen answers it in one place from live data only — every number here
 * is read at render time, and nothing is cached or estimated.
 */

if (!defined('ABSPATH')) {
    exit();
}

const ELEMENTOR_MCP_DASHBOARD_PAGE = 'elementor-mcp-dashboard';

const ELEMENTOR_MCP_FORGET_CLIENT_NONCE = 'elementor_mcp_forget_client';

/**
 * Handle "Forget" on a client card, and the sweep of revoked credentials.
 *
 * Forgetting deletes rows and nothing else. A connection row is a record that
 * some client introduced itself once; there is no live state attached, and if
 * that client connects again it records itself afresh on the next handshake.
 * So this is not "disconnect" — a card with a live credential behind it will
 * reappear the moment the client calls again, which is the honest behaviour:
 * the way to stop a client is to revoke its credential, not to hide its row.
 *
 * @return array{type: string, message: string}|null
 */
function elementor_mcp_dashboard_handle_forget(): ?array
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return null;
    }

    $sweep = array_key_exists('elementor_mcp_forget_stale', $_POST);
    $raw = is_string($_POST['elementor_mcp_forget_client'] ?? null) ? $_POST['elementor_mcp_forget_client'] : '';
    if (!$sweep && $raw === '') {
        return null;
    }

    check_admin_referer(ELEMENTOR_MCP_FORGET_CLIENT_NONCE);

    if (!elementor_mcp_current_user_can_manage()) {
        wp_die(esc_html__('You are not allowed to manage Elementor MCP connections.', domain: 'elementor-mcp'));
    }

    if ($sweep) {
        $removed = elementor_mcp_forget_stale_connections();

        return [
            'type' => $removed > 0 ? 'success' : 'info',
            'message' => $removed > 0
                ? sprintf(
                    /* translators: %d: number of connection records removed */
                    _n(
                        single: 'Removed %d connection whose credential no longer exists.',
                        plural: 'Removed %d connections whose credentials no longer exist.',
                        number: $removed,
                        domain: 'elementor-mcp',
                    ),
                    $removed,
                )
                : __('Every listed connection still has a working credential.', domain: 'elementor-mcp'),
        ];
    }

    $removed = 0;
    foreach (explode(',', $raw) as $id) {
        $id = (int) trim($id);
        if ($id > 0) {
            $removed += elementor_mcp_forget_connection($id);
        }
    }

    return [
        'type' => $removed > 0 ? 'success' : 'error',
        'message' => $removed > 0
            ? __(
                'Forgotten. If that client connects again it will reappear — revoke its credential to keep it out.',
                domain: 'elementor-mcp',
            )
            : __('That connection was already gone.', domain: 'elementor-mcp'),
    ];
}

/**
 * Registered OAuth clients, newest first.
 *
 * @return list<array<string, mixed>>
 */
function elementor_mcp_dashboard_oauth_clients(int $limit = 25): array
{
    if (!function_exists('ElementorMCP\\OAuth\\ClientValidation\\client_table_exists')) {
        return [];
    }
    if (!\ElementorMCP\OAuth\ClientValidation\client_table_exists()) {
        return [];
    }

    // @mago-expect lint:no-global -- $wpdb is WordPress' database handle.
    global $wpdb;
    /** @var \wpdb $wpdb */
    $table = $wpdb->prefix . 'elementor_mcp_oauth_clients';

    // @mago-expect analysis:possibly-invalid-argument
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Values are bound by the inline $wpdb->prepare(). The only interpolation is the table name, which prepare() has no placeholder for. Not cached: this reads live per-request state.
    $rows = $wpdb->get_results(
        // @mago-expect analysis:possibly-invalid-argument
        $wpdb->prepare("SELECT client_id, client_name, created_at, last_used_at, admin_created
             FROM `{$table}` ORDER BY (last_used_at IS NULL), last_used_at DESC, created_at DESC LIMIT %d", $limit),
        ARRAY_A,
    );

    if (!is_array($rows)) {
        return [];
    }

    $clients = [];
    /** @var mixed $row */
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        /** @var array<string, mixed> $client */
        $client = $row;
        $clients[] = $client;
    }

    return $clients;
}

/**
 * What Elementor MCP Pro reports about itself, or null when it is not answering.
 *
 * Published by Pro on a filter rather than fetched by calling into it, so this
 * screen has no dependency on Pro being installed, on its version, or on its
 * function names. An unlicensed Pro never registers the filter, so "installed
 * but locked" and "not installed" both read as null here — which is correct,
 * because in both cases the customer is getting nothing from it.
 *
 * @return array{
 *     active_integrations: list<string>,
 *     inactive_integrations: list<string>,
 *     total_integrations: int,
 *     abilities: int,
 *     version: string
 * }|null
 */
function elementor_mcp_dashboard_pro_status(): ?array
{
    /** @var mixed $status */
    $status = apply_filters('elementor_mcp_pro_status', value: null);
    if (!is_array($status)) {
        return null;
    }

    $list = static function (mixed $value): array {
        if (!is_array($value)) {
            return [];
        }
        $clean = [];
        /** @var mixed $item */
        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $clean[] = $item;
            }
        }

        return $clean;
    };

    return [
        'active_integrations' => $list($status['active_integrations'] ?? null),
        'inactive_integrations' => $list($status['inactive_integrations'] ?? null),
        'total_integrations' => (int) ($status['total_integrations'] ?? 0),
        'abilities' => (int) ($status['abilities'] ?? 0),
        'version' => is_string($status['version'] ?? null) ? $status['version'] : '',
    ];
}

/**
 * When each transport last carried a real MCP request.
 *
 * Recorded by includes/troubleshoot/bootstrap.php, at most once a minute per
 * method, so this is a liveness signal rather than a request counter.
 *
 * @return array{oauth: ?int, token: ?int, password: ?int}
 */
function elementor_mcp_dashboard_last_seen(): array
{
    /** @var mixed $stored */
    $stored = get_option('elementor_mcp_mcp_last_request', default_value: []);
    if (!is_array($stored)) {
        $stored = [];
    }

    $read = static function (mixed $value): ?int {
        return is_int($value) && $value > 0 ? $value : null;
    };

    return [
        'oauth' => $read($stored['oauth'] ?? null),
        'token' => $read($stored['token'] ?? null),
        'password' => $read($stored['password'] ?? null),
    ];
}

/**
 * How many abilities are exposed right now, grouped by category.
 *
 * @return array{total: int, by_category: array<string, int>}
 */
function elementor_mcp_dashboard_exposure(): array
{
    if (!function_exists('wp_get_abilities')) {
        return ['total' => 0, 'by_category' => []];
    }

    $by_category = [];
    $total = 0;
    /** @var mixed $ability */
    foreach (wp_get_abilities() as $ability) {
        if (!$ability instanceof WP_Ability) {
            continue;
        }
        $total++;
        $category = $ability->get_category();
        $by_category[$category] = ($by_category[$category] ?? 0) + 1;
    }

    arsort($by_category);

    return ['total' => $total, 'by_category' => $by_category];
}

/**
 * Absolute date for a timestamp, or an em dash. wp_date() returns false on a
 * bad timezone, so the failure is shown as "unknown" rather than an empty cell.
 */
function elementor_mcp_dashboard_date(mixed $timestamp, string $format): string
{
    if (!is_int($timestamp)) {
        return '—';
    }

    $formatted = wp_date($format, $timestamp);

    return $formatted === false ? __('unknown', domain: 'elementor-mcp') : $formatted;
}

/**
 * Relative age of a timestamp, or an em dash when there is none.
 */
function elementor_mcp_dashboard_ago(?int $timestamp): string
{
    if ($timestamp === null) {
        return '—';
    }

    return sprintf(
        /* translators: %s: human-readable time difference, e.g. "5 mins" */
        __('%s ago', domain: 'elementor-mcp'),
        human_time_diff($timestamp, current_time('timestamp')),
    );
}

/**
 * A truthful operational snapshot for the Overview command centre.
 *
 * Every value comes from a registry or ledger Elementor MCP already owns. No
 * analytics estimate is presented as a measurement, and request totals remain
 * explicitly lifetime observations because the connection table has no daily
 * buckets.
 *
 * @return array{
 *     armed: bool,
 *     elementor_ready: bool,
 *     elementor_version: string,
 *     oauth_ready: bool,
 *     abilities: int,
 *     elementor_abilities: int,
 *     clients: int,
 *     reachable_clients: int,
 *     requests: int,
 *     changes: list<array<string, mixed>>,
 *     elementor_pages: int,
 *     pending_reviews: int,
 *     skills: int,
 *     designs: int,
 *     active_design: string,
 *     last_activity: ?int
 * }
 */
function elementor_mcp_dashboard_snapshot(): array
{
    $exposure = elementor_mcp_dashboard_exposure();
    $activity = elementor_mcp_dashboard_client_activity();
    $requests = 0;
    $reachable_clients = 0;
    $last_activity = null;

    foreach ($activity as $client) {
        $requests += (int) ($client['requests'] ?? 0);
        if (($client['reachable'] ?? false) === true) {
            $reachable_clients++;
        }

        $last_seen = trim((string) ($client['last_seen'] ?? ''));
        if ($last_seen !== '') {
            $timestamp = strtotime($last_seen . ' UTC');
            if (is_int($timestamp) && ($last_activity === null || $timestamp > $last_activity)) {
                $last_activity = $timestamp;
            }
        }
    }

    foreach (elementor_mcp_dashboard_last_seen() as $timestamp) {
        if (is_int($timestamp) && ($last_activity === null || $timestamp > $last_activity)) {
            $last_activity = $timestamp;
        }
    }

    $changes = function_exists('elementor_mcp_get_change_log') ? elementor_mcp_get_change_log() : [];
    $previews = function_exists('ElementorMCP\\Preview\\Store\\all')
        ? \ElementorMCP\Preview\Store\all()
        : [];
    $pending_reviews = count(array_filter(
        is_array($previews) ? $previews : [],
        static fn(mixed $preview): bool => is_array($preview) && ($preview['status'] ?? '') === 'pending',
    ));
    $skills = function_exists('ElementorMCP\\Skills\\Sources\\discoverable')
        ? count(\ElementorMCP\Skills\Sources\discoverable('agentic'))
        : 0;
    $designs = function_exists('ElementorMCP\\Design\\Store\\all_user')
        ? count(\ElementorMCP\Design\Store\all_user())
        : 0;
    /** @var mixed $active_design_option */
    $active_design_option = get_option('elementor_mcp_active_design', default_value: '');

    return [
        'armed' => elementor_mcp_is_enabled() && elementor_mcp_get_mcp_dependency_error() === null,
        'elementor_ready' => defined('ELEMENTOR_VERSION') || did_action('elementor/loaded') > 0,
        'elementor_version' => defined('ELEMENTOR_VERSION') ? (string) constant('ELEMENTOR_VERSION') : '',
        'oauth_ready' => function_exists('ElementorMCP\\OAuth\\Endpoints\\Discovery\\protected_resource_metadata_url'),
        'abilities' => $exposure['total'],
        'elementor_abilities' => (int) ($exposure['by_category']['elementor'] ?? 0),
        'clients' => count($activity),
        'reachable_clients' => $reachable_clients,
        'requests' => $requests,
        'changes' => $changes,
        'elementor_pages' => elementor_mcp_dashboard_elementor_pages(),
        'pending_reviews' => $pending_reviews,
        'skills' => $skills,
        'designs' => $designs,
        'active_design' => is_string($active_design_option) ? $active_design_option : '',
        'last_activity' => $last_activity,
    ];
}

/**
 * Published content currently edited with Elementor.
 */
function elementor_mcp_dashboard_elementor_pages(): int
{
    // @mago-expect lint:no-global -- $wpdb is WordPress' database handle.
    global $wpdb;
    /** @var \wpdb $wpdb */
    $posts = $wpdb->posts;
    $postmeta = $wpdb->postmeta;

    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Core table names are trusted; values are bound. This is a live dashboard count.
    $count = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(DISTINCT p.ID)
        FROM `{$posts}` p
        INNER JOIN `{$postmeta}` pm ON pm.post_id = p.ID
        WHERE pm.meta_key = %s
          AND pm.meta_value = %s
          AND p.post_status NOT IN ('trash', 'auto-draft')",
        '_elementor_edit_mode',
        'builder',
    ));

    return is_numeric($count) ? (int) $count : 0;
}

/**
 * The Dashboard is intentionally summary-only. Setup and credentials live on
 * Connections; site-wide switches live on Preferences.
 */
function elementor_mcp_render_dashboard_page(): void
{
    if (!elementor_mcp_current_user_can_manage()) {
        return;
    }

    elementor_mcp_render_admin_header();
    ?>
    <div class="wrap">
        <h1><?php echo esc_html(elementor_mcp_nav_label('elementor-mcp-connect')); ?></h1>
        <p class="elementor-mcp-lede"><?php esc_html_e(
            'Live Elementor automation health, usage and recent activity from this site.',
            domain: 'elementor-mcp',
        ); ?></p>
        <?php elementor_mcp_dashboard_command_center(); ?>
    </div>
    <?php
}

/**
 * High-value operational summary. Detailed connection, client and ability
 * tables live on Connections; each metric links to its source screen.
 */
function elementor_mcp_dashboard_command_center(): void
{
    $snapshot = elementor_mcp_dashboard_snapshot();
    $recent_changes = array_slice(array_reverse($snapshot['changes']), offset: 0, length: 5);
    $state_label = $snapshot['armed']
        ? __('Automation online', domain: 'elementor-mcp')
        : __('Automation paused', domain: 'elementor-mcp');
    $state_note = match (true) {
        !$snapshot['elementor_ready'] => __('Elementor is not active, so page-building tools cannot run.', domain: 'elementor-mcp'),
        $snapshot['armed'] => __('Authenticated clients can use the abilities exposed by this site.', domain: 'elementor-mcp'),
        default => __('Turn on AI abilities when you are ready to accept authenticated requests.', domain: 'elementor-mcp'),
    };
    ?>
    <section class="elementor-mcp-command-center" aria-labelledby="elementor-mcp-command-title">
        <div class="elementor-mcp-command-center__intro">
            <span class="elementor-mcp-eyebrow"><?php esc_html_e('Site operations', domain: 'elementor-mcp'); ?></span>
            <h2 id="elementor-mcp-command-title"><?php esc_html_e(
                'Elementor automation at a glance',
                domain: 'elementor-mcp',
            ); ?></h2>
            <p><?php esc_html_e(
                'Live capability, client activity and governed changes—measured from this WordPress site.',
                domain: 'elementor-mcp',
            ); ?></p>
            <div class="elementor-mcp-command-center__actions">
                <a class="button button-primary" href="<?php echo esc_url(admin_url(
                    'admin.php?page=elementor-mcp-connections',
                )); ?>"><?php esc_html_e(
                    'Manage connections',
                    domain: 'elementor-mcp',
                ); ?></a>
                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=elementor-mcp-chat')); ?>"><?php
                    esc_html_e('Open Chat', domain: 'elementor-mcp');
                ?></a>
            </div>
        </div>

        <div class="elementor-mcp-command-state <?php echo $snapshot['armed'] ? 'is-live' : 'is-paused'; ?>">
            <span class="elementor-mcp-command-state__dot" aria-hidden="true"></span>
            <span class="elementor-mcp-command-state__label"><?php echo esc_html($state_label); ?></span>
            <strong><?php echo esc_html($state_note); ?></strong>
            <span><?php
                printf(
                    /* translators: %s: relative timestamp or an em dash */
                    esc_html__('Last authenticated request: %s', domain: 'elementor-mcp'),
                    esc_html(elementor_mcp_dashboard_ago($snapshot['last_activity'])),
                );
            ?></span>
        </div>
    </section>

    <div class="elementor-mcp-metric-grid" aria-label="<?php esc_attr_e('Elementor MCP statistics', domain: 'elementor-mcp'); ?>">
        <?php
        elementor_mcp_dashboard_metric(
            __('Elementor tools', domain: 'elementor-mcp'),
            number_format_i18n($snapshot['elementor_abilities']),
            __('available now', domain: 'elementor-mcp'),
            admin_url('admin.php?page=elementor-mcp-abilities'),
            'brand',
        );
        elementor_mcp_dashboard_metric(
            __('Connected clients', domain: 'elementor-mcp'),
            number_format_i18n($snapshot['reachable_clients']),
            sprintf(
                /* translators: %d: all clients observed, including ones with revoked credentials */
                _n('%d observed', '%d observed', $snapshot['clients'], domain: 'elementor-mcp'),
                $snapshot['clients'],
            ),
            admin_url('admin.php?page=elementor-mcp-connections#elementor-mcp-clients'),
            $snapshot['reachable_clients'] > 0 ? 'success' : 'neutral',
        );
        elementor_mcp_dashboard_metric(
            __('Requests observed', domain: 'elementor-mcp'),
            number_format_i18n($snapshot['requests']),
            __('lifetime total', domain: 'elementor-mcp'),
            admin_url('admin.php?page=elementor-mcp-connections#elementor-mcp-clients'),
        );
        elementor_mcp_dashboard_metric(
            __('Governed changes', domain: 'elementor-mcp'),
            number_format_i18n(count($snapshot['changes'])),
            __('retained in the ledger', domain: 'elementor-mcp'),
            '#elementor-mcp-recent-activity',
        );
        elementor_mcp_dashboard_metric(
            __('Elementor pages', domain: 'elementor-mcp'),
            number_format_i18n($snapshot['elementor_pages']),
            __('using Elementor builder data', domain: 'elementor-mcp'),
            admin_url('edit.php?post_type=page'),
        );
        elementor_mcp_dashboard_metric(
            __('Pending reviews', domain: 'elementor-mcp'),
            number_format_i18n($snapshot['pending_reviews']),
            __('changes waiting for approval', domain: 'elementor-mcp'),
            admin_url('admin.php?page=elementor-mcp-preview'),
            $snapshot['pending_reviews'] > 0 ? 'warning' : 'neutral',
        );
        ?>
    </div>

    <div class="elementor-mcp-overview-grid">
        <section class="elementor-mcp-dashboard-card" aria-labelledby="elementor-mcp-health-title">
            <div class="elementor-mcp-dashboard-card__head">
                <div>
                    <span class="elementor-mcp-eyebrow"><?php esc_html_e('Readiness', domain: 'elementor-mcp'); ?></span>
                    <h2 id="elementor-mcp-health-title"><?php esc_html_e('System health', domain: 'elementor-mcp'); ?></h2>
                </div>
                <a href="<?php echo esc_url(admin_url('admin.php?page=elementor-mcp-troubleshoot')); ?>"><?php
                    esc_html_e('Run diagnostics', domain: 'elementor-mcp');
                ?></a>
            </div>
            <ul class="elementor-mcp-health-list">
                <?php
                elementor_mcp_dashboard_health_item(
                    __('MCP server', domain: 'elementor-mcp'),
                    $snapshot['armed'] ? __('Online', domain: 'elementor-mcp') : __('Paused', domain: 'elementor-mcp'),
                    $snapshot['armed'] ? 'success' : 'warning',
                );
                elementor_mcp_dashboard_health_item(
                    __('Elementor runtime', domain: 'elementor-mcp'),
                    $snapshot['elementor_ready']
                        ? sprintf(__('Ready · v%s', domain: 'elementor-mcp'), $snapshot['elementor_version'])
                        : __('Not detected', domain: 'elementor-mcp'),
                    $snapshot['elementor_ready'] ? 'success' : 'danger',
                );
                elementor_mcp_dashboard_health_item(
                    __('Secure OAuth', domain: 'elementor-mcp'),
                    $snapshot['oauth_ready'] ? __('Available', domain: 'elementor-mcp') : __('Unavailable', domain: 'elementor-mcp'),
                    $snapshot['oauth_ready'] ? 'success' : 'warning',
                );
                elementor_mcp_dashboard_health_item(
                    __('Design governance', domain: 'elementor-mcp'),
                    $snapshot['active_design'] !== ''
                        ? sprintf(__('Active · %s', domain: 'elementor-mcp'), $snapshot['active_design'])
                        : __('Optional · not configured', domain: 'elementor-mcp'),
                    $snapshot['active_design'] !== '' ? 'success' : 'neutral',
                );
                ?>
            </ul>
        </section>

        <section id="elementor-mcp-recent-activity" class="elementor-mcp-dashboard-card" aria-labelledby="elementor-mcp-activity-title">
            <div class="elementor-mcp-dashboard-card__head">
                <div>
                    <span class="elementor-mcp-eyebrow"><?php esc_html_e('Change ledger', domain: 'elementor-mcp'); ?></span>
                    <h2 id="elementor-mcp-activity-title"><?php esc_html_e('Recent agent activity', domain: 'elementor-mcp'); ?></h2>
                </div>
                <span class="elementor-mcp-dashboard-card__count"><?php echo esc_html(
                    number_format_i18n(count($snapshot['changes'])),
                ); ?></span>
            </div>
            <?php if ($recent_changes === []) { ?>
                <div class="elementor-mcp-empty-compact">
                    <strong><?php esc_html_e('No governed changes yet', domain: 'elementor-mcp'); ?></strong>
                    <span><?php esc_html_e(
                        'Writes made through Elementor MCP will appear here with actor, risk and rollback evidence.',
                        domain: 'elementor-mcp',
                    ); ?></span>
                </div>
            <?php } else { ?>
                <ol class="elementor-mcp-activity-list">
                    <?php foreach ($recent_changes as $change) {
                        $ability = str_replace('elementor-mcp/', '', (string) ($change['ability'] ?? 'unknown'));
                        $recorded = strtotime((string) ($change['recorded_at'] ?? ''));
                        $risk = (string) ($change['risk'] ?? 'write');
                        ?>
                        <li>
                            <span class="elementor-mcp-activity-list__marker" aria-hidden="true"></span>
                            <span class="elementor-mcp-activity-list__body">
                                <code><?php echo esc_html($ability); ?></code>
                                <span><?php echo esc_html(elementor_mcp_dashboard_ago(
                                    is_int($recorded) && $recorded > 0 ? $recorded : null,
                                )); ?></span>
                            </span>
                            <span class="elementor-mcp-risk-badge is-<?php echo esc_attr(sanitize_html_class($risk)); ?>"><?php
                                echo esc_html($risk);
                            ?></span>
                        </li>
                    <?php } ?>
                </ol>
            <?php } ?>
        </section>
    </div>

    <nav class="elementor-mcp-quick-actions" aria-label="<?php esc_attr_e('Quick actions', domain: 'elementor-mcp'); ?>">
        <span class="elementor-mcp-quick-actions__label"><?php esc_html_e('Quick actions', domain: 'elementor-mcp'); ?></span>
        <a href="<?php echo esc_url(admin_url('admin.php?page=elementor-mcp-connections')); ?>"><?php esc_html_e('Connections', domain: 'elementor-mcp'); ?></a>
        <a href="<?php echo esc_url(admin_url('admin.php?page=elementor-mcp-abilities')); ?>"><?php esc_html_e('Manage tools', domain: 'elementor-mcp'); ?></a>
        <a href="<?php echo esc_url(admin_url('admin.php?page=elementor-mcp-design')); ?>"><?php esc_html_e('Open Design', domain: 'elementor-mcp'); ?></a>
        <a href="<?php echo esc_url(admin_url('admin.php?page=elementor-mcp-context')); ?>"><?php esc_html_e('Edit instructions', domain: 'elementor-mcp'); ?></a>
        <a href="<?php echo esc_url(admin_url('admin.php?page=elementor-mcp-settings')); ?>"><?php esc_html_e('Review settings', domain: 'elementor-mcp'); ?></a>
        <a href="<?php echo esc_url(admin_url('edit.php?post_type=page')); ?>"><?php esc_html_e('View pages', domain: 'elementor-mcp'); ?></a>
    </nav>
    <?php
}

/**
 * One linked dashboard metric.
 */
function elementor_mcp_dashboard_metric(
    string $label,
    string $value,
    string $note,
    string $href,
    string $tone = 'default',
): void {
    ?>
    <a class="elementor-mcp-metric is-<?php echo esc_attr(sanitize_html_class($tone)); ?>" href="<?php echo esc_url($href); ?>">
        <span class="elementor-mcp-metric__label"><?php echo esc_html($label); ?></span>
        <strong><?php echo esc_html($value); ?></strong>
        <span class="elementor-mcp-metric__note"><?php echo esc_html($note); ?></span>
    </a>
    <?php
}

/**
 * One labelled health signal. Text carries the state; colour is supplemental.
 */
function elementor_mcp_dashboard_health_item(string $label, string $value, string $state): void
{
    ?>
    <li>
        <span class="elementor-mcp-health-list__name"><?php echo esc_html($label); ?></span>
        <span class="elementor-mcp-health-list__value is-<?php echo esc_attr(sanitize_html_class($state)); ?>">
            <span class="elementor-mcp-health-list__dot" aria-hidden="true"></span>
            <?php echo esc_html($value); ?>
        </span>
    </li>
    <?php
}

/**
 * Detailed connection state: what is exposed, who is using it, endpoints and
 * licensed Pro contribution. The summary Dashboard deliberately does not call
 * this; Connections does.
 */
function elementor_mcp_render_dashboard_sections(): void
{
    $notice = elementor_mcp_dashboard_handle_forget();
    if ($notice !== null) {
        printf(
            '<div class="notice notice-%s"><p>%s</p></div>',
            esc_attr($notice['type']),
            esc_html($notice['message']),
        );
    }

    elementor_mcp_dashboard_connection();
    elementor_mcp_dashboard_clients();
    elementor_mcp_dashboard_pro();
    elementor_mcp_dashboard_reach();
}

/**
 * Connection: whether anything is live, under which profile, and when a
 * client last used each transport.
 */
function elementor_mcp_dashboard_connection(): void
{
    $armed = elementor_mcp_is_enabled() && elementor_mcp_get_mcp_dependency_error() === null;
    $exposure = elementor_mcp_dashboard_exposure();
    $last_seen = elementor_mcp_dashboard_last_seen();
    $clients = elementor_mcp_dashboard_oauth_clients();
    $profiles = elementor_mcp_safety_profiles();
    $profile = elementor_mcp_get_safety_profile();
    ?>
        <section id="elementor-mcp-connection" class="elementor-mcp-panel <?php echo $armed ? 'is-armed' : ''; ?>">
            <h2 class="elementor-mcp-setting-group__title"><?php esc_html_e('Connection', domain: 'elementor-mcp'); ?></h2>
            <div class="elementor-mcp-stats">
                <?php

                elementor_mcp_dashboard_stat(
                    __('Status', domain: 'elementor-mcp'),
                    $armed ? __('Live', domain: 'elementor-mcp') : __('Off', domain: 'elementor-mcp'),
                    $armed ? 'armed' : 'idle',
                );
                elementor_mcp_dashboard_stat(
                    __('Safety profile', domain: 'elementor-mcp'),
                    (string) ($profiles[$profile]['label'] ?? $profile),
                );
                elementor_mcp_dashboard_stat(__('Abilities exposed', domain: 'elementor-mcp'), (string) $exposure['total']);
                elementor_mcp_dashboard_stat(
                    __('AI clients connected', domain: 'elementor-mcp'),
                    (string) count(elementor_mcp_dashboard_client_activity()),
                );
                elementor_mcp_dashboard_stat(__('Registered OAuth clients', domain: 'elementor-mcp'), (string) count($clients));
                ?>
            </div>

            <?php elementor_mcp_dashboard_access_tokens(); ?>

            <?php elementor_mcp_dashboard_endpoints(); ?>

            <p class="elementor-mcp-legend"><?php esc_html_e('Last request seen', domain: 'elementor-mcp'); ?></p>
            <table class="widefat">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Transport', domain: 'elementor-mcp'); ?></th>
                        <th><?php esc_html_e('Last used', domain: 'elementor-mcp'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><?php esc_html_e('Application password', domain: 'elementor-mcp'); ?></td>
                        <td class="elementor-mcp-mono"><?php echo
                            esc_html(elementor_mcp_dashboard_ago($last_seen['password']))
                        ; ?></td>
                    </tr>
                    <tr>
                        <td><?php esc_html_e('OAuth', domain: 'elementor-mcp'); ?></td>
                        <td class="elementor-mcp-mono"><?php echo
                            esc_html(elementor_mcp_dashboard_ago($last_seen['oauth']))
                        ; ?></td>
                    </tr>
                    <tr>
                        <td><?php esc_html_e('Access token', domain: 'elementor-mcp'); ?></td>
                        <td class="elementor-mcp-mono"><?php echo
                            esc_html(elementor_mcp_dashboard_ago($last_seen['token']))
                        ; ?></td>
                    </tr>
                </tbody>
            </table>
            <p class="description"><?php esc_html_e(
                'Recorded at most once a minute per transport, so this shows whether a client is active, not how many calls it made.',
                domain: 'elementor-mcp',
            ); ?></p>
        </section>

    <?php
}

/**
 * Access tokens: what they are for, what exists, and the way to make one.
 *
 * Sits between the connection stats and the endpoint table because that is the
 * order the questions arrive in — is anything live, how would something
 * authenticate, and what address does it call. The credential itself is managed
 * on the Connect screen; this is the pointer to it, because a method that only
 * appears three screens down the setup flow is a method nobody finds.
 */
function elementor_mcp_dashboard_access_tokens(): void
{
    $tokens = function_exists('elementor_mcp_tokens_for_user') ? elementor_mcp_tokens_for_user(get_current_user_id()) : [];
    $connect_url = admin_url('admin.php?page=elementor-mcp-connect#elementor-mcp-token-method');
    $dt_format = elementor_mcp_get_datetime_format('Y-m-d H:i');

    // Expired rows are still listed on the Connect screen so they can be cleared
    // deliberately, but counting them here would overstate what actually works.
    $live = 0;
    $last_used = null;
    foreach ($tokens as $token) {
        $expires = $token['expires'];
        if ($expires !== '' && (int) strtotime($expires . ' UTC') < time()) {
            continue;
        }
        $live++;
        $used = $token['last_used'] !== '' ? (int) strtotime($token['last_used'] . ' UTC') : 0;
        if ($used > 0 && ($last_used === null || $used > $last_used)) {
            $last_used = $used;
        }
    }
    ?>
        <p class="elementor-mcp-legend"><?php esc_html_e('Access tokens', domain: 'elementor-mcp'); ?></p>

        <p class="description" style="margin:0 0 10px;">
            <?php esc_html_e(
                'A long-lived bearer token, for callers that cannot sign in through a browser: the Claude Messages API MCP connector, the OpenAI Responses API, a cron job, an automation platform, or any client that takes a URL and one header. It authenticates on the same MCP address listed below.',
                domain: 'elementor-mcp',
            ); ?>
        </p>

        <?php if ($live === 0) { ?>
            <p style="margin:0 0 6px;">
                <a class="button" href="<?php echo esc_url($connect_url); ?>"><?php esc_html_e(
                    'Create an access token',
                    domain: 'elementor-mcp',
                ); ?></a>
            </p>
            <p class="description" style="margin:0;">
                <?php esc_html_e(
                    'None exist yet. Creating one shows the value once, then generates the configuration for your client with the token already in it.',
                    domain: 'elementor-mcp',
                ); ?>
            </p>
        <?php } else { ?>
            <table class="widefat">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Active tokens', domain: 'elementor-mcp'); ?></th>
                        <th><?php esc_html_e('Last used', domain: 'elementor-mcp'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><?php echo esc_html((string) $live); ?></td>
                        <td class="elementor-mcp-mono"><?php echo
                            esc_html(
                                $last_used === null
                                    ? __('Never', domain: 'elementor-mcp')
                                    : elementor_mcp_dashboard_date($last_used, $dt_format),
                            )
                        ; ?></td>
                    </tr>
                </tbody>
            </table>
            <p class="description" style="margin:6px 0 0;">
                <a href="<?php echo esc_url($connect_url); ?>"><?php esc_html_e(
                    'Create, inspect or revoke tokens, and get the configuration for your client',
                    domain: 'elementor-mcp',
                ); ?></a>
            </p>
        <?php } ?>
    <?php
}

/**
 * Roll the connection rows up into one entry per AI client.
 *
 * A client can hold several credentials — an application password on the laptop
 * and an OAuth grant on the desktop are two rows for one Claude Desktop — and
 * the question the screen answers is "is Claude Desktop connected", not "how
 * many rows does it have". Rows are keyed by the client the request identified
 * itself as, so the roll-up is by product rather than by credential.
 *
 * @return array<string, array{
 *     label: string,
 *     known: bool,
 *     versions: list<string>,
 *     methods: list<string>,
 *     users: list<string>,
 *     credentials: int,
 *     requests: int,
 *     first_seen: string,
 *     last_seen: string,
 *     ids: list<int>,
 *     reachable: bool
 * }>
 */
function elementor_mcp_dashboard_client_activity(): array
{
    /** @var array<string, array<string, mixed>> $activity */
    $activity = [];

    foreach (elementor_mcp_get_connections(limit: 200) as $connection) {
        $reported = (string) ($connection['client_name'] ?? '');
        $known = elementor_mcp_client_key($reported);
        $key = $known ?? ($reported !== '' ? 'raw:' . $reported : 'unidentified');

        $activity[$key] = elementor_mcp_dashboard_merge_connection(
            $activity[$key] ?? elementor_mcp_dashboard_blank_client($reported, $known),
            $connection,
        );
    }

    uasort($activity, static fn(array $a, array $b): int => strcmp((string) $b['last_seen'], (string) $a['last_seen']));

    /** @var array<string, array{label: string, known: bool, versions: list<string>, methods: list<string>, users: list<string>, credentials: int, requests: int, first_seen: string, last_seen: string, ids: list<int>, reachable: bool}> $activity */
    return $activity;
}

/**
 * Record which rows a card covers, and whether any of them can still connect.
 *
 * Split out of the merge so that stays readable: the roll-up is about display
 * columns, this is about identity and liveness.
 *
 * Reachable is an OR across the card's rows — one working credential is enough
 * for the client to come back, however many others were revoked.
 *
 * @param array<string, mixed> $entry
 * @param array<string, mixed> $connection
 * @return array<string, mixed>
 */
function elementor_mcp_dashboard_track_credential(array $entry, array $connection): array
{
    /** @var list<int> $ids */
    $ids = is_array($entry['ids'] ?? null) ? $entry['ids'] : [];
    $ids[] = (int) ($connection['id'] ?? 0);
    $entry['ids'] = $ids;

    $entry['reachable'] =
        ($entry['reachable'] ?? false) === true
        || elementor_mcp_connection_credential_exists(
            (string) ($connection['method'] ?? ''),
            (string) ($connection['credential_key'] ?? ''),
            (int) ($connection['user_id'] ?? 0),
        );

    return $entry;
}

/**
 * Whether any card is listing a client that can no longer get in.
 *
 * Drives the sweep button: offering "remove revoked" on a screen where nothing
 * is revoked is a button that does nothing, which teaches people to distrust
 * the ones that do.
 *
 * @param array<string, array<string, mixed>> $activity
 */
function elementor_mcp_dashboard_has_stale_clients(array $activity): bool
{
    foreach ($activity as $client) {
        if (($client['reachable'] ?? false) !== true) {
            return true;
        }
    }

    return false;
}

/**
 * An empty roll-up entry for a client.
 *
 * @return array<string, mixed>
 */
function elementor_mcp_dashboard_blank_client(string $reported, ?string $registry_key): array
{
    return [
        'label' => $reported !== '' ? elementor_mcp_client_label($reported) : __('Unidentified client', domain: 'elementor-mcp'),
        'known' => $registry_key !== null,
        'versions' => [],
        'methods' => [],
        'users' => [],
        'credentials' => 0,
        'requests' => 0,
        'first_seen' => '',
        'last_seen' => '',
        // Row ids behind this card, so Forget can delete exactly what the card
        // represents rather than guessing from the reported name.
        'ids' => [],
        // False once every credential behind the card has been revoked: the
        // client is listed, but it can no longer get in.
        'reachable' => false,
    ];
}

/**
 * Fold one connection row into a client's roll-up.
 *
 * @param array<string, mixed> $entry
 * @param array<string, mixed> $connection
 * @return array<string, mixed>
 */
function elementor_mcp_dashboard_merge_connection(array $entry, array $connection): array
{
    $entry['credentials'] = (int) $entry['credentials'] + 1;
    $entry['requests'] = (int) $entry['requests'] + (int) ($connection['request_count'] ?? 0);

    $entry = elementor_mcp_dashboard_track_credential($entry, $connection);

    $user = get_userdata((int) ($connection['user_id'] ?? 0));

    /** @var array<string, string> $columns */
    $columns = [
        'versions' => (string) ($connection['client_version'] ?? ''),
        'methods' => (string) ($connection['method'] ?? ''),
        'users' => $user instanceof WP_User ? $user->user_login : '',
    ];
    foreach ($columns as $field => $value) {
        /** @var list<string> $seen */
        $seen = is_array($entry[$field] ?? null) ? $entry[$field] : [];
        if ($value !== '' && !in_array($value, $seen, strict: true)) {
            $seen[] = $value;
        }
        $entry[$field] = $seen;
    }

    return elementor_mcp_dashboard_widen_window($entry, $connection);
}

/**
 * Stretch a client's first/last seen window to cover one more connection.
 *
 * String comparison is correct on 'Y-m-d H:i:s': the format sorts
 * lexicographically in the same order it sorts chronologically.
 *
 * @param array<string, mixed> $entry
 * @param array<string, mixed> $connection
 * @return array<string, mixed>
 */
function elementor_mcp_dashboard_widen_window(array $entry, array $connection): array
{
    $first = (string) ($connection['first_seen'] ?? '');
    if ($first !== '' && ((string) $entry['first_seen'] === '' || $first < (string) $entry['first_seen'])) {
        $entry['first_seen'] = $first;
    }

    $last = (string) ($connection['last_seen'] ?? '');
    if ($last > (string) $entry['last_seen']) {
        $entry['last_seen'] = $last;
    }

    return $entry;
}

/**
 * Which AI clients are talking to this site.
 *
 * Reads includes/connections.php, which records one row per credential and
 * client across the whole site. The previous version listed only the current
 * user's own application passwords, so on a site with several administrators it
 * showed a fraction of the truth — and it identified connections by credential
 * label, which is a name the user typed rather than the software connecting.
 *
 * Clients that have never connected are listed too. "Cursor is not connected" is
 * the answer to a question people actually arrive with, and an empty screen
 * cannot give it.
 */
function elementor_mcp_dashboard_clients(): void
{
    $activity = elementor_mcp_dashboard_client_activity();
    ?>
        <section id="elementor-mcp-clients" class="elementor-mcp-panel <?php echo $activity !== [] ? 'is-ready' : ''; ?>">
            <h2 class="elementor-mcp-setting-group__title"><?php esc_html_e('AI clients', domain: 'elementor-mcp'); ?></h2>

            <?php if ($activity === []) { ?>
                <p class="description"><?php esc_html_e(
                    'Nothing has called the MCP endpoint yet. A client appears here the moment it authenticates and introduces itself.',
                    domain: 'elementor-mcp',
                ); ?></p>
            <?php } else { ?>
                <div class="elementor-mcp-clients">
                    <?php foreach ($activity as $client) {
                        elementor_mcp_dashboard_client_card($client);
                    } ?>
                </div>
                <p class="description"><?php esc_html_e(
                    'Name and version are what each client said about itself during the handshake, so treat them as a label rather than proof — the credential, transport, request count and times are ElementorMCP\'s own observations. Times are UTC.',
                    domain: 'elementor-mcp',
                ); ?></p>

                <?php if (elementor_mcp_dashboard_has_stale_clients($activity)) { ?>
                    <form method="post">
                        <?php wp_nonce_field(ELEMENTOR_MCP_FORGET_CLIENT_NONCE); ?>
                        <button type="submit" name="elementor_mcp_forget_stale" value="1" class="button"><?php

                        esc_html_e('Remove clients with revoked credentials', domain: 'elementor-mcp'); ?></button>
                    </form>
                <?php } ?>
            <?php } ?>

            <?php

            // Every client Elementor MCP knows about, connected ones marked. This
            // used to list only the ones that had never called, under the
            // heading "Not connected yet" — which, on a site where something
            // *was* connected, read as a flat denial and contradicted the
            // panel directly above it. Showing the whole roster with the
            // connected ones lit up answers both questions people bring here:
            // "is anything talking to my site?" and "is my client supported?".
            $roster = elementor_mcp_selectable_clients();
            ?>
            <?php if ($roster !== []) { ?>
                <p class="elementor-mcp-legend"><?php esc_html_e('Supported clients', domain: 'elementor-mcp'); ?></p>
                <div class="elementor-mcp-clients elementor-mcp-clients--idle">
                    <?php // Not links: the setup flow is further down this same page. ?>
                    <?php foreach ($roster as $key => $client) { ?>
                        <?php $is_connected = array_key_exists((string) $key, $activity); ?>
                        <span class="elementor-mcp-client-chip<?php echo $is_connected ? ' is-connected' : ''; ?>">
                            <?php if ($is_connected) { ?>
                                <span class="elementor-mcp-client-chip__dot" aria-hidden="true"></span>
                            <?php } ?>
                            <?php echo esc_html((string) ($client['label'] ?? '')); ?>
                            <?php if ($is_connected) { ?>
                                <span class="screen-reader-text"><?php esc_html_e(
                                    'connected',
                                    domain: 'elementor-mcp',
                                ); ?></span>
                            <?php } ?>
                        </span>
                    <?php } ?>
                </div>
                <p class="description"><?php

                if ($activity === []) {
                    esc_html_e('Pick one below to get its exact configuration.', domain: 'elementor-mcp');
                } else {
                    printf(
                        /* translators: %d: number of AI clients that have connected */
                        esc_html(_n(
                            single: '%d client has connected. Pick any client below to get its exact configuration.',
                            plural: '%d clients have connected. Pick any client below to get its exact configuration.',
                            number: count($activity),
                            domain: 'elementor-mcp',
                        )),
                        count($activity),
                    );
                }
                ?></p>
            <?php } ?>

            <?php elementor_mcp_dashboard_oauth_list(); ?>
        </section>
    <?php
}

/**
 * One connected client.
 *
 * @param array{
 *     label: string,
 *     known: bool,
 *     versions: list<string>,
 *     methods: list<string>,
 *     users: list<string>,
 *     credentials: int,
 *     requests: int,
 *     first_seen: string,
 *     last_seen: string,
 *     ids: list<int>,
 *     reachable: bool
 * } $client
 */
function elementor_mcp_dashboard_client_card(array $client): void
{
    $transports = [
        'password' => __('Application password', domain: 'elementor-mcp'),
        'oauth' => __('OAuth', domain: 'elementor-mcp'),
        'token' => __('Access token', domain: 'elementor-mcp'),
    ];
    $methods = [];
    foreach ($client['methods'] as $method) {
        $methods[] = (string) ($transports[$method] ?? $method);
    }
    ?>
    <article class="elementor-mcp-client-card<?php echo $client['known'] ? '' : ' is-unknown'; ?>">
        <header class="elementor-mcp-client-card__head">
            <h3 class="elementor-mcp-client-card__name"><?php echo esc_html($client['label']); ?></h3>
            <?php if ($client['versions'] !== []) { ?>
                <span class="elementor-mcp-client-card__version"><?php echo
                    esc_html(implode(', ', $client['versions']))
                ; ?></span>
            <?php } ?>
        </header>

        <dl class="elementor-mcp-client-card__facts">
            <div>
                <dt><?php esc_html_e('Requests', domain: 'elementor-mcp'); ?></dt>
                <dd class="elementor-mcp-client-card__count"><?php echo
                    esc_html(number_format_i18n($client['requests']))
                ; ?></dd>
            </div>
            <div>
                <dt><?php esc_html_e('Transport', domain: 'elementor-mcp'); ?></dt>
                <dd><?php echo esc_html($methods === [] ? '—' : implode(', ', $methods)); ?></dd>
            </div>
            <div>
                <dt><?php esc_html_e('Signed in as', domain: 'elementor-mcp'); ?></dt>
                <dd><?php echo
                    esc_html(
                        $client['users'] === [] ? __('unknown', domain: 'elementor-mcp') : implode(', ', $client['users']),
                    )
                ; ?></dd>
            </div>
            <div>
                <dt><?php esc_html_e('Credentials', domain: 'elementor-mcp'); ?></dt>
                <dd><?php echo esc_html(number_format_i18n($client['credentials'])); ?></dd>
            </div>
            <div>
                <dt><?php esc_html_e('First seen', domain: 'elementor-mcp'); ?></dt>
                <dd><?php echo esc_html($client['first_seen'] !== '' ? $client['first_seen'] : '—'); ?></dd>
            </div>
            <div>
                <dt><?php esc_html_e('Last seen', domain: 'elementor-mcp'); ?></dt>
                <dd><?php echo esc_html($client['last_seen'] !== '' ? $client['last_seen'] : '—'); ?></dd>
            </div>
        </dl>

        <div class="elementor-mcp-client-card__actions">
            <?php if ($client['reachable'] !== true) { ?>
                <span class="elementor-mcp-pill elementor-mcp-pill--attention"><?php esc_html_e(
                    'Credential revoked',
                    domain: 'elementor-mcp',
                ); ?></span>
            <?php } ?>

            <form method="post" class="elementor-mcp-client-card__forget">
                <?php wp_nonce_field(ELEMENTOR_MCP_FORGET_CLIENT_NONCE); ?>
                <input type="hidden" name="elementor_mcp_forget_client" value="<?php echo
                    esc_attr(implode(',', array_map(static fn(int $id): string => (string) $id, $client['ids'])))
                ; ?>">
                <button type="submit" class="button-link elementor-mcp-revoke-btn"><?php esc_html_e(
                    'Forget',
                    domain: 'elementor-mcp',
                ); ?></button>
            </form>
        </div>
    </article>
    <?php
}

/**
 * OAuth clients that have registered, with their own last-used stamp.
 *
 * Kept beside the connection list rather than merged into it: a registered
 * client that never completed a token exchange has never reached MCP, so it
 * belongs in neither the same table nor the same count.
 */
function elementor_mcp_dashboard_oauth_list(): void
{
    $clients = elementor_mcp_dashboard_oauth_clients();
    ?>
            <p class="elementor-mcp-legend"><?php esc_html_e('Registered OAuth clients', domain: 'elementor-mcp'); ?></p>
            <?php if ($clients === []) { ?>
                <p class="description"><?php esc_html_e(
                    'No OAuth client has registered. Clients appear here after they complete the authorize step.',
                    domain: 'elementor-mcp',
                ); ?></p>
            <?php } else { ?>
                <table class="widefat">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Client', domain: 'elementor-mcp'); ?></th>
                            <th><?php esc_html_e('Client ID', domain: 'elementor-mcp'); ?></th>
                            <th><?php esc_html_e('Registered', domain: 'elementor-mcp'); ?></th>
                            <th><?php esc_html_e('Last used', domain: 'elementor-mcp'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($clients as $client) {
                            $used = (string) ($client['last_used_at'] ?? '');
                            ?>
                            <tr>
                                <td><strong><?php echo
                                    esc_html((string) ($client['client_name'] ?? ''))
                                ; ?></strong></td>
                                <td><code><?php echo esc_html((string) ($client['client_id'] ?? '')); ?></code></td>
                                <td class="elementor-mcp-mono"><?php echo
                                    esc_html((string) ($client['created_at'] ?? ''))
                                ; ?></td>
                                <td class="elementor-mcp-mono"><?php echo
                                    esc_html($used !== '' ? $used : __('never', domain: 'elementor-mcp'))
                                ; ?></td>
                            </tr>
                        <?php
                        } ?>
                    </tbody>
                </table>
            <?php } ?>
    <?php
}

/**
 * The addresses a client needs, and how it is expected to reach them.
 *
 * An MCP client is configured by pasting URLs, and when a connection fails the
 * first question is always which URL was wrong. Previously only the application
 * password endpoint appeared here, so the OAuth endpoint and the two discovery
 * documents — the ones a client actually fetches first — had to be reconstructed
 * by hand or found in the Connect screen's generated snippets.
 */
function elementor_mcp_dashboard_endpoints(): void
{
    $rows = elementor_mcp_dashboard_endpoint_rows();
    $oauth_available = count($rows) > 1;
    ?>
        <p class="elementor-mcp-legend"><?php esc_html_e('Endpoints', domain: 'elementor-mcp'); ?></p>

        <?php if (!$oauth_available) { ?>
            <p class="description"><?php esc_html_e(
                'OAuth is not being served on this site, so only the application-password endpoint exists. Elementor MCP withholds the OAuth routes on a public plain-HTTP site because authorization codes and access tokens would cross the network unencrypted. Enable HTTPS and they appear here.',
                domain: 'elementor-mcp',
            ); ?></p>
        <?php } ?>

        <table class="widefat">
            <thead>
                <tr>
                    <th><?php esc_html_e('Purpose', domain: 'elementor-mcp'); ?></th>
                    <th><?php esc_html_e('Address', domain: 'elementor-mcp'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row) { ?>
                    <tr>
                        <td><?php echo esc_html($row['label']); ?></td>
                        <td><code><?php echo esc_html($row['url']); ?></code></td>
                    </tr>
                <?php } ?>
            </tbody>
        </table>
        <p class="description"><?php esc_html_e(
            'Most clients need only the OAuth or application-password endpoint; they fetch the discovery documents themselves. The metadata addresses are here for a client that has to be told explicitly, or for checking what a failing client saw.',
            domain: 'elementor-mcp',
        ); ?></p>
    <?php
}

/**
 * The endpoint table's rows.
 *
 * @return list<array{label: string, url: string}>
 */
function elementor_mcp_dashboard_endpoint_rows(): array
{
    $rows = [
        [
            // Both credentials that travel in a header authenticate here; only
            // OAuth gets a route of its own. Naming just one of them is what sent
            // people looking for a separate access-token address that does not
            // exist.
            'label' => __('MCP endpoint (application password or access token)', domain: 'elementor-mcp'),
            'url' => rest_url('mcp/elementor-mcp'),
        ],
    ];

    // The OAuth routes are registered as a set or not at all — on a public
    // plain-HTTP site Elementor MCP refuses to serve them, because authorization codes
    // and bearer tokens would cross the network in the clear. Listing the
    // addresses anyway would hand someone four URLs that all 404 and no clue why,
    // so the whole group is omitted and the panel says what is missing instead.
    if (!function_exists('ElementorMCP\\OAuth\\Endpoints\\Discovery\\protected_resource_metadata_url')) {
        return $rows;
    }

    $rows[] = [
        'label' => __('MCP endpoint (OAuth)', domain: 'elementor-mcp'),
        'url' => rest_url('mcp/elementor-mcp-oauth'),
    ];
    $rows[] = [
        'label' => __('Protected resource metadata', domain: 'elementor-mcp'),
        'url' => \ElementorMCP\OAuth\Endpoints\Discovery\protected_resource_metadata_url(),
    ];
    $rows[] = [
        'label' => __('Authorization server metadata', domain: 'elementor-mcp'),
        'url' => home_url(\ElementorMCP\OAuth\Endpoints\Discovery\AUTHORIZATION_SERVER),
    ];

    return $rows;
}

/**
 * What Elementor MCP Pro is contributing, when it is licensed and running.
 *
 * Rendered only when Pro answers. The section names the integrations that
 * matched software actually installed on this site, because "7 of 39" on its
 * own is a number, while "Elementor, WooCommerce, ACF" is a receipt — and the
 * ones that did not match are worth showing too, since they are what the licence
 * would cover if the customer installed them later.
 */
function elementor_mcp_dashboard_pro(): void
{
    $pro = elementor_mcp_dashboard_pro_status();
    if ($pro === null) {
        return;
    }

    $active = $pro['active_integrations'];
    ?>
        <section id="elementor-mcp-pro-status" class="elementor-mcp-panel is-ready">
            <h2 class="elementor-mcp-setting-group__title"><?php esc_html_e('Elementor MCP Pro', domain: 'elementor-mcp'); ?></h2>

            <div class="elementor-mcp-stats">
                <?php

                elementor_mcp_dashboard_stat(
                    __('Integrations active', domain: 'elementor-mcp'),
                    sprintf(
                        /* translators: 1: integrations matched on this site, 2: integrations Pro supports in total */
                        __('%1$d of %2$d', domain: 'elementor-mcp'),
                        count($active),
                        $pro['total_integrations'],
                    ),
                );
                elementor_mcp_dashboard_stat(
                    __('Abilities from Pro', domain: 'elementor-mcp'),
                    number_format_i18n($pro['abilities']),
                );
                if ($pro['version'] !== '') {
                    elementor_mcp_dashboard_stat(__('Pro version', domain: 'elementor-mcp'), $pro['version']);
                }
                ?>
            </div>

            <?php if ($active !== []) { ?>
                <p class="elementor-mcp-legend"><?php esc_html_e('Matched on this site', domain: 'elementor-mcp'); ?></p>
                <div class="elementor-mcp-clients elementor-mcp-clients--idle">
                    <?php foreach ($active as $label) { ?>
                        <span class="elementor-mcp-client-chip is-matched"><?php echo esc_html($label); ?></span>
                    <?php } ?>
                </div>
            <?php } else { ?>
                <p class="description"><?php esc_html_e(
                    'None of the plugins or themes Pro specializes in are active here yet. Its abilities appear automatically as soon as one is.',
                    domain: 'elementor-mcp',
                ); ?></p>
            <?php } ?>

            <?php if ($pro['inactive_integrations'] !== []) { ?>
                <p class="elementor-mcp-legend"><?php esc_html_e('Also covered by your licence', domain: 'elementor-mcp'); ?></p>
                <div class="elementor-mcp-clients elementor-mcp-clients--idle">
                    <?php foreach ($pro['inactive_integrations'] as $label) { ?>
                        <span class="elementor-mcp-client-chip"><?php echo esc_html($label); ?></span>
                    <?php } ?>
                </div>
            <?php } ?>
        </section>
    <?php
}

/**
 * What agents can reach, grouped by ability category.
 */
function elementor_mcp_dashboard_reach(): void
{
    $exposure = elementor_mcp_dashboard_exposure();
    ?>
        <section id="elementor-mcp-agent-reach" class="elementor-mcp-panel">
            <h2 class="elementor-mcp-setting-group__title"><?php esc_html_e(
                'What agents can reach',
                domain: 'elementor-mcp',
            ); ?></h2>
            <?php if ($exposure['by_category'] === []) { ?>
                <p class="description"><?php esc_html_e(
                    'No abilities are registered. Turn on AI abilities in Settings.',
                    domain: 'elementor-mcp',
                ); ?></p>
            <?php } else { ?>
                <table class="widefat">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Category', domain: 'elementor-mcp'); ?></th>
                            <th><?php esc_html_e('Abilities', domain: 'elementor-mcp'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($exposure['by_category'] as $category => $count) { ?>
                            <tr>
                                <td><code><?php echo esc_html((string) $category); ?></code></td>
                                <td class="elementor-mcp-mono"><?php echo esc_html((string) $count); ?></td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            <?php } ?>
        </section>
    <?php
}

/**
 * One figure in the connection strip.
 */
function elementor_mcp_dashboard_stat(string $label, string $value, string $state = ''): void
{ ?>
    <div class="elementor-mcp-stat">
        <span class="elementor-mcp-stat__label"><?php echo esc_html($label); ?></span>
        <span class="elementor-mcp-stat__value<?php echo $state !== '' ? ' is-' . esc_attr($state) : ''; ?>"><?php

        echo esc_html($value); ?></span>
    </div>
    <?php }

// The Overview screen no longer registers a menu entry of its own.
//
// It is rendered by the `elementor-mcp-connect` page, which is the parent slug every
// other Elementor MCP screen hangs off. Keeping one screen means one place to look;
// keeping that slug means the fourteen registrations pointing at it, and any
// link a user saved, keep working.
//
// Requests to the old dashboard URL are forwarded rather than 404ed, because it
// was a real page people could have bookmarked.
// On `init`, not `admin_init`: wp-admin/admin.php resolves the `page` query arg
// against the registered submenus and wp_die()s with a 403 for anything it does
// not recognise, and that happens before admin_init fires. A redirect hooked
// there never runs — the user gets "you are not allowed to access this page" for
// a link that used to work.
add_action('init', static function (): void {
    if (!is_admin() || ($_GET['page'] ?? null) !== ELEMENTOR_MCP_DASHBOARD_PAGE) {
        return;
    }

    wp_safe_redirect(admin_url('admin.php?page=elementor-mcp-connect'));
    exit();
});

<?php

// SPDX-FileCopyrightText: 2026 Elementor MCP <dev@elementormcp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace ElementorMCP\Preview\Admin;

use ElementorMCP\Preview;
use ElementorMCP\Preview\Store;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * The Preview screen: what an agent has proposed, and the diff behind each one.
 *
 * This is a destination rather than hidden machinery, so it belongs in the nav
 * map. "What is waiting on my review" is a thing a person
 * browses to.
 */

const PAGE_SLUG = 'elementor-mcp-preview';
const NOTICE_TRANSIENT = 'elementor_mcp_preview_admin_notice_';

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
    if (!function_exists('elementor_mcp_manage_capability')) {
        return;
    }

    $pending = 0;
    foreach (Store\all() as $record) {
        if (($record['status'] ?? '') === Store\STATUS_PENDING) {
            $pending++;
        }
    }

    $menu_title = \elementor_mcp_nav_label(PAGE_SLUG, fallback: __('Preview', domain: 'elementor-mcp'));
    if ($pending > 0) {
        $menu_title .= sprintf(
            ' <span class="awaiting-mod"><span class="pending-count">%d</span></span>',
            $pending,
        );
    }

    add_submenu_page(
        parent_slug: 'elementor-mcp-connect',
        page_title: \elementor_mcp_nav_label(PAGE_SLUG, fallback: __('Preview', domain: 'elementor-mcp')),
        menu_title: $menu_title,
        capability: capability(),
        menu_slug: PAGE_SLUG,
        callback: __NAMESPACE__ . '\\render_page',
    );
}

/**
 * Add the screen to the shared nav map without editing it.
 *
 * @param mixed $map
 * @return mixed
 */
function register_nav(mixed $map): mixed
{
    if (!is_array($map)) {
        return $map;
    }
    $map[PAGE_SLUG] = ['label' => __('Change Review', domain: 'elementor-mcp'), 'group' => 'elementor'];
    return $map;
}

function render_page(): void
{
    if (!current_user_can_manage()) {
        wp_die(esc_html__('Not allowed.', domain: 'elementor-mcp'), title: '', args: ['response' => 403]);
    }

    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing between two views.
    $requested = array_key_exists('preview', $_GET) ? sanitize_text_field(wp_unslash((string) $_GET['preview'])) : '';

    if ($requested !== '' && Store\is_valid_id($requested)) {
        $record = Store\get($requested);
        if ($record !== null) {
            require __DIR__ . '/templates/detail.php';
            return;
        }
    }

    require __DIR__ . '/templates/list.php';
}

function register_post_handlers(): void
{
    add_action('admin_post_elementor_mcp_preview_apply', __NAMESPACE__ . '\\handle_apply');
    add_action('admin_post_elementor_mcp_preview_discard', __NAMESPACE__ . '\\handle_discard');
}

function handle_apply(): void
{
    $id = read_id();
    require_capability_and_nonce('elementor_mcp_preview_apply_' . $id);

    // The same funnel the ability uses. A screen and an ability that each run
    // their own version of a security-relevant sequence will drift.
    $result = Preview\apply($id, confirm: true);

    if (is_wp_error($result)) {
        redirect_with_notice('error', $result->get_error_message(), ['preview' => $id]);
        return;
    }

    redirect_with_notice('success', __('The change was applied.', domain: 'elementor-mcp'), ['preview' => $id]);
}

function handle_discard(): void
{
    $id = read_id();
    require_capability_and_nonce('elementor_mcp_preview_discard_' . $id);

    $result = Preview\discard($id);
    if (is_wp_error($result)) {
        redirect_with_notice('error', $result->get_error_message(), ['preview' => $id]);
        return;
    }

    redirect_with_notice('success', __('The preview was discarded. Nothing was written.', domain: 'elementor-mcp'));
}

function read_id(): string
{
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer() runs in require_capability_and_nonce().
    $raw = array_key_exists('preview_id', $_POST) ? sanitize_text_field(wp_unslash((string) $_POST['preview_id'])) : '';
    return Store\is_valid_id($raw) ? $raw : '';
}

function require_capability_and_nonce(string $nonce_action): void
{
    if (!current_user_can_manage()) {
        wp_die(esc_html__('Not allowed.', domain: 'elementor-mcp'), title: '', args: ['response' => 403]);
    }
    check_admin_referer($nonce_action);
}

/** @param array<string, string> $args */
function redirect_with_notice(string $type, string $message, array $args = []): void
{
    set_transient(
        NOTICE_TRANSIENT . get_current_user_id(),
        ['type' => $type, 'message' => $message],
        expiration: 30,
    );
    wp_safe_redirect(add_query_arg([...['page' => PAGE_SLUG], ...$args], admin_url('admin.php')));
    exit();
}

function render_notice(): void
{
    if (!current_user_can_manage()) {
        return;
    }
    /** @var mixed $notice */
    $notice = get_transient(NOTICE_TRANSIENT . get_current_user_id());
    if (!is_array($notice)) {
        return;
    }
    delete_transient(NOTICE_TRANSIENT . get_current_user_id());

    wp_admin_notice((string) ($notice['message'] ?? ''), [
        'type' => (string) ($notice['type'] ?? 'info'),
        'dismissible' => true,
    ]);
}

function enqueue_assets(string $hook): void
{
    if ($hook !== 'elementor-mcp_page_' . PAGE_SLUG) {
        return;
    }

    wp_enqueue_style(
        'elementor-mcp-preview-admin',
        (string) ELEMENTOR_MCP_PLUGIN_URL . 'includes/preview/assets/admin.css',
        ['elementor-mcp-admin'],
        \elementor_mcp_asset_version('includes/preview/assets/admin.css'),
    );
}

/**
 * Human-readable age, used by both templates.
 */
function relative_time(string $iso): string
{
    $timestamp = strtotime($iso);
    if ($timestamp === false) {
        return '';
    }
    $now = time();
    // human_time_diff() is direction-agnostic, so a future moment — an expiry
    // that has not passed — would otherwise be rendered as "... ago".
    if ($timestamp > $now) {
        return sprintf(
            /* translators: %s: human-readable time difference, e.g. "5 mins" */
            __('in %s', domain: 'elementor-mcp'),
            human_time_diff($now, $timestamp),
        );
    }
    return sprintf(
        /* translators: %s: human-readable time difference, e.g. "5 mins" */
        __('%s ago', domain: 'elementor-mcp'),
        human_time_diff($timestamp, $now),
    );
}

function status_label(string $status): string
{
    return match ($status) {
        Store\STATUS_PENDING => __('Pending review', domain: 'elementor-mcp'),
        Store\STATUS_APPLIED => __('Applied', domain: 'elementor-mcp'),
        Store\STATUS_DISCARDED => __('Discarded', domain: 'elementor-mcp'),
        Store\STATUS_CONFLICTED => __('Target changed', domain: 'elementor-mcp'),
        Store\STATUS_FAILED => __('Failed', domain: 'elementor-mcp'),
        Store\STATUS_EXPIRED => __('Expired', domain: 'elementor-mcp'),
        Store\STATUS_APPLYING => __('Applying', domain: 'elementor-mcp'),
        default => $status,
    };
}

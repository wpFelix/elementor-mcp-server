<?php

// SPDX-FileCopyrightText: 2026 Elementor MCP <dev@elementormcp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * Who is allowed to manage Elementor MCP.
 *
 * One capability answer for the admin screens, the REST surface, and the
 * ability permission callbacks, so they cannot drift apart.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Runtime permission check for privileged Elementor MCP administration.
 */
function elementor_mcp_current_user_can_manage(): bool
{
    return is_multisite() ? is_super_admin() : current_user_can('manage_options');
}

/**
 * Runtime permission check for a specific user.
 */
function elementor_mcp_user_can_manage(int|WP_User $user): bool
{
    $user_id = $user instanceof WP_User ? $user->ID : $user;
    if ($user_id <= 0) {
        return false;
    }

    $manage_capability = 'manage_options';

    return is_multisite() ? is_super_admin($user_id) : user_can($user, $manage_capability);
}

/**
 * Capability string for WordPress APIs that cannot accept a boolean callback.
 */
function elementor_mcp_manage_capability(): string
{
    return is_multisite() ? 'manage_network_options' : 'manage_options';
}

/**
 * Permission callback for privileged Elementor MCP administration.
 *
 * @return bool
 */
function elementor_mcp_permission_callback()
{
    if (!elementor_mcp_is_enabled()) {
        return false;
    }

    return elementor_mcp_current_user_can_manage();
}

/**
 * Return a bearer token from a REST request header.
 */
function elementor_mcp_rest_header_token(WP_REST_Request $request, string $header_name): string
{
    $header_token = $request->get_header($header_name);
    if (is_string($header_token) && trim($header_token) !== '') {
        return trim($header_token);
    }

    $authorization = $request->get_header('authorization');
    if (!is_string($authorization)) {
        return '';
    }

    $matches = [];
    if (preg_match('/^\s*Bearer\s+(.+?)\s*$/i', $authorization, $matches) !== 1) {
        return '';
    }

    return trim($matches[1]);
}

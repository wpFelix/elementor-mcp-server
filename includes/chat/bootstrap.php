<?php

// SPDX-FileCopyrightText: 2026 Elementor MCP <dev@elementormcp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * Elementor MCP Chat: shared constants, feature gate, and consent state.
 *
 * Loaded first by chat.php. Everything else in this directory may rely on
 * the constants and predicates defined here.
 */

if (!defined('ABSPATH')) {
    exit();
}

const ELEMENTOR_MCP_CHAT_PAGE = 'elementor-mcp-chat';

const ELEMENTOR_MCP_CHAT_REST_NAMESPACE = 'elementor-mcp/v1';

// Base64 inflates size by ~33%, so a single image must stay well under
// ELEMENTOR_MCP_CHAT_MAX_ROW_BYTES (the per-session storage guard); otherwise it would be
// blanked on persist and the model would never receive it. Keep the two in sync.
const ELEMENTOR_MCP_CHAT_MAX_IMAGE_BYTES = 3_145_728;

const ELEMENTOR_MCP_CHAT_MAX_ATTACHMENTS = 4;

const ELEMENTOR_MCP_CHAT_MAX_ROW_BYTES = 5_242_880;

const ELEMENTOR_MCP_CHAT_MAX_SESSIONS_PER_USER = 50;

const ELEMENTOR_MCP_CHAT_CONSENT_META = 'elementor_mcp_chat_consent';

/**
 * Whether Elementor MCP Chat is enabled. Site owners can turn the feature off entirely
 * with `add_filter('elementor_mcp_chat_enabled', '__return_false')`: no menu entry, no
 * assets, no REST routes, and the page itself refuses to render.
 */
function elementor_mcp_chat_is_enabled(): bool
{
    return apply_filters('elementor_mcp_chat_enabled', value: true) !== false;
}

/**
 * Whether the current user has accepted the one-time Elementor MCP Chat cost notice.
 */
function elementor_mcp_chat_user_has_consented(): bool
{
    return get_user_meta(get_current_user_id(), ELEMENTOR_MCP_CHAT_CONSENT_META, single: true) === '1';
}

/**
 * @return array{available: bool, reason: string, message: string}
 */
function elementor_mcp_chat_status(): array
{
    if (!function_exists('wp_ai_client_prompt')) {
        return [
            'available' => false,
            'reason' => 'missing_ai_client',
            'message' => __(
                'Elementor MCP Chat requires WordPress 7 or newer, with an AI provider configured.',
                domain: 'elementor-mcp',
            ),
        ];
    }

    if (!elementor_mcp_chat_native_tools_available()) {
        return [
            'available' => false,
            'reason' => 'missing_native_tool_calling',
            'message' => __(
                'Elementor MCP Chat requires WordPress AI Client native function calling support.',
                domain: 'elementor-mcp',
            ),
        ];
    }

    return [
        'available' => true,
        'reason' => 'available',
        'message' => __('Ready to run Elementor MCP with native tool calls.', domain: 'elementor-mcp'),
    ];
}

function elementor_mcp_chat_url(): string
{
    return add_query_arg(['page' => ELEMENTOR_MCP_CHAT_PAGE], admin_url('admin.php'));
}

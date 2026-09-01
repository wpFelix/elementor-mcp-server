<?php

// SPDX-FileCopyrightText: 2026 Elementor MCP <dev@elementormcp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace ElementorMCP\Abilities\Elementor;

/**
 * Elementor integration helpers.
 *
 * The implementation is split by concern in the helpers/ directory beside this
 * file; every ability in this folder requires only this one file:
 *
 *   elementor-mcp-page-io.php              reading and writing a page's element tree
 *   elementor-mcp-tree.php                 navigating and mutating that tree
 *   elementor-mcp-schema.php               extracting a widget's control schema
 *   elementor-mcp-settings-validation.php  validating settings against a schema
 *   elementor-mcp-atomic-values.php        v4: coercing values into atomic prop envelopes
 *   elementor-mcp-styles-map.php           v4: the styles map on an atomic element
 *   elementor-mcp-style-values.php         v4: wrapping individual style property values
 *   elementor-mcp-style-unions.php         v4: choosing an alternative for a union-typed prop
 *   elementor-mcp-unknown-keys.php         reporting keys that were dropped
 *   elementor-mcp-v3-v4-keys.php           telling classic and atomic settings keys apart
 *
 * Every symbol is a plain namespaced function or constant, so load order does
 * not matter.
 */

if (!defined('ABSPATH')) {
    exit();
}

require_once __DIR__ . '/helpers/elementor-mcp-page-io.php';
require_once __DIR__ . '/helpers/elementor-mcp-tree.php';
require_once __DIR__ . '/helpers/elementor-mcp-schema.php';
require_once __DIR__ . '/helpers/elementor-mcp-settings-validation.php';
require_once __DIR__ . '/helpers/elementor-mcp-atomic-values.php';
require_once __DIR__ . '/helpers/elementor-mcp-styles-map.php';
require_once __DIR__ . '/helpers/elementor-mcp-style-values.php';
require_once __DIR__ . '/helpers/elementor-mcp-style-unions.php';
require_once __DIR__ . '/helpers/elementor-mcp-unknown-keys.php';
require_once __DIR__ . '/helpers/elementor-mcp-v3-v4-keys.php';

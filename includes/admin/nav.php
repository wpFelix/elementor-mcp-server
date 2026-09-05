<?php

// SPDX-FileCopyrightText: 2026 Elementor MCP <dev@elementormcp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * The one place Elementor MCP's screens are named and ordered.
 *
 * Elementor MCP screens used to name themselves at their own registration site, which
 * produced two problems this file exists to fix.
 *
 * The names drifted apart. "Configuration" and "Settings" were separate screens
 * whose titles are synonyms, so no label told you which one held the thing you
 * wanted. "Abilities Hub" carried internal vocabulary outward. Every screen now
 * takes its label from elementor_mcp_nav_label(), so a
 * rename happens once and reaches the sidebar and the tab rail together.
 *
 * And a long list of sibling items read as something to search rather than a
 * structure to be navigated. Grouping them says what each screen is for:
 * Control Center is the operational summary, AI Workspace contains the agent
 * surfaces, Elementor Studio contains visual work, and Account & Safety holds
 * governance and commercial settings.
 *
 * Slugs are deliberately untouched. They are in bookmarks, in support threads,
 * and in the `page=` links other plugins may hold; renaming a screen should not
 * break a URL someone saved.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Section headings for the tab rail, in the order they appear.
 *
 * @return array<string, string>
 */
function elementor_mcp_nav_groups(): array
{
    $groups = [
        'home' => __('Control Center', domain: 'elementor-mcp'),
        'ai' => __('AI Workspace', domain: 'elementor-mcp'),
        'elementor' => __('Elementor Studio', domain: 'elementor-mcp'),
        'account' => __('Account & Safety', domain: 'elementor-mcp'),
    ];

    /**
     * Filter the tab rail's section headings.
     *
     * @param array<string, string> $groups Group key to heading, in display order.
     */
    /** @var mixed $filtered */
    $filtered = apply_filters('elementor_mcp_nav_groups', $groups);
    if (!is_array($filtered)) {
        return $groups;
    }

    $safe = [];
    /** @var mixed $heading */
    foreach ($filtered as $key => $heading) {
        $safe[(string) $key] = (string) (is_scalar($heading) ? $heading : $key);
    }

    return $safe;
}

/**
 * Every Elementor MCP screen: its label and the group it belongs to.
 *
 * An add-on registering a screen adds itself here through the filter; anything
 * absent still appears on the rail, in the group named by the fallback below.
 *
 * @return array<string, array<array-key, mixed>>
 */
function elementor_mcp_nav_map(): array
{
    $map = [
        // Operational health and connection management. The Dashboard keeps the
        // historical `elementor-mcp-connect` slug because it is the parent every
        // other Elementor MCP screen registers under. Connections owns setup,
        // credentials, endpoints and client records so the Dashboard can remain
        // a concise live summary.
        'elementor-mcp-connect' => ['label' => __('Dashboard', domain: 'elementor-mcp'), 'group' => 'home'],
        'elementor-mcp-connections' => ['label' => __('Connections', domain: 'elementor-mcp'), 'group' => 'home'],
        'elementor-mcp-troubleshoot' => ['label' => __('System Health', domain: 'elementor-mcp'), 'group' => 'home'],

        // What an agent is allowed to do, and what it knows before it starts.
        'elementor-mcp-chat' => ['label' => __('AI Assistant', domain: 'elementor-mcp'), 'group' => 'ai'],
        'elementor-mcp-abilities' => ['label' => __('AI Tools', domain: 'elementor-mcp'), 'group' => 'ai'],
        'elementor-mcp-context' => ['label' => __('Agent Rules', domain: 'elementor-mcp'), 'group' => 'ai'],
        'elementor-mcp-skills' => ['label' => __('AI Skills', domain: 'elementor-mcp'), 'group' => 'ai'],
        'elementor-mcp-prompts' => ['label' => __('Prompt Library', domain: 'elementor-mcp'), 'group' => 'ai'],
        'elementor-mcp-pro-memory' => ['label' => __('Memory', domain: 'elementor-mcp'), 'group' => 'ai'],

        // Where work on the site actually gets made.
        'elementor-mcp-design' => ['label' => __('Design System', domain: 'elementor-mcp'), 'group' => 'elementor'],
        'elementor-mcp-preview' => ['label' => __('Change Review', domain: 'elementor-mcp'), 'group' => 'elementor'],

        // The machinery underneath.
        'elementor-mcp-settings' => ['label' => __('Preferences', domain: 'elementor-mcp'), 'group' => 'account'],
        'elementor-mcp-sandbox' => ['label' => __('Safety Lab', domain: 'elementor-mcp'), 'group' => 'account'],
    ];

    /**
     * Filter the Elementor MCP screen registry.
     *
     * @param array<string, array<string, mixed>> $map Page slug to label and group.
     */
    /** @var mixed $filtered */
    $filtered = apply_filters('elementor_mcp_nav_map', $map);
    if (!is_array($filtered)) {
        return $map;
    }

    $safe = [];
    /** @var mixed $entry */
    foreach ($filtered as $slug => $entry) {
        if (is_array($entry)) {
            $safe[(string) $slug] = $entry;
        }
    }

    return $safe;
}

/**
 * The label for a screen, for both the WordPress sidebar and the tab rail.
 *
 * Registration sites pass their own previous title as the fallback, so a screen
 * this file does not know about keeps working and simply keeps its old name.
 */
function elementor_mcp_nav_label(string $slug, string $fallback = ''): string
{
    $entry = elementor_mcp_nav_map()[$slug] ?? null;
    if (is_array($entry) && is_string($entry['label'] ?? null) && $entry['label'] !== '') {
        return $entry['label'];
    }

    return $fallback !== '' ? $fallback : $slug;
}

/**
 * The group a screen belongs to.
 *
 * Unknown screens land in the last group rather than being dropped: an add-on
 * that has not declared itself should still be reachable.
 */
function elementor_mcp_nav_group(string $slug): string
{
    $entry = elementor_mcp_nav_map()[$slug] ?? null;
    if (is_array($entry) && is_string($entry['group'] ?? null) && $entry['group'] !== '') {
        return $entry['group'];
    }

    $groups = elementor_mcp_nav_groups();
    $keys = array_keys($groups);

    return $keys === [] ? 'account' : (string) end($keys);
}

/**
 * Custom AI robot icon for Elementor MCP's WordPress menu entry.
 *
 * A compact inline SVG avoids shipping another icon request and stays crisp at
 * WordPress' 20px menu scale.
 */
function elementor_mcp_admin_menu_icon(): string
{
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" aria-hidden="true"><g fill="#c5d2e1"><rect x="7.1" y="3.6" width="2.1" height="12.8" rx="0.8"/><rect x="7.1" y="3.6" width="9.1" height="2.6" rx="0.8"/><rect x="7.1" y="8.7" width="7.2" height="2.6" rx="0.8"/><rect x="7.1" y="13.8" width="8.7" height="2.6" rx="0.8"/></g><g fill="none" stroke="#c5d2e1" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M4.6 6.6 2.2 10l2.4 3.4"/></g></svg>';

    return 'data:image/svg+xml;base64,' . base64_encode($svg);
}

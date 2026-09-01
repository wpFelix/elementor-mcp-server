<?php

// SPDX-FileCopyrightText: 2026 Elementor MCP <dev@elementormcp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace ElementorMCP\Design\Library;

use ElementorMCP\Design\Contract;
use ElementorMCP\Design\Parser;
use ElementorMCP\Design\Store;
use ElementorMCP\Design\Tokens;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * All saved designs.
 *
 * @return list<array{slug: string, name: string, description: string, content: string}>
 */
function all(): array
{
    return Store\all_user();
}

/**
 * @return array{slug: string, name: string, description: string, content: string}|null
 */
function find(string $slug): ?array
{
    $slug = Parser\normalize_slug($slug);
    foreach (all() as $record) {
        if ($record['slug'] === $slug) {
            return $record;
        }
    }
    return null;
}

/**
 * The active design's sync-relevant tokens (colors + fonts), canonicalized
 * through the same mapping the previews use, or null when no design is active.
 *
 * A stable public read for consumers that need the active brand tokens without
 * knowing the DESIGN.md shape — notably the Pro design-sync, which propagates
 * these into the site's own global design system. Reading the active tokens is a
 * free design-system capability; applying them site-wide is the Pro part.
 *
 * @return array{
 *   slug: string,
 *   name: string,
 *   colors: array<string, string>,
 *   fonts: array<string, string>,
 *   token_sources: array{colors: string, typography: string, spacing: string, rounded: string, components: string, dials: string, scale: string},
 *   readiness: array{ready: bool, sync_ready: bool, errors: list<string>, warnings: list<string>}
 * }|null
 */
function active_tokens(): ?array
{
    $slug = Store\get_active_slug();
    if ($slug === '') {
        return null;
    }
    $record = find($slug);
    if ($record === null) {
        return null;
    }

    $inspection = Contract\inspect($record['content']);
    $vars = Tokens\css_vars(Tokens\extract($record['content']));
    $colors = [];
    foreach (['bg' => '--elementor-mcp-bg', 'ink' => '--elementor-mcp-ink', 'accent' => '--elementor-mcp-accent'] as $role => $var) {
        if (($vars[$var] ?? '') === '') {
            continue;
        }
        $colors[$role] = $vars[$var];
    }
    $fonts = [];
    foreach (['heading' => '--elementor-mcp-font-heading', 'body' => '--elementor-mcp-font-body'] as $role => $var) {
        if (($vars[$var] ?? '') === '') {
            continue;
        }
        $fonts[$role] = $vars[$var];
    }

    return [
        'slug' => $record['slug'],
        'name' => $record['name'],
        'colors' => $colors,
        'fonts' => $fonts,
        'token_sources' => $inspection['token_sources'],
        'readiness' => $inspection['readiness'],
    ];
}

<?php

// SPDX-FileCopyrightText: 2026 Elementor MCP <dev@elementormcp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace ElementorMCP\Design\Adopt;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Read the design a site already has.
 *
 * For any site that is not brand new, this beats every readymade palette on
 * offer, because the brand already exists — it is in the theme's global styles,
 * in the customizer, in the pages somebody built. The failure mode this exists
 * to prevent is an agent inventing a fresh direction for a site that already had
 * one, and then building something that clashes with every existing page while
 * being perfectly consistent with itself.
 *
 * Sources are read from the customizer's standard settings and the Elementor
 * source supplied by Pro. Nothing here writes;
 * the result is a draft for a person to review, name and save.
 *
 * Elementor's kit is Pro's to read through its own abilities, and it extends
 * this via the `elementor_mcp_design_adopt_sources` filter.
 */

/**
 * Gather every readable source of design on this site.
 *
 * @return array{
 *   colors: array<string, string>,
 *   typography: array<string, array<string, string>>,
 *   spacing: array<string, string>,
 *   sources: list<array{source: string, colors: int, fonts: int}>,
 *   notes: list<string>
 * }
 */
function gather(): array
{
    $result = [
        'colors' => [],
        'typography' => [],
        'spacing' => [],
        'sources' => [],
        'notes' => [],
    ];

    merge_source($result, customizer());

    /**
     * Let other plugins contribute what they can read.
     *
     * Pro uses this to add the Elementor palette. Each entry has the same shape
     * a built-in source returns, so a contributed source is not privileged over
     * the customizer source — first writer of a token name wins.
     *
     * @param list<array<string, mixed>> $extra
     */
    $extra = apply_filters('elementor_mcp_design_adopt_sources', []);
    if (is_array($extra)) {
        foreach ($extra as $source) {
            if (is_array($source)) {
                merge_source($result, $source);
            }
        }
    }

    if ($result['colors'] === []) {
        $result['notes'][] = __(
            'No palette could be read from this site. It may be using a theme that defines colours only in CSS, which this cannot see.',
            domain: 'elementor-mcp',
        );
    }
    if ($result['typography'] === []) {
        $result['notes'][] = __(
            'No font families could be read. Check the theme\'s own typography settings before assuming the site has none.',
            domain: 'elementor-mcp',
        );
    }

    return $result;
}

/**
 * @param array<string, mixed> $result
 * @param array<string, mixed> $source
 */
function merge_source(array &$result, array $source): void
{
    $name = (string) ($source['source'] ?? 'unknown');
    $colors = is_array($source['colors'] ?? null) ? $source['colors'] : [];
    $typography = is_array($source['typography'] ?? null) ? $source['typography'] : [];
    $spacing = is_array($source['spacing'] ?? null) ? $source['spacing'] : [];

    if ($colors === [] && $typography === [] && $spacing === []) {
        return;
    }

    foreach ($colors as $key => $value) {
        $key = token_key((string) $key);
        $hex = normalize((string) $value);
        // First writer wins: sources are visited most-authoritative first, and a
        // later source repeating a name is a copy rather than a correction.
        if ($key !== '' && $hex !== '' && !array_key_exists($key, $result['colors'])) {
            $result['colors'][$key] = $hex;
        }
    }
    foreach ($typography as $role => $props) {
        $role = token_key((string) $role);
        if ($role === '' || !is_array($props) || array_key_exists($role, $result['typography'])) {
            continue;
        }
        $clean = [];
        foreach (['fontFamily', 'fontWeight', 'fontSize', 'lineHeight', 'letterSpacing', 'measure'] as $prop) {
            if (($props[$prop] ?? '') !== '' && is_scalar($props[$prop])) {
                $clean[$prop] = (string) $props[$prop];
            }
        }
        if ($clean !== []) {
            $result['typography'][$role] = $clean;
        }
    }
    foreach ($spacing as $key => $value) {
        $key = token_key((string) $key);
        if ($key !== '' && !array_key_exists($key, $result['spacing']) && is_scalar($value)) {
            $result['spacing'][$key] = (string) $value;
        }
    }

    $result['sources'][] = [
        'source' => $name,
        'colors' => count($colors),
        'fonts' => count($typography),
    ];
}

/**
 * Customizer settings every theme is likely to set.
 *
 * Deliberately narrow: the standard core mods plus the two names half the
 * classic-theme world uses. Guessing at a theme's own option names would produce
 * a palette that is right on one site and nonsense on the next, and Pro's
 * theme-aware modules read the specific ones properly.
 *
 * @return array<string, mixed>
 */
function customizer(): array
{
    $colors = [];
    foreach (['background_color' => 'background'] as $mod => $token) {
        $value = get_theme_mod($mod, '');
        if (is_string($value) && $value !== '') {
            $colors[$token] = str_starts_with($value, '#') ? $value : '#' . $value;
        }
    }
    // Core stores this one as an option rather than a theme mod.
    $background = get_option('background_color', '');
    if (is_string($background) && $background !== '' && !array_key_exists('background', $colors)) {
        $colors['background'] = '#' . ltrim($background, characters: '#');
    }

    if ($colors === []) {
        return [];
    }

    return ['source' => 'customizer', 'colors' => $colors, 'typography' => [], 'spacing' => []];
}

/**
 * Build a DESIGN.md draft from gathered tokens.
 *
 * Written here rather than left to the agent so the front matter is exactly what
 * the parser reads back. A draft that does not parse is worse than no draft.
 *
 * @param array<string, mixed> $gathered
 */
function to_markdown(string $name, array $gathered): string
{
    $lines = ['---', 'name: ' . scrub($name)];

    /** @var array<string, string> $colors */
    $colors = $gathered['colors'];
    if ($colors !== []) {
        $lines[] = 'colors:';
        foreach (array_slice($colors, offset: 0, length: 24, preserve_keys: true) as $key => $value) {
            $lines[] = sprintf('  %s: "%s"', scrub($key), scrub($value));
        }
    }
    /** @var array<string, array<string, string>> $typography */
    $typography = $gathered['typography'];
    if ($typography !== []) {
        $lines[] = 'typography:';
        foreach (array_slice($typography, offset: 0, length: 8, preserve_keys: true) as $role => $props) {
            $lines[] = sprintf('  %s:', scrub($role));
            foreach ($props as $prop => $value) {
                $lines[] = sprintf('    %s: "%s"', $prop, scrub((string) $value));
            }
        }
    }
    /** @var array<string, string> $spacing */
    $spacing = $gathered['spacing'];
    if ($spacing !== []) {
        $lines[] = 'spacing:';
        foreach (array_slice($spacing, offset: 0, length: 10, preserve_keys: true) as $key => $value) {
            $lines[] = sprintf('  %s: "%s"', scrub($key), scrub($value));
        }
    }
    $lines[] = '---';
    $lines[] = '';
    $lines[] = '# ' . scrub($name);
    $lines[] = '';
    $lines[] = __(
        'Adopted from what this site already looks like, so new work matches the existing pages rather than competing with them.',
        domain: 'elementor-mcp',
    );
    $lines[] = '';
    $lines[] = sprintf(
        /* translators: %s: comma-separated source names. */
        __('Read from: %s.', domain: 'elementor-mcp'),
        implode(', ', array_map(
            static fn(array $s): string => (string) $s['source'],
            is_array($gathered['sources']) ? $gathered['sources'] : [],
        )),
    );
    $lines[] = '';
    $lines[] = '## Review before building';
    $lines[] = '';
    $lines[] = __(
        'This is a draft. It carries the tokens the site already uses and none of the reasoning behind them: which colour is the accent rather than merely present, what the site should never do, how much variation a page is allowed. Add those before anybody builds from it, or the design will be followed literally and still miss.',
        domain: 'elementor-mcp',
    );

    return implode("\n", $lines) . "\n";
}

/** Normalise a colour to lowercase six-digit hex, or '' if it is not one. */
function normalize(string $value): string
{
    $value = trim($value);
    if (preg_match('/^#?([0-9a-f]{6})$/i', $value, $m) === 1) {
        return '#' . strtolower($m[1]);
    }
    if (preg_match('/^#?([0-9a-f]{3})$/i', $value, $m) === 1) {
        $s = strtolower($m[1]);

        return '#' . $s[0] . $s[0] . $s[1] . $s[1] . $s[2] . $s[2];
    }
    if (preg_match('/rgba?\(\s*(\d{1,3})\s*[,\s]\s*(\d{1,3})\s*[,\s]\s*(\d{1,3})/i', $value, $m) === 1) {
        foreach ([1, 2, 3] as $i) {
            if ((int) $m[$i] > 255) {
                return '';
            }
        }

        return sprintf('#%02x%02x%02x', (int) $m[1], (int) $m[2], (int) $m[3]);
    }

    return '';
}

/** The first real family in a CSS font stack. */
function first_family(string $stack): string
{
    foreach (explode(',', $stack) as $family) {
        $family = trim($family, " \t\n\r\0\x0B\"'");
        if ($family !== '' && !str_starts_with($family, 'var(')) {
            return $family;
        }
    }

    return '';
}

/** Token names in the lowercase-dashed form DESIGN.md uses. */
function token_key(string $name): string
{
    $name = preg_replace('/([a-z0-9])([A-Z])/', '$1-$2', $name) ?? $name;
    $name = strtolower(str_replace([' ', '_', '/'], '-', $name));
    $name = preg_replace('/[^a-z0-9-]/', '', $name) ?? $name;
    // Collapse runs: a builder label like "Accent / Meta" turns three separators
    // into three dashes, and "accent---meta" reads as a typo in the file a person
    // is about to review.
    $name = preg_replace('/-{2,}/', '-', $name) ?? $name;

    return trim($name, characters: '-');
}

/** Keep a value on one line and out of the front matter's syntax. */
function scrub(string $value): string
{
    $value = str_replace(['"', "\r", "\n"], ['', ' ', ' '], $value);

    return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
}

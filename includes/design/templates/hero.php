<?php

// SPDX-FileCopyrightText: 2026 Elementor MCP <dev@elementormcp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template file: require()d inside a namespaced render function, so every variable is function-scoped. Reads are type-checked and escaped on output.

/**
 * The active design, at the top of the screen.
 *
 * The first question anybody opening this page has is "what is my site set to
 * right now" — and before this the answer was somewhere in a list of cards, told
 * in hex codes. It is answered here in the design's own colours and faces, at a
 * size you read rather than parse, with the few facts that decide whether it is
 * usable: is it activated, can its palette carry text, how many tokens it holds.
 *
 * When nothing is active this becomes the empty state, because a person with no
 * design needs a route in far more than they need a status panel.
 */

use ElementorMCP\Design\Admin;
use ElementorMCP\Design\Contrast;
use ElementorMCP\Design\Contract;
use ElementorMCP\Design\Tokens;

if (!defined('ABSPATH')) {
    exit();
}

/** @var array{slug: string, name: string, description: string, content: string}|null $active */
if ($active === null) {
    ?>
    <div class="elementor-mcp-hero elementor-mcp-hero-empty">
        <div class="elementor-mcp-hero-empty-body">
            <h2><?php esc_html_e('No design is active yet', domain: 'elementor-mcp'); ?></h2>
            <p><?php esc_html_e(
                'A design is the one document your AI builds within: your colours, your typefaces, and the things this site should never do. Until one is active, every page is built on whatever the model reaches for by default.',
                domain: 'elementor-mcp',
            ); ?></p>
            <p class="elementor-mcp-hero-empty-routes"><?php esc_html_e(
                'Start from a kit below, or ask your agent: "read this site and save a design from what it already looks like".',
                domain: 'elementor-mcp',
            ); ?></p>
        </div>
    </div>
    <?php
    return;
}

$hero_tokens = Tokens\extract($active['content']);
$hero_vars = Tokens\css_vars($hero_tokens);
$hero_inspection = Contract\inspect($active['content']);
$hero_style = Tokens\css_vars_string($hero_tokens);

$hero_bg = $hero_vars['--elementor-mcp-bg'] ?? '#ffffff';
$hero_ink = $hero_vars['--elementor-mcp-ink'] ?? '#000000';
$hero_ratio = Contrast\ratio($hero_ink, $hero_bg);
$hero_grade = $hero_ratio >= Contrast\AAA_NORMAL
    ? __('AAA', domain: 'elementor-mcp')
    : ($hero_ratio >= Contrast\AA_NORMAL ? __('AA', domain: 'elementor-mcp') : __('below AA', domain: 'elementor-mcp'));
$hero_grade_ok = $hero_ratio >= Contrast\AA_NORMAL;

$hero_colors = count($hero_tokens['colors']);
$hero_faces = count($hero_tokens['typography']);
$hero_donts = count(ElementorMCP\Design\Preflight\context($active['content'])['donts']);

$hero_edit = '';
$hero_post = ElementorMCP\Design\Store\find_user_post($active['slug']);
if ($hero_post instanceof \WP_Post) {
    $hero_edit = add_query_arg(['page' => Admin\PAGE_SLUG, 'design' => $hero_post->ID], admin_url('admin.php'));
}
$hero_view = add_query_arg(['page' => Admin\PAGE_SLUG, 'view' => $active['slug']], admin_url('admin.php'));
?>
<div class="elementor-mcp-hero" style="<?php echo esc_attr($hero_style); ?>">
    <div class="elementor-mcp-hero-specimen" style="background:<?php echo esc_attr($hero_bg); ?>;color:<?php
        echo esc_attr($hero_ink);
    ?>">
        <p class="elementor-mcp-hero-kicker"><?php esc_html_e('Active design', domain: 'elementor-mcp'); ?></p>
        <p class="elementor-mcp-hero-display" style="font-family:<?php echo esc_attr(
            ($hero_vars['--elementor-mcp-font-heading'] ?? '') !== ''
                ? '"' . $hero_vars['--elementor-mcp-font-heading'] . '", sans-serif'
                : 'inherit',
        ); ?>"><?php echo esc_html($active['name']); ?></p>
        <p class="elementor-mcp-hero-sample" style="font-family:<?php echo esc_attr(
            ($hero_vars['--elementor-mcp-font-body'] ?? '') !== ''
                ? '"' . $hero_vars['--elementor-mcp-font-body'] . '", sans-serif'
                : 'inherit',
        ); ?>"><?php esc_html_e(
            'Body text, at the size a visitor actually reads it.',
            domain: 'elementor-mcp',
        ); ?></p>
        <span class="elementor-mcp-hero-cta"><?php esc_html_e('Primary action', domain: 'elementor-mcp'); ?></span>

        <div class="elementor-mcp-hero-swatches" aria-hidden="true">
            <?php foreach (array_slice($hero_tokens['colors'], offset: 0, length: 8) as $role => $value):
                $swatch = ElementorMCP\Design\Preflight\normalize_hex((string) $value);
                if ($swatch === '') {
                    continue;
                } ?>
                <span
                    class="elementor-mcp-hero-swatch"
                    style="background:<?php echo esc_attr($swatch); ?>"
                    title="<?php echo esc_attr($role . ' ' . $swatch); ?>"
                ></span>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="elementor-mcp-hero-facts">
        <h2><?php echo esc_html($active['name']); ?></h2>
        <?php if ($active['description'] !== ''): ?>
            <p class="elementor-mcp-hero-desc"><?php echo esc_html($active['description']); ?></p>
        <?php endif; ?>

        <ul class="elementor-mcp-hero-chips">
            <li class="is-<?php echo $hero_inspection['readiness']['ready'] ? 'ok' : 'warn'; ?>"><?php
                echo esc_html($hero_inspection['readiness']['ready']
                    ? __('Ready to build with', domain: 'elementor-mcp')
                    : __('Incomplete', domain: 'elementor-mcp'));
            ?></li>
            <li class="is-<?php echo $hero_grade_ok ? 'ok' : 'warn'; ?>"><?php echo esc_html(sprintf(
                /* translators: 1: contrast ratio, 2: WCAG grade. */
                __('Text %1$s:1 · %2$s', domain: 'elementor-mcp'),
                number_format($hero_ratio, decimals: 1),
                $hero_grade,
            )); ?></li>
            <li><?php echo esc_html(sprintf(
                /* translators: %d: number of colours. */
                _n('%d colour', '%d colours', $hero_colors, 'elementor-mcp'),
                $hero_colors,
            )); ?></li>
            <li><?php echo esc_html(sprintf(
                /* translators: %d: number of typefaces. */
                _n('%d typeface', '%d typefaces', $hero_faces, 'elementor-mcp'),
                $hero_faces,
            )); ?></li>
            <li class="is-<?php echo $hero_donts > 0 ? 'ok' : 'warn'; ?>"><?php echo esc_html(sprintf(
                /* translators: %d: number of enforceable rules. */
                _n('%d enforced rule', '%d enforced rules', $hero_donts, 'elementor-mcp'),
                $hero_donts,
            )); ?></li>
        </ul>

        <?php
        /**
         * Extra status a paid module can add, such as whether the design has
         * actually been written into the site's builders.
         *
         * @param string $slug The active design's slug.
         */
        do_action('elementor_mcp_design_hero_status', $active['slug']);
        ?>

        <div class="elementor-mcp-hero-actions">
            <?php if ($hero_edit !== ''): ?>
                <a class="button button-primary" href="<?php echo esc_url($hero_edit); ?>"><?php esc_html_e(
                    'Edit this design',
                    domain: 'elementor-mcp',
                ); ?></a>
            <?php endif; ?>
            <a class="button" href="<?php echo esc_url($hero_view); ?>"><?php esc_html_e(
                'View details',
                domain: 'elementor-mcp',
            ); ?></a>
        </div>
    </div>
</div>

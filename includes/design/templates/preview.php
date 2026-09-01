<?php

// SPDX-FileCopyrightText: 2026 Elementor MCP <dev@elementormcp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template file: require()d inside a namespaced render function, so every variable is function-scoped, never global. The prefix sniff cannot see across the include boundary. Reads are type-checked and escaped on output.

if (!defined('ABSPATH')) {
    exit();
}

/** @var string $vars_style  Inline CSS custom-property string (already sanitized). */
$vars_style ??= '';
?>
<div class="elementor-mcp-preview" data-elementor-mcp-preview style="<?php echo esc_attr($vars_style); ?>">
    <div class="elementor-mcp-preview-screen">
        <header class="elementor-mcp-preview-nav">
            <span class="elementor-mcp-preview-brand"><span class="elementor-mcp-preview-mark" aria-hidden="true"></span><?php echo
                esc_html(get_bloginfo('name'))
            ; ?></span>
            <nav class="elementor-mcp-preview-links" aria-hidden="true">
                <span><?php esc_html_e('Work', domain: 'elementor-mcp'); ?></span>
                <span><?php esc_html_e('Studio', domain: 'elementor-mcp'); ?></span>
                <span><?php esc_html_e('Journal', domain: 'elementor-mcp'); ?></span>
            </nav>
            <span class="elementor-mcp-preview-pill"><?php esc_html_e('Get started', domain: 'elementor-mcp'); ?></span>
        </header>

        <section class="elementor-mcp-preview-hero">
            <span class="elementor-mcp-preview-tag"><?php esc_html_e('Preview', domain: 'elementor-mcp'); ?></span>
            <h3 class="elementor-mcp-preview-title"><?php esc_html_e(
                'A heading in this design system.',
                domain: 'elementor-mcp',
            ); ?></h3>
            <p class="elementor-mcp-preview-lede"><?php esc_html_e(
                'Sample body copy: type, colour, spacing and shape applied to real content.',
                domain: 'elementor-mcp',
            ); ?></p>
            <div class="elementor-mcp-preview-actions">
                <span class="elementor-mcp-preview-btn elementor-mcp-preview-btn-primary"><?php esc_html_e(
                    'Primary action',
                    domain: 'elementor-mcp',
                ); ?></span>
                <span class="elementor-mcp-preview-btn elementor-mcp-preview-btn-ghost"><?php esc_html_e(
                    'Learn more',
                    domain: 'elementor-mcp',
                ); ?> <span class="elementor-mcp-preview-arrow" aria-hidden="true">&rarr;</span></span>
            </div>
        </section>

        <section class="elementor-mcp-preview-grid" aria-hidden="true">
            <div class="elementor-mcp-preview-tile">
                <span class="elementor-mcp-preview-num">01</span>
                <strong><?php esc_html_e('Typography', domain: 'elementor-mcp'); ?></strong>
                <span class="elementor-mcp-preview-tile-sub"><?php esc_html_e('Scale & weight', domain: 'elementor-mcp'); ?></span>
            </div>
            <div class="elementor-mcp-preview-tile">
                <span class="elementor-mcp-preview-num">02</span>
                <strong><?php esc_html_e('Colour', domain: 'elementor-mcp'); ?></strong>
                <span class="elementor-mcp-preview-tile-sub"><?php esc_html_e(
                    'Palette & accent',
                    domain: 'elementor-mcp',
                ); ?></span>
            </div>
            <div class="elementor-mcp-preview-tile">
                <span class="elementor-mcp-preview-num">03</span>
                <strong><?php esc_html_e('Spacing', domain: 'elementor-mcp'); ?></strong>
                <span class="elementor-mcp-preview-tile-sub"><?php esc_html_e(
                    'Rhythm & density',
                    domain: 'elementor-mcp',
                ); ?></span>
            </div>
        </section>
    </div>
</div>

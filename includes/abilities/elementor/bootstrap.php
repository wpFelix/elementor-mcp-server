<?php

// SPDX-FileCopyrightText: 2026 Elementor MCP <dev@elementormcp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

// Function definitions and runtime feature detection only. Loading this file
// does not register Elementor abilities until the Abilities API init hook runs.
require_once __DIR__ . '/elementor-mcp-runtime.php';

/**
 * Register the single page-builder category owned by Elementor MCP.
 */
function elementor_mcp_register_elementor_ability_category(): void
{
    if (!\ElementorMCP\Abilities\Elementor\el_min_runtime_available()) {
        return;
    }

    if (wp_has_ability_category('elementor')) {
        return;
    }

    wp_register_ability_category('elementor', [
        'label' => __('Elementor', domain: 'elementor-mcp'),
        'description' => __('Core Elementor page-building, editing, templates, and design-token abilities.', domain: 'elementor-mcp'),
    ]);
}

/**
 * Load the complete Free Elementor surface in dependency order.
 *
 * The potential surface is 41 abilities. Elementor 4-only abilities register
 * only when the corresponding Atomic Widgets, Global Classes, or Variables
 * runtime is actually available on the site.
 */
function elementor_mcp_load_elementor_abilities(): void
{
    if (!\ElementorMCP\Abilities\Elementor\el_min_runtime_available()) {
        return;
    }

    $dir = __DIR__ . '/';

    // Shared extraction, validation, persistence, and tree libraries.
    require_once $dir . 'elementor-mcp-schema-extractor.php';
    require_once $dir . 'elementor-mcp-helpers.php';
    require_once $dir . 'elementor-mcp-get-content.php';
    require_once $dir . 'elementor-mcp-pipeline.php';

    // Elementor Core: inspect, create, edit, move, delete, and build pages.
    require_once $dir . 'elementor-mcp-set-content.php';
    require_once $dir . 'elementor-mcp-add-element.php';
    require_once $dir . 'elementor-mcp-edit-element.php';
    require_once $dir . 'elementor-mcp-delete-element.php';
    require_once $dir . 'elementor-mcp-structure.php';
    require_once $dir . 'elementor-mcp-manage-templates.php';
    require_once $dir . 'elementor-mcp-manage-page-settings.php';
    require_once $dir . 'elementor-mcp-delete-page-content.php';
    require_once $dir . 'elementor-mcp-build-page.php';
    require_once $dir . 'elementor-mcp-delete-element-style.php';
    require_once $dir . 'elementor-mcp-get-widget-schema.php';
    require_once $dir . 'elementor-mcp-get-widget-params.php';
    require_once $dir . 'elementor-mcp-place-stock-image.php';
    require_once $dir . 'elementor-mcp-manage-global-styles-v3.php';
    require_once $dir . 'elementor-mcp-check-setup.php';
    require_once $dir . 'elementor-mcp-clear-document-cache.php';

    // Elementor 4 capabilities remain Free but register only on compatible
    // installs, so older Elementor sites receive an honest tool surface.
    if (\ElementorMCP\Abilities\Elementor\el_atomic_runtime_available()) {
        require_once $dir . 'elementor-mcp-validate-widget.php';
        require_once $dir . 'elementor-mcp-create-atomic-widget.php';
    }

    if (\ElementorMCP\Abilities\Elementor\el_style_schema_available()) {
        require_once $dir . 'elementor-mcp-get-style-schema.php';
    }

    if (\ElementorMCP\Abilities\Elementor\el_global_classes_available()) {
        require_once $dir . 'elementor-mcp-manage-global-classes.php';
    }

    if (\ElementorMCP\Abilities\Elementor\el_variables_available()) {
        require_once $dir . 'elementor-mcp-manage-variables.php';
    }
}

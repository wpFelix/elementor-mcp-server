<?php

// SPDX-FileCopyrightText: 2026 Elementor MCP <dev@elementormcp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- Every state-changing request on this screen verifies a nonce via check_admin_referer() before acting; the sniff cannot trace that across function boundaries. Reads are type-checked, whitelist-compared, and escaped on output.

/**
 * Shared admin chrome.
 *
 * The header every Elementor MCP screen renders, and the date/time format
 * helper those screens format timestamps with.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * JSON encoded for embedding directly inside a <script> block.
 *
 * Plain wp_json_encode() is already safe against the obvious attack: it escapes
 * forward slashes by default, so an embedded "</script>" comes out as
 * "<\/script>" and cannot close the block. This goes further and escapes the
 * angle brackets and ampersand too.
 *
 * The reason is that the existing safety rests entirely on slash escaping, which
 * is a default someone could switch off later by adding JSON_UNESCAPED_SLASHES
 * for prettier URLs — a change that looks cosmetic and would quietly reopen the
 * hole. Escaping the brackets themselves does not depend on that.
 *
 * The output is still valid JSON: a browser reads the hex escapes back to the
 * original characters, so the JavaScript sees exactly the intended value.
 */
function elementor_mcp_script_json(mixed $value): string
{
    $json = wp_json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

    return $json === false ? 'null' : $json;
}

/**
 * Build a combined date/time format string from WordPress settings.
 *
 * Falls back to 'Y-m-d H:i:s' if either format is empty.
 *
 * @param string $fallback Optional fallback format.
 * @return string
 */
function elementor_mcp_get_datetime_format($fallback = 'Y-m-d H:i:s')
{
    $date_format = (string) get_option('date_format');
    $time_format = (string) get_option('time_format');

    if ($date_format === '' || $time_format === '') {
        return $fallback;
    }

    return $date_format . ' ' . $time_format;
}

/**
 * Render the Elementor MCP masthead.
 *
 * Styling lives in includes/assets/admin.css, which every Elementor MCP screen loads.
 *
 * One logo serves every screen; the section is named by the engraved legend
 * beside it. Design and Chat used to ship their own wordmark SVGs that differed
 * only by the word after "ELEMENTOR_MCP", which meant three files to keep in step.
 *
 * The right-hand readout is the instrument lamp: whether agents can currently
 * act on this site. It is the single most consequential fact about Elementor MCP's
 * state, and it belongs on every screen rather than only on Configuration.
 *
 * @param string $legend Section name shown beside the logo.
 */
function elementor_mcp_render_admin_header(string $legend = ''): void
{
    $armed = elementor_mcp_is_enabled() && elementor_mcp_get_mcp_dependency_error() === null;
    $blocked = elementor_mcp_is_enabled() && elementor_mcp_get_mcp_dependency_error() !== null;

    // Off is idle, not "good": a green lamp next to "abilities off" would read
    // as a healthy running system, which is the opposite of what it means.
    $state_class = match (true) {
        $armed => 'elementor-mcp-masthead__status--armed',
        $blocked => 'elementor-mcp-masthead__status--attention',
        default => 'elementor-mcp-masthead__status--idle',
    };
    $state_text = match (true) {
        $armed => __('AI abilities live', domain: 'elementor-mcp'),
        $blocked => __('AI abilities unavailable', domain: 'elementor-mcp'),
        default => __('AI abilities off', domain: 'elementor-mcp'),
    };
    ?>
    <div class="elementor-mcp-masthead">
        <div class="elementor-mcp-masthead__inner">
        <div class="elementor-mcp-masthead__mark">
            <img
                src="<?php echo esc_url((string) ELEMENTOR_MCP_PLUGIN_URL . 'assets/elementor_mcp_logo.svg'); ?>"
                alt="Elementor MCP"
                width="182"
                height="32"
            >
            <span class="elementor-mcp-masthead__legend"><?php echo
                esc_html($legend !== '' ? $legend : __('MCP server', domain: 'elementor-mcp'))
            ; ?></span>
        </div>
        <div class="elementor-mcp-masthead__status <?php echo esc_attr($state_class); ?>">
            <span class="elementor-mcp-masthead__lamp" aria-hidden="true"></span>
            <span><?php echo esc_html($state_text); ?></span>
        </div>
        </div>
    </div>
    <?php

    elementor_mcp_render_admin_tabs();
}

/**
 * Product navigation for the Elementor MCP screens.
 *
 * Built from the registered submenu rather than a hardcoded list, so it cannot
 * drift when a screen is added, removed, or capability-gated — Pro's Memory tab
 * appears here only because Pro registered it.
 *
 * The WordPress submenu stays as it is; this is in-product navigation for
 * people who are already inside Elementor MCP and think of it as one tool.
 */
function elementor_mcp_render_admin_tabs(): void
{
    $tabs = elementor_mcp_admin_tabs();
    if (count($tabs) < 2) {
        return;
    }

    // Thirteen siblings on one rail read as a list to be searched. Grouped, the
    // rail says what each screen is for before you read any single label.
    $groups = elementor_mcp_nav_groups();
    $group_icons = [
        'home' => 'dashicons-dashboard',
        'ai' => 'dashicons-lightbulb',
        'elementor' => 'dashicons-admin-appearance',
        'account' => 'dashicons-shield',
    ];
    /** @var array<string, list<array{slug: string, label: string}>> $bucketed */
    $bucketed = [];
    foreach ($tabs as $tab) {
        $bucketed[elementor_mcp_nav_group($tab['slug'])][] = $tab;
    }

    $current = is_string($_GET['page'] ?? null) ? sanitize_key((string) $_GET['page']) : '';
    ?>
    <nav class="elementor-mcp-tabs" aria-label="<?php esc_attr_e('Elementor MCP sections', domain: 'elementor-mcp'); ?>">
        <div class="elementor-mcp-tabs__intro">
            <span><?php esc_html_e('Workspace', domain: 'elementor-mcp'); ?></span>
            <strong><?php echo esc_html(sprintf(
                /* translators: %d: number of available admin sections */
                _n('%d section', '%d sections', count($tabs), domain: 'elementor-mcp'),
                count($tabs),
            )); ?></strong>
        </div>
        <?php foreach ($groups as $key => $heading) {
            $group_tabs = $bucketed[$key] ?? [];
            if ($group_tabs === []) {
                continue;
            }
            ?>
            <div class="elementor-mcp-tabs__group elementor-mcp-tabs__group--<?php echo esc_attr($key); ?>">
                <span class="elementor-mcp-tabs__group-head">
                    <span class="elementor-mcp-tabs__group-icon dashicons <?php echo
                        esc_attr($group_icons[$key] ?? 'dashicons-admin-generic')
                    ; ?>" aria-hidden="true"></span>
                    <span class="elementor-mcp-tabs__heading"><?php echo esc_html($heading); ?></span>
                </span>
                <span class="elementor-mcp-tabs__items">
                    <?php foreach ($group_tabs as $tab) {
                        $is_current = $tab['slug'] === $current;
                        ?>
                        <a
                            class="elementor-mcp-tabs__tab<?php echo $is_current ? ' is-current' : ''; ?>"
                            href="<?php echo esc_url(admin_url('admin.php?page=' . $tab['slug'])); ?>"
                            <?php echo $is_current ? 'aria-current="page"' : ''; ?>
                        ><?php echo esc_html($tab['label']); ?></a>
                    <?php
                    } ?>
                </span>
            </div>
        <?php
        } ?>
    </nav>
    <?php
}

/**
 * The Elementor MCP sections the current user may open, in menu order.
 *
 * @return list<array{slug: string, label: string}>
 */
function elementor_mcp_admin_tabs(): array
{
    // @mago-expect lint:no-global -- $submenu is WordPress' menu registry.
    global $submenu;

    // The sidebar hides these entries with CSS rather than unregistering them
    // (see includes/admin/sidebar.php for why removing them breaks the pages),
    // so this array is still the full, authoritative list.
    /** @var array<string, list<array<int, string>>> $submenu */
    $items = $submenu['elementor-mcp-connect'] ?? [];
    if (!is_array($items)) {
        return [];
    }

    $tabs = [];
    foreach ($items as $item) {
        $slug = (string) ($item[2] ?? '');
        if ($slug === '') {
            continue;
        }

        // WordPress allows a submenu entry whose "slug" is an external URL, and
        // the Get Pro link is one. Rendering it here produced a tab labelled with
        // the raw URL, pointing at admin.php?page=https://... — so anything that
        // is not a real page slug belongs in the sidebar only.
        if (str_contains($slug, '://')) {
            continue;
        }

        $capability = (string) ($item[1] ?? '');
        if ($capability !== '' && !current_user_can($capability)) {
            continue;
        }

        $label = elementor_mcp_admin_tab_label((string) ($item[0] ?? $slug), $slug);
        if ($label === $slug) {
            // The title was entirely markup, so there is no text to put on a tab.
            continue;
        }

        $tabs[] = ['slug' => $slug, 'label' => $label];
    }

    // The product map owns information architecture; WordPress registration
    // priorities only decide when callbacks are attached. Sorting here keeps
    // the visible rail stable when optional Free or Pro screens register later.
    $nav_order = array_flip(array_keys(elementor_mcp_nav_map()));
    usort(
        $tabs,
        static fn(array $left, array $right): int => ($nav_order[$left['slug']] ?? PHP_INT_MAX)
            <=> ($nav_order[$right['slug']] ?? PHP_INT_MAX),
    );

    return $tabs;
}

/**
 * Reduce a menu title to plain tab text.
 *
 * Menu titles may append markup — "Visual" carries an Experimental badge, and
 * WordPress adds count bubbles the same way. Only the text before the first tag
 * belongs on the tab, or it reads "Visual Experimental".
 */
function elementor_mcp_admin_tab_label(string $title, string $fallback): string
{
    $mapped = elementor_mcp_nav_label($fallback);
    if ($mapped !== $fallback) {
        return $mapped;
    }

    $head = explode('<', $title, limit: 2)[0];
    $label = trim(wp_strip_all_tags($head));

    return $label === '' ? $fallback : $label;
}

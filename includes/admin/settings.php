<?php

// SPDX-FileCopyrightText: 2026 Elementor MCP <dev@elementormcp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

// phpcs:disable WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- Every state-changing request on this screen verifies a nonce via check_admin_referer() before acting; the sniff cannot trace that across function boundaries. Reads are type-checked, whitelist-compared, and escaped on output.

/**
 * The Settings screen: one home for every site-wide Elementor MCP switch.
 *
 * These switches used to live on whichever screen happened to use them — the
 * abilities toggle on Configuration, user context on Context, memory on Pro's
 * own page — so there was no single place to see or change how Elementor MCP behaves.
 *
 * Sections are collected through the `elementor_mcp_settings_sections` filter and
 * each one carries its own save callback, so a section's owner keeps ownership
 * of its option. That is how Pro contributes Memory here without the free
 * plugin knowing anything about it.
 *
 * Per-item screens (Abilities Hub, Skills, Design) are deliberately not folded
 * in: those are lists of things, not settings, and they belong on their own
 * screens.
 */

if (!defined('ABSPATH')) {
    exit();
}

const ELEMENTOR_MCP_SETTINGS_PAGE = 'elementor-mcp-settings';

const ELEMENTOR_MCP_SETTINGS_NONCE = 'elementor_mcp_save_settings';

/**
 * Every settings section, in display order.
 *
 * Shape per section: id, title, description, fields, and a save callback.
 * Typed loosely because the list passes through a filter and may carry sections
 * this file has never seen.
 *
 * @return list<array<string, mixed>>
 */
function elementor_mcp_settings_sections(): array
{
    $sections = [
        [
            'id' => 'agent-access',
            'title' => __('Agent access', domain: 'elementor-mcp'),
            'description' => __(
                'Whether connected AI agents can act on this site at all, and how much they are allowed to do when they can.',
                domain: 'elementor-mcp',
            ),
            'fields' => [
                [
                    'type' => 'toggle',
                    'name' => 'elementor_mcp_ai_abilities_enabled',
                    'label' => __('AI abilities', domain: 'elementor-mcp'),
                    'help' => __(
                        'Off means no MCP endpoint and no ability is exposed, whatever else is configured here.',
                        domain: 'elementor-mcp',
                    ),
                    'value' => elementor_mcp_is_enabled(),
                    'state' => 'armed',
                ],
                [
                    'type' => 'select',
                    'name' => 'elementor_mcp_safety_profile',
                    'label' => __('Safety profile', domain: 'elementor-mcp'),
                    'help' => __(
                        'Enforced on the server for MCP and REST alike. Critical and destructive calls always require explicit confirmation on top of this.',
                        domain: 'elementor-mcp',
                    ),
                    'value' => elementor_mcp_get_safety_profile(),
                    'options' => elementor_mcp_settings_profile_options(),
                ],
            ],
            'save' => 'elementor_mcp_settings_save_agent_access',
        ],
        [
            'id' => 'context',
            'title' => __('Context', domain: 'elementor-mcp'),
            'description' => __(
                'Site-specific instructions prepended to what every connected agent receives. Write the text itself on the Context screen.',
                domain: 'elementor-mcp',
            ),
            'fields' => [
                [
                    'type' => 'toggle',
                    'name' => 'elementor_mcp_instructions_enabled',
                    'label' => __('Send user context to agents', domain: 'elementor-mcp'),
                    'help' => __('When off, the context you wrote is kept but not sent.', domain: 'elementor-mcp'),
                    'value' => \ElementorMCP\Context\instructions_is_enabled(),
                ],
            ],
            'save' => 'elementor_mcp_settings_save_context',
        ],
    ];

    /**
     * Filter the Elementor MCP settings sections.
     *
     * Add a section to expose a site-wide switch on the Settings screen. Keep
     * the save callback with whichever code owns the option.
     *
     * @param list<array<string, mixed>> $sections
     */
    /** @var mixed $filtered */
    $filtered = apply_filters('elementor_mcp_settings_sections', $sections);
    if (!is_array($filtered)) {
        return $sections;
    }

    // A filter can return anything. Keep only entries that are actually
    // sections, so one malformed contribution cannot break the whole screen.
    $clean = [];
    /** @var mixed $section */
    foreach ($filtered as $section) {
        if (!is_array($section)) {
            continue;
        }
        /** @var array<string, mixed> $normalized */
        $normalized = $section;
        $clean[] = $normalized;
    }

    return $clean;
}

/**
 * Safety profile choices, labelled for display.
 *
 * @return array<string, string>
 */
function elementor_mcp_settings_profile_options(): array
{
    $options = [];
    foreach (elementor_mcp_safety_profiles() as $id => $profile) {
        $options[(string) $id] = (string) ($profile['label'] ?? $id);
    }

    return $options;
}

/**
 * @param array<string, mixed> $post
 */
function elementor_mcp_settings_save_agent_access(array $post): void
{
    $profile = is_string($post['elementor_mcp_safety_profile'] ?? null)
        ? sanitize_key((string) $post['elementor_mcp_safety_profile'])
        : '';
    if ($profile !== '') {
        elementor_mcp_update_safety_profile($profile);
    }

    // An unchecked checkbox is absent from the POST entirely.
    if (($post['elementor_mcp_ai_abilities_enabled'] ?? null) !== null) {
        elementor_mcp_enable_ai_abilities();
        return;
    }

    elementor_mcp_disable_ai_abilities();
}

/**
 * @param array<string, mixed> $post
 */
function elementor_mcp_settings_save_context(array $post): void
{
    update_option(
        \ElementorMCP\Context\INSTRUCTIONS_ENABLED_OPTION,
        ($post['elementor_mcp_instructions_enabled'] ?? null) !== null ? '1' : '0',
        autoload: true,
    );
}

/**
 * Persist the whole form, then redirect so a refresh cannot resubmit it.
 */
function elementor_mcp_handle_settings_save(): void
{
    if (($_POST['elementor_mcp_settings_submit'] ?? null) === null) {
        return;
    }
    if (!elementor_mcp_current_user_can_manage()) {
        return;
    }

    check_admin_referer(ELEMENTOR_MCP_SETTINGS_NONCE);

    /** @var array<string, mixed> $post */
    $post = wp_unslash($_POST);

    foreach (elementor_mcp_settings_sections() as $section) {
        $save = $section['save'] ?? null;
        if (is_callable($save)) {
            $save($post);
        }
    }

    wp_safe_redirect(add_query_arg(['updated' => '1'], admin_url('admin.php?page=' . ELEMENTOR_MCP_SETTINGS_PAGE)));
    exit();
}

function elementor_mcp_render_settings_screen(): void
{
    if (!elementor_mcp_current_user_can_manage()) {
        wp_die(esc_html__('You are not allowed to manage Elementor MCP settings.', domain: 'elementor-mcp'));
    }

    elementor_mcp_render_admin_header();
    ?>
    <div class="wrap">
        <h1><?php echo esc_html(elementor_mcp_nav_label('elementor-mcp-settings')); ?></h1>
        <p class="elementor-mcp-lede"><?php esc_html_e(
            'Every site-wide Elementor MCP switch, in one place. Per-ability and per-skill controls stay on their own screens.',
            domain: 'elementor-mcp',
        ); ?></p>

        <?php if (($_GET['updated'] ?? null) === '1') { ?>
            <div class="notice notice-success"><p><?php

            esc_html_e('Settings saved.', domain: 'elementor-mcp'); ?></p></div>
        <?php } ?>

        <form method="post" action="">
            <?php wp_nonce_field(ELEMENTOR_MCP_SETTINGS_NONCE); ?>
            <?php foreach (elementor_mcp_settings_sections() as $section) {
                elementor_mcp_render_settings_section($section);
            } ?>
            <p>
                <button type="submit" name="elementor_mcp_settings_submit" value="1" class="button button-primary">
                    <?php esc_html_e('Save settings', domain: 'elementor-mcp'); ?>
                </button>
            </p>
        </form>
    </div>
    <?php
}

/**
 * @param array<array-key, mixed> $section
 */
function elementor_mcp_render_settings_section(array $section): void
{
    $fields = is_array($section['fields'] ?? null) ? $section['fields'] : [];
    if ($fields === []) {
        return;
    }
    ?>
    <section class="elementor-mcp-panel">
        <h2 class="elementor-mcp-setting-group__title"><?php echo esc_html((string) ($section['title'] ?? '')); ?></h2>
        <?php if (($section['description'] ?? '') !== '') { ?>
            <p class="elementor-mcp-setting-group__note"><?php

            echo esc_html((string) $section['description']); ?></p>
        <?php } ?>
        <?php foreach ($fields as $field) {
            elementor_mcp_render_settings_field(is_array($field) ? $field : []);
        } ?>
    </section>
    <?php
}

/**
 * @param array<array-key, mixed> $field
 */
function elementor_mcp_render_settings_field(array $field): void
{
    $name = (string) ($field['name'] ?? '');
    if ($name === '') {
        return;
    }

    $type = (string) ($field['type'] ?? 'toggle');
    $label = (string) ($field['label'] ?? $name);
    $help = (string) ($field['help'] ?? '');
    $id = 'elementor-mcp-field-' . sanitize_key($name);
    ?>
    <div class="elementor-mcp-setting">
        <div class="elementor-mcp-setting__text">
            <label class="elementor-mcp-setting__label" for="<?php echo esc_attr($id); ?>"><?php

            echo esc_html($label); ?></label>
            <?php if ($help !== '') { ?>
                <p class="elementor-mcp-setting__help"><?php echo esc_html($help); ?></p>
            <?php } ?>
        </div>
        <div class="elementor-mcp-setting__control">
            <?php if ($type === 'select') {
                $options = is_array($field['options'] ?? null) ? $field['options'] : [];
                ?>
                <select id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($name); ?>">
                    <?php foreach ($options as $value => $text) { ?>
                        <option
                            value="<?php echo esc_attr((string) $value); ?>"
                            <?php selected((string) $value, (string) ($field['value'] ?? '')); ?>
                        ><?php echo esc_html((string) $text); ?></option>
                    <?php } ?>
                </select>
            <?php
            } else {
                $on = ($field['value'] ?? false) === true;
                $state = (string) ($field['state'] ?? '');
                ?>
                <label class="elementor-mcp-switch<?php echo $state === 'armed' ? ' elementor-mcp-switch--armed' : ''; ?>">
                    <input
                        type="checkbox"
                        id="<?php echo esc_attr($id); ?>"
                        name="<?php echo esc_attr($name); ?>"
                        value="1"
                        <?php checked($on); ?>
                    >
                    <span class="elementor-mcp-switch__track" aria-hidden="true"></span>
                </label>
            <?php
            } ?>
        </div>
    </div>
    <?php
}

add_action('admin_init', callback: 'elementor_mcp_handle_settings_save');

// Priority 15 places Settings directly after Configuration (10) and before
// Troubleshoot (20).
add_action(
    'admin_menu',
    static function (): void {
        add_submenu_page(
            parent_slug: 'elementor-mcp-connect',
            page_title: elementor_mcp_nav_label('elementor-mcp-settings'),
            menu_title: elementor_mcp_nav_label('elementor-mcp-settings'),
            capability: elementor_mcp_manage_capability(),
            menu_slug: ELEMENTOR_MCP_SETTINGS_PAGE,
            callback: 'elementor_mcp_render_settings_screen',
        );
    },
    priority: 15,
);

<?php

// SPDX-FileCopyrightText: 2026 Elementor MCP <dev@elementormcp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

// phpcs:disable WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- Every state-changing request on this screen verifies a nonce via check_admin_referer() before acting; the sniff cannot trace that across function boundaries. Reads are type-checked, whitelist-compared, and escaped on output.

/**
 * Configuration page, step 1: turning AI Abilities on and off.
 *
 * Owns the toggle itself, the admin-bar shortcut that drives the same
 * transition, and the production warning shown before enabling.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Enable AI Abilities for the current site domain.
 */
function elementor_mcp_enable_ai_abilities(): bool
{
    if (function_exists('elementor_mcp_get_mcp_dependency_error') && elementor_mcp_get_mcp_dependency_error() !== null) {
        return false;
    }

    update_option(option: 'elementor_mcp_ai_abilities_enabled', value: '1');
    update_option(option: 'elementor_mcp_ai_abilities_domain', value: (string) wp_parse_url(home_url(), PHP_URL_HOST));
    return true;
}

/**
 * Disable AI Abilities and clear the domain lock.
 */
function elementor_mcp_disable_ai_abilities(): bool
{
    update_option(option: 'elementor_mcp_ai_abilities_enabled', value: '0');
    delete_option('elementor_mcp_ai_abilities_domain');
    return true;
}

/**
 * Handle the enable/disable AI Abilities toggle submission.
 * Returns true on save, null when no submission.
 */
function elementor_mcp_handle_toggle_enabled(): ?bool
{
    if (($_POST['elementor_mcp_submit'] ?? null) === null) {
        return null;
    }
    if (!elementor_mcp_current_user_can_manage()) {
        return null;
    }

    check_admin_referer('elementor_mcp_settings');

    $profile = is_string($_POST['elementor_mcp_safety_profile'] ?? null)
        ? sanitize_key(wp_unslash($_POST['elementor_mcp_safety_profile']))
        : 'production';
    if (!elementor_mcp_update_safety_profile($profile)) {
        return false;
    }

    $enabled = ($_POST['elementor_mcp_ai_abilities_enabled'] ?? null) !== null;
    return $enabled ? elementor_mcp_enable_ai_abilities() : elementor_mcp_disable_ai_abilities();
}

/**
 * Handle the admin-bar AI Abilities toggle.
 */
function elementor_mcp_handle_admin_bar_toggle(): void
{
    if (!elementor_mcp_current_user_can_manage()) {
        wp_die(esc_html__('You are not allowed to manage Elementor MCP settings.', domain: 'elementor-mcp'));
    }

    check_admin_referer('elementor_mcp_toggle_ai_abilities');

    $target = $_GET['elementor_mcp_target'] ?? '';
    $result = null;
    if ($target === 'on') {
        $result = elementor_mcp_enable_ai_abilities();
    }
    if ($target === 'off') {
        $result = elementor_mcp_disable_ai_abilities();
    }

    $redirect = wp_get_referer();
    if (!is_string($redirect) || $redirect === '') {
        $redirect = admin_url('admin.php?page=elementor-mcp-connect');
    }

    $redirect = add_query_arg([
        'elementor_mcp_toggle_result' => $result === true ? $target : 'failed',
    ], $redirect);

    wp_safe_redirect($redirect);
    exit();
}

function elementor_mcp_render_enable_toggle(): void
{
    $enabled = elementor_mcp_is_enabled();
    $dependency_error = function_exists('elementor_mcp_get_mcp_dependency_error') ? elementor_mcp_get_mcp_dependency_error() : null;
    $toggle_disabled = $dependency_error !== null && !$enabled;
    $submit_attributes = $toggle_disabled ? ['disabled' => 'disabled'] : [];
    $looks_production = elementor_mcp_looks_like_production();
    $profile = elementor_mcp_get_safety_profile();
    $profiles = elementor_mcp_safety_profiles();
    ?>
    <h2 class="elementor-mcp-step-heading">
        <span class="elementor-mcp-step-badge">4</span>
        <?php esc_html_e('Enable AI Abilities', domain: 'elementor-mcp'); ?>
    </h2>
    <form method="post" action="" id="elementor-mcp-settings-form" style="margin: 16px 0 0;">
        <?php wp_nonce_field('elementor_mcp_settings'); ?>
        <label style="display:flex; align-items:center; gap:10px; font-size:16px; font-weight:600; color:#1d2327; margin:0 0 12px;">
            <input type="checkbox" name="elementor_mcp_ai_abilities_enabled" value="1" id="elementor-mcp-enable-checkbox" style="width:18px; height:18px;" <?php checked(
                checked: $enabled,
                current: true,
            ); ?> <?php disabled($toggle_disabled); ?> />
            <span><?php esc_html_e('Turn on AI Abilities for this site', domain: 'elementor-mcp'); ?></span>
        </label>
        <p class="description" style="margin:0 0 8px;">
            <strong style="color:#d63638;"><?php esc_html_e('Security note:', domain: 'elementor-mcp'); ?></strong>
            <?php esc_html_e(
                'Elementor MCP enforces the selected safety profile on the server for MCP and REST calls. Critical and destructive calls also require explicit confirmation.',
                domain: 'elementor-mcp',
            ); ?>
        </p>
        <label for="elementor-mcp-safety-profile" style="display:block; font-weight:600; margin:14px 0 6px;">
            <?php esc_html_e('Safety profile', domain: 'elementor-mcp'); ?>
        </label>
        <select name="elementor_mcp_safety_profile" id="elementor-mcp-safety-profile" style="min-width:260px;">
            <?php foreach ($profiles as $slug => $details): ?>
                <option value="<?php echo esc_attr($slug); ?>" <?php selected($profile, $slug); ?>>
                    <?php echo esc_html($details['label']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <div style="margin:8px 0 14px; max-width:760px;">
            <?php foreach ($profiles as $slug => $details): ?>
                <p class="description elementor-mcp-profile-description" data-profile="<?php echo
                    esc_attr($slug)
                ; ?>" <?php echo $slug === $profile ? '' : 'hidden'; ?>>
                    <?php echo esc_html($details['description']); ?>
                </p>
            <?php endforeach; ?>
        </div>
        <?php submit_button(
            text: __('Save Settings', domain: 'elementor-mcp'),
            type: 'primary',
            name: 'elementor_mcp_submit',
            wrap: false,
            other_attributes: $submit_attributes,
        ); ?>
    </form>
    <script>
    document.getElementById('elementor-mcp-settings-form').addEventListener('submit', function (e) {
        var cb = document.getElementById('elementor-mcp-enable-checkbox');
        var profile = document.getElementById('elementor-mcp-safety-profile');
        if (cb.checked && profile.value === 'developer' && (!cb.defaultChecked || profile.value !== <?php echo
            wp_json_encode($profile)
        ; ?>)) {
            var msg = <?php echo
                wp_json_encode(
                    $looks_production
                        ? __(
                            'This looks like a production site. Developer Full Access permits PHP execution, WP-CLI, filesystem access, and temporary administrator links. Use Production Safe unless unrestricted access is intentional. Continue?',
                            domain: 'elementor-mcp',
                        )
                        : __(
                            'Developer Full Access permits PHP execution, WP-CLI, filesystem access, and temporary administrator links. Continue?',
                            domain: 'elementor-mcp',
                        ),
                )
            ; ?>;
            if (!confirm(msg)) {
                e.preventDefault();
            }
        }
    });
    document.getElementById('elementor-mcp-safety-profile').addEventListener('change', function () {
        document.querySelectorAll('.elementor-mcp-profile-description').forEach(function (node) {
            node.hidden = node.getAttribute('data-profile') !== this.value;
        }, this);
    });
    </script>
    <?php
}

function elementor_mcp_render_enable_prompt(?WP_Error $dependency_error): void
{
    if (elementor_mcp_is_enabled() || $dependency_error !== null) {
        return;
    }

    ?>
    <p style="color:#666; font-size:14px;">
        <?php esc_html_e('Enable AI Abilities above to connect your AI client.', domain: 'elementor-mcp'); ?>
    </p>
    <?php
}

/**
 * Render the production-site warning banner above the enable toggle.
 *
 * Shown only when: AI Abilities are currently enabled AND the site looks like production
 * AND the current user has not dismissed the warning.
 */
function elementor_mcp_render_production_warning(): void
{
    if (!elementor_mcp_is_enabled()) {
        return;
    }
    if (!elementor_mcp_looks_like_production()) {
        return;
    }
    if (elementor_mcp_production_warning_dismissed()) {
        return;
    }
    ?>
    <div class="elementor-mcp-production-warning" role="alert">
        <p>
            <strong><?php esc_html_e('⚠️ This looks like a production site.', domain: 'elementor-mcp'); ?></strong>
            <?php esc_html_e(
                'Keeping the plugin installed here is fine, but AI Abilities should only be active on a staging or development copy. Make your changes there, then deploy the result the regular way. On production, keep AI Abilities off.',
                domain: 'elementor-mcp',
            ); ?>
        </p>
        <form method="post" style="margin:0;">
            <?php wp_nonce_field('elementor_mcp_dismiss_production_warning'); ?>
            <button type="submit" name="elementor_mcp_dismiss_production_warning" class="button button-small">
                <?php esc_html_e('Dismiss', domain: 'elementor-mcp'); ?>
            </button>
        </form>
    </div>
    <?php
}

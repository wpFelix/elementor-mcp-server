<?php

// SPDX-FileCopyrightText: 2026 Elementor MCP <dev@elementormcp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

// phpcs:disable WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- Every state-changing request on this screen verifies a nonce via check_admin_referer() before acting; the sniff cannot trace that across function boundaries. Reads are type-checked, whitelist-compared, and escaped on output.

/**
 * Configuration page: the Application Password connection method.
 *
 * Creating, reusing, listing, and revoking the password an MCP client
 * authenticates with. A generated password is shown once and never stored
 * in plaintext by Elementor MCP.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Handle the "use existing password" form submission.
 *
 * Returns the pasted plaintext value (only for the current request — never persisted),
 * a WP_Error on validation failure, or null when no submission.
 *
 * @return string|WP_Error|null
 */
function elementor_mcp_handle_use_existing_password()
{
    if (($_POST['elementor_mcp_use_existing_password'] ?? null) === null) {
        return null;
    }

    if (!elementor_mcp_current_user_can_manage()) {
        return new WP_Error('forbidden', __(
            'You do not have permission to use application passwords.',
            domain: 'elementor-mcp',
        ));
    }

    check_admin_referer('elementor_mcp_use_existing_password');

    $raw = $_POST['elementor_mcp_existing_password'] ?? '';
    $value = is_string($raw) ? trim($raw) : '';
    if ($value === '') {
        return new WP_Error('empty', __('Paste the application password value before submitting.', domain: 'elementor-mcp'));
    }
    if (strlen($value) < 16) {
        return new WP_Error('too_short', __(
            'That does not look like an application password. WordPress application passwords are at least 16 characters long.',
            domain: 'elementor-mcp',
        ));
    }
    return $value;
}

/**
 * Handle the create-password form submission.
 * Returns the plaintext password on success, a WP_Error on failure, or null when no submission.
 *
 * @return string|WP_Error|null
 */
function elementor_mcp_handle_create_password()
{
    if (($_POST['elementor_mcp_create_password'] ?? null) === null) {
        return null;
    }

    if (!elementor_mcp_current_user_can_manage()) {
        return new WP_Error('forbidden', __(
            'You do not have permission to create application passwords.',
            domain: 'elementor-mcp',
        ));
    }

    check_admin_referer('elementor_mcp_create_password');

    $status = elementor_mcp_app_passwords_status();
    if (!$status['available']) {
        return new WP_Error('not_available', $status['message']);
    }

    $user_id = get_current_user_id();
    $raw_name = $_POST['elementor_mcp_password_name'] ?? '';
    $input_name = is_string($raw_name) ? trim($raw_name) : '';
    $app_name = $input_name !== '' ? 'Elementor MCP: ' . $input_name : 'Elementor MCP';

    // Avoid duplicate names — append a counter if one already exists.
    $existing = WP_Application_Passwords::get_user_application_passwords($user_id);
    $names = array_column($existing, 'name');
    if (in_array(needle: $app_name, haystack: $names, strict: true)) {
        $i = 2;
        while (in_array(needle: $app_name . ' ' . $i, haystack: $names, strict: true)) {
            $i++;
        }
        $app_name = $app_name . ' ' . $i;
    }

    $result = WP_Application_Passwords::create_new_application_password($user_id, ['name' => $app_name]);

    if (is_wp_error($result)) {
        return $result;
    }

    // $result[0] is the plaintext password.
    return $result[0];
}

/**
 * Handle the revoke-password form submission. Redirects on success.
 * Called from admin_init so headers have not been sent yet.
 */
function elementor_mcp_handle_revoke_password(): void
{
    if (($_POST['elementor_mcp_revoke_password'] ?? null) === null) {
        return;
    }

    if (!elementor_mcp_current_user_can_manage()) {
        return;
    }

    $uuid = $_POST['elementor_mcp_revoke_uuid'] ?? '';
    if (!is_string($uuid) || $uuid === '') {
        return;
    }

    check_admin_referer('elementor_mcp_revoke_password_' . $uuid);

    $user_id = get_current_user_id();
    WP_Application_Passwords::delete_application_password($user_id, $uuid);

    wp_safe_redirect(admin_url('admin.php?page=elementor-mcp-connect&elementor_mcp_result=revoked'));
    exit();
}

/**
 * Return all application passwords for the current user whose name begins with "Elementor MCP".
 *
 * @return array<int, array<string, mixed>>
 */
function elementor_mcp_get_mcp_passwords(): array
{
    $user_id = get_current_user_id();
    $all = WP_Application_Passwords::get_user_application_passwords($user_id);
    return array_values(array_filter($all, static fn($item) => str_starts_with($item['name'], 'Elementor MCP')));
}

/**
 * Whether the app-password method is pre-selected on load: true when a password action happened
 * this request (a new or existing password, or an error to surface). OAuth is never pre-selected;
 * the user picks it. Shared by the chooser and the step 3 section so both agree on the initial
 * panel visibility.
 */
function elementor_mcp_password_method_preselected(
    ?string $new_password,
    ?string $existing_password,
    ?WP_Error $existing_error,
): bool {
    return $new_password !== null || $existing_password !== null || $existing_error !== null;
}

/**
 * Render a single password row for the passwords table.
 *
 * @param array<string, mixed> $pw        Password item from WP_Application_Passwords.
 * @param string               $dt_format Date/time format string.
 */
function elementor_mcp_render_password_row(array $pw, string $dt_format): void
{
    $uuid = (string) ($pw['uuid'] ?? '');
    $name = (string) ($pw['name'] ?? '');
    $created_date = ($pw['created'] ?? null) !== null ? wp_date($dt_format, (int) $pw['created']) : false;
    $created = $created_date !== false ? $created_date : __('Unknown', domain: 'elementor-mcp');
    $last_used_date = ($pw['last_used'] ?? null) !== null ? wp_date($dt_format, (int) $pw['last_used']) : false;
    $last_used = $last_used_date !== false ? $last_used_date : __('Never', domain: 'elementor-mcp');
    $revoke_nonce = (string) wp_create_nonce('elementor_mcp_revoke_password_' . $uuid);
    ?>
    <tr>
        <td><strong><?php echo esc_html($name); ?></strong></td>
        <td><?php echo esc_html($created); ?></td>
        <td><?php echo esc_html($last_used); ?></td>
        <td>
            <form method="post" style="margin:0;" onsubmit="return confirm('<?php echo
                esc_js(__('Revoke this password? Any clients using it will lose access.', domain: 'elementor-mcp'))
            ; ?>');">
                <input type="hidden" name="elementor_mcp_revoke_uuid" value="<?php echo esc_attr($uuid); ?>" />
                <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($revoke_nonce); ?>" />
                <button type="submit" name="elementor_mcp_revoke_password" class="button button-small elementor-mcp-revoke-btn"><?php esc_html_e(
                    'Revoke',
                    domain: 'elementor-mcp',
                ); ?></button>
            </form>
        </td>
    </tr>
    <?php
}

/**
 * Render the "Step 2 — Application Password" card.
 *
 * Just the generate button (with a collapsible name input) and a success notice after generation.
 * The list of existing passwords lives in the separate manage section at the bottom of the page.
 */
// Complexity is inherent: this is a single HTML template whose branches (password availability,
// newly generated vs. pasted vs. no password, has-existing toggles, error notices) each gate a
// distinct piece of inline markup. Splitting them into helpers would fragment one cohesive view.
// @mago-expect lint:cyclomatic-complexity
function elementor_mcp_render_password_step(
    ?string $new_password,
    ?string $existing_password = null,
    ?WP_Error $existing_error = null,
): void {
    $pw_status = elementor_mcp_app_passwords_status();
    $has_existing = elementor_mcp_get_mcp_passwords() !== [];
    $existing_section_open = $existing_password !== null || $existing_error !== null;
    ?>
    <p class="description" style="margin:0 0 12px;">
        <?php esc_html_e(
            'Generate an application password that your AI client will use to authenticate with WordPress. The password is embedded into the connection text in step 3.',
            domain: 'elementor-mcp',
        ); ?>
    </p>

    <?php if (!$pw_status['available']): ?>
        <div class="notice notice-error inline" style="margin:12px 0 16px;">
            <p><strong><?php echo esc_html($pw_status['message']); ?></strong></p>
            <?php if ($pw_status['reason'] === 'unsupported' && elementor_mcp_likely_local_http()): ?>
                <p style="margin:8px 0 0;">
                    <?php esc_html_e(
                        'This site is on a local hostname over HTTP. Add this line to your wp-config.php (above the "/* That\'s all" comment), then reload:',
                        domain: 'elementor-mcp',
                    ); ?>
                </p>
                <pre style="background:#f4f7fa; border:1px solid #d7e0ea; padding:10px 12px; margin:6px 0 0; font-size:13px; border-radius:8px;">define('WP_ENVIRONMENT_TYPE', 'local');</pre>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($new_password !== null): ?>
        <div class="notice notice-success inline" style="margin:8px 0 16px;">
            <p style="margin:0 0 8px;"><?php esc_html_e(
                'Application password generated. It is now embedded in the connection text in step 3. Save it somewhere safe: it will not be shown in full again.',
                domain: 'elementor-mcp',
            ); ?></p>
            <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                <code id="elementor-mcp-new-pw-value" style="font-size:14px; font-weight:600; padding:6px 10px; background:#fff; border:1px solid #d7e0ea; border-radius:8px;"><?php echo
                    esc_html($new_password)
                ; ?></code>
                <button type="button" class="button button-small" onclick="elementorMcpCopy('elementor-mcp-new-pw-value', this)">
                    <?php esc_html_e('Copy password only', domain: 'elementor-mcp'); ?>
                </button>
            </div>
        </div>
    <?php endif; ?>
    <?php if ($new_password === null && $existing_password !== null): ?>
        <div class="notice notice-success inline" style="margin:8px 0 16px;">
            <p style="margin:0;"><?php esc_html_e(
                'Password accepted. It is now embedded in the connection text in step 4.',
                domain: 'elementor-mcp',
            ); ?></p>
        </div>
    <?php endif; ?>

    <form method="post" style="margin: 0;">
        <?php wp_nonce_field('elementor_mcp_create_password'); ?>
        <?php if (!$has_existing): ?>
            <p style="margin:0 0 10px;">
                <button
                    type="button"
                    class="button-link"
                    id="elementor-mcp-password-name-toggle"
                    aria-expanded="false"
                    aria-controls="elementor-mcp-password-name-field"
                    onclick="elementorMcpTogglePasswordName(this)"
                ><?php esc_html_e('Customize password name (optional)', domain: 'elementor-mcp'); ?></button>
            </p>
        <?php endif; ?>
        <div
            id="elementor-mcp-password-name-field"
            <?php echo $has_existing ? '' : 'hidden'; ?>
            style="margin: 0 0 12px; <?php echo $has_existing ? '' : 'display:none;'; ?>"
        >
            <label for="elementor-mcp-password-name" style="display:block; margin-bottom:4px;">
                <strong><?php esc_html_e('Name', domain: 'elementor-mcp'); ?></strong>
            </label>
            <input
                type="text"
                id="elementor-mcp-password-name"
                name="elementor_mcp_password_name"
                placeholder="<?php esc_attr_e('e.g. Cursor on laptop, Claude Desktop', domain: 'elementor-mcp'); ?>"
                style="width:300px;"
                class="regular-text"
                maxlength="70"
            />
            <p class="description" style="margin-top:4px;">
                <?php esc_html_e(
                    'A label to identify this credential later. Leave blank to use "Elementor MCP".',
                    domain: 'elementor-mcp',
                ); ?>
            </p>
        </div>
        <button
            type="submit"
            name="elementor_mcp_create_password"
            class="button button-primary"
            <?php echo !$pw_status['available'] ? 'disabled' : ''; ?>>
            <?php echo
                $has_existing
                    ? esc_html__('Generate another application password', domain: 'elementor-mcp')
                    : esc_html__('Generate application password', domain: 'elementor-mcp')
            ; ?>
        </button>
    </form>

    <p style="margin:14px 0 4px;">
        <button
            type="button"
            class="button-link"
            id="elementor-mcp-use-existing-toggle"
            aria-expanded="<?php echo $existing_section_open ? 'true' : 'false'; ?>"
            aria-controls="elementor-mcp-use-existing-field"
            onclick="elementorMcpToggleUseExisting(this)"
        ><?php esc_html_e('I already have an application password', domain: 'elementor-mcp'); ?></button>
    </p>
    <div
        id="elementor-mcp-use-existing-field"
        <?php echo $existing_section_open ? '' : 'hidden'; ?>
        style="margin:6px 0 0; <?php echo $existing_section_open ? '' : 'display:none;'; ?>"
    >
        <form method="post" style="margin:0;">
            <?php wp_nonce_field('elementor_mcp_use_existing_password'); ?>
            <label for="elementor-mcp-existing-password" style="display:block; margin-bottom:4px;">
                <strong><?php esc_html_e('Paste the password value', domain: 'elementor-mcp'); ?></strong>
            </label>
            <input
                type="text"
                id="elementor-mcp-existing-password"
                name="elementor_mcp_existing_password"
                placeholder="xxxx xxxx xxxx xxxx xxxx xxxx"
                style="width:340px; font-family:monospace;"
                class="regular-text"
                autocomplete="off"
            />
            <button type="submit" name="elementor_mcp_use_existing_password" class="button">
                <?php esc_html_e('Use this password', domain: 'elementor-mcp'); ?>
            </button>
            <?php if ($existing_error !== null): ?>
                <div class="notice notice-error inline" style="margin:8px 0 0;">
                    <p style="margin:0;"><?php echo esc_html($existing_error->get_error_message()); ?></p>
                </div>
            <?php endif; ?>
            <p class="description" style="margin-top:4px;">
                <?php esc_html_e(
                    'For reusing an application password you already saved (e.g. from a password manager). It is used only to fill the connection text and never stored on this site.',
                    domain: 'elementor-mcp',
                ); ?>
            </p>
        </form>
    </div>
    <?php
}

/**
 * Render the "Manage existing application passwords" collapsible section at the bottom of the page.
 *
 * Only meaningful when at least one Elementor MCP-tagged password exists. Hosts the list with revoke
 * buttons. Used both when AI Abilities are enabled (revoke + create lives elsewhere) and when
 * disabled (revoke only).
 */
function elementor_mcp_render_manage_passwords_section(string $context = 'enabled'): void
{
    $mcp_passwords = elementor_mcp_get_mcp_passwords();
    if ($mcp_passwords === []) {
        return;
    }

    $dt_format = elementor_mcp_get_datetime_format('Y-m-d H:i');
    $count = count($mcp_passwords);
    $open_by_default = $count <= 3;
    $summary = sprintf(
        /* translators: %d: count of existing application passwords */
        _n(
            single: 'Manage existing application password (%d)',
            plural: 'Manage existing application passwords (%d)',
            number: $count,
            domain: 'elementor-mcp',
        ),
        $count,
    );
    ?>
    <details class="elementor-mcp-manage-passwords"<?php echo $open_by_default ? ' open' : ''; ?>>
        <summary class="elementor-mcp-manage-passwords-summary">
            <?php echo esc_html($summary); ?>
        </summary>
        <div class="elementor-mcp-manage-passwords-body">
            <?php if ($context === 'disabled'): ?>
                <p class="description" style="margin:0 0 12px;">
                    <?php esc_html_e(
                        'AI Abilities are disabled. These credentials remain valid for WordPress authentication, but the Elementor MCP MCP endpoint will reject requests until AI Abilities are turned back on.',
                        domain: 'elementor-mcp',
                    ); ?>
                </p>
            <?php endif; ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Name', domain: 'elementor-mcp'); ?></th>
                        <th style="width:180px;"><?php esc_html_e('Created', domain: 'elementor-mcp'); ?></th>
                        <th style="width:180px;"><?php esc_html_e('Last Used', domain: 'elementor-mcp'); ?></th>
                        <th style="width:80px;"><?php esc_html_e('Actions', domain: 'elementor-mcp'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($mcp_passwords as $pw): ?>
                        <?php elementor_mcp_render_password_row($pw, $dt_format); ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </details>
    <?php
}

/**
 * Informational notice above the connect prompt: pasting the prompt hands the
 * application password to the AI agent. Links to the manual configuration,
 * which reaches the same result without exposing the password to the AI.
 */
function elementor_mcp_render_prompt_password_notice(): void
{ ?>
    <div id="elementor-mcp-prompt-password-notice" class="notice notice-info inline" style="margin:0 0 12px;">
        <p style="margin:0;">
            <strong><?php esc_html_e(
                'This prompt shares your application password with your AI agent.',
                domain: 'elementor-mcp',
            ); ?></strong>
            <?php printf(
                /* translators: %s: link that opens the manual configuration section */
                esc_html__(
                    'Prefer to keep it private? Use the %s and paste the snippet into the config file yourself.',
                    domain: 'elementor-mcp',
                ),
                '<button type="button" class="button-link" onclick="elementorMcpOpenManualConfig()">'
                . esc_html__('manual configuration', domain: 'elementor-mcp')
                . '</button>',
            ); ?>
        </p>
    </div>
    <?php }

<?php

// SPDX-FileCopyrightText: 2026 Elementor MCP <dev@elementormcp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

// phpcs:disable WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- Every state-changing request on this screen verifies a nonce via check_admin_referer() before acting; the sniff cannot trace that across function boundaries. Reads are type-checked, whitelist-compared, and escaped on output.

/**
 * Configuration page: the Access token connection method.
 *
 * Minting, listing, and revoking the long-lived bearer tokens that let a caller
 * with no browser and no interactive session reach this site's MCP endpoint. The
 * secret is shown once, at creation, and only its digest is kept.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Expiry choices offered when minting, in days. 0 is "no expiry".
 *
 * @return array<int, string>
 */
function elementor_mcp_token_expiry_choices(): array
{
    return [
        30 => __('30 days', domain: 'elementor-mcp'),
        90 => __('90 days', domain: 'elementor-mcp'),
        365 => __('1 year', domain: 'elementor-mcp'),
        0 => __('No expiry', domain: 'elementor-mcp'),
    ];
}

/**
 * Handle the create-token form submission.
 *
 * Returns the plaintext secret on success, a WP_Error on failure, or null when
 * there was no submission.
 *
 * @return string|WP_Error|null
 */
function elementor_mcp_handle_create_token()
{
    if (($_POST['elementor_mcp_create_token'] ?? null) === null) {
        return null;
    }

    if (!elementor_mcp_current_user_can_manage()) {
        return new WP_Error('forbidden', __('You do not have permission to create access tokens.', domain: 'elementor-mcp'));
    }

    check_admin_referer('elementor_mcp_create_token');

    $raw_name = $_POST['elementor_mcp_token_name'] ?? '';
    $name = is_string($raw_name) ? trim($raw_name) : '';

    $raw_ttl = $_POST['elementor_mcp_token_ttl'] ?? '';
    $ttl = is_string($raw_ttl) || is_int($raw_ttl) ? (int) $raw_ttl : 0;
    // Whitelisted rather than clamped: an arbitrary posted number would let the
    // form mint a token with a lifetime the screen never offered.
    if (!array_key_exists($ttl, elementor_mcp_token_expiry_choices())) {
        $ttl = 90;
    }

    $created = elementor_mcp_token_create(get_current_user_id(), $name, $ttl);
    if ($created instanceof WP_Error) {
        return $created;
    }

    return $created['secret'];
}

/**
 * Handle the revoke-token form submission. Redirects on success.
 *
 * Called from admin_init, so headers have not been sent yet.
 */
function elementor_mcp_handle_revoke_token(): void
{
    if (($_POST['elementor_mcp_revoke_token'] ?? null) === null) {
        return;
    }

    if (!elementor_mcp_current_user_can_manage()) {
        return;
    }

    $raw_id = $_POST['elementor_mcp_revoke_token_id'] ?? '';
    $token_id = is_string($raw_id) || is_int($raw_id) ? (int) $raw_id : 0;
    if ($token_id <= 0) {
        return;
    }

    check_admin_referer('elementor_mcp_revoke_token_' . $token_id);

    elementor_mcp_token_revoke($token_id, get_current_user_id());

    wp_safe_redirect(admin_url('admin.php?page=elementor-mcp-connect&elementor_mcp_result=token_revoked'));
    exit();
}

/**
 * Whether the access-token method is pre-selected on load.
 *
 * True only when something happened on this method during this request, matching
 * how the application-password panel decides. A token that merely exists does not
 * pre-select the method: OAuth stays the recommendation.
 */
function elementor_mcp_token_method_preselected(?string $new_token, ?WP_Error $token_error): bool
{
    return $new_token !== null || $token_error !== null;
}

/**
 * Render the access-token method panel: what it is for, the mint form, and the
 * one-time reveal of a freshly created secret.
 */
function elementor_mcp_render_token_step(?string $new_token, ?WP_Error $token_error = null): void
{
    $has_tokens = elementor_mcp_tokens_for_user(get_current_user_id()) !== [];
    ?>
    <p class="description" style="margin:0 0 12px;">
        <?php esc_html_e(
            'A long-lived bearer token for callers that cannot sign in through a browser: the Claude Messages API MCP connector, the OpenAI Responses API, a cron job, an automation platform, or any client that takes a URL and one header.',
            domain: 'elementor-mcp',
        ); ?>
    </p>

    <?php if ($token_error !== null): ?>
        <div class="notice notice-error inline" style="margin:8px 0 16px;">
            <p style="margin:0;"><?php echo esc_html($token_error->get_error_message()); ?></p>
        </div>
    <?php endif; ?>

    <?php if ($new_token !== null): ?>
        <div class="notice notice-success inline" id="elementor-mcp-token-created" style="margin:8px 0 16px;">
            <p style="margin:0 0 8px;"><strong><?php esc_html_e(
                'Copy this token now. It is shown once and cannot be recovered — only its digest is stored.',
                domain: 'elementor-mcp',
            ); ?></strong></p>
            <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                <code id="elementor-mcp-new-token-value" style="font-size:13px; font-weight:600; padding:6px 10px; background:#fff; border:1px solid #d7e0ea; border-radius:8px; word-break:break-all;"><?php echo
                    esc_html($new_token)
                ; ?></code>
                <button type="button" class="button button-small" onclick="elementorMcpCopy('elementor-mcp-new-token-value', this)">
                    <?php esc_html_e('Copy token', domain: 'elementor-mcp'); ?>
                </button>
            </div>
            <p style="margin:8px 0 0; color:#d63638; font-size:13px;">
                <?php esc_html_e(
                    'Anyone holding this token has the same access to this site as your account. Store it in a secret manager, never in a repository.',
                    domain: 'elementor-mcp',
                ); ?>
            </p>
        </div>

        <?php

        /*
         * What to do with the token, said here rather than left to be found.
         *
         * The configuration for every client is generated further down the page,
         * and a token is only shown once — so someone who copies the value,
         * closes the page and goes looking for instructions has already lost the
         * one thing they needed. The three steps and the jump link are what turn
         * the reveal into the start of a setup rather than the end of a form.
         */
        ?>
        <div class="elementor-mcp-token-next" style="margin:0 0 20px; padding:14px 16px; border:1px solid #d7e0ea; border-left:4px solid #1c4ea1; border-radius:10px; background:#fff;">
            <p style="margin:0 0 8px;"><strong><?php esc_html_e(
                'How to connect with this token',
                domain: 'elementor-mcp',
            ); ?></strong></p>
            <ol style="margin:0 0 10px 18px; padding:0;">
                <li><?php printf(
                    /* translators: %s: the MCP endpoint URL, in a <code> tag */
                    esc_html__('Point your client at %s.', domain: 'elementor-mcp'),
                    '<code>' . esc_html(rest_url('mcp/elementor-mcp')) . '</code>',
                ); ?></li>
                <li><?php printf(
                    /* translators: %s: the Authorization header, in a <code> tag */
                    esc_html__('Send the token as %s on every request.', domain: 'elementor-mcp'),
                    '<code>Authorization: Bearer ' . esc_html($new_token) . '</code>',
                ); ?></li>
                <li><?php esc_html_e(
                    'Restart the client. Most read their configuration only at startup.',
                    domain: 'elementor-mcp',
                ); ?></li>
            </ol>
            <p style="margin:0;">
                <button type="button" class="button button-primary" onclick="elementorMcpJumpToTokenSnippets()"><?php esc_html_e(
                    'Show the ready-made configuration for my client',
                    domain: 'elementor-mcp',
                ); ?></button>
                <span class="description" style="margin-left:8px;"><?php esc_html_e(
                    'Claude Code, Claude Desktop, Codex, Cursor, VS Code, the Claude and OpenAI APIs, curl, and more — each with the token already filled in.',
                    domain: 'elementor-mcp',
                ); ?></span>
            </p>
        </div>
    <?php endif; ?>

    <form method="post" style="margin:0;">
        <?php wp_nonce_field('elementor_mcp_create_token'); ?>
        <div style="display:flex; gap:16px; flex-wrap:wrap; align-items:flex-end; margin:0 0 12px;">
            <div>
                <label for="elementor-mcp-token-name" style="display:block; margin-bottom:4px;">
                    <strong><?php esc_html_e('Name', domain: 'elementor-mcp'); ?></strong>
                </label>
                <input
                    type="text"
                    id="elementor-mcp-token-name"
                    name="elementor_mcp_token_name"
                    placeholder="<?php esc_attr_e('e.g. Messages API, nightly audit job', domain: 'elementor-mcp'); ?>"
                    style="width:300px;"
                    class="regular-text"
                    maxlength="70"
                />
            </div>
            <div>
                <label for="elementor-mcp-token-ttl" style="display:block; margin-bottom:4px;">
                    <strong><?php esc_html_e('Expires', domain: 'elementor-mcp'); ?></strong>
                </label>
                <select id="elementor-mcp-token-ttl" name="elementor_mcp_token_ttl">
                    <?php foreach (elementor_mcp_token_expiry_choices() as $days => $label): ?>
                        <option value="<?php echo esc_attr((string) $days); ?>"<?php echo
                            $days === 90 ? ' selected' : ''
                        ; ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <button type="submit" name="elementor_mcp_create_token" class="button button-primary">
            <?php echo
                $has_tokens
                    ? esc_html__('Create another access token', domain: 'elementor-mcp')
                    : esc_html__('Create access token', domain: 'elementor-mcp')
            ; ?>
        </button>
    </form>
    <?php
}

/**
 * Render the "Manage access tokens" collapsible section.
 *
 * Shown only when the current user holds at least one. Tokens are per-user by
 * design — the digest is bound to the account whose capabilities it borrows — so
 * this lists the current user's, exactly as the application-password section does.
 */
function elementor_mcp_render_manage_tokens_section(): void
{
    $tokens = elementor_mcp_tokens_for_user(get_current_user_id());
    if ($tokens === []) {
        return;
    }

    $dt_format = elementor_mcp_get_datetime_format('Y-m-d H:i');
    $count = count($tokens);
    $summary = sprintf(
        /* translators: %d: count of existing access tokens */
        _n(single: 'Manage access token (%d)', plural: 'Manage access tokens (%d)', number: $count, domain: 'elementor-mcp'),
        $count,
    );
    ?>
    <details class="elementor-mcp-manage-passwords"<?php echo $count <= 3 ? ' open' : ''; ?>>
        <summary class="elementor-mcp-manage-passwords-summary"><?php echo esc_html($summary); ?></summary>
        <div class="elementor-mcp-manage-passwords-body">
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Name', domain: 'elementor-mcp'); ?></th>
                        <th style="width:110px;"><?php esc_html_e('Token', domain: 'elementor-mcp'); ?></th>
                        <th style="width:150px;"><?php esc_html_e('Created', domain: 'elementor-mcp'); ?></th>
                        <th style="width:150px;"><?php esc_html_e('Last Used', domain: 'elementor-mcp'); ?></th>
                        <th style="width:150px;"><?php esc_html_e('Expires', domain: 'elementor-mcp'); ?></th>
                        <th style="width:80px;"><?php esc_html_e('Actions', domain: 'elementor-mcp'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tokens as $token): ?>
                        <?php elementor_mcp_render_token_row($token, $dt_format); ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </details>
    <?php
}

/**
 * Render the client picker and config snippet for the access-token method.
 *
 * Every element id here is namespaced `elementor-mcp-token-*`: the application-password
 * panel renders its own tab strip and code block on the same page, and a shared id
 * would make one panel's copy button read the other panel's snippet.
 */
function elementor_mcp_render_token_config_section(string $url, ?string $token): void
{
    $default_name = elementor_mcp_get_mcp_server_name_default();
    $name_placeholder = '__ELEMENTOR_MCP_MCP_NAME__';
    $display_token = $token ?? ELEMENTOR_MCP_TOKEN_PLACEHOLDER;
    $token_is_placeholder = $token === null;
    $configs = elementor_mcp_build_token_configs($url, $name_placeholder, $display_token);

    // Labels come from the client registry so a client added there reaches this
    // strip too, with the API-side callers appended — they are not clients and
    // have no registry entry, but from here they are the same question.
    $labels = [];
    foreach (elementor_mcp_selectable_clients() as $key => $client) {
        if (array_key_exists((string) $key, $configs)) {
            $labels[(string) $key] = (string) ($client['label'] ?? $key);
        }
    }
    foreach (elementor_mcp_token_api_client_labels() as $key => $label) {
        if (array_key_exists($key, $configs)) {
            $labels[$key] = $label;
        }
    }

    $copied_label = __('Copied!', domain: 'elementor-mcp');
    ?>
    <h2 class="elementor-mcp-step-heading" id="elementor-mcp-token-snippets">
        <span class="elementor-mcp-step-badge">2</span>
        <?php esc_html_e('Connect Your AI Client', domain: 'elementor-mcp'); ?>
    </h2>

    <?php if ($token_is_placeholder): ?>
        <div class="notice notice-warning inline" style="margin:12px 0;">
            <p style="margin:0;">
                <strong><?php esc_html_e('These snippets are not ready to use yet.', domain: 'elementor-mcp'); ?></strong>
                <?php esc_html_e(
                    'They carry a placeholder because no access token has been created. Create one above and every snippet fills itself in.',
                    domain: 'elementor-mcp',
                ); ?>
            </p>
        </div>
    <?php endif; ?>

    <div class="elementor-mcp-client-tabs" style="gap:8px; margin-top:16px; margin-bottom:0;">
    <?php foreach ($labels as $key => $label): ?>
        <button
            type="button"
            class="elementor-mcp-client-tab elementor-mcp-token-client-tab"
            data-client="<?php echo esc_attr($key); ?>"
            onclick="elementorMcpSetTokenClient('<?php echo esc_js($key); ?>', this)"
        ><?php echo esc_html($label); ?></button>
    <?php endforeach; ?>
    </div>

    <div id="elementor-mcp-token-connect-content" style="display:none; margin-top:16px;">
        <ol id="elementor-mcp-token-config-steps" style="display:none; margin:0 0 14px 20px; padding:0;"></ol>

        <div class="elementor-mcp-tab-content" id="elementor-mcp-token-config-tab" style="border-radius:4px;">
            <div class="elementor-mcp-config-block">
                <pre id="elementor-mcp-token-config-code"></pre>
                <button
                    type="button"
                    class="button elementor-mcp-copy-btn"
                    onclick="elementorMcpCopy('elementor-mcp-token-config-code', this)"
                ><?php esc_html_e('Copy', domain: 'elementor-mcp'); ?></button>
            </div>
            <div id="elementor-mcp-token-config-footer" style="font-size:13px; color:#5e6c7d; border-top:1px solid #d7e0ea;">
                <div id="elementor-mcp-token-config-merge-note" style="padding:10px 16px 0;">
                    <?php esc_html_e(
                        'If your config file already has content, merge this into it instead of replacing it.',
                        domain: 'elementor-mcp',
                    ); ?>
                </div>
                <div id="elementor-mcp-token-config-hint" style="padding:10px 16px;"></div>
                <div id="elementor-mcp-token-config-paths" style="padding:0 16px 10px;"></div>
            </div>
        </div>

        <?php elementor_mcp_render_agent_prompt_block('elementor-mcp-token', method: 'token'); ?>

        <p style="margin:10px 0 0;">
            <label for="elementor-mcp-token-mcp-name"><?php esc_html_e('Server name', domain: 'elementor-mcp'); ?></label>
            <input
                type="text"
                id="elementor-mcp-token-mcp-name"
                value="<?php echo esc_attr($default_name); ?>"
                maxlength="25"
                style="width:220px; margin-left:6px;"
                oninput="elementorMcpUpdateTokenName(this.value)"
            >
        </p>
    </div>

    <?php // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Every value emitted in this block goes through elementor_mcp_script_json(), which hex-escapes <, >, & and quotes for <script> context. ?>
    <script>
    (function () {
        var configs = <?php echo elementor_mcp_script_json($configs); ?>;
        var namePlaceholder = <?php echo elementor_mcp_script_json($name_placeholder); ?>;
        var defaultName = <?php echo elementor_mcp_script_json($default_name); ?>;
        var placeholderToken = <?php echo elementor_mcp_script_json(ELEMENTOR_MCP_TOKEN_PLACEHOLDER); ?>;
        var isPlaceholder = <?php echo elementor_mcp_script_json($token_is_placeholder); ?>;
        var mcpName = defaultName;
        var client = '';
        var labels = <?php echo elementor_mcp_script_json($labels); ?>;
        var endpointUrl = <?php echo elementor_mcp_script_json($url); ?>;
        var authLine = <?php echo elementor_mcp_script_json(elementor_mcp_agent_prompt_auth_line('token')); ?>;
        var tokenNotes = <?php echo elementor_mcp_script_json(elementor_mcp_agent_prompt_token_notes()); ?>;

        function renderSteps(steps) {
            var list = document.getElementById('elementor-mcp-token-config-steps');
            list.innerHTML = '';
            steps.forEach(function (step) {
                var li = document.createElement('li');
                li.style.margin = '0 0 6px';
                // A step that is nothing but a URL, a token or a header value is
                // something to copy rather than something to read, so it is set
                // as code — which also stops a long token wrapping mid-word.
                if (/^(https?:\/\/|emcp_|Bearer )/.test(step)) {
                    var code = document.createElement('code');
                    code.style.wordBreak = 'break-all';
                    code.textContent = step;
                    li.appendChild(code);
                } else {
                    li.textContent = step;
                }
                list.appendChild(li);
            });
            list.style.display = '';
        }

        function render() {
            if (!client || !configs[client]) { return; }
            var cfg = configs[client];

            // A hosted web UI has no file to write, so it shows the click-through
            // steps instead of a snippet. Everything below the fork — the hint,
            // the agent prompt — is common to both.
            var hasSteps = !!(cfg.steps && cfg.steps.length);
            document.getElementById('elementor-mcp-token-config-tab').style.display = hasSteps ? 'none' : '';
            document.getElementById('elementor-mcp-token-config-steps').style.display = hasSteps ? '' : 'none';
            if (hasSteps) {
                renderSteps(cfg.steps);
                document.getElementById('elementor-mcp-token-config-hint').innerHTML = cfg.hint;
                window.elementorMcpAgentPrompt('elementor-mcp-token', {
                    clientLabel: labels[client] || client,
                    serverName: mcpName,
                    url: endpointUrl,
                    authLine: authLine,
                    code: '',
                    paths: {},
                    isShell: false,
                    steps: cfg.steps,
                    hasSecret: true,
                    notes: tokenNotes
                });
                return;
            }

            var codeEl = document.getElementById('elementor-mcp-token-config-code');
            codeEl.textContent = cfg.code.split(namePlaceholder).join(mcpName);
            if (isPlaceholder) {
                codeEl.innerHTML = codeEl.innerHTML.split(placeholderToken).join(
                    '<span class="elementor-mcp-placeholder">' + placeholderToken + '</span>'
                );
            }
            document.getElementById('elementor-mcp-token-config-hint').innerHTML = cfg.hint;

            var mergeNote = document.getElementById('elementor-mcp-token-config-merge-note');
            if (mergeNote) { mergeNote.style.display = cfg.isShell ? 'none' : ''; }

            // Same values the snippet above was just built from, so the prompt
            // cannot describe a different client than the one on screen.
            window.elementorMcpAgentPrompt('elementor-mcp-token', {
                clientLabel: labels[client] || client,
                serverName: mcpName,
                url: endpointUrl,
                authLine: authLine,
                code: cfg.code.split(namePlaceholder).join(mcpName),
                paths: cfg.paths,
                isShell: cfg.isShell,
                hasSecret: true,
                notes: tokenNotes
            });

            var pathsEl = document.getElementById('elementor-mcp-token-config-paths');
            var keys = Object.keys(cfg.paths);
            if (keys.length === 0) {
                pathsEl.innerHTML = '';
                pathsEl.style.display = 'none';
                return;
            }
            var html = '<ul style="margin:4px 0 0; padding-left:20px;">';
            keys.forEach(function (label) {
                html += '<li><strong>' + label + '</strong>: <code>' + cfg.paths[label] + '</code></li>';
            });
            pathsEl.innerHTML = html + '</ul>';
            pathsEl.style.display = '';
        }

        window.elementorMcpSetTokenClient = function (key, btn) {
            client = key;
            document.querySelectorAll('.elementor-mcp-token-client-tab').forEach(function (tab) {
                tab.classList.remove('active');
            });
            btn.classList.add('active');
            var content = document.getElementById('elementor-mcp-token-connect-content');
            if (content) { content.style.display = ''; }
            render();
        };

        window.elementorMcpUpdateTokenName = function (value) {
            mcpName = value.trim() || defaultName;
            render();
        };

        // Open the snippet area on the first client rather than waiting for a
        // click. An empty panel under a "Connect Your AI Client" heading reads as
        // "nothing here", which is how the configuration went unnoticed.
        var first = document.querySelector('.elementor-mcp-token-client-tab');
        if (first) {
            window.elementorMcpSetTokenClient(first.getAttribute('data-client'), first);
        }

        // Called from the reveal box above, which is rendered in a different
        // container and cannot reach this scope any other way.
        window.elementorMcpJumpToTokenSnippets = function () {
            var heading = document.getElementById('elementor-mcp-token-snippets');
            if (heading) {
                heading.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        };
    }());
    </script>
    <?php // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped ?>
    <?php
}

/**
 * Render one row of the access-token table.
 *
 * @param array{id: int, name: string, last_four: string, created: string, last_used: string, expires: string} $token
 */
function elementor_mcp_render_token_row(array $token, string $dt_format): void
{
    $revoke_nonce = (string) wp_create_nonce('elementor_mcp_revoke_token_' . $token['id']);
    $format = static function (string $stored, string $empty) use ($dt_format): string {
        if ($stored === '') {
            return $empty;
        }
        $timestamp = strtotime($stored . ' UTC');
        if ($timestamp === false) {
            return $empty;
        }
        $formatted = wp_date($dt_format, $timestamp);

        return $formatted !== false ? $formatted : $empty;
    };
    $expires = $token['expires'];
    $has_expired = $expires !== '' && (int) strtotime($expires . ' UTC') < time();
    ?>
    <tr>
        <td><strong><?php echo esc_html($token['name']); ?></strong></td>
        <td class="elementor-mcp-mono">…<?php echo esc_html($token['last_four']); ?></td>
        <td><?php echo esc_html($format($token['created'], __('Unknown', domain: 'elementor-mcp'))); ?></td>
        <td><?php echo esc_html($format($token['last_used'], __('Never', domain: 'elementor-mcp'))); ?></td>
        <td>
            <?php echo esc_html($format($expires, __('Never', domain: 'elementor-mcp'))); ?>
            <?php if ($has_expired): ?>
                <strong><?php esc_html_e('(expired)', domain: 'elementor-mcp'); ?></strong>
            <?php endif; ?>
        </td>
        <td>
            <form method="post" style="margin:0;" onsubmit="return confirm('<?php echo
                esc_js(__('Revoke this token? Any caller using it will lose access.', domain: 'elementor-mcp'))
            ; ?>');">
                <input type="hidden" name="elementor_mcp_revoke_token_id" value="<?php echo
                    esc_attr((string) $token['id'])
                ; ?>" />
                <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($revoke_nonce); ?>" />
                <button type="submit" name="elementor_mcp_revoke_token" class="button button-small elementor-mcp-revoke-btn"><?php esc_html_e(
                    'Revoke',
                    domain: 'elementor-mcp',
                ); ?></button>
            </form>
        </td>
    </tr>
    <?php
}

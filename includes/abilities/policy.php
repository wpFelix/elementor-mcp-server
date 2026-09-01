<?php

// SPDX-FileCopyrightText: 2026 Elementor MCP <dev@elementormcp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- Every state-changing request on this screen verifies a nonce via check_admin_referer() before acting; the sniff cannot trace that across function boundaries. Reads are type-checked, whitelist-compared, and escaped on output.

/**
 * Per-ability enable/disable rules.
 *
 * The Abilities Hub writes rules here; the policy filter applies them at
 * execution time. Some abilities are hub-protected and cannot be turned
 * off, or the agent would lose the means to turn anything back on.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Return the persisted per-ability hub rules.
 *
 * @return array<string, array{disabled: bool}>
 */
function elementor_mcp_get_ability_rules(): array
{
    /** @var mixed $stored */
    $stored = get_option('elementor_mcp_ability_rules', default_value: []);
    if (!is_array($stored)) {
        return [];
    }

    $rules = [];
    /** @var mixed $rule */
    foreach ($stored as $ability_name => $rule) {
        if (!is_string($ability_name) || !is_array($rule) || !elementor_mcp_is_valid_ability_name($ability_name)) {
            continue;
        }
        $rules[$ability_name] = [
            'disabled' => in_array($rule['disabled'] ?? false, [true, '1', 1], strict: true),
        ];
    }

    return $rules;
}

/**
 * Persist the per-ability hub rules.
 *
 * @param array<string, array{disabled?: bool}> $rules
 */
function elementor_mcp_update_ability_rules(array $rules): void
{
    $clean = [];
    foreach ($rules as $ability_name => $rule) {
        if (!elementor_mcp_is_valid_ability_name($ability_name)) {
            continue;
        }
        if (!($rule['disabled'] ?? false)) {
            continue;
        }
        $clean[$ability_name] = ['disabled' => true];
    }

    update_option('elementor_mcp_ability_rules', $clean, autoload: false);
}

function elementor_mcp_is_valid_ability_name(string $ability_name): bool
{
    return preg_match('/^[a-z0-9-]+\/[a-z0-9-\/]+$/', $ability_name) === 1;
}

/**
 * Normalize an ability name received from a request before validating it.
 *
 * PHP normally URL-decodes form fields while populating $_POST. Some proxies
 * and security plugins can re-encode a value, leaving the namespacing slash as
 * "%2F". Decode that transport encoding before sanitize_text_field() removes
 * percent-encoded octets altogether. The strict ability-name validator remains
 * the authority on whether the resulting value is accepted.
 */
function elementor_mcp_sanitize_requested_ability_name(string $ability_name): string
{
    return sanitize_text_field(rawurldecode(wp_unslash($ability_name)));
}

/**
 * Ability names kept always-on and out of the abilities screen, alongside the mcp-adapter meta-tools:
 * the skills loader the discovery flow points agents to. Filterable so other infrastructure abilities
 * can opt in.
 *
 * @return list<string>
 */
function elementor_mcp_always_on_ability_names(): array
{
    /** @var list<string> */
    return apply_filters('elementor_mcp_always_on_ability_names', ['elementor-mcp/skill-get']);
}

function elementor_mcp_ability_is_hub_protected(string $ability_name): bool
{
    return (
        str_starts_with($ability_name, 'mcp-adapter/')
        || in_array($ability_name, elementor_mcp_always_on_ability_names(), strict: true)
    );
}

/**
 * Whether the current request is rendering the Abilities Hub admin screen.
 *
 * The Hub manages every ability, disabled ones included, so it must see the
 * full registry; the disable policy is therefore not enforced while it renders.
 */
function elementor_mcp_is_ability_hub_screen(): bool
{
    if (!is_admin()) {
        return false;
    }

    $page = is_string($_GET['page'] ?? null) ? sanitize_key(wp_unslash($_GET['page'])) : '';

    return $page === 'elementor-mcp-abilities';
}

/**
 * Apply persisted Abilities Hub rules after all providers have registered.
 */
function elementor_mcp_apply_ability_policy(): void
{
    if (!function_exists('wp_get_abilities') || !function_exists('wp_unregister_ability')) {
        return;
    }

    // The Hub screen lists disabled abilities with their full metadata, so do not
    // unregister them there. Enforcement still runs on REST/MCP and front-end
    // requests, which is where ability exposure actually matters.
    if (elementor_mcp_is_ability_hub_screen()) {
        return;
    }

    $rules = elementor_mcp_get_ability_rules();

    foreach (wp_get_abilities() as $ability) {
        elementor_mcp_apply_ability_policy_rule($ability, $rules);
    }
}

/**
 * @param array<string, array{disabled: bool}> $rules
 */
function elementor_mcp_apply_ability_policy_rule(WP_Ability $ability, array $rules): void
{
    $ability_name = $ability->get_name();
    $rule = $rules[$ability_name] ?? null;
    $manually_disabled = ($rule['disabled'] ?? false) === true;
    $profile_blocked =
        function_exists('elementor_mcp_safety_profile_allows_ability') && !elementor_mcp_safety_profile_allows_ability($ability);

    if (($manually_disabled || $profile_blocked) && !elementor_mcp_ability_is_hub_protected($ability_name)) {
        wp_unregister_ability($ability_name);
    }
}

/**
 * Turn the MCP adapter's generic "ability not found" result into a clear "switched off" message
 * when the requested ability is one an admin disabled in the Abilities screen. A disabled ability is
 * unregistered, so the adapter cannot otherwise tell it apart from one that was never installed —
 * which leaves an agent following a skill that calls it with a misleading "not found".
 *
 * Filters mcp_adapter_tool_call_result (the execute-ability / get-ability-info result).
 *
 * @param mixed $result The raw tool result.
 * @param mixed $args   The tool arguments; carries ability_name for the ability meta-tools.
 * @return mixed
 */
function elementor_mcp_enrich_disabled_ability_error(mixed $result, mixed $args): mixed
{
    if (!is_array($result) || ($result['success'] ?? null) !== false) {
        return $result;
    }

    $name = is_array($args) && is_string($args['ability_name'] ?? null) ? $args['ability_name'] : '';
    if ($name === '' || ($result['error'] ?? null) !== "Ability '{$name}' not found") {
        return $result;
    }

    $rules = elementor_mcp_get_ability_rules();
    if (($rules[$name]['disabled'] ?? false) !== true) {
        return $result;
    }

    $result['error'] = sprintf(
        /* translators: %s: the ability name, e.g. elementor-mcp/execute-php */
        __(
            "Ability '%s' exists but is switched off in Elementor MCP's AI Abilities settings. Ask the site admin to re-enable it there, then retry.",
            domain: 'elementor-mcp',
        ),
        $name,
    );

    return $result;
}

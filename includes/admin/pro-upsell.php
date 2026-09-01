<?php

// SPDX-FileCopyrightText: 2026 Elementor MCP <dev@elementormcp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

// phpcs:disable WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Every state-changing request on this screen verifies a nonce via check_admin_referer() before acting; the sniff cannot trace that across function boundaries. Reads are type-checked, whitelist-compared, and escaped on output.

/**
 * Elementor MCP Pro upsell: submenu entry, Connect-page card, dismissible welcome notice.
 */

if (!defined('ABSPATH')) {
    exit();
}

const ELEMENTOR_MCP_PRO_URL = 'https://elementormcp.com/pro/';

const ELEMENTOR_MCP_PRO_DISMISS_PREFIX = 'elementor_mcp_pro_dismissed_';

const ELEMENTOR_MCP_PRO_WELCOME_KEY = 'welcome';

/**
 * True when the Elementor MCP Pro plugin is active.
 * License state is irrelevant — if Pro is running, the upsell is.
 */
function elementor_mcp_pro_is_active(): bool
{
    return defined('ELEMENTOR_MCP_PRO_VERSION');
}

/**
 * Third-party plugins and themes that Elementor MCP Pro ships dedicated
 * specializations and skills for. Drives the personalized upsell copy: any of
 * these that is active on the site gets named explicitly.
 *
 * The `category` keys group entries for the generic fallback copy; see
 * elementor_mcp_pro_integration_groups().
 *
 * Each entry declares one of `constant` / `class` / `function`; presence of that symbol means the
 * plugin or theme is active (see elementor_mcp_pro_integration_active()).
 *
 * @return list<array{label: string, category: string, constant?: string, class?: string, function?: string}>
 */
// @mago-expect lint:halstead
function elementor_mcp_pro_integration_catalog(): array
{
    return [
        // Elementor is the only supported page builder. Other integrations
        // below are content, SEO, forms, commerce, or developer surfaces.
        ['label' => 'Elementor', 'category' => 'builder', 'constant' => 'ELEMENTOR_VERSION', 'abilities' => 26],
        // Custom fields and content modeling.
        ['label' => 'Advanced Custom Fields', 'category' => 'content', 'class' => 'ACF', 'abilities' => 23],
        ['label' => 'JetEngine', 'category' => 'content', 'function' => 'jet_engine', 'abilities' => 26],
        ['label' => 'Meta Box', 'category' => 'content', 'constant' => 'RWMB_VER', 'abilities' => 32],
        ['label' => 'Pods', 'category' => 'content', 'constant' => 'PODS_VERSION', 'abilities' => 25],
        ['label' => 'ACPT', 'category' => 'content', 'constant' => 'ACPT_PLUGIN_VERSION', 'abilities' => 24],
        ['label' => 'ASE', 'category' => 'content', 'constant' => 'ASENHA_VERSION', 'abilities' => 18],
        // SEO.
        ['label' => 'Yoast SEO', 'category' => 'seo', 'constant' => 'WPSEO_VERSION', 'abilities' => 10],
        ['label' => 'Rank Math SEO', 'category' => 'seo', 'constant' => 'RANK_MATH_VERSION', 'abilities' => 8],
        ['label' => 'All in One SEO', 'category' => 'seo', 'constant' => 'AIOSEO_VERSION', 'abilities' => 12],
        ['label' => 'SeoPress', 'category' => 'seo', 'constant' => 'SEOPRESS_VERSION', 'abilities' => 16],
        // Forms.
        ['label' => 'Contact Form 7', 'category' => 'forms', 'constant' => 'WPCF7_VERSION', 'abilities' => 9],
        ['label' => 'WPForms', 'category' => 'forms', 'constant' => 'WPFORMS_VERSION', 'abilities' => 28],
        ['label' => 'Gravity Forms', 'category' => 'forms', 'class' => 'GFForms', 'abilities' => 28],
        ['label' => 'Fluent Forms', 'category' => 'forms', 'constant' => 'FLUENTFORM_VERSION', 'abilities' => 37],
        ['label' => 'Formidable Forms', 'category' => 'forms', 'class' => 'FrmAppHelper', 'abilities' => 39],
        ['label' => 'Ninja Forms', 'category' => 'forms', 'class' => 'Ninja_Forms', 'abilities' => 21],
        // Commerce, dev tools, dynamic content.
        ['label' => 'WooCommerce', 'category' => 'commerce', 'class' => 'WooCommerce', 'abilities' => 35],
        ['label' => 'Code Snippets', 'category' => 'dev', 'constant' => 'CODE_SNIPPETS_VERSION', 'abilities' => 11],
        ['label' => 'Dynamic Shortcodes', 'category' => 'dynamic', 'constant' => 'DYNAMIC_SHORTCODES_VERSION', 'abilities' => 9],
    ];
}

/**
 * Whether the integration's plugin or theme is active, detected by the presence of the constant,
 * class, or function its catalog entry declares.
 *
 * @param array{label: string, category: string, constant?: string, class?: string, function?: string} $integration
 */
function elementor_mcp_pro_integration_active(array $integration): bool
{
    $constant = $integration['constant'] ?? '';
    $class = $integration['class'] ?? '';
    $function = $integration['function'] ?? '';

    return (
        $constant !== ''
        && defined($constant)
        || $class !== ''
        && class_exists($class)
        || $function !== ''
        && function_exists($function)
    );
}

/**
 * Total number of Pro integration families, for the marketing copy. Kept as a
 * constant rather than counted from the catalog above: that catalog only lists
 * what this plugin can *detect*, which is a subset of what Pro ships.
 */
const ELEMENTOR_MCP_PRO_INTEGRATION_COUNT = 51;

/**
 * Labels of catalog integrations whose plugin or theme is active on this site,
 * in catalog order.
 *
 * @return list<string>
 */
function elementor_mcp_pro_active_integrations(): array
{
    $active = [];
    foreach (elementor_mcp_pro_integration_catalog() as $integration) {
        if (!elementor_mcp_pro_integration_active($integration)) {
            continue;
        }
        $active[] = $integration['label'];
    }
    return $active;
}

/**
 * The detected integrations that carry an ability count, richest first, as
 * "26 Elementor" style fragments. Concrete numbers make the offer specific:
 * "26 advanced Elementor abilities" lands where "specializations" does not.
 *
 * @return list<string>
 */
function elementor_mcp_pro_active_ability_phrases(int $limit = 3): array
{
    $matches = [];
    foreach (elementor_mcp_pro_integration_catalog() as $integration) {
        if (!elementor_mcp_pro_integration_active($integration)) {
            continue;
        }
        $abilities = $integration['abilities'] ?? 0;
        if (!is_int($abilities) || $abilities < 1) {
            continue;
        }
        $matches[] = ['label' => $integration['label'], 'abilities' => $abilities];
    }

    usort($matches, static fn(array $a, array $b): int => $b['abilities'] <=> $a['abilities']);

    $phrases = [];
    foreach (array_slice($matches, offset: 0, length: $limit) as $match) {
        $phrases[] = sprintf(
            /* translators: 1: number of abilities, 2: plugin name, e.g. "26 Elementor". */
            __('%1$d %2$s', domain: 'elementor-mcp'),
            $match['abilities'],
            $match['label'],
        );
    }
    return $phrases;
}

/**
 * Short, category-level summary of what Pro specializes in, for the generic (no-match) copy:
 * e.g. "page builders, custom fields plugins, SEO plugins, form plugins, and more". Kept brief so
 * the fallback blurb never enumerates all the integrations; the single-entry categories
 * (WooCommerce, Code Snippets, Dynamic Shortcodes) and future specializations fall under "more".
 */
function elementor_mcp_pro_integration_groups(): string
{
    $group_labels = [
        'builder' => __('page builders', domain: 'elementor-mcp'),
        'content' => __('custom fields plugins', domain: 'elementor-mcp'),
        'seo' => __('SEO plugins', domain: 'elementor-mcp'),
        'forms' => __('form plugins', domain: 'elementor-mcp'),
    ];

    $present = [];
    foreach (elementor_mcp_pro_integration_catalog() as $integration) {
        $present[$integration['category']] = true;
    }

    $names = [];
    foreach ($group_labels as $category => $label) {
        if (!($present[$category] ?? false)) {
            continue;
        }
        $names[] = $label;
    }
    $names[] = __('more', domain: 'elementor-mcp');

    return wp_sprintf('%l', $names);
}

/**
 * One-line Pro upsell blurb, shared by the welcome notice and the Connect card.
 * Names the integrations the user already runs; falls back to the full grouped
 * catalog when none are detected.
 */
function elementor_mcp_pro_upsell_blurb(): string
{
    $phrases = elementor_mcp_pro_active_ability_phrases();

    if ($phrases === []) {
        return sprintf(
            /* translators: 1: grouped integration list, 2: total number of Pro integrations. */
            __(
                'Free gives an agent WordPress. Pro gives it your stack: plugin-native abilities for %1$s across %2$d integrations, plus memory that carries between sessions and an approval queue that holds a write until a human says yes.',
                domain: 'elementor-mcp',
            ),
            elementor_mcp_pro_integration_groups(),
            ELEMENTOR_MCP_PRO_INTEGRATION_COUNT,
        );
    }

    return sprintf(
        /* translators: 1: ability counts, e.g. "35 WooCommerce, 26 Elementor and 23 ACF", 2: total Pro integrations. */
        __(
            'This site runs %1$s abilities Pro can add today — typed operations that understand each plugin\'s own data model, not generic content writes. Pro ships %2$d integrations in total, plus memory that carries between sessions and an approval queue that holds a write until a human says yes.',
            domain: 'elementor-mcp',
        ),
        wp_sprintf('%l', $phrases),
        ELEMENTOR_MCP_PRO_INTEGRATION_COUNT,
    );
}

/**
 * Short headline paired with the blurb. Named when we can name something.
 */
function elementor_mcp_pro_upsell_headline(): string
{
    $active = elementor_mcp_pro_active_integrations();
    if ($active === []) {
        return __('Your agent knows WordPress. Not your plugins.', domain: 'elementor-mcp');
    }

    return sprintf(
        /* translators: %s: the first detected plugin name, e.g. "Elementor". */
        __('Your agent can see %s. It cannot speak it yet.', domain: 'elementor-mcp'),
        $active[0],
    );
}

/**
 * Brand palette, shared by the notice and the card so the two never drift.
 *
 * @return array{ink: string, teal: string, copper: string, paper: string, muted: string}
 */
function elementor_mcp_pro_brand(): array
{
    return [
        'ink' => '#142033',
        'teal' => '#1c4ea1',
        'copper' => '#2d6fd2',
        'paper' => '#f4f7fa',
        'muted' => 'rgba(255,255,255,.62)',
    ];
}

/**
 * Append a "Get Pro" submenu entry that links out to elementormcp.com/pro/.
 * Uses the $submenu global because add_submenu_page() doesn't accept external URLs.
 */
add_action(
    'admin_menu',
    static function (): void {
        if (elementor_mcp_pro_is_active()) {
            return;
        }
        // @mago-expect lint:no-global
        global $submenu;
        if (!is_array($submenu) || !is_array($submenu['elementor-mcp-connect'] ?? null)) {
            return;
        }
        $entries = $submenu['elementor-mcp-connect'];
        $entries[] = [
            // Brass on the dark admin menu, matching the product palette.
            '<span style="color:#2d6fd2;font-weight:700;">' . esc_html__('Get Pro', domain: 'elementor-mcp') . '</span>',
            elementor_mcp_manage_capability(),
            esc_url(ELEMENTOR_MCP_PRO_URL . '?utm_source=plugin&utm_medium=submenu'),
        ];
        $submenu['elementor-mcp-connect'] = $entries;
    },
    priority: 99,
);

/**
 * Add a "Get Pro" action link on the Plugins page row for Elementor MCP Free.
 */
add_filter(
    'plugin_action_links_' . plugin_basename(dirname(__DIR__, 2) . '/elementor-mcp.php'),
    static function (array $links): array {
        if (elementor_mcp_pro_is_active()) {
            return $links;
        }
        $url = esc_url(ELEMENTOR_MCP_PRO_URL . '?utm_source=plugin&utm_medium=plugins_row');
        $links[] =
            '<a href="'
            . $url
            . '" target="_blank" rel="noopener">'
            . esc_html__('Get Pro', domain: 'elementor-mcp')
            . '</a>';
        return $links;
    },
);

add_action('admin_footer', static function (): void {
    if (!elementor_mcp_current_user_can_manage()) {
        return;
    }
    ?>
    <script>
    (function() {
        var links = document.querySelectorAll('#toplevel_page_elementor-mcp-connect .wp-submenu a');
        for (var i = 0; i < links.length; i++) {
            if (links[i].href.indexOf('elementormcp.com/pro') !== -1) {
                links[i].target = '_blank';
                links[i].rel = 'noopener';
            }
        }
    })();
    </script>
    <?php
});

/**
 * Flag the welcome notice on first activation.
 *
 * Called from includes/lifecycle.php per site rather than registering its own
 * activation hook, so a network activation records the timestamp on every site
 * instead of only the one the network admin happened to be on.
 */
function elementor_mcp_pro_upsell_on_activate(): void
{
    if (get_option('elementor_mcp_pro_upsell_installed_at') === false) {
        update_option('elementor_mcp_pro_upsell_installed_at', time(), autoload: false);
    }
}

/**
 * Nonce action for dismissing one upsell notice.
 */
function elementor_mcp_pro_dismiss_nonce_action(string $key): string
{
    return 'elementor_mcp_pro_dismiss_' . $key;
}

/**
 * Build the dismiss URL for a notice, carrying its nonce.
 */
function elementor_mcp_pro_dismiss_url(string $key): string
{
    return wp_nonce_url(
        add_query_arg('elementor_mcp_pro_dismiss', $key, admin_url('admin.php?page=elementor-mcp-connect')),
        action: elementor_mcp_pro_dismiss_nonce_action($key),
    );
}

/**
 * Handle dismiss requests (AJAX GET from the notice).
 *
 * The capability check alone left this open to CSRF: any page an administrator
 * visited could dismiss notices on their behalf via an <img> tag.
 */
add_action('admin_init', static function (): void {
    $raw_key = $_GET['elementor_mcp_pro_dismiss'] ?? null;
    if (!is_string($raw_key) || $raw_key === '') {
        return;
    }
    if (!elementor_mcp_current_user_can_manage()) {
        return;
    }
    $key = sanitize_key($raw_key);
    if ($key === '') {
        return;
    }

    check_admin_referer(elementor_mcp_pro_dismiss_nonce_action($key));

    update_user_meta(get_current_user_id(), ELEMENTOR_MCP_PRO_DISMISS_PREFIX . $key, meta_value: 1);
    wp_die('Dismissed', title: 'Dismissed', args: ['response' => 200]);
});

/**
 * Render the one-time welcome notice until dismissed.
 */
add_action('admin_notices', callback: 'elementor_mcp_render_pro_welcome_notice');

function elementor_mcp_render_pro_welcome_notice(): void
{
    if (!elementor_mcp_current_user_can_manage()) {
        return;
    }
    if (elementor_mcp_pro_is_active()) {
        return;
    }
    $user_id = get_current_user_id();
    if (get_user_meta($user_id, ELEMENTOR_MCP_PRO_DISMISS_PREFIX . ELEMENTOR_MCP_PRO_WELCOME_KEY, single: true)) {
        return;
    }
    // Don't show on the Pro page itself or irrelevant screens outside Elementor MCP admin.
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    $on_elementor_mcp =
        $screen
        && (
            str_starts_with($screen->id, 'toplevel_page_elementor-mcp')
            || str_starts_with($screen->id, 'elementor-mcp_page_')
            || $screen->id === 'dashboard'
            || $screen->id === 'plugins'
        );
    if (!$on_elementor_mcp) {
        return;
    }

    $dismiss_url = elementor_mcp_pro_dismiss_url(ELEMENTOR_MCP_PRO_WELCOME_KEY);
    $pro_url = ELEMENTOR_MCP_PRO_URL . '?utm_source=plugin&utm_medium=welcome_notice';
    ?>
    <?php $brand = elementor_mcp_pro_brand(); ?>
    <div class="notice is-dismissible elementor-mcp-pro-notice" data-dismiss-url="<?php echo
        esc_url($dismiss_url)
    ; ?>" style="border-left:4px solid <?php echo esc_attr($brand['teal']); ?>;padding:4px 12px 12px;">
        <p style="margin:12px 0 6px;font-size:15px;font-weight:700;letter-spacing:-.01em;color:<?php echo
            esc_attr($brand['ink'])
        ; ?>;">
            <?php echo esc_html(elementor_mcp_pro_upsell_headline()); ?>
        </p>
        <p style="margin:0 0 12px;max-width:70ch;font-size:13.5px;line-height:1.6;color:#50575e;">
            <?php echo esc_html(elementor_mcp_pro_upsell_blurb()); ?>
        </p>
        <p style="margin:0 0 4px;display:flex;flex-wrap:wrap;gap:10px;align-items:center;">
            <a href="<?php echo
                esc_url($pro_url)
            ; ?>" target="_blank" rel="noopener" class="button" style="background:<?php echo
                esc_attr($brand['teal'])
            ; ?>;border-color:<?php echo esc_attr($brand['ink']); ?>;color:<?php echo
                esc_attr($brand['ink'])
            ; ?>;font-weight:700;text-shadow:none;box-shadow:none;">
                <?php esc_html_e('See what Pro adds', domain: 'elementor-mcp'); ?>
            </a>
            <a href="<?php echo
                esc_url(ELEMENTOR_MCP_PRO_URL . '?utm_source=plugin&utm_medium=welcome_notice_pricing')
            ; ?>" target="_blank" rel="noopener" style="font-size:13px;color:#50575e;">
                <?php esc_html_e('Pricing and licence tiers', domain: 'elementor-mcp'); ?>
            </a>
        </p>
    </div>
    <script>
    (function() {
        var notices = document.querySelectorAll('.elementor-mcp-pro-notice');
        for (var i = 0; i < notices.length; i++) {
            notices[i].addEventListener('click', function(e) {
                if (e.target && e.target.classList.contains('notice-dismiss')) {
                    var url = this.getAttribute('data-dismiss-url');
                    if (url) { fetch(url, {credentials: 'same-origin'}); }
                }
            });
        }
    })();
    </script>
    <?php
}

/**
 * Render a Pro upsell card — called from the Connect page.
 */
function elementor_mcp_render_pro_upsell_card(): void
{
    if (elementor_mcp_pro_is_active()) {
        return;
    }
    $pro_url = ELEMENTOR_MCP_PRO_URL . '?utm_source=plugin&utm_medium=connect_card';
    ?>
    <?php
    $brand = elementor_mcp_pro_brand();
    $pricing_url = ELEMENTOR_MCP_PRO_URL . '?utm_source=plugin&utm_medium=connect_card_pricing';
    ?>
    <div class="elementor-mcp-pro-card" style="position:relative;overflow:hidden;margin:24px 0;padding:26px 28px;border-radius:14px;background:<?php echo
        esc_attr($brand['ink'])
    ; ?>;color:#fff;">
        <span aria-hidden="true" style="position:absolute;inset:0 0 auto 0;height:3px;background:<?php echo
            esc_attr($brand['teal'])
        ; ?>;"></span>

        <p style="margin:0 0 10px;font-size:10px;font-weight:800;letter-spacing:.16em;text-transform:uppercase;color:<?php echo
            esc_attr($brand['teal'])
        ; ?>;">
            <?php esc_html_e('Elementor MCP Pro', domain: 'elementor-mcp'); ?>
        </p>
        <h2 style="margin:0 0 10px;font-size:21px;line-height:1.25;font-weight:800;letter-spacing:-.02em;color:#fff;">
            <?php echo esc_html(elementor_mcp_pro_upsell_headline()); ?>
        </h2>
        <p style="margin:0 0 18px;max-width:70ch;font-size:13.5px;line-height:1.7;color:<?php echo
            esc_attr($brand['muted'])
        ; ?>;">
            <?php echo esc_html(elementor_mcp_pro_upsell_blurb()); ?>
        </p>
        <p style="margin:0;display:flex;flex-wrap:wrap;gap:12px;align-items:center;">
            <a href="<?php echo
                esc_url($pro_url)
            ; ?>" target="_blank" rel="noopener" class="button" style="background:<?php echo
                esc_attr($brand['teal'])
            ; ?>;border-color:<?php echo esc_attr($brand['teal']); ?>;color:<?php echo
                esc_attr($brand['ink'])
            ; ?>;font-weight:700;text-shadow:none;box-shadow:none;">
                <?php esc_html_e('See what Pro adds', domain: 'elementor-mcp'); ?>
            </a>
            <a href="<?php echo
                esc_url($pricing_url)
            ; ?>" target="_blank" rel="noopener" style="font-size:13px;color:<?php echo
                esc_attr($brand['muted'])
            ; ?>;">
                <?php esc_html_e('Pricing and licence tiers', domain: 'elementor-mcp'); ?>
            </a>
        </p>
    </div>
    <?php
}

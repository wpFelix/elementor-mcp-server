<?php

// SPDX-FileCopyrightText: 2026 Elementor MCP <dev@elementormcp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- Template file: require()d inside a namespaced render function, so every variable is function-scoped, never global. The prefix sniff cannot see across the include boundary. Reads are type-checked and escaped on output.

use ElementorMCP\Design\Admin;
use ElementorMCP\Design\Contract;
use ElementorMCP\Design\Library;
use ElementorMCP\Design\Markdown;
use ElementorMCP\Design\Parser;
use ElementorMCP\Design\Store;
use ElementorMCP\Design\Tokens;

if (!defined('ABSPATH')) {
    exit();
}

if (!Admin\current_user_can_manage()) {
    wp_die(esc_html__('You do not have permission to view this page.', domain: 'elementor-mcp'));
}

$view_param = $_GET['view'] ?? '';
$slug = Parser\normalize_slug(is_string($view_param) ? $view_param : '');
$design = $slug !== '' ? Library\find($slug) : null;
if ($design === null) {
    wp_die(esc_html__('Design not found.', domain: 'elementor-mcp'));
}
/** @var array{slug: string, name: string, description: string, content: string} $design */

$tokens = Tokens\extract($design['content']);
$inspection = Contract\inspect($design['content']);
$is_ready = $inspection['readiness']['ready'];
$dials = Tokens\dials($tokens);
$palette = Tokens\palette($design['content']);
$shadows = Tokens\prose_shadows($design['content'], $palette);
$animations = Tokens\prose_animations($design['content']);
$philosophy = Markdown\section($design['content'], [
    'overview',
    'philosophy',
    'design philosophy',
    'about',
    'introduction',
]);
$guidance = Markdown\guidance($design['content'], [
    "do's and don'ts",
    "dos and don'ts",
    "do's & don'ts",
    "dos & don'ts",
    "do and don't",
    'guidelines',
    'principles',
    'rules',
]);
$has_guidance = $guidance['dos'] !== [] || $guidance['donts'] !== [] || $guidance['rest'] !== '';
$vars_style = Tokens\css_vars_string($tokens);
$accent = Tokens\css_vars($tokens)['--elementor-mcp-accent'] ?? '';
$active_slug = Store\get_active_slug();
$is_active = $design['slug'] === $active_slug;
$action_url = admin_url('admin-post.php');
$gallery_url = admin_url('admin.php?page=' . Admin\PAGE_SLUG);

$edit_url = '';
$post = Store\find_user_post($design['slug']);
if ($post instanceof \WP_Post) {
    $edit_url = add_query_arg(['page' => Admin\PAGE_SLUG, 'design' => $post->ID], admin_url('admin.php'));
}
$page_style = $accent !== '' ? '--ds-accent:' . $accent : '';
?>
<?php elementor_mcp_render_admin_header(legend: __('Design', domain: 'elementor-mcp')); ?>
<div class="wrap elementor-mcp-design elementor-mcp-design-detail" style="<?php echo esc_attr($page_style); ?>">
    <a class="elementor-mcp-detail-back" href="<?php echo esc_url($gallery_url); ?>">&larr; <?php esc_html_e(
        'All designs',
        domain: 'elementor-mcp',
    ); ?></a>

    <header class="elementor-mcp-detail-head">
        <div class="elementor-mcp-detail-headmain">
            <span class="elementor-mcp-detail-kicker"><?php esc_html_e('Design', domain: 'elementor-mcp'); ?></span>
            <h1 class="elementor-mcp-detail-title"><?php echo esc_html($design['name']); ?></h1>
            <?php if ($design['description'] !== ''): ?>
                <p class="elementor-mcp-detail-desc"><?php echo esc_html($design['description']); ?></p>
            <?php endif; ?>
            <?php if (!$is_ready): ?>
                <span class="elementor-mcp-design-incomplete-badge"><?php esc_html_e(
                    'Incomplete',
                    domain: 'elementor-mcp',
                ); ?></span>
            <?php endif; ?>
            <?php $waivers = ElementorMCP\Design\Preflight\waivers($design['content']); ?>
            <?php if ($waivers !== []): ?>
                <span class="elementor-mcp-design-allows"><?php echo
                    esc_html(sprintf(
                        /* translators: %s: list of anti-slop rules this design waives */
                        __('Allows: %s', domain: 'elementor-mcp'),
                        implode(' · ', $waivers),
                    ))
                ; ?><span class="elementor-mcp-design-allows-help" title="<?php echo
                    esc_attr__(
                        'Anti-slop rules this design intentionally waives. Elementor MCP normally flags these AI tells; here they count as a deliberate house-style choice, not a mistake.',
                        domain: 'elementor-mcp',
                    )
                ; ?>">?</span></span>
            <?php endif; ?>
        </div>
        <div class="elementor-mcp-detail-actions">
            <?php if ($is_active): ?>
                <span class="elementor-mcp-design-active-badge elementor-mcp-detail-activebadge"><?php esc_html_e(
                    'Active',
                    domain: 'elementor-mcp',
                ); ?></span>
            <?php endif; ?>
            <?php if (!$is_active && $is_ready): ?>
                <form method="post" action="<?php echo esc_url($action_url); ?>">
                    <?php wp_nonce_field('elementor_mcp_design_activate'); ?>
                    <input type="hidden" name="action" value="elementor_mcp_design_activate" />
                    <input type="hidden" name="slug" value="<?php echo esc_attr($design['slug']); ?>" />
                    <button type="submit" class="button button-primary"><?php esc_html_e(
                        'Activate',
                        domain: 'elementor-mcp',
                    ); ?></button>
                </form>
            <?php endif; ?>
            <?php if (!$is_active && !$is_ready): ?>
                <button type="button" class="button" disabled title="<?php echo
                    esc_attr(Contract\activation_error($inspection))
                ; ?>"><?php esc_html_e('Activate', domain: 'elementor-mcp'); ?></button>
            <?php endif; ?>
            <?php if ($edit_url !== ''): ?>
                <a class="button" href="<?php echo esc_url($edit_url); ?>"><?php esc_html_e(
                    'Edit',
                    domain: 'elementor-mcp',
                ); ?></a>
            <?php endif; ?>
            <button type="button" class="button" data-elementor-mcp-copy-design><?php esc_html_e(
                'Copy DESIGN.md',
                domain: 'elementor-mcp',
            ); ?></button>
            <form method="post" action="<?php echo esc_url($action_url); ?>">
                <?php wp_nonce_field('elementor_mcp_design_duplicate'); ?>
                <input type="hidden" name="action" value="elementor_mcp_design_duplicate" />
                <input type="hidden" name="slug" value="<?php echo esc_attr($design['slug']); ?>" />
                <button type="submit" class="button"><?php esc_html_e('Duplicate', domain: 'elementor-mcp'); ?></button>
            </form>
        </div>
    </header>

    <section class="elementor-mcp-detail-stage">
        <?php require __DIR__ . '/preview.php'; ?>
    </section>

    <?php if ($philosophy !== ''): ?>
        <section class="elementor-mcp-detail-block elementor-mcp-detail-philosophy">
            <span class="elementor-mcp-detail-eyebrow"><?php esc_html_e('Philosophy', domain: 'elementor-mcp'); ?></span>
            <div class="elementor-mcp-doc elementor-mcp-doc-lead"><?php echo wp_kses_post($philosophy); ?></div>
        </section>
    <?php endif; ?>

    <div class="elementor-mcp-detail-cols">
        <?php if ($palette !== []): ?>
            <section class="elementor-mcp-detail-block">
                <h2><?php esc_html_e('Palette', domain: 'elementor-mcp'); ?> <span class="elementor-mcp-detail-count"><?php echo
                    esc_html((string) count($palette))
                ; ?></span></h2>
                <div class="elementor-mcp-palette">
                    <?php foreach ($palette as $name => $hex): ?>
                        <div class="elementor-mcp-palette-chip">
                            <span class="elementor-mcp-palette-swatch" style="background:<?php echo
                                esc_attr(Tokens\css_value($hex))
                            ; ?>"></span>
                            <span class="elementor-mcp-palette-name"><?php echo esc_html($name); ?></span>
                            <span class="elementor-mcp-palette-hex"><?php echo esc_html($hex); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($tokens['rounded'] !== [] || $tokens['spacing'] !== []): ?>
            <section class="elementor-mcp-detail-block">
                <h2><?php esc_html_e('Shape & spacing', domain: 'elementor-mcp'); ?></h2>
                <div class="elementor-mcp-shape" style="<?php echo esc_attr($vars_style); ?>">
                    <?php if ($tokens['rounded'] !== []): ?>
                        <div class="elementor-mcp-shape-group">
                            <span class="elementor-mcp-shape-group-label"><?php esc_html_e(
                                'Radius',
                                domain: 'elementor-mcp',
                            ); ?></span>
                            <div class="elementor-mcp-radius-row">
                                <?php foreach ($tokens['rounded'] as $k => $v):
                                    $radius = is_numeric($v) ? $v . 'px' : $v;
                                    ?>
                                    <div class="elementor-mcp-radius-spec">
                                        <span class="elementor-mcp-radius-box" style="border-radius:<?php echo
                                            esc_attr(Tokens\css_value($radius))
                                        ; ?>"></span>
                                        <span class="elementor-mcp-shape-spec-label"><?php echo
                                            esc_html($k . ' · ' . $v)
                                        ; ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    <?php foreach ($tokens['spacing'] as $k => $v):
                        $space_matches = [];
                        preg_match_all('/\d+(?:\.\d+)?/', $v, $space_matches);
                        $steps = $space_matches[0];
                        if ($steps === []) {
                            continue;
                        }
                        ?>
                        <div class="elementor-mcp-shape-group">
                            <span class="elementor-mcp-shape-group-label"><?php echo
                                esc_html(sprintf(
                                    /* translators: %s: spacing token name */
                                    __('Space %s', domain: 'elementor-mcp'),
                                    $k,
                                ))
                            ; ?></span>
                            <div class="elementor-mcp-space-rows">
                                <?php foreach ($steps as $step):
                                    $bar_width = is_numeric($step) ? min((float) $step, 320.0) : 0.0;
                                    ?>
                                    <div class="elementor-mcp-space-row">
                                        <span class="elementor-mcp-space-num"><?php echo esc_html($step); ?></span>
                                        <span class="elementor-mcp-space-bar" style="width:<?php echo
                                            esc_attr((string) $bar_width)
                                        ; ?>px"></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>
    </div>

    <section class="elementor-mcp-detail-block">
        <h2><?php esc_html_e('Composition', domain: 'elementor-mcp'); ?></h2>
        <div class="elementor-mcp-dials">
            <?php foreach (['variance', 'density', 'motion'] as $dial_key):
                $dial_pct = (int) round($dials[$dial_key] * 100);
                ?>
                <div class="elementor-mcp-dial">
                    <div class="elementor-mcp-dial-head">
                        <span class="elementor-mcp-dial-label"><?php echo esc_html(ucfirst($dial_key)); ?></span>
                        <span class="elementor-mcp-dial-value"><?php echo
                            esc_html(number_format($dials[$dial_key], decimals: 2))
                        ; ?></span>
                    </div>
                    <div class="elementor-mcp-dial-track">
                        <span class="elementor-mcp-dial-fill" style="width:<?php echo
                            esc_attr((string) $dial_pct)
                        ; ?>%"></span>
                    </div>
                </div>
            <?php endforeach; ?>
            <p class="elementor-mcp-dials-note"><?php esc_html_e(
                '0 = symmetric / airy / static.  1 = asymmetric / packed / kinetic.',
                domain: 'elementor-mcp',
            ); ?></p>
        </div>
    </section>

    <?php if ($tokens['typography'] !== []): ?>
        <section class="elementor-mcp-detail-block">
            <h2><?php esc_html_e('Typography', domain: 'elementor-mcp'); ?></h2>
            <div class="elementor-mcp-typeset">
                <?php foreach ($tokens['typography'] as $role => $props):
                    $family = $props['fontFamily'] ?? '';
                    $weight = $props['fontWeight'] ?? '';
                    $size = $props['fontSize'] ?? '';
                    $display = Tokens\display_px($size);
                    $sample_style = 'font-family:' . Tokens\css_value($family);
                    if ($weight !== '') {
                        $sample_style .= ';font-weight:' . Tokens\css_value($weight);
                    }
                    if ($display !== '') {
                        $sample_style .= ';font-size:' . $display . ';line-height:1.15';
                    }
                    ?>
                    <div class="elementor-mcp-typespec">
                        <div class="elementor-mcp-typespec-meta">
                            <code><?php echo esc_html($role); ?></code>
                            <span class="elementor-mcp-typespec-family"><?php echo
                                esc_html($family !== '' ? $family : '—')
                            ; ?></span>
                            <?php if (trim($weight . ' ' . $size) !== ''): ?>
                                <span class="elementor-mcp-typespec-num"><?php echo
                                    esc_html(trim($weight . ' ' . $size))
                                ; ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="elementor-mcp-typespec-sample" style="<?php echo
                            esc_attr($sample_style)
                        ; ?>">Ag · The quick brown fox</div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <section class="elementor-mcp-detail-block">
        <h2><?php esc_html_e('Components', domain: 'elementor-mcp'); ?></h2>
        <div class="elementor-mcp-components" style="<?php echo esc_attr($vars_style); ?>">
            <div class="elementor-mcp-comp-row">
                <button type="button" class="elementor-mcp-comp-btn elementor-mcp-comp-primary"><?php esc_html_e(
                    'Primary',
                    domain: 'elementor-mcp',
                ); ?></button>
                <button type="button" class="elementor-mcp-comp-btn elementor-mcp-comp-secondary"><?php esc_html_e(
                    'Secondary',
                    domain: 'elementor-mcp',
                ); ?></button>
                <button type="button" class="elementor-mcp-comp-btn elementor-mcp-comp-ghost"><?php esc_html_e(
                    'Ghost',
                    domain: 'elementor-mcp',
                ); ?></button>
                <span class="elementor-mcp-comp-badge"><?php esc_html_e('Badge', domain: 'elementor-mcp'); ?></span>
            </div>
            <input class="elementor-mcp-comp-input" type="text" placeholder="<?php esc_attr_e(
                'Input field',
                domain: 'elementor-mcp',
            ); ?>" />
            <div class="elementor-mcp-comp-card">
                <strong><?php esc_html_e('Card title', domain: 'elementor-mcp'); ?></strong>
                <span><?php esc_html_e('A small surface, rendered in this design system.', domain: 'elementor-mcp'); ?></span>
            </div>
        </div>
    </section>

    <?php if ($shadows !== []): ?>
        <section class="elementor-mcp-detail-block">
            <h2><?php esc_html_e('Elevation', domain: 'elementor-mcp'); ?> <span class="elementor-mcp-detail-count"><?php echo
                esc_html((string) count($shadows))
            ; ?></span></h2>
            <div class="elementor-mcp-shadows" style="<?php echo esc_attr($vars_style); ?>">
                <?php foreach ($shadows as $shadow_name => $shadow): ?>
                    <div class="elementor-mcp-shadow">
                        <span class="elementor-mcp-shadow-swatch" style="box-shadow:<?php echo
                            esc_attr($shadow['css'])
                        ; ?>"></span>
                        <span class="elementor-mcp-shadow-name"><?php echo esc_html($shadow_name); ?></span>
                        <span class="elementor-mcp-shadow-spec"><?php echo esc_html($shadow['spec']); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($has_guidance): ?>
        <section class="elementor-mcp-detail-block elementor-mcp-detail-doc">
            <h2><?php esc_html_e('Do\'s &amp; Don\'ts', domain: 'elementor-mcp'); ?></h2>
            <?php if ($guidance['rest'] !== ''): ?>
                <div class="elementor-mcp-doc"><?php echo wp_kses_post($guidance['rest']); ?></div>
            <?php endif; ?>
            <?php if ($guidance['dos'] !== [] || $guidance['donts'] !== []): ?>
                <div class="elementor-mcp-doc-split">
                    <?php if ($guidance['dos'] !== []): ?>
                        <div class="elementor-mcp-doc-col">
                            <span class="elementor-mcp-doc-col-label elementor-mcp-doc-col-label--do"><?php esc_html_e(
                                'Do',
                                domain: 'elementor-mcp',
                            ); ?></span>
                            <ul class="elementor-mcp-doc elementor-mcp-doc-list">
                                <?php foreach ($guidance['dos'] as $item): ?>
                                    <li class="elementor-mcp-doc-do"><?php echo wp_kses_post($item); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                    <?php if ($guidance['donts'] !== []): ?>
                        <div class="elementor-mcp-doc-col">
                            <span class="elementor-mcp-doc-col-label elementor-mcp-doc-col-label--dont"><?php esc_html_e(
                                'Don\'t',
                                domain: 'elementor-mcp',
                            ); ?></span>
                            <ul class="elementor-mcp-doc elementor-mcp-doc-list">
                                <?php foreach ($guidance['donts'] as $item): ?>
                                    <li class="elementor-mcp-doc-dont"><?php echo wp_kses_post($item); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if ($animations !== []): ?>
        <section class="elementor-mcp-detail-block">
            <h2><?php esc_html_e('Animations', domain: 'elementor-mcp'); ?> <span class="elementor-mcp-detail-count"><?php echo
                esc_html((string) count($animations))
            ; ?></span></h2>
            <div class="elementor-mcp-anims">
                <?php foreach ($animations as $anim_name => $anim_desc): ?>
                    <div class="elementor-mcp-anim">
                        <span class="elementor-mcp-anim-name"><?php echo esc_html($anim_name); ?></span>
                        <span class="elementor-mcp-anim-desc"><?php echo esc_html($anim_desc); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <section class="elementor-mcp-detail-block">
        <details class="elementor-mcp-detail-raw">
            <summary><?php esc_html_e('Raw DESIGN.md', domain: 'elementor-mcp'); ?></summary>
            <pre class="elementor-mcp-design-md elementor-mcp-detail-md"><?php echo esc_html($design['content']); ?></pre>
        </details>
    </section>

    <?php if ($post instanceof \WP_Post): ?>
        <?php require __DIR__ . '/history.php'; ?>
    <?php endif; ?>
</div>

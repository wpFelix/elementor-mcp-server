<?php

// SPDX-FileCopyrightText: 2026 Elementor MCP <dev@elementormcp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- Template file: require()d inside a namespaced render function, so every variable is function-scoped, never global. The prefix sniff cannot see across the include boundary. Reads are type-checked and escaped on output.

use ElementorMCP\Design\Admin;
use ElementorMCP\Design\Cpt;
use ElementorMCP\Design\Tokens;

if (!defined('ABSPATH')) {
    exit();
}

if (!Admin\current_user_can_manage()) {
    wp_die(esc_html__('You do not have permission to edit designs.', domain: 'elementor-mcp'));
}

$design_param = $_GET['design'] ?? '';
$post_id = is_scalar($design_param) ? (int) $design_param : 0;
// @mago-expect analysis:mixed-assignment
$maybe_post = $post_id > 0 ? get_post($post_id) : null;
if (!$maybe_post instanceof \WP_Post || $maybe_post->post_type !== Cpt\POST_TYPE) {
    wp_die(esc_html__('Design not found.', domain: 'elementor-mcp'));
}
/** @var \WP_Post $post */
$post = $maybe_post;
$content = $post->post_content;
$name = $post->post_title !== '' ? $post->post_title : $post->post_name;

$list_url = admin_url('admin.php?page=' . Admin\PAGE_SLUG);
$action_url = admin_url('admin-post.php');
$tokens = Tokens\extract($content);
$vars_style = Tokens\css_vars_string($tokens);
?>
<?php elementor_mcp_render_admin_header(legend: __('Design', domain: 'elementor-mcp')); ?>
<div class="wrap elementor-mcp-design elementor-mcp-design-edit">
    <h1>
        <a href="<?php echo esc_url($list_url); ?>">← <?php esc_html_e('Design', domain: 'elementor-mcp'); ?></a>
        / <?php echo esc_html($name); ?>
    </h1>

    <div class="elementor-mcp-design-edit-grid">
        <form method="post" action="<?php echo esc_url($action_url); ?>" class="elementor-mcp-design-edit-form">
            <?php wp_nonce_field('elementor_mcp_design_save_' . $post_id); ?>
            <input type="hidden" name="action" value="elementor_mcp_design_save" />
            <input type="hidden" name="design_id" value="<?php echo (int) $post_id; ?>" />
            <p class="description"><?php esc_html_e(
                'Edit the raw DESIGN.md. The name comes from the front matter. The preview updates as you type.',
                domain: 'elementor-mcp',
            ); ?></p>
            <textarea name="content" id="elementor-mcp-design-content" rows="22" class="large-text code"><?php

            echo esc_textarea($content);
            ?></textarea>
            <p>
                <button type="submit" class="button button-primary"><?php esc_html_e(
                    'Save',
                    domain: 'elementor-mcp',
                ); ?></button>
                <a href="<?php echo esc_url($list_url); ?>" class="button"><?php esc_html_e(
                    'Cancel',
                    domain: 'elementor-mcp',
                ); ?></a>
            </p>
        </form>

        <div class="elementor-mcp-design-edit-preview">
            <h2><?php esc_html_e('Live preview', domain: 'elementor-mcp'); ?></h2>
            <?php require __DIR__ . '/preview.php'; ?>
        </div>
    </div>

    <form method="post" action="<?php echo esc_url($action_url); ?>" class="elementor-mcp-design-delete-form"
        onsubmit="return confirm('<?php echo esc_js(__('Delete this design permanently?', domain: 'elementor-mcp')); ?>');">
        <?php wp_nonce_field('elementor_mcp_design_delete_' . $post_id); ?>
        <input type="hidden" name="action" value="elementor_mcp_design_delete" />
        <input type="hidden" name="design_id" value="<?php echo (int) $post_id; ?>" />
        <button type="submit" class="button button-link-delete"><?php esc_html_e(
            'Delete design',
            domain: 'elementor-mcp',
        ); ?></button>
    </form>
</div>

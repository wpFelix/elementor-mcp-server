<?php

// SPDX-FileCopyrightText: 2026 Elementor MCP <dev@elementormcp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- Template file: require()d inside a namespaced render function, so every variable is function-scoped, never global. The prefix sniff cannot see across the include boundary. Reads are type-checked and escaped on output.

use ElementorMCP\Skills\Admin;
use ElementorMCP\Skills\Cpt;
use ElementorMCP\Skills\Sources;

if (!defined('ABSPATH')) {
    exit();
}

if (!Admin\current_user_can_manage()) {
    wp_die(esc_html__('You do not have permission to view this page.', domain: 'elementor-mcp'));
}

$per_page = 50;
$paged = max(1, (int) ($_GET['paged'] ?? 1));
$user_query = new \WP_Query([
    'post_type' => Cpt\POST_TYPE,
    'post_status' => ['publish', 'draft'],
    'posts_per_page' => $per_page,
    'paged' => $paged,
    'orderby' => 'title',
    'order' => 'ASC',
]);
/** @var list<\WP_Post> $user_posts */
$user_posts = $user_query->posts;
$user_total = (int) $user_query->found_posts;
$user_total_pages = (int) $user_query->max_num_pages;

// One-shot list of post IDs to flash-highlight (set by handle_upload).
$just_imported_key = 'elementor_mcp_skill_just_imported_' . get_current_user_id();
/** @var list<int> $just_imported */
$just_imported = [];
/** @var mixed $just_imported_raw */
$just_imported_raw = get_transient($just_imported_key);
if (is_array($just_imported_raw)) {
    /** @var mixed $post_id */
    foreach ($just_imported_raw as $post_id) {
        if (!is_scalar($post_id)) {
            continue;
        }
        $just_imported[] = (int) $post_id;
    }
}
if ($just_imported !== []) {
    delete_transient($just_imported_key);
}

/** @var list<\WP_Post> $trashed_posts */
$trashed_posts = get_posts([
    'post_type' => Cpt\POST_TYPE,
    'post_status' => 'trash',
    'posts_per_page' => -1,
    'orderby' => 'modified',
    'order' => 'DESC',
]);

// Group skills from non-user-cpt sources by source_id so each contributor
// gets its own table with its own dynamic heading (e.g. "Elementor MCP Pro").
$external_groups = [];
foreach (Sources\registry() as $entry) {
    if ($entry['id'] === 'user-cpt') {
        continue;
    }
    $skills = $entry['loader']();
    if ($skills === []) {
        continue;
    }
    $external_groups[$entry['id']] = [
        'label' => $entry['label'],
        'skills' => $skills,
    ];
}

$action_url = admin_url('admin-post.php');
$new_url = add_query_arg(['page' => Admin\PAGE_SLUG, 'skill' => 'new'], admin_url('admin.php'));
?>
<?php elementor_mcp_render_admin_header(); ?>
<div class="wrap elementor-mcp-skills">
    <h1 class="wp-heading-inline"><?php echo esc_html(\elementor_mcp_nav_label('elementor-mcp-skills')); ?></h1>
    <label for="elementor-mcp-skills-upload-file" class="page-title-action"><?php esc_html_e(
        'Upload .md',
        domain: 'elementor-mcp',
    ); ?></label>
    <a href="<?php echo esc_url($new_url); ?>" class="page-title-action"><?php esc_html_e(
        'Add new',
        domain: 'elementor-mcp',
    ); ?></a>
    <?php if ($user_total > 0): ?>
        <a
            href="<?php echo
                esc_url(wp_nonce_url(add_query_arg([
                    'action' => 'elementor_mcp_skill_download_all',
                ], admin_url('admin-post.php')), action: 'elementor_mcp_skill_download_all'))
            ; ?>"
            class="page-title-action"
        ><?php esc_html_e('Download all', domain: 'elementor-mcp'); ?></a>
    <?php endif; ?>
    <hr class="wp-header-end" />

    <?php require __DIR__ . '/upload.php'; ?>

    <details class="elementor-mcp-skills-trust-warning">
        <summary>
            <span class="dashicons dashicons-shield" aria-hidden="true"></span>
            <span class="summary-text"><?php esc_html_e(
                'Only upload skills from sources you trust.',
                domain: 'elementor-mcp',
            ); ?></span>
            <span class="summary-toggle"><?php esc_html_e('Why?', domain: 'elementor-mcp'); ?></span>
        </summary>
        <div class="elementor-mcp-skills-trust-body">
            <p><?php esc_html_e(
                'A skill\'s description and body become part of the AI\'s context on this site. A malicious skill can:',
                domain: 'elementor-mcp',
            ); ?></p>
            <ul>
                <li><?php esc_html_e(
                    'Override or hijack the AI\'s behaviour with hidden instructions (prompt injection).',
                    domain: 'elementor-mcp',
                ); ?></li>
                <li><?php esc_html_e(
                    'Trick the AI into reading sensitive files (config, credentials, customer data) and sending them outside.',
                    domain: 'elementor-mcp',
                ); ?></li>
                <li><?php esc_html_e(
                    'Get the AI to run arbitrary code on your site via ElementorMCP\'s PHP-execution abilities.',
                    domain: 'elementor-mcp',
                ); ?></li>
            </ul>
            <p><?php esc_html_e(
                'Treat an uploaded .md the same way you\'d treat installing a plugin: trust the author first.',
                domain: 'elementor-mcp',
            ); ?></p>
        </div>
    </details>

    <?php if ($user_posts === [] && $external_groups === []): ?>
        <div class="elementor-mcp-skills-empty">
            <span class="dashicons dashicons-welcome-learn-more"></span>
            <p><?php esc_html_e(
                'No skills yet. Upload a .md file or create one from scratch.',
                domain: 'elementor-mcp',
            ); ?></p>
            <p>
                <a href="<?php echo esc_url($new_url); ?>" class="button button-primary"><?php esc_html_e(
                    'Create from scratch',
                    domain: 'elementor-mcp',
                ); ?></a>
            </p>
        </div>
    <?php endif; ?>

    <?php if ($user_posts !== []): ?>
    <section class="elementor-mcp-skills-d-section">
        <div class="elementor-mcp-skills-d-header">
            <h2><?php esc_html_e('Your Skills', domain: 'elementor-mcp'); ?> <span class="count"><?php

            echo (int) $user_total;
            ?></span></h2>
            <input
                type="search"
                id="elementor-mcp-skills-search"
                class="elementor-mcp-skills-search"
                placeholder="<?php esc_attr_e('Filter on this page…', domain: 'elementor-mcp'); ?>"
                autocomplete="off"
            />
        </div>
        <div class="elementor-mcp-skills-d-list" data-elementor-mcp-skills-list>
            <?php foreach ($user_posts as $post):
                $slug = $post->post_name;
                $malformed_title = $slug === '';
                $prompt_on = boolval(get_post_meta($post->ID, Cpt\META_ENABLE_PROMPT, single: true));
                $agentic_on = boolval(get_post_meta($post->ID, Cpt\META_ENABLE_AGENTIC, single: true));
                $enabled = $post->post_status === 'publish';
                $description = trim($post->post_excerpt);
                $missing_description = $description === '';
                $missing_body = trim($post->post_content) === '';
                $external_conflict = $slug !== '' ? Sources\exists_in_external_source($slug) : null;
                // Missing description/body are shown as inline badges in
                // the slug column; the right-side ⚠ pill is reserved for
                // issues that don't fit there (malformed slug, external
                // source collision).
                $has_warning = $malformed_title || $external_conflict !== null;
                $edit_url = add_query_arg([
                    'page' => Admin\PAGE_SLUG,
                    'skill' => $post->ID,
                ], admin_url('admin.php'));
                $row_classes = ['elementor-mcp-skills-d-row'];
                if ($enabled) {
                    $row_classes[] = 'is-on';
                }
                if ($has_warning) {
                    $row_classes[] = 'has-warn';
                }
                if (in_array((int) $post->ID, $just_imported, strict: true)) {
                    $row_classes[] = 'is-just-imported';
                }
                ?>
            <div class="<?php echo esc_attr(implode(' ', $row_classes)); ?>">
                <form
                    method="post"
                    action="<?php echo esc_url($action_url); ?>"
                    class="elementor-mcp-skills-d-toggle"
                    title="<?php echo
                        $enabled ? esc_attr__('Disable', domain: 'elementor-mcp') : esc_attr__('Enable', domain: 'elementor-mcp')
                    ; ?>"
                >
                    <?php wp_nonce_field('elementor_mcp_skill_toggle_status_' . $post->ID); ?>
                    <input type="hidden" name="action" value="elementor_mcp_skill_toggle_status" />
                    <input type="hidden" name="post_id" value="<?php echo (int) $post->ID; ?>" />
                    <button type="submit" class="elementor-mcp-skills-d-check" aria-label="<?php echo
                        $enabled
                            ? esc_attr__('Click to disable', domain: 'elementor-mcp')
                            : esc_attr__('Click to enable', domain: 'elementor-mcp')
                    ; ?>"></button>
                </form>
                <a class="elementor-mcp-skills-d-main" href="<?php echo esc_url($edit_url); ?>">
                    <span class="slug"><?php echo esc_html($slug !== '' ? $slug : $post->post_title); ?></span>
                    <?php if ($missing_description): ?>
                        <span class="desc-badge is-missing">⚠ <?php esc_html_e(
                            'Missing description',
                            domain: 'elementor-mcp',
                        ); ?></span>
                    <?php endif; ?>
                    <?php if (!$missing_description && $description !== ''): ?>
                        <span class="desc"><?php echo esc_html($description); ?></span>
                    <?php endif; ?>
                    <?php if ($missing_body): ?>
                        <span class="desc-badge is-missing">⚠ <?php esc_html_e(
                            'Missing body',
                            domain: 'elementor-mcp',
                        ); ?></span>
                    <?php endif; ?>
                </a>
                <div class="elementor-mcp-skills-d-pills">
                    <?php if ($agentic_on): ?>
                        <span class="pill auto"><?php esc_html_e('Auto', domain: 'elementor-mcp'); ?></span>
                    <?php endif; ?>
                    <?php if ($prompt_on): ?>
                        <span class="pill cmd"><?php esc_html_e('Command', domain: 'elementor-mcp'); ?></span>
                    <?php endif; ?>
                    <?php if ($has_warning): ?>
                        <?php

                        $critical = $external_conflict !== null;
                        $warnings = [];
                        if ($malformed_title) {
                            $warnings[] = __('Malformed title', domain: 'elementor-mcp');
                        }
                        if ($external_conflict !== null) {
                            $warnings[] = sprintf(
                                /* translators: %s = source label */
                                __('Conflicts with %s', domain: 'elementor-mcp'),
                                $external_conflict,
                            );
                        }
                        ?>
                        <span
                            class="pill warn<?php echo $critical ? ' is-critical' : ''; ?>"
                            title="<?php echo esc_attr(implode(' · ', $warnings)); ?>"
                        >⚠ <?php echo (int) count($warnings); ?></span>
                    <?php endif; ?>
                </div>
                <div class="elementor-mcp-skills-d-actions">
                    <a class="action-btn" href="<?php echo esc_url($edit_url); ?>"><?php

                    esc_html_e('Edit', domain: 'elementor-mcp');
                    ?></a>
                    <a
                        class="action-btn"
                        href="<?php echo
                            esc_url(wp_nonce_url(
                                add_query_arg([
                                    'action' => 'elementor_mcp_skill_download',
                                    'post_id' => (int) $post->ID,
                                ], admin_url('admin-post.php')),
                                'elementor_mcp_skill_download_' . (int) $post->ID,
                            ))
                        ; ?>"
                    ><?php esc_html_e('Download', domain: 'elementor-mcp'); ?></a>
                    <form
                        method="post"
                        action="<?php echo esc_url($action_url); ?>"
                        onsubmit="return confirm('<?php echo
                            esc_js(__('Delete this skill permanently?', domain: 'elementor-mcp'))
                        ; ?>');"
                    >
                        <?php wp_nonce_field('elementor_mcp_skill_delete_' . $post->ID); ?>
                        <input type="hidden" name="action" value="elementor_mcp_skill_delete" />
                        <input type="hidden" name="post_id" value="<?php echo (int) $post->ID; ?>" />
                        <button type="submit" class="action-btn action-btn--danger"><?php

                        esc_html_e('Delete', domain: 'elementor-mcp');
                        ?></button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php if ($user_total_pages > 1): ?>
            <nav class="elementor-mcp-skills-pagination" aria-label="<?php esc_attr_e(
                'Skills pagination',
                domain: 'elementor-mcp',
            ); ?>">
                <?php

                /** @var mixed $links */
                $links = paginate_links([
                    'base' => add_query_arg(['paged' => '%#%'], admin_url('admin.php?page=' . Admin\PAGE_SLUG)),
                    'format' => '',
                    'current' => $paged,
                    'total' => $user_total_pages,
                    'prev_text' => '‹',
                    'next_text' => '›',
                    'type' => 'plain',
                ]);
                echo is_string($links) ? $links : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?>
            </nav>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <?php if ($trashed_posts !== []): ?>
    <section class="elementor-mcp-skills-d-section elementor-mcp-skills-d-section--trash">
        <div class="elementor-mcp-skills-d-header">
            <h2><?php esc_html_e('Trash', domain: 'elementor-mcp'); ?> <span class="count"><?php

            echo (int) count($trashed_posts);
            ?></span></h2>
            <span class="elementor-mcp-skills-d-trash-hint"><?php esc_html_e(
                'Trashed skills are not loaded by the AI. Items are auto-removed after 30 days.',
                domain: 'elementor-mcp',
            ); ?></span>
        </div>
        <div class="elementor-mcp-skills-d-list">
            <?php foreach ($trashed_posts as $post):
                $slug = $post->post_name !== '' ? $post->post_name : $post->post_title;
                // WP appends `__trashed` to post_name when trashing; strip for display.
                $slug = (string) preg_replace('/__trashed$/', replacement: '', subject: $slug);
                $description = trim($post->post_excerpt);
                ?>
            <div class="elementor-mcp-skills-d-row is-trashed">
                <div class="elementor-mcp-skills-d-trash-icon" aria-hidden="true">⌫</div>
                <div class="elementor-mcp-skills-d-main elementor-mcp-skills-d-main--trash">
                    <span class="slug"><?php echo esc_html($slug); ?></span>
                    <?php if ($description !== ''): ?>
                        <span class="desc"><?php echo esc_html($description); ?></span>
                    <?php endif; ?>
                </div>
                <div class="elementor-mcp-skills-d-pills">
                    <span class="pill"><?php esc_html_e('Trash', domain: 'elementor-mcp'); ?></span>
                </div>
                <div class="elementor-mcp-skills-d-actions elementor-mcp-skills-d-actions--trash">
                    <form method="post" action="<?php echo esc_url($action_url); ?>">
                        <?php wp_nonce_field('elementor_mcp_skill_restore_' . $post->ID); ?>
                        <input type="hidden" name="action" value="elementor_mcp_skill_restore" />
                        <input type="hidden" name="post_id" value="<?php echo (int) $post->ID; ?>" />
                        <button type="submit" class="action-btn"><?php

                        esc_html_e('Restore', domain: 'elementor-mcp');
                        ?></button>
                    </form>
                    <form
                        method="post"
                        action="<?php echo esc_url($action_url); ?>"
                        onsubmit="return confirm('<?php echo
                            esc_js(__('Delete this skill permanently? This cannot be undone.', domain: 'elementor-mcp'))
                        ; ?>');"
                    >
                        <?php wp_nonce_field('elementor_mcp_skill_permanent_delete_' . $post->ID); ?>
                        <input type="hidden" name="action" value="elementor_mcp_skill_permanent_delete" />
                        <input type="hidden" name="post_id" value="<?php echo (int) $post->ID; ?>" />
                        <button type="submit" class="action-btn action-btn--danger"><?php

                        esc_html_e('Delete permanently', domain: 'elementor-mcp');
                        ?></button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <?php foreach ($external_groups as $source_id => $group): ?>
    <section class="elementor-mcp-skills-d-section elementor-mcp-skills-d-section--external">
        <div class="elementor-mcp-skills-d-header">
            <h2><?php

            printf(
                /* translators: %s: contributor label, e.g. "Elementor MCP Pro" */
                esc_html__('Skills from %s', domain: 'elementor-mcp'),
                esc_html($group['label']),
            );
            ?> <span class="count"><?php

            echo (int) count($group['skills']);
            ?></span></h2>
            <span class="elementor-mcp-skills-d-readonly-note"><?php esc_html_e('Not editable', domain: 'elementor-mcp'); ?></span>
        </div>
        <?php if (str_starts_with($source_id, 'elementor-mcp-pro')): ?>
            <p class="elementor-mcp-skills-d-source-blurb">
                <?php

                printf(
                    /* translators: 1: source label, 2: link opening tag, 3: link closing tag */
                    esc_html__(
                        '%1$s combines skills, abilities, and more. You see only the skills relevant to the plugins you have installed. %2$sLearn more →%3$s',
                        domain: 'elementor-mcp',
                    ),
                    esc_html($group['label']),
                    '<a href="https://elementormcp.com/pro/?utm_source=plugin&utm_medium=skills" target="_blank" rel="noopener">',
                    '</a>',
                );
                ?>
            </p>
        <?php endif; ?>
        <div class="elementor-mcp-skills-d-list">
            <?php foreach ($group['skills'] as $skill):
                $slug = (string) ($skill['slug'] ?? '');
                $description = trim((string) ($skill['description'] ?? ''));
                $missing_description = $description === '';
                $prompt_on = boolval($skill['enable_prompt'] ?? false);
                $agentic_on = boolval($skill['enable_agentic'] ?? false);
                ?>
            <div class="elementor-mcp-skills-d-row is-external is-on">
                <div class="elementor-mcp-skills-d-source-icon" aria-hidden="true">↗</div>
                <div class="elementor-mcp-skills-d-main elementor-mcp-skills-d-main--external">
                    <span class="slug"><?php echo esc_html($slug); ?></span>
                    <?php if ($missing_description): ?>
                        <span class="desc-badge is-missing">⚠ <?php esc_html_e(
                            'Missing description',
                            domain: 'elementor-mcp',
                        ); ?></span>
                    <?php endif; ?>
                    <?php if (!$missing_description): ?>
                        <span class="desc"><?php echo esc_html($description); ?></span>
                    <?php endif; ?>
                </div>
                <div class="elementor-mcp-skills-d-pills">
                    <?php if ($agentic_on): ?>
                        <span class="pill auto"><?php esc_html_e('Auto', domain: 'elementor-mcp'); ?></span>
                    <?php endif; ?>
                    <?php if ($prompt_on): ?>
                        <span class="pill cmd"><?php esc_html_e('Command', domain: 'elementor-mcp'); ?></span>
                    <?php endif; ?>
                </div>
                <div class="elementor-mcp-skills-d-actions"></div>
            </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endforeach; ?>

</div>
<script>
(function () {
    var file = document.getElementById('elementor-mcp-skills-upload-file');
    var form = document.getElementById('elementor-mcp-skills-upload-form');
    var MAX_UPLOAD = 20;
    if (file && form) {
        file.addEventListener('change', function () {
            if (!file.files || file.files.length === 0) {
                return;
            }
            if (file.files.length > MAX_UPLOAD) {
                alert('Too many files. Upload up to ' + MAX_UPLOAD + ' skills at a time.');
                file.value = '';
                return;
            }
            form.submit();
        });
    }

    // Client-side filter for the current page's rows. Searches slug and
    // description (visible text inside each row).
    var search = document.getElementById('elementor-mcp-skills-search');
    var list = document.querySelector('[data-elementor-mcp-skills-list]');
    if (search && list) {
        var rows = Array.prototype.slice.call(list.querySelectorAll('.elementor-mcp-skills-d-row'));
        search.addEventListener('input', function () {
            var q = search.value.toLowerCase().trim();
            rows.forEach(function (row) {
                if (q === '') {
                    row.style.display = '';
                    return;
                }
                row.style.display = (row.textContent || '').toLowerCase().indexOf(q) !== -1 ? '' : 'none';
            });
        });
    }
})();
</script>

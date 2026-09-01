<?php

// SPDX-FileCopyrightText: 2026 Elementor MCP <dev@elementormcp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace ElementorMCP\PromptLibrary\Admin;

use ElementorMCP\PromptLibrary;

/**
 * The Prompts screen: pick a category, copy a prompt, paste it at your agent.
 *
 * Deliberately static HTML with a copy button and no server round-trip. A
 * prompt is text; the moment this screen needs saving, filtering or state it
 * has stopped being a library and started being an app nobody asked for.
 *
 * Locked packs are shown, not hidden. Someone on the free plugin should be able
 * to see that the Elementor prompts exist and what they cover. That is the
 * honest version of an upsell, and it is also the answer to "does Pro have
 * anything for my builder?".
 */

if (!defined('ABSPATH')) {
    exit();
}

function register_menu(): void
{
    add_submenu_page(
        parent_slug: 'elementor-mcp-connect',
        page_title: \elementor_mcp_nav_label(PromptLibrary\PAGE, __('Prompts', domain: 'elementor-mcp')),
        menu_title: \elementor_mcp_nav_label(PromptLibrary\PAGE, __('Prompts', domain: 'elementor-mcp')),
        capability: (string) \elementor_mcp_manage_capability(),
        menu_slug: PromptLibrary\PAGE,
        callback: __NAMESPACE__ . '\\render',
    );
}

/**
 * @param mixed $map
 * @return mixed
 */
function register_nav(mixed $map): mixed
{
    if (!is_array($map)) {
        return $map;
    }

    $map[PromptLibrary\PAGE] = ['label' => __('Prompt Library', domain: 'elementor-mcp'), 'group' => 'ai'];

    return $map;
}

/**
 * Whether Pro is licensed and answering.
 *
 * Uses the same filter the Dashboard reads, so this screen never calls into Pro
 * and never checks whether it is installed. An unlicensed Pro does not answer,
 * which is the correct reading: its packs are not available.
 */
function pro_active(): bool
{
    /** @var mixed $status */
    $status = apply_filters('elementor_mcp_pro_status', value: null);

    return is_array($status);
}

/**
 * Group the packs for display, preserving the order groups() declares.
 *
 * @param list<array<string, mixed>> $packs
 * @return array<string, list<array<string, mixed>>>
 */
function grouped(array $packs): array
{
    $grouped = [];
    foreach (array_keys(PromptLibrary\groups()) as $group) {
        $matching = array_values(array_filter(
            $packs,
            static fn(array $pack): bool => ($pack['group'] ?? '') === $group,
        ));
        if ($matching !== []) {
            $grouped[$group] = $matching;
        }
    }

    return $grouped;
}

function render(): void
{
    if (!\elementor_mcp_current_user_can_manage()) {
        return;
    }

    $packs = PromptLibrary\packs();
    $groups = PromptLibrary\groups();
    $grouped = grouped($packs);
    $licensed = pro_active();
    $locked = array_values(array_filter($packs, static fn(array $p): bool => $p['pro'] === true && !$licensed));

    \elementor_mcp_render_admin_header();
    ?>
    <div class="wrap">
        <h1><?php echo esc_html(\elementor_mcp_nav_label(PromptLibrary\PAGE, __('Prompts', domain: 'elementor-mcp'))); ?></h1>
        <p class="elementor-mcp-lede"><?php esc_html_e(
            'Ready-made instructions for your AI client. Copy one, edit the parts in brackets, and paste it wherever you talk to your agent.',
            domain: 'elementor-mcp',
        ); ?></p>
        <?php // These are a shortcut, not the interface. Nobody should read this
              // screen and conclude their own wording will not work. ?>
        <p class="description" style="margin:-6px 0 18px;max-width:70ch;"><?php esc_html_e(
            'You do not have to use any of these. Your agent asks this site what it can do before it starts, and loads the right skill on its own, so asking in your own words works just as well. These are here to save you the first hour.',
            domain: 'elementor-mcp',
        ); ?></p>

        <?php if ($packs === []) {
            ?>
            <section class="elementor-mcp-panel">
                <p class="description"><?php esc_html_e('No prompt packs are available.', domain: 'elementor-mcp'); ?></p>
            </section>
            <?php

            return;
        } ?>

        <div class="elementor-mcp-client-tabs" id="elementor-mcp-prompt-tabs">
            <?php $first = true; ?>
            <?php foreach ($grouped as $group => $group_packs) { ?>
                <?php foreach ($group_packs as $pack) { ?>
                    <button
                        type="button"
                        class="elementor-mcp-client-tab elementor-mcp-prompt-tab<?php echo $first ? ' active' : ''; ?>"
                        data-pack="<?php echo esc_attr((string) $pack['slug']); ?>"
                    >
                        <?php echo esc_html((string) $pack['label']); ?>
                        <?php if ($pack['pro'] === true && !$licensed) { ?>
                            <span class="elementor-mcp-prompt-lock" aria-label="<?php

                            esc_attr_e('Pro', domain: 'elementor-mcp'); ?>">&#128274;</span>
                        <?php } ?>
                    </button>
                    <?php $first = false; ?>
                <?php } ?>
            <?php } ?>
        </div>
        <p class="description" style="margin:6px 0 18px;"><?php

        $labels = [];
        foreach ($grouped as $group => $group_packs) {
            $labels[] = sprintf('%s (%d)', $groups[$group] ?? $group, count($group_packs));
        }
        echo esc_html(implode(' · ', $labels));
        ?></p>

        <?php $first = true; ?>
        <?php foreach ($packs as $pack) { ?>
            <div
                class="elementor-mcp-prompt-pack"
                data-pack="<?php echo esc_attr((string) $pack['slug']); ?>"
                <?php echo $first ? '' : 'hidden'; ?>
            >
                <?php render_pack($pack, $licensed); ?>
            </div>
            <?php $first = false; ?>
        <?php } ?>

        <?php if ($locked !== []) { ?>
            <section class="elementor-mcp-panel is-attention">
                <h2 class="elementor-mcp-setting-group__title"><?php esc_html_e(
                    'More prompts with Elementor MCP Pro',
                    domain: 'elementor-mcp',
                ); ?></h2>
                <p><?php

                printf(
                    /* translators: 1: number of locked packs, 2: comma-separated pack names */
                    esc_html(_n(
                        single: '%1$d more pack is included with Pro: %2$s.',
                        plural: '%1$d more packs are included with Pro: %2$s.',
                        number: count($locked),
                        domain: 'elementor-mcp',
                    )),
                    count($locked),
                    esc_html(implode(', ', array_map(static fn(array $p): string => (string) $p['label'], $locked))),
                );
                ?></p>
                <p>
                    <a class="button button-primary" href="https://elementormcp.com/pricing/" target="_blank" rel="noopener">
                        <?php esc_html_e('See Elementor MCP Pro', domain: 'elementor-mcp'); ?>
                    </a>
                </p>
            </section>
        <?php } ?>
    </div>

    <?php // Styles for this screen live in includes/assets/admin.css. ?>
    <script>
    (function () {
        function fallbackClipboardCopy(text) {
            return new Promise(function (resolve, reject) {
                var textarea = document.createElement('textarea');
                var active = document.activeElement;
                textarea.value = text;
                textarea.setAttribute('readonly', '');
                textarea.setAttribute('aria-hidden', 'true');
                textarea.style.position = 'fixed';
                textarea.style.top = '-9999px';
                textarea.style.opacity = '0';
                document.body.appendChild(textarea);
                textarea.focus();
                textarea.select();

                var copied = false;
                try {
                    copied = document.execCommand('copy');
                } catch (error) {
                    copied = false;
                }

                document.body.removeChild(textarea);
                if (active && typeof active.focus === 'function') {
                    active.focus();
                }
                copied ? resolve() : reject(new Error('copy command was rejected'));
            });
        }

        // The Prompts screen is independent from Connect and Troubleshoot, so
        // it must own the helper it calls. Also fall back when the modern API
        // exists but rejects because of browser permissions or an HTTP origin.
        if (!window.elementorMcpClipboardCopy) {
            window.elementorMcpClipboardCopy = function (text) {
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    return navigator.clipboard.writeText(text).catch(function () {
                        return fallbackClipboardCopy(text);
                    });
                }
                return fallbackClipboardCopy(text);
            };
        }

        function showCopyState(button, label) {
            var original = button.getAttribute('data-label') || button.textContent;
            button.setAttribute('data-label', original);
            button.textContent = label;
            setTimeout(function () { button.textContent = original; }, 1800);
        }

        var tabs = document.querySelectorAll('.elementor-mcp-prompt-tab');
        var packs = document.querySelectorAll('.elementor-mcp-prompt-pack');
        tabs.forEach(function (tab) {
            tab.addEventListener('click', function () {
                var want = tab.getAttribute('data-pack');
                tabs.forEach(function (t) { t.classList.remove('active'); });
                tab.classList.add('active');
                packs.forEach(function (p) {
                    p.hidden = p.getAttribute('data-pack') !== want;
                });
            });
        });

        document.querySelectorAll('.elementor-mcp-prompt-copy').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var body = document.getElementById(btn.getAttribute('data-target'));
                if (!body) {
                    showCopyState(btn, btn.getAttribute('data-failed'));
                    return;
                }
                window.elementorMcpClipboardCopy(body.textContent).then(function () {
                    showCopyState(btn, btn.getAttribute('data-copied'));
                }).catch(function () {
                    showCopyState(btn, btn.getAttribute('data-failed'));
                });
            });
        });
    })();
    </script>
    <?php
}

/**
 * One pack: its prompts, or the reason they are not shown.
 *
 * @param array<string, mixed> $pack
 */
function render_pack(array $pack, bool $licensed): void
{
    $is_locked = ($pack['pro'] ?? false) === true && !$licensed;
    /** @var list<array{title: string, description: string, prompt: string}> $prompts */
    $prompts = is_array($pack['prompts'] ?? null) ? $pack['prompts'] : [];
    $copied = __('Copied', domain: 'elementor-mcp');
    $copy_failed = __('Copy failed', domain: 'elementor-mcp');
    ?>
    <section class="elementor-mcp-panel">
        <h2 class="elementor-mcp-setting-group__title"><?php echo esc_html((string) $pack['label']); ?></h2>
        <?php if ((string) ($pack['description'] ?? '') !== '') { ?>
            <p class="description" style="margin-top:0;"><?php

            echo esc_html((string) $pack['description']); ?></p>
        <?php } ?>

        <?php if ($is_locked) { ?>
            <p><?php

            printf(
                /* translators: %d: number of prompts in this pack */
                esc_html(_n(
                    single: '%d prompt in this pack, included with Elementor MCP Pro.',
                    plural: '%d prompts in this pack, included with Elementor MCP Pro.',
                    number: count($prompts),
                    domain: 'elementor-mcp',
                )),
                count($prompts),
            );
            ?></p>
            <ul class="elementor-mcp-prompt-locked-list">
                <?php foreach ($prompts as $prompt) { ?>
                    <li>
                        <strong><?php echo esc_html($prompt['title']); ?></strong>
                        <?php if ($prompt['description'] !== '') { ?>, <?php echo esc_html($prompt['description']); ?>
                        <?php } ?>
                    </li>
                <?php } ?>
            </ul>
        <?php } else { ?>
            <?php foreach ($prompts as $index => $prompt) { ?>
                <?php $id = 'elementor-mcp-prompt-' . sanitize_key((string) $pack['slug']) . '-' . $index; ?>
                <div class="elementor-mcp-prompt">
                    <div class="elementor-mcp-prompt__head">
                        <div>
                            <h3 class="elementor-mcp-prompt__title"><?php echo esc_html($prompt['title']); ?></h3>
                            <?php if ($prompt['description'] !== '') { ?>
                                <p class="description" style="margin:2px 0 0;"><?php

                                echo esc_html($prompt['description']); ?></p>
                            <?php } ?>
                        </div>
                        <button
                            type="button"
                            class="button elementor-mcp-prompt-copy"
                            data-target="<?php echo esc_attr($id); ?>"
                            data-copied="<?php echo esc_attr($copied); ?>"
                            data-failed="<?php echo esc_attr($copy_failed); ?>"
                            aria-live="polite"
                        ><?php esc_html_e('Copy', domain: 'elementor-mcp'); ?></button>
                    </div>
                    <pre class="elementor-mcp-prompt__body" id="<?php echo esc_attr($id); ?>"><?php

                    echo esc_html($prompt['prompt']); ?></pre>
                </div>
            <?php } ?>
        <?php } ?>
    </section>
    <?php
}

add_action('admin_menu', __NAMESPACE__ . '\register_menu', priority: 42);
add_filter('elementor_mcp_nav_map', __NAMESPACE__ . '\register_nav');

<?php

// SPDX-FileCopyrightText: 2026 Elementor MCP <dev@elementormcp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace ElementorMCP\Abilities\Elementor;

/**
 * Ability: Create an Elementor v4 atomic widget.
 */

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('elementor-mcp/elementor-create-atomic-widget', [
    'label' => __('Create Elementor Atomic Widget', domain: 'elementor-mcp'),
    'description' => __(
        'Creates or regenerates an Elementor v4 ATOMIC widget (PHP class + Twig template + loader) as a mu-plugin. This ability only targets atomic (v4) widgets — it does not generate legacy v3 widgets. IMPORTANT: before creating a new widget, use elementor-mcp/elementor-get-schema action "list" to check if a similar widget already exists in Elementor core, Pro, or third-party plugins. Styling surfaces: "base_styles" emits Style-tab-editable defaults (maps to define_base_styles() — same shape as elementor-mcp/elementor-create-global-class\'s "styles", validated against the v4 Style Schema); "css" emits static shell CSS as a linked stylesheet. Default is no base styles and no CSS — the widget renders as bare content. Do NOT inline <style> blocks in the Twig — they duplicate on every render. To iterate on an existing widget, pass overwrite:true — regenerates every file from the input and destroys any hand edits to elementor-mcp-widget.php, the Twig, the loader, or the JS/CSS assets. Callers must re-supply the full props/twig/js/css/base_styles each time since this is a full regeneration, not a patch.',
        domain: 'elementor-mcp',
    ),
    'category' => 'elementor',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'name' => [
                'type' => 'string',
                'description' => 'Widget slug (kebab-case), e.g. "before-after", "price-table".',
                'pattern' => '^[a-z][a-z0-9-]*$',
            ],
            'title' => [
                'type' => 'string',
                'description' => 'Widget display title, e.g. "Before After", "Price Table".',
            ],
            'description' => [
                'type' => 'string',
                'description' => 'Short widget description.',
            ],
            'icon' => [
                'type' => 'string',
                'description' => 'Elementor icon class, e.g. "eicon-image-before-after". Defaults to "eicon-code".',
            ],
            'props' => [
                'type' => 'array',
                'description' => 'Widget props. Each prop has a name, type, and optional label/default.',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => [
                            'type' => 'string',
                            'description' => 'Prop name (snake_case), e.g. "image_before", "title".',
                        ],
                        'type' => [
                            'type' => 'string',
                            'description' => 'Prop type — drives both the storage Prop_Type and the editor control. Note: `textarea` and `email` are control shortcuts — both store as `String_Prop_Type` (so `elementor-get-schema` reports them as `{"t":"string"}`) but pair with a specialized editor control (multi-line textarea, email input with validation). Use `html` when the value is rich text that should round-trip with formatting.',
                            'enum' => [
                                'image',
                                'string',
                                'link',
                                'html',
                                'number',
                                'boolean',
                                'email',
                                'color',
                                'select',
                                'video',
                                'textarea',
                                'date_time',
                            ],
                        ],
                        'label' => [
                            'type' => 'string',
                            'description' => 'Control label shown in editor.',
                        ],
                        'default' => [
                            'type' => ['string', 'number', 'boolean', 'null'],
                            'description' => 'Default value. Shape per prop type: string/email/textarea/html → text; image/video → URL string; color → hex like "#111"; select → one of the option values (omit to default to the first option); number → numeric; boolean → true/false. Ignored for `link` and `date_time` — the generator does not thread a default into those schemas.',
                        ],
                        'options' => [
                            'type' => 'array',
                            'description' => 'Options for select type. Each item has value and label.',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'value' => [
                                        'type' => 'string',
                                        'description' => 'Option value.',
                                    ],
                                    'label' => [
                                        'type' => 'string',
                                        'description' => 'Option display label.',
                                    ],
                                ],
                                'required' => ['value', 'label'],
                            ],
                        ],
                        'min' => [
                            'type' => 'number',
                            'description' => 'Minimum value for number type.',
                        ],
                        'max' => [
                            'type' => 'number',
                            'description' => 'Maximum value for number type.',
                        ],
                        'step' => [
                            'type' => 'number',
                            'description' => 'Step increment for number type.',
                        ],
                    ],
                    'required' => ['name', 'type'],
                ],
            ],
            'twig' => [
                'type' => 'string',
                'description' => 'Custom Twig template. If omitted, a default template is generated. Render-time prop shapes (how props appear inside the template, which DIFFER from the schema field names for compound props): scalar types string/email/textarea/number/select/date_time/color/boolean — access directly as {{ settings.foo }}; image — { src, ... }, e.g. {{ settings.img.src | e("full_url") }}; video — { url, ... }, e.g. {{ settings.vid.url }}; link — { href, target, tag } (these are render-time HTML-attribute names, NOT the schema names {destination, isTargetBlank, tag} — the v4 pipeline remaps them), e.g. <a href="{{ settings.cta.href }}" target="{{ settings.cta.target }}">. Boolean/color have no default visual. Image "empty state" gotcha: image props always fall back to Elementor\'s bundled placeholder-v4.svg when the user hasn\'t uploaded one, so {{ settings.X.src }} is NEVER empty and {% if settings.X.src %}…{% endif %} is dead code. To detect an unset image, check {% if settings.X.id %}…{% endif %} (id is null until an upload exists) or compare src against the placeholder URL. Style-tab integration: the widget\'s outermost element MUST carry base_styles.base (and typically settings.classes) for define_base_styles() and user Style-tab edits to apply — without it, the Style tab silently does nothing. Idiomatic first line: {% set classes = settings.classes | merge( [ base_styles.base, \'elementor-mcp-your-widget\' ] ) | join(\' \') %} then <div class="{{ classes }}">. Do NOT include <script> tags — use the js parameter instead.',
            ],
            'js' => [
                'type' => 'string',
                'description' => 'Optional frontend JavaScript. Saved as a separate file and enqueued on pages that use this widget. The widget container has id="elementor-mcp-{name}-{id}" where {id} is the element ID. Use document.querySelectorAll("[class*=elementor-mcp-{name}]") to target all instances. Do NOT include <script> tags.',
            ],
            'js_deps' => [
                'type' => 'array',
                'description' => 'Optional JS dependency handles to load before the widget script. Use registered WordPress handles like "jquery", or CDN URLs (https only).',
                'items' => ['type' => 'string'],
            ],
            'css' => [
                'type' => 'string',
                'description' => 'Optional frontend CSS. Saved as a separate file at assets/{name}.css and enqueued once per page that uses this widget. Prefer this over inline <style> blocks in the Twig template, which duplicate per render. Scope selectors to a class you also emit in the Twig (e.g. ".elementor-mcp-{name}") — base_styles classes are Elementor-generated and should be reserved for the v4 Style Schema.',
            ],
            'css_deps' => [
                'type' => 'array',
                'description' => 'Optional CSS dependency handles to load before the widget stylesheet. Use registered WordPress style handles, or CDN URLs (https only).',
                'items' => ['type' => 'string'],
            ],
            'base_styles' => [
                'type' => 'object',
                'description' => 'Optional Style-tab-editable default styles for the widget\'s base class (drives define_base_styles()). CSS property → value map using the same shape as elementor-mcp/elementor-create-global-class\'s "styles" — scalars are auto-wrapped against the v4 Style Schema (e.g. "color":"#111", "padding":24, "padding":{"block-start":24,"inline-end":24,"block-end":24,"inline-start":24}); long-form `{$$type, value}` shapes also work. Compound prop types (background, box-shadow, border, transform, …) need their nested shape even at the ergonomic level — e.g. "background":{"color":"#ffffff"}, NOT "background":"#ffffff"; scalar auto-wrap applies to the inner fields, not to the outer envelope. Call elementor-mcp/elementor-get-style-schema for the exact nested shape per property. Validated fail-hard against the Style Schema with per-property errors. When omitted, the widget is emitted with an empty define_base_styles() so users see a clean Style tab. Use "css" instead for static shell styling that should NOT be editable in the Style tab.',
                'additionalProperties' => true,
            ],
            'overwrite' => [
                'type' => 'boolean',
                'description' => 'When true, regenerate all widget files even if they already exist on disk. Destroys any hand edits to elementor-mcp-widget.php, the Twig template, the loader, and the JS/CSS assets — callers must re-supply the full props/twig/js/css. Defaults to false: calls against an existing widget name are rejected with a collision error and a suggested alternative name.',
            ],
        ],
        'required' => ['name', 'title', 'props'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'files' => [
                'type' => 'array',
                'description' => 'Absolute paths of files written on success.',
                'items' => ['type' => 'string'],
            ],
            'warnings' => [
                'type' => 'array',
                'description' => 'Non-blocking warnings about the widget configuration.',
                'items' => ['type' => 'string'],
            ],
            'error' => ['type' => 'string'],
            'failed_paths' => [
                'type' => 'array',
                'description' => 'On write failure: list of {path, reason} entries. reason is one of mkdir_failed, parent_dir_missing, write_failed.',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'path' => ['type' => 'string'],
                        'reason' => ['type' => 'string'],
                    ],
                ],
            ],
            'collision_paths' => [
                'type' => 'array',
                'description' => 'On collision failure: existing paths that would have been overwritten. The widget is not written; pick a different name (see suggested_name), delete the existing files first, or retry with overwrite:true.',
                'items' => ['type' => 'string'],
            ],
            'suggested_name' => [
                'type' => 'string',
                'description' => 'On collision failure: an alternative widget name that does not collide with existing files on disk.',
            ],
            'overwritten_paths' => [
                'type' => 'array',
                'description' => 'On success with overwrite:true: paths that existed on disk and were regenerated from the current input.',
                'items' => ['type' => 'string'],
            ],
            'unknown_properties' => [
                'type' => 'array',
                'description' => 'On base_styles validation failure: CSS property names that are not in the v4 Style Schema. Call elementor-mcp/elementor-get-style-schema for the full list of valid properties.',
                'items' => ['type' => 'string'],
            ],
            'invalid_values' => [
                'type' => 'object',
                'description' => 'On base_styles validation failure: per-property {received_type, expected_types, reason} describing why the value failed schema validation.',
            ],
        ],
        'required' => ['success'],
    ],
    'execute_callback' => 'ElementorMCP\Abilities\Elementor\create_elementor_atomic_widget',
    'permission_callback' => 'elementor_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true, 'type' => 'tool'],
        'annotations' => [
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

/**
 * Convert kebab-case to PascalCase for class names.
 */
function kebab_to_pascal(string $slug): string
{
    return str_replace(
        search: ' ',
        replace: '_',
        subject: ucwords(str_replace(search: '-', replace: ' ', subject: $slug)),
    );
}

/**
 * Generate the prop schema line for a given prop.
 *
 * @param array{name: string, type: string, label?: string, default?: string|int|float|bool, options?: list<array{value: string, label: string}>} $prop
 */
function generate_prop_schema(array $prop): string
{
    $name = $prop['name'];
    $default = $prop['default'] ?? null;
    $str_default = cew_php_string_literal($default);

    return match ($prop['type']) {
        'image' => cew_prop_schema_entry($name, prop_class: 'Image_Prop_Type', chain: [
            ['default_url',  cew_image_default_url_expr($default)],
            ['default_size', "'full'"],
        ]),
        'html' => cew_prop_schema_entry($name, prop_class: 'Html_V3_Prop_Type', chain: [
            ['default', cew_html_default_expr($default)],
        ]),
        'select' => generate_select_prop_schema($prop),
        'link' => cew_prop_schema_entry($name, prop_class: 'Link_Prop_Type'),
        'date_time' => cew_prop_schema_entry($name, prop_class: 'Date_Time_Prop_Type'),
        'number' => cew_prop_schema_entry($name, prop_class: 'Number_Prop_Type', chain: [
            ['default', $default !== null ? (string) (float) $default : '0'],
        ]),
        'boolean' => cew_prop_schema_entry($name, prop_class: 'Boolean_Prop_Type', chain: [
            ['default', $default === true ? 'true' : 'false'],
        ]),
        'video' => cew_prop_schema_entry($name, prop_class: 'Video_Src_Prop_Type', chain: [
            ['default_url', $str_default],
        ]),
        'string', 'email', 'textarea' => cew_prop_schema_entry($name, prop_class: 'String_Prop_Type', chain: [
            ['default', $str_default],
        ]),
        'color' => cew_prop_schema_entry($name, prop_class: 'Color_Prop_Type', chain: [
            ['default', $str_default],
        ]),
        default => '',
    };
}

/**
 * Build a `'name' => Prop_Type::make()->chain(arg)->chain(arg),` line
 * for the generated widget's `props_schema()` method. `$chain` is an
 * ordered list of `[method_name, php_expression_literal]` pairs — each
 * becomes a `->method(expression)` call on a new line, indented 16
 * spaces to match the class body of the generated file. Passing an
 * empty chain yields a bare `Prop_Type::make(),` line.
 *
 * @param list<array{0: string, 1: string}> $chain
 */
function cew_prop_schema_entry(string $name, string $prop_class, array $chain = []): string
{
    $suffix = '';
    foreach ($chain as $call) {
        $suffix .= sprintf("\n                ->%s(%s)", $call[0], $call[1]);
    }
    return sprintf("            '%s' => %s::make()%s,", $name, $prop_class, $suffix);
}

/**
 * Quote an arbitrary default value as a single-quoted PHP string
 * literal, escaping embedded single quotes and backslashes. Null and
 * missing defaults become the empty string literal `''`.
 */
function cew_php_string_literal(mixed $default): string
{
    return "'" . addslashes((string) ($default ?? '')) . "'";
}

/**
 * Build the `default_url(...)` argument expression for an image prop.
 * Falls back to the placeholder image expression when no default is
 * given.
 */
function cew_image_default_url_expr(mixed $default): string
{
    if ($default === null || $default === '') {
        return 'Placeholder_Image::get_placeholder_image()';
    }
    return cew_php_string_literal($default);
}

/**
 * Build the `default([...])` argument expression for an html-v3 prop.
 * The default becomes a structured array literal with a `content`
 * wrapped in `String_Prop_Type::generate()` and an empty `children`
 * list.
 */
function cew_html_default_expr(mixed $default): string
{
    $content = (string) ($default === null || $default === '' ? 'Enter text here' : $default);
    return sprintf(
        "[\n                    'content' => String_Prop_Type::generate('%s'),\n                    'children' => [],\n                ]",
        addslashes($content),
    );
}

/**
 * Generate prop schema for select type.
 *
 * @param array{name: string, type: string, label?: string, default?: string|int|float|bool, options?: list<array{value: string, label: string}>} $prop
 */
function generate_select_prop_schema(array $prop): string
{
    $name = $prop['name'];
    $options = $prop['options'] ?? [];
    $values = array_map(fn(array $opt): string => "'" . addslashes($opt['value']) . "'", $options);
    $default = $prop['default'] ?? $options[0]['value'] ?? '';

    return sprintf(
        "            '%s' => String_Prop_Type::make()\n                ->enum([%s])\n                ->default('%s'),",
        $name,
        implode(', ', $values),
        addslashes((string) $default),
    );
}

/**
 * Generate the control line for a given prop.
 *
 * @param array{name: string, type: string, label?: string, default?: string|int|float|bool, options?: list<array{value: string, label: string}>} $prop
 */
function generate_control(array $prop): string
{
    $type = $prop['type'];

    if ($type === 'color') {
        // No standard control; handled by the style system.
        return '';
    }
    if ($type === 'select') {
        return generate_select_control($prop);
    }

    $control_class = simple_control_class_for_type($type);
    if ($control_class === null) {
        return '';
    }

    $name = $prop['name'];
    $label = $prop['label'] ?? ucwords(str_replace(search: '_', replace: ' ', subject: $name));

    return sprintf(
        "                    %s::bind_to('%s')\n                        ->set_label(__('%s', 'elementor-mcp')),",
        $control_class,
        $name,
        addslashes($label),
    );
}

/**
 * Map a prop type to the Elementor v4 control class that renders it as
 * a single-line `bind_to(...)->set_label(...)` declaration. Returns
 * null for types that need their own generator (`select`) or are
 * handled outside the control system (`color` → style schema) or are
 * unknown.
 */
function simple_control_class_for_type(string $type): ?string
{
    $map = [
        'image' => 'Image_Control',
        'string' => 'Text_Control',
        'html' => 'Inline_Editing_Control',
        'link' => 'Link_Control',
        'number' => 'Number_Control',
        'boolean' => 'Switch_Control',
        'email' => 'Text_Control',
        'video' => 'Video_Control',
        'textarea' => 'Textarea_Control',
        'date_time' => 'Date_Time_Control',
    ];
    return $map[$type] ?? null;
}

/**
 * Generate a Select_Control for select type props.
 *
 * @param array{name: string, type: string, label?: string, default?: string|int|float|bool, options?: list<array{value: string, label: string}>} $prop
 */
function generate_select_control(array $prop): string
{
    $name = $prop['name'];
    $label = $prop['label'] ?? ucwords(str_replace(search: '_', replace: ' ', subject: $name));
    $options = $prop['options'] ?? [];

    $option_lines = [];
    foreach ($options as $opt) {
        $option_lines[] = sprintf(
            "                                ['value' => '%s', 'label' => '%s']",
            addslashes($opt['value']),
            addslashes($opt['label']),
        );
    }
    $options_str = implode(",\n", $option_lines);

    return sprintf(
        "                    Select_Control::bind_to('%s')\n                        ->set_options([\n%s,\n                            ])\n                        ->set_label(__('%s', 'elementor-mcp')),",
        $name,
        $options_str,
        addslashes($label),
    );
}

/**
 * Generate a default Twig template based on props.
 *
 * @param list<array{name: string, type: string, label?: string, default?: string|int|float|bool, options?: list<array{value: string, label: string}>}> $props
 */
function generate_default_twig(array $props): string
{
    $lines = [
        "{% set classes = settings.classes | merge( [ base_styles.base ] ) | join(' ') %}",
        '<div class="{{ classes }}">',
    ];

    foreach ($props as $prop) {
        $snippet = cew_twig_snippet_for_prop($prop);
        if ($snippet !== '') {
            $lines[] = $snippet;
        }
    }

    $lines[] = '</div>';

    return implode("\n", $lines);
}

/**
 * Return the default Twig snippet that renders one prop's value on the
 * widget template. Most snippets wrap a single HTML tag inside a
 * `{% if ... %}` guard; a few props render a plain `<span>` and a
 * couple (boolean, color) render nothing by default.
 *
 * @param array{name: string, type: string, label?: string, default?: string|int|float|bool, options?: list<array{value: string, label: string}>} $prop
 */
function cew_twig_snippet_for_prop(array $prop): string
{
    $name = $prop['name'];
    $access = "settings.{$name}";

    return match ($prop['type']) {
        'image' => cew_twig_if(
            "{$access}.src is not empty",
            "<img src=\"{{ {$access}.src | e('full_url') }}\" alt=\"\" />",
        ),
        'string' => cew_twig_if("{$access} is not empty", "<span>{{ {$access} }}</span>"),
        'html' => cew_twig_if(
            "{$access} is not empty",
            "<div>{{ {$access} | striptags('<b><strong><em><i><u><a><br><span>') | raw }}</div>",
        ),
        'email' => cew_twig_if("{$access} is not empty", "<a href=\"mailto:{{ {$access} }}\">{{ {$access} }}</a>"),
        'video' => cew_twig_if("{$access}.url is not empty", "<video src=\"{{ {$access}.url }}\" controls></video>"),
        'textarea' => cew_twig_if("{$access} is not empty", "<p>{{ {$access} }}</p>"),
        'link' => cew_twig_if("{$access}.href", cew_twig_link_body($access)),
        'number', 'select', 'date_time' => "    <span>{{ {$access} }}</span>",
        // boolean + color have no default visual output.
        'boolean', 'color' => '',
        default => '',
    };
}

/**
 * Wrap a single-line body in a Twig `{% if %}...{% endif %}` guard
 * with the standard 4/8-space indentation the generated templates
 * use.
 */
function cew_twig_if(string $condition, string $body): string
{
    return "    {% if {$condition} %}\n        {$body}\n    {% endif %}";
}

/**
 * Build the body for the `link` prop's Twig snippet: a dynamic tag
 * (via `e('html_tag')`) that uses `href` when the tag is `a` and
 * `data-action-link` otherwise, with `target` preserved from the
 * settings.
 */
function cew_twig_link_body(string $access): string
{
    $tag = "{{ {$access}.tag | e('html_tag') }}";
    $href_attr =
        "{% if {$access}.tag == 'a' %}href=\"{{ {$access}.href }}\""
        . "{% else %}data-action-link=\"{{ {$access}.href }}\"{% endif %}";
    return "<{$tag} {$href_attr} target=\"{{ {$access}.target }}\">Link</{$tag}>";
}

/**
 * Build the use statements needed for the widget class.
 *
 * @param list<array{name: string, type: string, label?: string, default?: string|int|float|bool, options?: list<array{value: string, label: string}>}> $props
 * @return list<string>
 */
function collect_use_statements(array $props): array
{
    $uses = [
        'use Elementor\\Modules\\AtomicWidgets\\Elements\\Base\\Atomic_Widget_Base;',
        'use Elementor\\Modules\\AtomicWidgets\\Elements\\Base\\Has_Template;',
        'use Elementor\\Modules\\AtomicWidgets\\Controls\\Section;',
        'use Elementor\\Modules\\AtomicWidgets\\Styles\\Style_Definition;',
        'use Elementor\\Modules\\AtomicWidgets\\Styles\\Style_Variant;',
        'use Elementor\\Modules\\AtomicWidgets\\PropTypes\\Classes_Prop_Type;',
        'use Elementor\\Modules\\AtomicWidgets\\PropTypes\\Primitives\\String_Prop_Type;',
    ];

    $type_map = [
        'image' => [
            'use Elementor\\Modules\\AtomicWidgets\\PropTypes\\Image_Prop_Type;',
            'use Elementor\\Modules\\AtomicWidgets\\Controls\\Types\\Image_Control;',
            'use Elementor\\Modules\\AtomicWidgets\\Utils\\Image\\Placeholder_Image;',
        ],
        'string' => [
            'use Elementor\\Modules\\AtomicWidgets\\PropTypes\\Primitives\\String_Prop_Type;',
            'use Elementor\\Modules\\AtomicWidgets\\Controls\\Types\\Text_Control;',
        ],
        'html' => [
            'use Elementor\\Modules\\AtomicWidgets\\PropTypes\\Html_V3_Prop_Type;',
            'use Elementor\\Modules\\AtomicWidgets\\PropTypes\\Primitives\\String_Prop_Type;',
            'use Elementor\\Modules\\AtomicWidgets\\Controls\\Types\\Inline_Editing_Control;',
        ],
        'link' => [
            'use Elementor\\Modules\\AtomicWidgets\\PropTypes\\Link_Prop_Type;',
            'use Elementor\\Modules\\AtomicWidgets\\Controls\\Types\\Link_Control;',
        ],
        'number' => [
            'use Elementor\\Modules\\AtomicWidgets\\PropTypes\\Primitives\\Number_Prop_Type;',
            'use Elementor\\Modules\\AtomicWidgets\\Controls\\Types\\Number_Control;',
        ],
        'boolean' => [
            'use Elementor\\Modules\\AtomicWidgets\\PropTypes\\Primitives\\Boolean_Prop_Type;',
            'use Elementor\\Modules\\AtomicWidgets\\Controls\\Types\\Switch_Control;',
        ],
        // 'url' type removed — use 'link' or 'string' instead.
        'email' => [
            'use Elementor\\Modules\\AtomicWidgets\\PropTypes\\Primitives\\String_Prop_Type;',
            'use Elementor\\Modules\\AtomicWidgets\\Controls\\Types\\Text_Control;',
        ],
        'color' => [
            'use Elementor\\Modules\\AtomicWidgets\\PropTypes\\Color_Prop_Type;',
        ],
        'select' => [
            'use Elementor\\Modules\\AtomicWidgets\\PropTypes\\Primitives\\String_Prop_Type;',
            'use Elementor\\Modules\\AtomicWidgets\\Controls\\Types\\Select_Control;',
        ],
        'video' => [
            'use Elementor\\Modules\\AtomicWidgets\\PropTypes\\Video_Src_Prop_Type;',
            'use Elementor\\Modules\\AtomicWidgets\\Controls\\Types\\Video_Control;',
        ],
        'textarea' => [
            'use Elementor\\Modules\\AtomicWidgets\\PropTypes\\Primitives\\String_Prop_Type;',
            'use Elementor\\Modules\\AtomicWidgets\\Controls\\Types\\Textarea_Control;',
        ],
        'date_time' => [
            'use Elementor\\Modules\\AtomicWidgets\\PropTypes\\Date_Time_Prop_Type;',
            'use Elementor\\Modules\\AtomicWidgets\\Controls\\Types\\Date_Time_Control;',
        ],
    ];

    $seen_types = [];
    foreach ($props as $prop) {
        $type = $prop['type'];
        if (array_key_exists($type, $seen_types)) {
            continue;
        }
        $seen_types[$type] = true;
        if (array_key_exists($type, $type_map)) {
            array_push($uses, ...$type_map[$type]);
        }
    }

    return array_values(array_unique($uses));
}

/**
 * Build the props schema string for a widget class.
 *
 * @param list<array{name: string, type: string, label?: string, default?: string|int|float|bool, options?: list<array{value: string, label: string}>}> $props
 */
function cew_build_props_schema(array $props): string
{
    $schema_lines = [
        "            'classes' => Classes_Prop_Type::make()->default([]),",
    ];
    foreach ($props as $prop) {
        $line = generate_prop_schema($prop);
        if ($line !== '') {
            $schema_lines[] = $line;
        }
    }

    return implode("\n", $schema_lines);
}

/**
 * Build the controls string for a widget class.
 *
 * @param list<array{name: string, type: string, label?: string, default?: string|int|float|bool, options?: list<array{value: string, label: string}>}> $props
 */
function cew_build_controls(array $props): string
{
    $control_lines = [];
    foreach ($props as $prop) {
        $line = generate_control($prop);
        if ($line !== '') {
            $control_lines[] = $line;
        }
    }

    return implode("\n", $control_lines);
}

/**
 * Render a PHP value as a short-array literal expression, ready to paste into
 * the generated widget class. Handles the subset of types produced by the v4
 * Style Schema's `$$type`/`value` tree: scalars, null, and nested assoc/list
 * arrays. Strings are single-quoted with `\\` and `'` escaped.
 *
 * `$indent` is the column at which the opening `[` sits — children are
 * rendered at `$indent + 4` and the closing `]` sits back at `$indent`.
 */
function cew_emit_php_literal(mixed $value, int $indent): string
{
    if ($value === null) {
        return 'null';
    }
    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }
    if (is_int($value) || is_float($value)) {
        return (string) $value;
    }
    if (is_string($value)) {
        return "'" . strtr($value, ['\\' => '\\\\', "'" => "\\'"]) . "'";
    }
    if (!is_array($value)) {
        return 'null';
    }
    if ($value === []) {
        return '[]';
    }

    $is_list = el_array_is_list($value);
    $pad = str_repeat(' ', $indent);
    $inner_pad = str_repeat(' ', $indent + 4);
    $lines = [];
    /** @var mixed $v */
    foreach ($value as $k => $v) {
        $rhs = cew_emit_php_literal($v, indent: $indent + 4);
        if ($is_list) {
            $lines[] = $inner_pad . $rhs . ',';
            continue;
        }
        $key_literal = is_int($k) ? (string) $k : "'" . strtr($k, ['\\' => '\\\\', "'" => "\\'"]) . "'";
        $lines[] = sprintf('%s%s => %s,', $inner_pad, $key_literal, $rhs);
    }
    return "[\n" . implode("\n", $lines) . "\n" . $pad . ']';
}

/**
 * Build the `define_base_styles()` method body for insertion into the widget
 * class heredoc. Empty `$base_styles` → `return [];` (default case, so the
 * Style tab has no pre-populated base group). Non-empty → one `add_prop()`
 * call per validated entry, using the normalized `{$$type, value}` struct as
 * a literal argument. Indentation matches the heredoc's 8-space trim prefix
 * so the emitted method sits at the same column as its sibling definitions.
 *
 * @param array<string, mixed> $base_styles Validated + normalized CSS prop → value map.
 */
function cew_build_base_styles_method(array $base_styles): string
{
    if ($base_styles === []) {
        return implode("\n", [
            '    protected function define_base_styles(): array {',
            '        return [];',
            '    }',
        ]);
    }

    $add_prop_lines = [];
    /** @var mixed $value */
    foreach ($base_styles as $prop => $value) {
        $literal = cew_emit_php_literal($value, indent: 24);
        $add_prop_lines[] = sprintf("                        ->add_prop('%s', %s)", addslashes($prop), $literal);
    }

    $head = [
        '    protected function define_base_styles(): array {',
        '        return [',
        "            'base' => Style_Definition::make()",
        "                ->set_label(__('Base', 'elementor-mcp'))",
        '                ->add_variant(',
        '                    Style_Variant::make()',
    ];
    $tail = [
        '                ),',
        '        ];',
        '    }',
    ];
    return implode("\n", [...$head, ...$add_prop_lines, ...$tail]);
}

/**
 * Build the PHP widget class file content.
 *
 * @param array{name: string, title: string, description: string, icon: string, namespace: string, class_name: string} $meta
 * @param list<array{name: string, type: string, label?: string, default?: string|int|float|bool, options?: list<array{value: string, label: string}>}> $props
 * @param array<string, mixed> $base_styles Validated + normalized CSS prop → value map; empty for the default-no-shell case.
 */
function cew_build_widget_file_content(array $meta, array $props, array $base_styles): string
{
    $name = $meta['name'];
    $title = $meta['title'];
    $description = $meta['description'];
    $icon = $meta['icon'];
    $namespace = $meta['namespace'];
    $class_name = $meta['class_name'];

    $use_statements = implode("\n", collect_use_statements($props));
    $props_schema = cew_build_props_schema($props);
    $controls = cew_build_controls($props);
    $base_styles_method = cew_build_base_styles_method($base_styles);

    return <<<PHP
        <?php
        namespace {$namespace};

        {$use_statements}

        if (!defined('ABSPATH')) {
            exit;
        }

        class {$class_name}_Widget extends Atomic_Widget_Base {
            use Has_Template;

            public static \$widget_description = '{$description}';

            public static function get_element_type(): string {
                return '{$name}';
            }

            public function get_title(): string {
                return __('{$title}', 'elementor-mcp');
            }

            public function get_icon(): string {
                return '{$icon}';
            }

            public function get_keywords(): array {
                return ['elementor-mcp'];
            }

            protected static function define_props_schema(): array {
                return [
        {$props_schema}
                ];
            }

            protected function define_atomic_controls(): array {
                return [
                    Section::make()
                        ->set_label(__('Content', 'elementor-mcp'))
                        ->set_items([
        {$controls}
                        ]),
                ];
            }

        {$base_styles_method}

            protected function get_templates(): array {
                return [
                    'elementor/widgets/{$name}' => __DIR__ . '/templates/{$name}.html.twig',
                ];
            }
        }
        PHP;
}

/**
 * Build the JS enqueue block for the loader mu-plugin.
 *
 * @param list<string> $js_deps
 */
function cew_build_js_enqueue(string $name, array $js_deps): string
{
    $cdn_registers = '';
    $dep_handles = [];
    foreach ($js_deps as $i => $dep) {
        if (!str_starts_with($dep, 'http://') && !str_starts_with($dep, 'https://')) {
            $dep_handles[] = $dep;
            continue;
        }
        $handle = "elementor-mcp-dep-{$name}-{$i}";
        $cdn_registers .= "\n    wp_register_script('{$handle}', '{$dep}', [], null, ['in_footer' => true]);";
        $dep_handles[] = $handle;
    }
    $deps_array = $dep_handles === [] ? '[]' : "['" . implode("', '", $dep_handles) . "']";

    return (
        "\n\n"
        . "add_action('wp_enqueue_scripts', function () {{$cdn_registers}\n    wp_register_script(\n        'elementor-mcp-widget-{$name}',\n        plugins_url('{$name}/assets/{$name}.js', __FILE__),\n        {$deps_array},\n        '1.0.0',\n        ['in_footer' => true]\n    );\n});\n\nadd_action('elementor/frontend/after_enqueue_scripts', function () {\n    wp_enqueue_script('elementor-mcp-widget-{$name}');\n});"
    );
}

/**
 * Build the CSS enqueue block for the loader mu-plugin. Mirrors
 * `cew_build_js_enqueue` so agents get a per-widget stylesheet instead
 * of inlining `<style>` blocks in Twig (which duplicate per render).
 *
 * @param list<string> $css_deps
 */
function cew_build_css_enqueue(string $name, array $css_deps): string
{
    $cdn_registers = '';
    $dep_handles = [];
    foreach ($css_deps as $i => $dep) {
        if (!str_starts_with($dep, 'http://') && !str_starts_with($dep, 'https://')) {
            $dep_handles[] = $dep;
            continue;
        }
        $handle = "elementor-mcp-dep-{$name}-style-{$i}";
        $cdn_registers .= "\n    wp_register_style('{$handle}', '{$dep}', [], null);";
        $dep_handles[] = $handle;
    }
    $deps_array = $dep_handles === [] ? '[]' : "['" . implode("', '", $dep_handles) . "']";

    return (
        "\n\n"
        . "add_action('wp_enqueue_scripts', function () {{$cdn_registers}\n    wp_register_style(\n        'elementor-mcp-widget-{$name}-style',\n        plugins_url('{$name}/assets/{$name}.css', __FILE__),\n        {$deps_array},\n        '1.0.0'\n    );\n});\n\nadd_action('elementor/frontend/after_enqueue_styles', function () {\n    wp_enqueue_style('elementor-mcp-widget-{$name}-style');\n});"
    );
}

/**
 * Build the loader mu-plugin file content.
 *
 * @param array{name: string, title: string, description: string, icon: string, namespace: string, class_name: string} $meta
 */
function cew_build_loader_content(array $meta, string $asset_enqueues): string
{
    $name = $meta['name'];
    $title = $meta['title'];
    $description = $meta['description'];
    // Pre-compose the fully-qualified class reference so the heredoc only needs
    // a single, plainly-braced interpolation — heredocs treat the "\\{$var}"
    // pattern literally which confuses downstream parsers.
    $fqcn = '\\' . $meta['namespace'] . '\\' . $meta['class_name'] . '_Widget';

    return <<<PHP
        <?php
        /**
         * Plugin Name: Elementor MCP Widget — {$title}
         * Description: {$description}
         */
        if (!defined('ABSPATH')) {
            exit;
        }

        add_action('elementor/widgets/register', function (\$widgets_manager) {
            require_once __DIR__ . '/{$name}/elementor-mcp-widget.php';
            \$widgets_manager->register(new {$fqcn}());
        });{$asset_enqueues}
        PHP;
}

/**
 * Ensure required directories exist and write all widget files.
 *
 * Propagates failures from `wp_mkdir_p` and `file_put_contents` back to the
 * caller as `{path, reason}` entries so the ability can return a truthful
 * `success:false` instead of silently lying about a widget that never landed
 * on disk — an agent following a "success" response straight into
 * `add-element widget_type:"..."` otherwise hits `Unknown widget_type` with
 * no breadcrumb back to the real write failure.
 *
 * @param array<string, string> $files Map of path => content.
 * @param string $mu_dir
 * @param string $template_dir
 * @param string|null $assets_dir Directory to create only when JS/CSS assets are needed.
 * @return array{failed: list<array{path: string, reason: string}>}
 */
function cew_write_files(array $files, string $mu_dir, string $template_dir, ?string $assets_dir): array
{
    /** @var list<array{path: string, reason: string}> $failed */
    $failed = [];

    $dirs = [$mu_dir, $template_dir];
    if ($assets_dir !== null) {
        $dirs[] = $assets_dir;
    }
    foreach ($dirs as $dir) {
        if (is_dir($dir)) {
            continue;
        }
        if (!wp_mkdir_p($dir)) {
            $failed[] = ['path' => $dir, 'reason' => 'mkdir_failed'];
        }
    }

    foreach ($files as $path => $content) {
        // Skip files whose parent dir failed to materialize — no point
        // attempting a write that is guaranteed to fail and will just
        // duplicate the same root cause in the error list.
        $parent = dirname($path);
        if (!is_dir($parent)) {
            $failed[] = ['path' => $path, 'reason' => 'parent_dir_missing'];
            continue;
        }
        if (file_put_contents($path, $content) === false) {
            $failed[] = ['path' => $path, 'reason' => 'write_failed'];
        }
    }

    return ['failed' => $failed];
}

/**
 * Build the map of files to write for the widget.
 *
 * @param array{name: string, title: string, description: string, icon: string, namespace: string, class_name: string} $meta
 * @param list<array{name: string, type: string, label?: string, default?: string|int|float|bool, options?: list<array{value: string, label: string}>}> $props
 * @param array{js: string, js_deps: list<string>, css: string, css_deps: list<string>} $assets
 * @param array<string, mixed> $base_styles Validated + normalized CSS prop → value map; empty for the default-no-shell case.
 * @return array{files: array<string, string>, mu_dir: string, template_dir: string, assets_dir: string|null}
 */
function cew_build_file_map(array $meta, array $props, string $twig, array $assets, array $base_styles): array
{
    $name = $meta['name'];
    $mu_dir = WPMU_PLUGIN_DIR;
    $widget_dir = $mu_dir . '/' . $name;
    $template_dir = $widget_dir . '/templates';
    $assets_dir = $widget_dir . '/assets';

    $asset_enqueues = '';
    if ($assets['js'] !== '') {
        $asset_enqueues .= cew_build_js_enqueue($name, $assets['js_deps']);
    }
    if ($assets['css'] !== '') {
        $asset_enqueues .= cew_build_css_enqueue($name, $assets['css_deps']);
    }
    $widget_php = cew_build_widget_file_content($meta, $props, $base_styles);
    $loader_php = cew_build_loader_content($meta, $asset_enqueues);

    $files = [
        $widget_dir . '/elementor-mcp-widget.php' => $widget_php,
        $template_dir . '/' . $name . '.html.twig' => $twig,
        $mu_dir . '/' . $name . '-loader.php' => $loader_php,
    ];

    if ($assets['js'] !== '') {
        $files[$assets_dir . '/' . $name . '.js'] = $assets['js'];
    }
    if ($assets['css'] !== '') {
        $files[$assets_dir . '/' . $name . '.css'] = $assets['css'];
    }

    $has_assets = $assets['js'] !== '' || $assets['css'] !== '';
    return [
        'files' => $files,
        'mu_dir' => $mu_dir,
        'template_dir' => $template_dir,
        'assets_dir' => $has_assets ? $assets_dir : null,
    ];
}

/**
 * Create an Elementor v4 atomic widget.
 *
 * @param array<string, mixed> $input
 * @return array{success: bool, files?: list<string>, warnings?: list<string>, error?: string, validation_errors?: list<string>, failed_paths?: list<array{path: string, reason: string}>, collision_paths?: list<string>, suggested_name?: string, overwritten_paths?: list<string>, unknown_properties?: list<string>, invalid_values?: array<string, array{received_type: string, expected_types: list<string>, reason: string}>}
 */
function create_elementor_atomic_widget(array $input): array
{
    if (!class_exists(ELEMENTOR_MCP_ELEMENTOR_ATOMIC_BASE_CLASS)) {
        return [
            'success' => false,
            'error' => 'Elementor Atomic Widgets module is not available on this site. Atomic widget generation requires Elementor 3.27+ with the atomic-widgets experiment active, or Elementor 4.0+. Generating an atomic widget on a site without the runtime would produce code that cannot register itself.',
        ];
    }

    $parsed = cew_parse_input($input);
    if (array_key_exists('error_response', $parsed)) {
        return $parsed['error_response'];
    }

    // Static analyzer can't narrow the union once the error branch is
    // returned — pin the success variant so every dereference below is
    // a simple indexed access and not a possibly-undefined check.
    /**
     * @var array{
     *     meta: array{name: string, title: string, description: string, icon: string, namespace: string, class_name: string},
     *     props: list<array{name: string, type: string, label?: string, default?: string|int|float|bool, options?: list<array{value: string, label: string}>}>,
     *     twig: string,
     *     custom_js: string,
     *     js_deps: list<string>,
     *     custom_css: string,
     *     css_deps: list<string>,
     *     base_styles: array<string, mixed>,
     *     overwrite: bool,
     *     warnings: list<string>
     * } $parsed
     */
    $map = cew_build_file_map(
        $parsed['meta'],
        $parsed['props'],
        $parsed['twig'],
        [
            'js' => $parsed['custom_js'],
            'js_deps' => $parsed['js_deps'],
            'css' => $parsed['custom_css'],
            'css_deps' => $parsed['css_deps'],
        ],
        $parsed['base_styles'],
    );

    // Preflight the mu-plugins directory: on a read-only filesystem the
    // writes below would all fail the same way, so short-circuit with a
    // directly-actionable hint instead of an empty-list "write_failed".
    $preflight = cew_preflight_mu_dir($map['mu_dir']);
    if ($preflight !== null) {
        return $preflight;
    }

    $collisions = cew_check_collisions($map['files']);
    if ($collisions !== [] && !$parsed['overwrite']) {
        $suggested = cew_suggest_name($map['mu_dir'], $parsed['meta']['name']);
        return [
            'success' => false,
            'error' => sprintf(
                'Widget "%s" already has files on disk — refusing to overwrite. Delete the existing widget files first, retry with a different name (suggested: "%s"), or pass overwrite:true to regenerate from scratch.',
                $parsed['meta']['name'],
                $suggested,
            ),
            'collision_paths' => $collisions,
            'suggested_name' => $suggested,
        ];
    }

    // When overwriting without supplying an asset, the previously-generated
    // assets/<name>.{js,css} becomes an orphan (the new loader no longer
    // enqueues it). Remove it so the widget dir reflects the current
    // input — "overwrite" means the call's state wins, including removal.
    if ($parsed['overwrite']) {
        $drop_exts = [];
        if ($parsed['custom_js'] === '') {
            $drop_exts[] = 'js';
        }
        if ($parsed['custom_css'] === '') {
            $drop_exts[] = 'css';
        }
        cew_cleanup_stale_assets($map['mu_dir'], $parsed['meta']['name'], $drop_exts);
    }

    $write = cew_write_files($map['files'], $map['mu_dir'], $map['template_dir'], $map['assets_dir']);
    if ($write['failed'] !== []) {
        return [
            'success' => false,
            'error' => cew_write_failure_message($write['failed'], $map['mu_dir']),
            'failed_paths' => $write['failed'],
        ];
    }

    $result = ['success' => true, 'files' => array_keys($map['files'])];
    if ($collisions !== []) {
        $result['overwritten_paths'] = $collisions;
    }
    if ($parsed['warnings'] !== []) {
        $result['warnings'] = $parsed['warnings'];
    }
    return $result;
}

/**
 * Extract and validate the optional `base_styles` input via the shared
 * Style Schema validator. Extracted out of `cew_parse_input` so that orchestrator
 * stays under the cyclomatic/halstead thresholds the analyzer enforces.
 * Returns either the normalized props map or a pre-built error response with
 * the same `unknown_properties` / `invalid_values` shape as the global class
 * abilities, prefixed with "base_styles is invalid." for disambiguation when
 * an agent is driving multiple style surfaces in the same call.
 *
 * @param array<string, mixed> $input
 * @return array{base_styles: array<string, mixed>}|array{error_response: array{success: false, error: string, unknown_properties?: list<string>, invalid_values?: array<string, array{received_type: string, expected_types: list<string>, reason: string}>}}
 */
function cew_parse_base_styles(array $input): array
{
    /** @var array<string, mixed> $raw */
    $raw = is_array($input['base_styles'] ?? null) ? $input['base_styles'] : [];
    if ($raw === []) {
        return ['base_styles' => []];
    }

    $validation = el_validate_style_props($raw);
    if (array_key_exists('props', $validation)) {
        return ['base_styles' => $validation['props']];
    }

    /** @var array{error: string, unknown_properties?: list<string>, invalid_values?: array<string, array{received_type: string, expected_types: list<string>, reason: string}>} $validation */
    $error_response = [
        'success' => false,
        'error' => 'base_styles is invalid. ' . $validation['error'],
    ];
    if (array_key_exists('unknown_properties', $validation)) {
        $error_response['unknown_properties'] = $validation['unknown_properties'];
    }
    if (array_key_exists('invalid_values', $validation)) {
        $error_response['invalid_values'] = $validation['invalid_values'];
    }
    return ['error_response' => $error_response];
}

/**
 * Parse and validate raw RPC input for create-atomic-widget and assemble the
 * resolved request (meta, props, twig source, JS, deps, base_styles). Returns
 * either the ready-to-use struct or a pre-built error response when the input
 * is rejected by `validate_widget_input` or by the base_styles Style Schema
 * validator.
 *
 * Extracted out of the main orchestrator so the latter stays readable and
 * stays under the complexity threshold the analyzer enforces.
 *
 * @param array<string, mixed> $input
 * @return array{error_response: array{success: false, error: string, validation_errors?: list<string>, unknown_properties?: list<string>, invalid_values?: array<string, array{received_type: string, expected_types: list<string>, reason: string}>}}|array{meta: array{name: string, title: string, description: string, icon: string, namespace: string, class_name: string}, props: list<array{name: string, type: string, label?: string, default?: string|int|float|bool, options?: list<array{value: string, label: string}>}>, twig: string, custom_js: string, js_deps: list<string>, custom_css: string, css_deps: list<string>, base_styles: array<string, mixed>, overwrite: bool, warnings: list<string>}
 */
function cew_parse_input(array $input): array
{
    $validation = validate_widget_input($input);
    if ($validation['errors'] !== []) {
        return [
            'error_response' => [
                'success' => false,
                'error' => implode(' ', $validation['errors']),
                'validation_errors' => $validation['errors'],
            ],
        ];
    }

    $bs_result = cew_parse_base_styles($input);
    if (array_key_exists('error_response', $bs_result)) {
        return $bs_result;
    }
    /** @var array{base_styles: array<string, mixed>} $bs_result */
    $base_styles = $bs_result['base_styles'];

    $name = (string) ($input['name'] ?? '');
    $title = (string) ($input['title'] ?? '');
    /** @var list<array{name: string, type: string, label?: string, default?: string|int|float|bool, options?: list<array{value: string, label: string}>}> $props */
    $props = $input['props'] ?? [];
    $custom_twig = (string) ($input['twig'] ?? '');
    $custom_js = (string) ($input['js'] ?? '');
    /** @var list<string> $js_deps */
    $js_deps = $input['js_deps'] ?? [];
    $custom_css = (string) ($input['css'] ?? '');
    /** @var list<string> $css_deps */
    $css_deps = $input['css_deps'] ?? [];
    $overwrite = ($input['overwrite'] ?? false) === true;

    $class_name = kebab_to_pascal($name);

    return [
        'meta' => [
            'name' => $name,
            'title' => $title,
            'description' => (string) ($input['description'] ?? $title),
            'icon' => (string) ($input['icon'] ?? 'eicon-code'),
            'namespace' => 'ElementorMCP\\Widgets\\' . $class_name,
            'class_name' => $class_name,
        ],
        'props' => $props,
        'twig' => $custom_twig !== '' ? $custom_twig : generate_default_twig($props),
        'custom_js' => $custom_js,
        'js_deps' => $js_deps,
        'custom_css' => $custom_css,
        'css_deps' => $css_deps,
        'base_styles' => $base_styles,
        'overwrite' => $overwrite,
        'warnings' => $validation['warnings'],
    ];
}

/**
 * Remove orphaned `assets/<name>.<ext>` files from a previous generation for
 * each extension the current overwrite call does not supply. The regenerated
 * loader no longer enqueues the dropped asset, so leaving the file around is
 * dead weight and drifts the on-disk state away from the caller's input. Also
 * prunes the `assets/` directory when empty so a future asset-supplying call
 * recreates it cleanly.
 *
 * @param list<string> $drop_exts Extensions (without dot) to remove, e.g. ['js', 'css'].
 */
function cew_cleanup_stale_assets(string $mu_dir, string $name, array $drop_exts): void
{
    $assets_dir = $mu_dir . '/' . $name . '/assets';
    foreach ($drop_exts as $ext) {
        $path = $assets_dir . '/' . $name . '.' . $ext;
        if (file_exists($path)) {
            unlink($path);
        }
    }

    if (!is_dir($assets_dir)) {
        return;
    }
    $leftover = glob($assets_dir . '/*');
    if ($leftover === [] || $leftover === false) {
        rmdir($assets_dir);
    }
}

/**
 * Check if any of the target files already exist on disk. The ability is
 * `idempotent: false` by default, so silently overwriting an existing
 * widget's source would destroy the current code with no breadcrumb — refuse
 * instead and let the caller pick a different name (see `cew_suggest_name`),
 * delete the existing files first, or opt in with `overwrite: true` to
 * regenerate from scratch.
 *
 * @param array<string, string> $files Map of path => content.
 * @return list<string> Paths that already exist on disk.
 */
function cew_check_collisions(array $files): array
{
    $collisions = [];
    foreach (array_keys($files) as $path) {
        if (!file_exists($path)) {
            continue;
        }
        $collisions[] = $path;
    }
    return $collisions;
}

/**
 * Suggest a non-colliding widget name by appending a numeric suffix.
 * Walks "<name>-2", "<name>-3", ... until a slug whose loader and widget
 * directory are both free is found. Caps the search at 99 and falls back to
 * a random suffix so a crowded mu-plugins dir still produces a usable name.
 */
function cew_suggest_name(string $mu_dir, string $base_name): string
{
    for ($i = 2; $i < 100; $i++) {
        $candidate = $base_name . '-' . $i;
        if (!cew_name_collides($mu_dir, $candidate)) {
            return $candidate;
        }
    }
    return $base_name . '-' . bin2hex(random_bytes(4));
}

/**
 * True when the given widget name would collide with something already in
 * `mu-plugins` — either the `<name>-loader.php` file or the `<name>/` widget
 * directory. Centralised so `cew_suggest_name` does not hand back a
 * candidate that `cew_check_collisions` would reject on the next call.
 */
function cew_name_collides(string $mu_dir, string $name): bool
{
    return file_exists($mu_dir . '/' . $name . '-loader.php') || file_exists($mu_dir . '/' . $name);
}

/**
 * Check that `mu-plugins` exists (or can be created) and is writable. Returns
 * an error response ready to be handed back to the caller on failure, or
 * `null` when the directory is usable. Pre-flighting here avoids generating
 * widget content just to drop it on a filesystem that would reject every
 * single write.
 *
 * @return array{success: false, error: string}|null
 */
function cew_preflight_mu_dir(string $mu_dir): ?array
{
    if (!is_dir($mu_dir) && !wp_mkdir_p($mu_dir)) {
        return [
            'success' => false,
            'error' => sprintf(
                'Cannot create mu-plugins directory "%s". Check that the parent directory exists and is writable by the PHP user.',
                $mu_dir,
            ),
        ];
    }
    if (!is_writable($mu_dir)) {
        return [
            'success' => false,
            'error' => sprintf(
                'mu-plugins directory "%s" is not writable by the PHP user. Check filesystem permissions — atomic widgets cannot register without disk access to this directory.',
                $mu_dir,
            ),
        ];
    }
    return null;
}

/**
 * Build a human-readable summary of the first few write failures with a hint
 * at the likely root cause. `is_writable` is re-checked because the mu-plugins
 * dir may have passed the preflight but a nested dir (template/assets) can
 * still be read-only.
 *
 * @param list<array{path: string, reason: string}> $failed
 */
function cew_write_failure_message(array $failed, string $mu_dir): string
{
    $hint = is_writable($mu_dir)
        ? 'Check nested directory permissions, disk space, and path collisions.'
        : sprintf('mu-plugins directory "%s" is not writable by the PHP user.', $mu_dir);

    return sprintf(
        'Failed to write %d widget file(s) — no changes persisted. %s See "failed_paths" for the list of paths and reasons.',
        count($failed),
        $hint,
    );
}

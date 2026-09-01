# Elementor MCP Server for WordPress

<p align="center">
  <a href="../../releases/latest/download/elementor-mcp.zip"><img src="https://img.shields.io/badge/Download_Elementor_MCP_Free-Latest_Stable_ZIP-5B36D0?style=for-the-badge&amp;logo=wordpress" alt="Download the latest stable Elementor MCP Free plugin ZIP"></a>
</p>

<p align="center"><a href="../../releases">View all releases and checksums</a></p>

> Install the attached `elementor-mcp.zip` release asset. GitHub's automatically
> generated “Source code” archives are development snapshots and are not the
> installable WordPress plugin package.

Elementor MCP is an open-source **Elementor MCP server** and **WordPress MCP
server** plugin. It gives AI clients that support the Model Context Protocol
(MCP) a safe, typed connection to WordPress core and Elementor workflows.

Website and documentation: [elementormcp.com](https://elementormcp.com)

## What Elementor MCP does

- Connects compatible AI clients to WordPress through a standards-based MCP
  endpoint.
- Inspects and builds Elementor pages, edits elements and widgets, works with
  templates and global styles, and supports compatible Elementor 4 Atomic
  Elements, Classes, and Variables.
- Provides WordPress content, media, settings, diagnostics, skills, design
  library, and controlled developer operations.
- Protects write operations with WordPress permissions, typed schemas, safety
  profiles, approvals, and change evidence.
- Loads supported forms, SEO, custom-fields, commerce, localization, and theme
  integrations only when their corresponding modules and plugins are present.

The free plugin provides the shared transport, authentication, safety
profiles, approvals, diagnostics, design library, skills, content, media,
settings, developer operations, and the complete Elementor Core workflow:
inspect and build pages, edit structure and widgets, use templates, manage
global styles, and work with Elementor 4 Atomic Elements, Classes, and
Variables when available. Elementor MCP Pro adds advanced specification
reconstruction, dynamic content, display conditions, popups, forms and
submissions, custom code, interactions, SVG upload, and premium workflows.

## Scope

Elementor is the only page-builder integration. WordPress core operations and
non-builder integrations such as forms, SEO, custom fields, commerce,
localization, diagnostics, and theme settings remain available where their
module is installed. No alternate page-builder adapter, loader, skill, or
ability is shipped.

## Download and install

1. [Download the latest stable `elementor-mcp.zip`](../../releases/latest/download/elementor-mcp.zip).
2. In WordPress, open **Plugins → Add New Plugin → Upload Plugin** and upload
   that ZIP.
3. Install and activate Elementor if it is not already active.
4. Activate Elementor MCP.
5. Create an access token or configure the supported OAuth flow.
6. Add the MCP endpoint to your compatible AI client.
7. Keep Production Safe enabled for normal site work.

Install Elementor MCP Pro only when its advanced Elementor and agency
workflows are required. The Pro plugin depends on the Free plugin and is not
included in this public repository.

## Free and Pro

The Free plugin provides the complete connection layer and essential Elementor
and WordPress MCP workflows. Elementor MCP Pro extends it with advanced
specification reconstruction, dynamic content, display conditions, popups,
forms and submissions, custom code, interactions, SVG upload, and premium
agency workflows.

The free plugin includes ten detailed, industry-specific page-building prompts.
The Pro prompt library contains more than 300 specialized prompts.

## MCP connection

After activation, use the connection screen inside WordPress to create a
purpose-scoped credential and copy the generated configuration for your MCP
client. Use HTTPS for remote WordPress sites and grant the connected WordPress
account only the capabilities it needs.

For a safe first session:

1. Connect with Production Safe enabled.
2. Discover the available MCP abilities and their live input schemas.
3. Inspect the existing Elementor document before editing it.
4. Preview consequential changes where a preview is available.
5. Apply only the approved change and review the returned evidence.

The plugin uses the WordPress Abilities API and the official MCP Adapter. Every
state-changing ability is permission-checked, schema-validated, and recorded
in the change evidence system.

## Elementor workflow

Use discovery before writes:

1. Check Elementor setup and version compatibility.
2. Read the current page or template tree.
3. Read the live widget schema before composing settings.
4. Preview the requested change.
5. Apply only the requested change and verify the returned evidence.

Elementor-owned content is protected from raw WordPress content writes so an
ordinary post-content update cannot silently overwrite the visual document.

## Development

The repositories contain PHP source, skills, tests, packaging scripts, and
flat corporate brand assets. Run the repository verification scripts before
creating a release ZIP. Do not commit credentials or production exports.

## License

GPL-2.0-or-later. Product documentation: https://elementormcp.com

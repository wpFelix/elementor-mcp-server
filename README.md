# Elementor MCP Server & WordPress MCP Server Plugin

<p align="center">
  <a href="https://github.com/wpFelix/elementor-mcp-server/releases/latest/download/elementor-mcp.zip"><img src="https://img.shields.io/badge/Download_Elementor_MCP_Free-Latest_Stable_ZIP-5B36D0?style=for-the-badge&amp;logo=wordpress" alt="Download the latest stable Elementor MCP Server and WordPress MCP Server plugin ZIP"></a>
</p>

<p align="center">
  <a href="https://github.com/wpFelix/elementor-mcp-server/releases/latest"><img src="https://img.shields.io/github/v/release/wpFelix/elementor-mcp-server?label=latest%20release" alt="Latest Elementor MCP release"></a>
  <a href="https://github.com/wpFelix/elementor-mcp-server/actions/workflows/ci.yml"><img src="https://github.com/wpFelix/elementor-mcp-server/actions/workflows/ci.yml/badge.svg" alt="Elementor MCP Server continuous integration status"></a>
  <a href="LICENSE"><img src="https://img.shields.io/github/license/wpFelix/elementor-mcp-server" alt="GPL 2.0 or later license"></a>
</p>

<p align="center">
  <a href="https://elementormcp.com">Website and documentation</a> |
  <a href="https://github.com/wpFelix/elementor-mcp-server/releases">Releases and checksums</a> |
  <a href="docs/SAFETY.md">Safety model</a> |
  <a href="docs/ARCHITECTURE.md">Architecture</a>
</p>

Elementor MCP is an open-source **WordPress MCP server** plugin built for
AI-assisted WordPress and Elementor work. It adds a typed Model Context
Protocol endpoint that compatible AI clients can use to inspect, build, edit,
and manage WordPress content and Elementor page designs.

The Free plugin includes a complete **Elementor MCP server** core with 41 typed
Elementor abilities, WordPress content and administration workflows, three
connection methods, safety profiles, approvals, change evidence, verified
rollback where supported, and 10 detailed industry page-building prompts.

> Install the attached `elementor-mcp.zip` release asset. GitHub's automatically
> generated "Source code" archives are development snapshots, not installable
> WordPress plugin packages.

## Why use Elementor MCP

- **Elementor Core is included in Free.** Inspect documents, create pages, edit
  containers and widgets, use templates and global styles, and work with
  compatible Elementor 4 Atomic Elements, Global Classes, and Variables.
- **WordPress is a first-class surface.** Work with posts, pages, public custom
  post types, taxonomies, media, comments, revisions, menus, allowlisted
  settings, diagnostics, and controlled plugin and theme lifecycle operations.
- **Safety is enforced at runtime.** WordPress capabilities, typed schemas,
  manual ability policy, safety profiles, rate limits, explicit confirmation,
  redacted change evidence, and bounded rollback are part of execution.
- **Client setup is guided.** The Connect screen provides client-specific
  instructions and configuration for OAuth, access tokens, or WordPress
  Application Passwords when that method is compatible with the selected
  client.
- **The prompt library is practical.** Free ships 10 long, evidence-led,
  industry-specific Elementor homepage prompts. Pro contains more than 300
  specialized prompts.

## Elementor MCP server features

The Free plugin registers 41 Elementor Core abilities through a shared typed
runtime:

- Inspect Elementor setup, version compatibility, documents, templates, page
  structure, widget schemas, global styles, classes, and variables.
- Create draft pages and build native Elementor container and widget trees.
- Edit, move, duplicate, and remove elements with schema-aware operations.
- Read and apply templates and manage compatible global design settings.
- Work with Elementor 4 Atomic Elements, Global Classes, and Variables when the
  installed Elementor version exposes them.
- Protect Elementor-owned documents from ordinary raw WordPress content writes.
- Preview consequential operations where available, then return change evidence
  for review.

Elementor is the only page-builder integration in this repository. No alternate
page-builder adapter, loader, skill, or ability is shipped.

## WordPress MCP server features

Elementor MCP also exposes a typed WordPress core surface that works without an
alternate page builder:

- Posts, pages, supported custom post types, terms, media, comments, revisions,
  and navigation menus.
- User reads and carefully allowlisted WordPress settings.
- Plugin and theme search, inspection, activation, deactivation, updates, and
  other lifecycle operations according to the active safety profile.
- Health, configuration, performance, and scoped security diagnostics.
- MCP resources, prompts, reusable skills, design context, and controlled
  developer operations.
- Optional forms, SEO, custom-field, commerce, localization, and theme
  integrations that load only when their required modules and plugins exist.

Creation is draft-first. Publishing requires an explicit published status and
the connected WordPress user's matching capability.

## Supported AI and MCP clients

The built-in client registry provides connection guidance for popular MCP
clients, including:

- Claude Code, Claude Desktop, and Claude on the web
- ChatGPT and the Codex desktop app
- Codex CLI, Cursor, VS Code, and GitHub Copilot
- Gemini CLI, Qwen Code, Kimi Code CLI, and Factory Droid
- Mistral Le Chat, Perplexity, Manus, Zed, Cline, OpenCode, and compatible
  remote MCP clients

Authentication availability differs by client. The Connect screen shows only
the methods recorded as compatible for the selected client. Hosted web clients
require a publicly reachable HTTPS WordPress site and cannot connect to
localhost.

## Requirements

| Component | Requirement |
| --- | --- |
| WordPress | 6.9 or newer |
| PHP | 8.0 or newer |
| Elementor | 3.6 or newer for Elementor workflows |
| Remote connections | HTTPS strongly recommended and required for OAuth |
| License | GPL-2.0-or-later |

Elementor is optional for WordPress core operations. Activating a compatible
Elementor version unlocks the 41-ability page-building surface.

## Download and install

1. [Download the latest stable `elementor-mcp.zip`](https://github.com/wpFelix/elementor-mcp-server/releases/latest/download/elementor-mcp.zip).
2. In WordPress, open **Plugins > Add New Plugin > Upload Plugin**.
3. Upload `elementor-mcp.zip`, install it, and activate Elementor MCP.
4. Install and activate Elementor if you want to use the Elementor abilities.
5. Open **Elementor MCP > Connect** in WordPress.
6. Choose your AI client and authentication method, then copy the generated
   connection details.
7. Keep **Production Safe** enabled for ordinary site work.

The public release includes a matching SHA-256 checksum. Use the release asset,
not GitHub's generated source archive.

## Connect an MCP client

Elementor MCP supports three connection methods:

| Method | Best for | Notes |
| --- | --- | --- |
| OAuth | Modern clients with browser sign-in | Uses consent, expiring credentials, refresh tokens, revocation, and a dedicated OAuth MCP route. |
| Access token | Clients that can send a Bearer token | Tokens are shown once, stored as hashes, can expire, and can be revoked. |
| Application Password | Clients that support HTTP Basic authentication | Uses WordPress's per-user Application Password system. |

For a safe first session:

1. Connect with **Production Safe** enabled.
2. Discover the abilities available to the authenticated WordPress user.
3. Read the live input schema for the target ability.
4. Inspect the existing page or template before changing it.
5. Preview the operation when a preview is available.
6. Apply only the approved change and review its returned evidence.

The server uses the WordPress Abilities API and bundles the official WordPress
MCP Adapter. It supports the repository's documented legacy and modern MCP
protocol paths while applying the same authorization and safety controls to
both.

## Safety and change control

| Profile | Intended use | Mutation policy |
| --- | --- | --- |
| Production Safe | Normal content, design, SEO, form, and commerce work | Allows ordinary operations while blocking critical developer primitives. Destructive allowed operations require explicit confirmation. |
| Read Only | Audits, discovery, reporting, and support | Blocks every mutation, including rollback. |
| Developer Full Access | Deliberate development or recovery work | Permits manually enabled critical abilities. Critical and destructive calls still require confirmation. |

Every state-changing ability is checked against the connected WordPress user's
capabilities and its declared schema. The bounded change ledger redacts secrets,
records before and after fingerprints, attributes writes to the agent
credential, and offers rollback only for operations that can be safely verified.
Irreversible actions are recorded as non-reversible.

Read the full [safety model](docs/SAFETY.md) before granting development access.

## Free industry prompt library

Free includes exactly 10 detailed Elementor homepage prompts:

| Industry | Prompt focus |
| --- | --- |
| Restaurant and Cafe | Menu accuracy, dietary process, reservations, hours, location, and original food photography |
| Dental Clinic | Verified clinicians, treatments, fees, accessibility, patient process, and responsible clinical claims |
| Law Firm | Jurisdiction, named lawyers, credentials, practice areas, consultation flow, and legal disclaimers |
| Real Estate Agency | Buyer, seller, rental, listing, agent, service-area, and valuation journeys |
| Fitness Studio | Classes, trainers, facilities, schedules, memberships, accessibility, and non-misleading outcome claims |
| Home Services | Service radius, real projects, credentials, process, workmanship terms, and quote flow |
| Nonprofit and Community | Programs, sourced impact, governance, safeguarding, reports, donations, and volunteering |
| Education and Training | Curriculum, instructors, prerequisites, workload, delivery, fees, support, and outcome boundaries |
| E-commerce and Retail | Product data, media, stock, price, shipping, returns, reviews, support, and buying guidance |
| SaaS and Software | Real workflows, screenshots, integrations, security evidence, pricing, documentation, and demos |

Each prompt includes discovery, an industry fact contract, section architecture,
a design system, media rules, accessibility, responsive behavior, performance,
safe draft construction, verification, and handoff requirements. Missing facts
must remain explicit instead of being invented by the AI client.

## Free and Pro

| Capability | Free | Pro |
| --- | --- | --- |
| MCP transport and authentication | Included | Uses Free |
| Safety profiles, approvals, and change evidence | Included | Extended workflows use the same controls |
| WordPress core abilities | Included | Uses Free |
| Elementor Core abilities | 41 included | Uses and extends the Free runtime |
| Elementor 4 Atomic Elements, Classes, and Variables | Included when available | Extended advanced workflows |
| Industry prompt library | 10 detailed prompts | More than 300 specialized prompts |
| Advanced specification reconstruction | Not included | Included |
| Dynamic content and display conditions | Not included | Included |
| Popups, forms and submissions, custom code, interactions, and SVG upload | Not included | Included |

Elementor MCP Pro depends on the Free plugin and is not included in this public
repository.

## Release quality

Tagged releases are built by GitHub Actions. The release workflow runs the
repository tests and static verification, builds the dashboard assets, creates
the installable plugin ZIP, and publishes its SHA-256 checksum. A passing build
is repository-level evidence, not a guarantee for every hosting stack,
third-party plugin combination, or production site.

## Frequently asked questions

### Is Elementor MCP a WordPress MCP server?

Yes. Elementor MCP installs as a WordPress plugin and exposes typed WordPress
abilities through the Model Context Protocol. The available operations still
depend on the connected user's WordPress capabilities, enabled abilities,
active safety profile, and installed integrations.

### Is this an Elementor MCP server?

Yes. Free includes 41 Elementor Core abilities for document inspection, page
building, element editing, templates, global styles, and compatible Elementor 4
Atomic features.

### Does Elementor have to be installed?

Only for Elementor-specific workflows. WordPress core, diagnostics, skills, and
compatible non-builder integrations can remain available without Elementor.

### Can an AI agent publish or delete content automatically?

Content creation defaults to draft. Publishing needs an explicit published
status and the matching WordPress capability. Destructive operations that are
allowed by the active profile require explicit confirmation.

### Which ZIP should I install?

Install `elementor-mcp.zip` from the latest GitHub Release. Do not install the
automatically generated "Source code" ZIP or tarball.

## Development and contribution

The repository contains PHP source, TypeScript dashboard source, bundled
dependencies, tests, skills, prompt packs, documentation, packaging scripts,
and release workflows. Run the repository verification scripts before creating
a release ZIP. Do not commit credentials, production exports, or generated local
state.

See [CONTRIBUTING.md](CONTRIBUTING.md) for contribution guidance and
[SECURITY.md](SECURITY.md) for private vulnerability reporting.

## License

Elementor MCP is licensed under
[GPL-2.0-or-later](LICENSE). Product documentation is available at
[elementormcp.com](https://elementormcp.com).

# GitHub repository metadata

Canonical public Free repository:

- Web: `https://github.com/wpFelix/elementor-mcp-server`
- Git: `git@github.com:wpFelix/elementor-mcp-server.git`

## Repository discovery metadata

Description:

> Open-source WordPress MCP server and Elementor MCP server plugin for AI agents. Build and edit Elementor pages with 41 typed core abilities, OAuth, approvals, change evidence, rollback, diagnostics, and 10 free industry prompts.

Website:

`https://elementormcp.com`

Topics:

`wordpress`, `elementor`, `mcp`, `mcp-server`, `model-context-protocol`,
`wordpress-plugin`, `ai-agents`, `ai-automation`, `page-builder`, `php`

## Publishing a download

Pushing a semantic version tag such as `v1.0.0` runs the Release workflow. The
workflow verifies the source, builds the installable `elementor-mcp.zip`, adds
its SHA-256 checksum, creates a draft GitHub Release, and publishes it as the
latest release only after both files are attached.

The download link at the beginning of `README.md` always targets the
`elementor-mcp.zip` asset attached to the latest published release. It does not
target GitHub's automatically generated source archive.

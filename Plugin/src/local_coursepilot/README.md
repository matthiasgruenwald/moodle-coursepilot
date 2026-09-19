# Coursepilot (local_coursepilot)

Coursepilot turns a Moodle site into an MCP server. A teacher connects an AI client
(Claude, Codex, or any other MCP client) to their own Moodle account and plans, builds and
revises course content in conversation — without installing anything locally.

- **Plugin type:** local
- **Requires:** Moodle 5.0 or later
- **Licence:** AGPL-3.0-or-later (see `LICENSE`)
- **Issue tracker:** https://github.com/matthiasgruenwald/moodle-coursepilot/issues

## What it does

- Exposes Moodle course structure, activities, question banks and the teacher's own working
  files as MCP tools, over a single endpoint (`/local/coursepilot/mcp.php`).
- Authorises clients through OAuth 2.1 with discovery, so the teacher never handles a token
  by hand.
- Writes activities through Moodle's own form path (`add_moduleinfo()`/`update_moduleinfo()`),
  so course changes stay consistent with what the Moodle UI would produce.
- Keeps a change history per activity, so an edit made in conversation can be reviewed and
  rolled back.
- Stores the teacher's planning files in their Moodle private files, or in a WebDAV storage
  they own.

## What it does not do

Coursepilot never reads learner-generated or learner performance data: no submissions, forum
posts, quiz attempts, grades or participant lists. That limit is a positive allowlist,
enforced by a contract test against the registered web service surface.

The plugin calls no AI provider itself. The teacher's own client does, and whatever a tool
returns reaches that client's provider. A site setting controls whether files marked as
containing personal data may leave the site at all.

## Why AGPL

Coursepilot is licensed under AGPL-3.0-or-later rather than GPL. This is deliberate: tools
built for education should stay free, including when they are offered as a hosted service
rather than distributed. If you modify Coursepilot and make it available to others, your
changes go back to the community.

## Installation

1. Install the plugin into `local/coursepilot` and run the upgrade.
2. Enable web services and the REST protocol.
3. Give teachers the `local/coursepilot:use` capability.
4. The teacher connects their MCP client to `https://<your-site>/local/coursepilot/mcp.php`
   and authorises it once.

Discovery follows RFC 8414 and RFC 9728. Both work without a web server change, provided
`slasharguments` is enabled — Moodle's default.

## Status

Alpha. The plugin is in real teaching use by its author; it has not yet been through a
production deployment at another school.

## Development

Development, issues and support all live in the primary repository:
https://github.com/matthiasgruenwald/moodle-coursepilot

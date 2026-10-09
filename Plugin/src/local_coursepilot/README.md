# Coursepilot (local_coursepilot)

Coursepilot turns a Moodle site into an MCP server. A teacher connects an AI client
(Claude, Codex, or any other MCP client) to their own Moodle account and plans, builds and
revises course content in conversation — without installing anything locally.

- **Plugin type:** local
- **Requires:** Moodle 5.1 or later
- **Licence:** AGPL-3.0-or-later (see `LICENSE`)
- **Issue tracker:** https://github.com/matthiasgruenwald/moodle-coursepilot/issues
- **Documentation:** https://matthiasgruenwald.github.io/moodle-coursepilot/en/

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

0. **Upgrading from Coursepilot 1.x (the local Node/stdio line)?** Uninstall the previous
   `local_coursepilot` component first (Site administration → Plugins → Manage plugins),
   then install this ZIP as a fresh plugin. There is no data, settings or webservice-token
   migration between the two lines — they share a component name but not a schema. See
   `docs/adr/0024-englische-basis-und-komponente-coursepilot.md` for the reasoning.
1. Install the plugin into `local/coursepilot` and run the upgrade.
2. Enable web services and the REST protocol.
3. Give teachers the `local/coursepilot:use` capability in their courses, and grant them
   remote access: select one or more existing **system cohorts** under the plugin setting
   *Remote access cohorts*, or allow `local/coursepilot:useremote` in a role you already
   assign system-wide. A course enrolment grants neither; the capability has no archetype
   default, so a site-wide teacher role does not grant remote access by accident. Remote
   access never adds course rights and is checked on every call, so removal takes effect
   for existing connections too.
4. The teacher connects their MCP client to `https://<your-site>/local/coursepilot/mcp.php`
   and authorises it once.

Discovery follows RFC 8414 and RFC 9728. Both work without a web server change, provided
`slasharguments` is enabled — Moodle's default.

## Supported versions

Coursepilot `2.1.2-beta` supports Moodle **5.1, 5.2 and 5.3** in the combinations below.

For upgrades from 2.0.0-beta, upgrade Moodle 5.0 to Moodle 5.1 first and back up
the database and Moodle data directory before replacing the plugin. Existing
teacher files, selected storage locations, OAuth connections and history are migrated.
Published location-selection and download URLs remain available as compatibility entries.
Required combinations (full native PHPUnit suite in CI):

| Moodle | PHP | Database |
|---|---|---|
| 5.1 | 8.4 | MariaDB 11.4 |
| 5.2 | 8.3 | MariaDB 11.4 |
| 5.2 | 8.4 | PostgreSQL 17 |
| 5.3 | 8.3 | MariaDB 11.4 |
| 5.3 | 8.4 | PostgreSQL 17 |

Fresh release-ZIP installation is also checked on all three Moodle lines with
PHP 8.4 and MariaDB 11.4. All required checks must pass before release.
`version.php` requires Moodle 5.1 or later. Moodle 5.1's PHP floor remains 8.2;
PHP 8.3/8.4 are the versions covered by the matrix. Other combinations are not
implicitly verified. Assignment marker allocation and grade recalculation
remain in Moodle's native form; Coursepilot preserves these settings during
unrelated updates and history restores.

## Language

Release ZIPs ship only the English base language (`lang/en/`). The source tree's
`lang/de/` is a development translation; published translations will use
[AMOS](https://lang.moodle.org/). The skill corpus is also English, while clients
reply in the teacher's language and create teaching content in the requested language
(see `docs/adr/0024-englische-basis-und-komponente-coursepilot.md`).

### WebDAV storage behind a reverse proxy

If a teacher's own WebDAV storage (e.g. Nextcloud) runs behind a reverse proxy such as
Cloudflare, and that proxy is not listed in the storage's `trusted_proxies` setting, the
storage sees every request as coming from the proxy's single IP address. Its own built-in
brute-force/rate protection can then throttle a normal, small Coursepilot write for 15–30
seconds or more (issue #529) — not a Coursepilot bug, but a common misconfiguration on the
storage side. If teachers report frequent "storage is throttling" pending-write notices,
check `trusted_proxies` on their storage first. Coursepilot itself deliberately keeps its
silent-retry budget short (10 s) rather than trying to absorb this: it cannot know the
quality of a storage it does not control, and a longer budget would block every write call
for everyone, including teachers whose storage is configured correctly.

## Status

Beta (`2.1.2-beta`). The plugin is in real teaching use by its author; it has not yet been
through a production deployment at another school.

## Development

Development, issues and support all live in the primary repository:
https://github.com/matthiasgruenwald/moodle-coursepilot

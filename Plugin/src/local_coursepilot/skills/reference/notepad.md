---
name: notepad
description: Read this when inventory changes cannot currently be performed, when a client with local file tools starts a session, or when a workbench file should enter inventory at original quality.
---

# Reference: notepad

`notepad.md` records inventory changes the AI cannot currently perform,
for later execution on a laptop. It is an ordinary context file using only
context-area tools, not a handshake field. It exists only for external
teacher inventory (WebDAV).

## Record changes (Spec #486 §14)

Immediately record inventory changes such as renaming, moving or creating
that cannot be performed in this session, instead of leaving them in chat.
Each item contains what, path relative to inventory root, why it cannot
be done now and date. Use no real names; items need no
`coursepilot.personenbezug: true`, following context-area's unmarked-file rule.

For clients with local file tools, such as laptop Codex or filesystem-server
clients, read notepad.md at session start before the test below. Otherwise
skip this step; the server-side notepad remains available.

## Three-step test before inventory access (Spec #486 §14, #477)

Before executing each item, check in order with yes/no answers:

1. Working directory and tools available? If not, ask the teacher to start
   the CLI in their material folder or open it as a Codex App project.
   Execute nothing. Otherwise continue.
2. Not explicitly read-only? If read-only, report that and execute nothing.
   Otherwise continue.
3. Root-level name sets match after exclusions? Compare server names from
   `coursepilot_list_material_files` with `location: store` at the root
   against local working-directory root names. Remove the exclusion list
   first. On mismatch, name differences and execute nothing. On a match,
   inventory is found; proceed to the offer.

Exclusion list, authoritative only here: `.DS_Store`, `._*`, `.sync_*.db*`,
`.owncloudsync.log`, `*.nextcloud`, `*.owncloud`, `Thumbs.db`, `desktop.ini`.

## Offer to process items (Spec #486 §14, #483)

After the test passes, name all open items and obtain one confirmation,
allowing a subset such as the first two. Each confirmed item takes exactly
one of three paths, never simply "do it yourself":

- Execute when tools exist and the change is unambiguous.
- Remove as completed when the teacher already did it; do not execute again.
- Propose a concrete solution and wait for confirmation when automatic
  execution remains ambiguous.

When inventory changes location, transfer open notepad items instead of
abandoning them. This is separate from context-area old content: inventory
has no old-content state, and its old location remains without a dedicated
handover step. The notepad is current pending state; the journal is the
history of decisions and completed work. Move completed items into journal
entries, not the reverse.

## Workbench to inventory (Spec #486 §13/§14, #484)

An item can transfer a workbench attachment or crop into inventory at
original quality, e.g. a phone photo of the board into a subject folder.
See `coursepilot_get_skill("mcp-tools")`.

First check for a shell tool. Without one, say in the teacher's language:
"I cannot do this in this program; I can do it in Codex on your laptop."
Attempt nothing. With a shell:

1. Before Codex asks for shell authorization, state the intended transfer,
   such as fetching N workbench files into inventory.
2. Request `coursepilot_create_workbench_download_links` for all workbench
   items in one call, not per file.
3. Download and verify each file:
   - POSIX: `curl -fsSL -o <target> <url>`, then `shasum -a 1 <target>`
     or `sha1sum <target>`.
   - Windows: `Invoke-WebRequest -OutFile <target> <url>` or `curl.exe`,
     then `Get-FileHash -Algorithm SHA1 <target>`.
4. When the local checksum matches reported sha1, delete the workbench
   file with `coursepilot_delete_material_files`; inventory now contains
   the original bytes.
5. On mismatch, try once more with a fresh download and checksum. Only
   afterward notify the teacher; preserve the workbench file.

## Setup: connections apply per user (Spec #486 §14)

The first test fails when Coursepilot is configured only for one project
because the material folder is another working directory. Configure the
connection per user: Claude Code `claude mcp add ... --scope user`;
Codex loads MCP servers globally from `~/.codex/config.toml`, with no
separate setup per folder.

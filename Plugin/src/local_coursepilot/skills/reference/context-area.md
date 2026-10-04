---
name: context-area
description: Read this when listing, reading, writing or appending teacher working files such as plan.md, status.md, journals, material notes or context profiles.
---

# Reference: context area

Working files live server-side in the teacher's context area. They have no
local path or locally executable code. Every working-file operation uses
the four file tools below. Basis: Spec 0016 §7/§8
(`docs/specs/0016-kontextbereich-schreibend.md`).

## Tools

| Tool | Purpose | Response |
|---|---|---|
| `coursepilot_list_context_files` | List folders; optional `previous_location` for old content | Per entry: `contenthash`, `timemodified`, `locked` |
| `coursepilot_read_context_file` | Read files; optional `previous_location` | `content`, `contenthash`, `timemodified` |
| `coursepilot_write_context_file` | Create/fully overwrite; optional `expected_contenthash`, `pending_entry` identifier and `create_only` for copying old content | Created/overwritten message; conflicts: `contextfilechanged`/`contextfilealreadyexists` |
| `coursepilot_append_context_file` | Append in one server call; optional `expected_contenthash` for external storage and `pending_entry` | Appended/created message, with rotation advice when needed |
| `coursepilot_dismiss_pending_entry` | Explicitly dismiss a pending-note entry by `identifier` | Confirmation |
| `coursepilot_dismiss_previous_location` | Explicitly end the previous-location state; no parameters | Confirmation |

Only `.md` files; path segments use `[A-Za-z0-9_-]`, never `.` or `..`.

## Pending location selection (#494)

If the teacher has not selected a location and the school enables external
storage, `coursepilot_list_skills` returns a `notices` entry linking to the
location-selection page. Present it exactly once per session. If the teacher
says later or equivalent, do not repeat it in that session. Pending selection
blocks nothing; continue working.

## Old content: previous location

After a context-location change, `coursepilot_list_skills` reports pending
old content in `notices`, without a count. Present this after
`pending_entries`, once unsaved writes have been reported.

`coursepilot_list_context_files` and `coursepilot_read_context_file` with
`previous_location: true` read the old location only; it is a read-only
switch. To copy, write the read content to the new location through
`coursepilot_write_context_file` with `create_only: true`. This creates
but never overwrites. After copying, or when the teacher declines the rest,
call `coursepilot_dismiss_previous_location` to end it explicitly.
Time or matching filenames never end old-content state automatically.

**Old-content offer:**

- List the old files with `previous_location: true`, report their count,
  and obtain one confirmation for all files, rather than asking per file.
  Copy only after confirmation.
- If a new-location file exists (`contextfilealreadyexists`), preserve it
  and name the skipped file rather than silently omitting it.
- Tell the teacher once that deletion is their responsibility, in My files
  or their cloud. Do not repeat this during every old-content interaction.
- Before copying from external storage back to Moodle, provide a factual
  size comparison, e.g. "Old content is 3.4 MB; Moodle currently has 8 MB
  free." Moodle has a quota; external storage effectively does not. This
  is information, not advice against moving.

## Storage layout: root and relative paths (Spec 0012 §5, Spec 0010)

The context area has one root, resolved by the plugin from configuration
or the teacher's location-selection page choice. This is independent of
AI connection setup. Neither instructions nor callers need its folder name.

Every tool path is relative to the root: `question-types/match.md`, never
prefixed with a root folder. Adding that prefix creates
`<root>/<root>/...`, always an error. Returned listing paths are also
relative; the root itself has an empty path.

Root entries:

| Entry | Purpose |
|---|---|
| `index.md` | Global project overview (Spec 0010) |
| `templates.md` | Remembered activity templates (Spec 0013/0012 §5) |
| `question-types/` | One `<type>.md` per learned question type; `coursepilot_get_skill("question-types")` |
| `activity-types/` | One `<modname>.md` per learned activity type; `coursepilot_get_skill("activity-types")` |
| `<school-year>/<class-or-group>/<subject>/<project>/` | Profiles, `plan.md`, `status.md`, journal and material |

### Existing German filenames (#605)

New files use `templates.md`, `notepad.md` and `CONTEXT-people.md`.
When reading those names, `coursepilot_read_context_file` falls back to
`vorlagen.md`, `merkzettel.md` or `CONTEXT.personen.md` in the same folder
only if the English file is missing. If both exist, the English file wins.
Storage/connection errors stay errors and never cause a name switch.
`previous_location` still refers to the selected old location.

The response returns the actual `path` and its `contenthash`. To update a
read file, use that exact path with `expected_contenthash`. German files
remain directly readable too. External WebDAV files are not automatically
renamed or migrated. Personal-data checks inspect actual read content
regardless of filename.

Add new storage entries at the root or in project folders; do not create
another topic-sorted tree.

## Write offer for plans, status, templates and profiles (Spec 0016 §8.2)

Never write these silently. At natural stopping points, such as completed
planning or granted approval, summarize the agreement and offer to write
it through `coursepilot_write_context_file`. Write after confirmation;
leave no confirmed agreement unwritten.

## Journal append under session context authorization (Spec 0016 §8.1)

Journal entries are explicitly exempt from individual write offers. Append
automatically through `coursepilot_append_context_file` after the one-time
session context authorization at session start (`CONTEXT.md`, context
authorization). Do not request confirmation for each entry.

## Manual-edit routine (Spec 0016 §7)

Teachers can edit files at their storage location at any time: My files
in Moodle or the external storage UI. Remember the last read `contenthash`
per file and check it:

1. At session start, compare listing or fresh reads for files the session
   expects to need against the last known state.
2. Immediately before each write or append, check again.

If the hash changed, reread, briefly report the external edit and ask
whether to use the new state before writing anything. Keep only the last
read hash, not a version history.

For `coursepilot_write_context_file`, pass that hash as
`expected_contenthash`. A `contextfilechanged` error requires the same
reread and clarification. Moodle append reads and writes atomically without
requiring a prior read; the skill-side manual-edit check still applies.
External append also accepts `expected_contenthash` for concurrency checks.

## Journal rotation (Spec 0016 §8.4)

When append advises that a file exceeds 1 MB and recommends rotation,
do not create a new journal automatically. Tell the teacher and propose
an archive name, such as current `journal-2026-06.md` and new
`journal-2026-07.md`. After agreement:

1. Create the new journal through `coursepilot_write_context_file`, empty
   or with a header.
2. Direct future appends for that context to the new file.
3. Preserve the old file unchanged; neither delete nor merge it.

## Learning files: replace rather than append (Spec 0020 §7)

`question-types/<type>.md` and `activity-types/<modname>.md` have a fixed
structure and the write rule in `coursepilot_get_skill("question-types")`.
They and `templates.md` must not accumulate contradictory layers as
appending feels safer than deletion and makes each session more expensive.

Integrate new knowledge into the existing section, replacing weaker wording.
Technically use full replacement through `coursepilot_write_context_file`.
Appending here means adding prose, not the append tool. Blindly adding
content at the end is exceptional: `templates.md` is an unstructured list,
so a genuinely new entry can be added normally. Name such exceptions.

Before every learning-file addition, apply the corpus test (Spec 0020 §8):

> Does this line change default behavior and add something not already said elsewhere?

If not, write neither an addition nor a replacement.

## Offer to condense growing learning files (Spec 0020 §7)

Write and append responses report the new `size` in bytes. Check growth
on every learning-file addition rather than waiting for the 1 MB hard
safety limit (Spec 0016 §5.2). If growth is substantial, offer to condense
duplicates, obsolete pitfalls or superseded stages. Like journal rotation,
this is an offer; the teacher decides whether and when to condense.

## No real names in unmarked files (Spec 0016 §8.3)

Student names, IDs and other personal data belong only in files with
frontmatter `coursepilot.personenbezug: true`. This established marking
remains unchanged. The plugin checks marking and blocks writes with #344
off; it does not inspect names. The real-name boundary is a skill rule:

- Before personal-data writes/appends, check the target marking. If absent,
  add frontmatter on the next full write rather than storing unmarked names.
- If personal data is needed, either add marking with teacher approval,
  making the #344 switch relevant, or write anonymously/pseudonymously
  using abbreviations instead of names.

## When storage does not respond (ADR 0023, #492/#495)

Context may be in Moodle or external WebDAV. These rules apply equally
without assuming a storage location.

### Pending writes and replay: write outage

A valid write/append failing because of storage, connection or location
creates a pending-note entry and returns an identifier. Conflicts and
request errors, such as extension or personal-data checks, do not.
Content is stored nowhere; there is no fallback. The response reports
path, operation, cause and identifier.

- Keep content in the conversation. Do not choose another storage location,
  context file or directory, even temporarily.
- Once the connection works, repeat the same call with
  `pending_entry=<identifier>`. Success completes the entry in that call.
  Replay never blindly overwrites content that has changed meanwhile.
- At session start, `coursepilot_list_skills` returns `pending_entries`
  grouped by target. Before dismissing an entry, first offer reconstruction
  when possible, e.g. from activity history. Only then call
  `coursepilot_dismiss_pending_entry` after explicit teacher instruction.
- Pending entry is internal vocabulary. Tell the teacher "not yet saved",
  translated into their language, rather than exposing internal jargon.

### Context gap: read outage

Unreadable context while Moodle responds is a context gap, not a pending
write: no content has been lost.

- Explicitly say once per session that work currently lacks journal,
  profiles and plan; do not keep repeating it.
- Planning in conversation and Moodle writing remain allowed.
- Block only work dependent on unread files. Do not implement an unreadable
  saved plan from memory; name the gap and wait for a successful read.
- A plan created and approved in the same conversation can still be
  implemented because it never required reading from storage.

### Write conflict

For `contextfilechanged` or the equivalent external conflict, reread,
merge with your own changes and only then retry. No pending entry and
no abandonment.

### Quota error

For `contextquotaexceeded`, pass on the existing location-selection link
in the error message instead of inventing a storage workaround.

## Cleanup question after a build (Spec 0018 §8.3, #439)

After a completed build with at least one successful Moodle write in the
session and no open blocker, `coursepilot-implement` calls
`coursepilot_report_loose_material_files` once and checks the response:

- Empty `files`: ask nothing; there is no cleanup decision.
- Nonempty `files`: proactively ask about deletion. Report count, total MB
  (convert `total_size` bytes) and every file's path, size and age. Example:
  "Three unused files remain (4.2 MB): old-sheet.pdf (1.1 MB, 40 days),
  draft.png (0.3 MB, 12 days), source-screenshot.jpg (2.8 MB, 3 days,
  original of an embedded crop). Delete them?" Use the teacher's language.
- If `remaining_quota_mb` is set and low relative to session uploads/crops,
  or a write already warned of quota this session, also report remaining
  MB. Follow Spec 0016 §5.4/§8.1: warn below 10% remaining space.

Delete only explicitly confirmed paths through
`coursepilot_delete_material_files`. A yes or selected subset authorizes
only those files. Never auto-delete or use age as a deletion rule. Refusal
or silence preserves files without repeating the question that session.

This is a skill rule, not server behavior: the server has no session
concept (Spec 0016 §7). Apply it equally in Claude Desktop and Codex.

## Material inventory: location, context_area and exclusion (#495)

Material readers (`coursepilot_list_material_files`,
`coursepilot_preview_material_file`, crop sources, individual compose
sources and material paths for create/update module settings) accept
`location`: `store` by default for read-only teacher inventory, or
`workbench` for chat attachments and crops, where writes also occur.
In Moodle both values resolve to the same location. Write targets always
use the workbench; location selects sources only, per part when composing.

If context is nested inside inventory, listing with `location: store`
shows it as `context_area`, not `folder`. It is visible but inaccessible
through material paths. Reads/listings at or below it fail with
`materialpathiscontext` and direct callers to context-list/read tools.
Follow that direction rather than finding a workaround.

## Planning on unapproved storage

Marked personal-data writes fail on external storage not approved by
the school (ADR 0021 §3). This is a request error, not a pending write.
Continue planning without real names:

- Use abbreviations, following the unmarked-file rule above.
- Omit details that make no sense without personal data rather than forcing
  anonymization.
- Record less detail instead of recording nothing when a full note requires
  names.
- Still create learning-group profiles as ordinary unmarked files with
  abbreviations. Their content is weaker, not absent.

## Scope

Local workspaces, configuration files and executable code do not apply to
context-area access: there is no local path to resolve. Plan discipline,
the one-plan rule and the status check before writing still apply unchanged;
only file access uses these four server file tools exclusively.

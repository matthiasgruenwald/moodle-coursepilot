---
name: activity-types
description: Read this to create an activity without a field catalog, such as book, checklist or glossary, or to supersede an existing activity with a new version.
---

# Reference: activity-type files and learning loop

Basis: Spec 0026 and ADR 0028
(`docs/specs/0026-erschlossene-aktivitaetsarten.md`). Use context-area tools,
write offers and manual-edit checks. This adds rules for uncataloged types
and follows `coursepilot_get_skill("question-types")`.

## Classify the type

| Type | Path |
|---|---|
| Cataloged, e.g. page, label, assign, url | coursepilot_create_module; this reference does not apply |
| Learned: installed without a catalog, e.g. book, checklist, glossary, lightboxgallery | Create from activity XML under this reference; lightboxgallery accepts images and captions through files |
| Excluded: lesson, quiz, scorm, imscp, h5pactivity, uninstalled types or types without Moodle backup | Do not create through this path. Pass on the tool's reason and suggest manual Moodle creation. The three package types still lack a file supplement |

`coursepilot_create_activity_from_xml` and `coursepilot_export_default_activity`
reject cataloged/excluded types and name the right path. The tool decides;
do not classify from memory alone.

## Teacher-facing vocabulary

Call the operation create. Reserve supported for field-catalog guarantees:
learned types make no such promise and are checked after creation to see
whether Moodle retained the supplied content. Say, in the teacher's
language: "I do not have fixed knowledge of book yet. I will try creating
it and check whether Moodle saved everything as intended."

Creation always makes a new activity. A revision creates a successor that
supersedes the old one, as described below.

## Activity-type learning files

Store learning as ordinary teacher context at a fixed relative path:

```
activity-types/<modname>.md
```

modname is the Moodle shortname, such as book, checklist or glossary.
One file per type records knowledge, not a clone template. Import experience
about tar structure, previews and checklist references is in
`coursepilot_get_skill("activity-backup-experience")`. These are hints,
not verified rules; record confirmed observations in the type file.

### Required structure

| Section | Content |
|---|---|
| Header | modname, Moodle release, plugin release/version, last verification date |
| Minimal example | Exact activity XML actually created |
| Required structure | What can be omitted and what cannot |
| Pitfalls | Symptom → cause → remedy per entry |

Retrieve concrete header versions through coursepilot_get_version_info
before writing; the header detects outdated knowledge.

### Minimal examples are evidence

Store the exact XML accepted by coursepilot_create_activity_from_xml
without deviation, including its declaration and activity wrapper.
Shortened, modified or reconstructed examples must themselves pass
creation and round-trip checks before storage. Untested retained
knowledge is worse than no file.

### Write rule

Use coursepilot_write_context_file with expected_contenthash for full
replacement, not append. Integrate knowledge into the appropriate section.
After the first successful creation of a new type, offer to save the
learning; write only after confirmation.

## Learning loop

Explain each step briefly to the teacher. Announce learned types, report
failures/corrections per attempt and settle each yes/no branch before
proceeding.

1. Read activity-types/<modname>.md using coursepilot_read_context_file
   with manual-edit checking. Present? Yes: step 2; no: step 3.
2. Compare header Moodle release/plugin version with coursepilot_get_version_info.
   Match? Yes: step 4. No: report a contradiction, then step 4 using the
   file as a hint only.
3. Obtain a template. Export an existing teacher-course activity through
   coursepilot_export_activity_backup(cmid). Otherwise use
   coursepilot_export_default_activity(courseid, modname), which briefly
   creates and removes an activity, leaving nothing. Announce this write
   in the existing plan/approval workflow and execute only after approval;
   without approval, template acquisition stops here.
4. Build XML from the learning file or template using teacher content.
   Template determines fields, order and ranges.
5. Create through coursepilot_create_activity_from_xml with courseid,
   modname, section, activity_xml and optional hidden/replaces_cmid/dry_run/files.
   The plugin creates hidden, exports and checks retention. Passed? Yes:
   report cmid; presets are Moodle-supplied defaults, not errors; step 7.
   No: the same call removed the activity, leaving nothing in course or
   trash. Use its deviation message for step 6.
6. Correct, at most three times. Fix the specific deviation and return
   to step 5. After the third unsuccessful correction, stop and explicitly
   ask the teacher to create an example manually and provide its cmid;
   export_activity_backup supplies that version-specific template.
7. Is there new knowledge to record? Yes if the type has no file, corrections
   taught something or a contradiction occurred: make the write offer.

### Contradictions

Explicitly report version mismatches from step 2 and behavior mismatches
when a documented rule fails or an excluded error occurs. Offer to revise
the affected section with likely cause and current header versions.
Until then, use the template from step 3.

## Supersede rather than edit

For requested changes to an existing learned-type activity:

1. Ask whether the new version should supersede the old, retaining the
   old hidden with all learner data.
2. Call coursepilot_create_activity_from_xml with replaces_cmid and
   dry_run:true. This writes nothing and returns references to the old
   activity (availability/course-completion criteria), successor_cmid and
   hidden_predecessors.
3. Report references. Coursepilot does not rewrite them; update them manually.
4. Already superseded (successor_cmid != 0)? No: step 5. Yes: report the
   successor cmid and ask whether to supersede it instead. If yes, return
   to step 2 with that successor. If no, continue with the original;
   the call is not blocked.
5. Report actual hidden_predecessors count: hidden old versions in the
   chain after superseding, with only the latest visible. Explain that
   the teacher may delete unneeded old versions in Moodle. Always report
   the real count, without a threshold.
6. After approval, repeat without dry_run. New activity appears directly
   after old; old is hidden, with title and data preserved. Omit section;
   type and course must match. Report returned notices as in steps 3–5.
7. Failed creation leaves nothing; retrying the same replaces_cmid needs
   no special handling.

## File supplement: lightboxgallery

When lightboxgallery is installed, pass images as a `files` list in the same
create call. Each entry has `path` (material path), `filearea: "gallery_images"`,
optional `caption` (plain text, default empty) and optional `location`
(`"store"` by default, or `"workbench"`). The server copies images after the
successful XML round trip, generates thumbnails through the native module
and sets captions before making the activity visible. A failed file step
discards only the new activity; a predecessor stays intact. `hidden` still
keeps the completed activity hidden. `dry_run` writes nothing and does not
read material paths or validate image contents.

Use distinct basenames: the gallery stores images at its root, itemid 0,
and captions are keyed by filename. Other areas and caller-supplied item IDs
are unavailable. Use captionfull/captionpos from a real export to control
caption display. Captions are image metadata, not Moodle learner comments.

Binary contents stay on the server: name paths, never put image bytes or
Base64 into activity XML or the AI context. For the exceptional consent
rule, see `coursepilot_get_skill("activity-backup-experience")` (ADR 0028).
Record only what the actual target instance verified in
`activity-types/lightboxgallery.md`; an isolated automated module test does
not prove Spike rendering or real Claude/Codex/ChatGPT use.

## User-data content

Activity XML carries settings and teacher-designed content such as
chapters/checklist items. User-data content fails round-trip checking and
is removed as in step 5.

Glossary pitfall: creation is empty. Entries are not carried through
activity XML; XML containing entry fails the round trip. Create without
entries, then use coursepilot_add_glossary_entries(cmid, entries) after
plan approval to add teacher-authored content to the new or an existing glossary.
Record this two-step path in activity-types/glossary.md.

Each entry accepts concept, definition, definitionformat (0 Moodle, 1 HTML,
2 plain text, 4 Markdown), aliases, categories (names), usedynalink,
casesensitive, fullmatch, optional approved, tags, attachment_files,
definition_files and location (store by default, or workbench). Use
@@PLUGINFILE@@/filename in definition for definition_files; list material
paths rather than file contents. Files are copied and sources stay intact.
Tags must be enabled for glossary entries; standard-only tagging permits
only existing standard tags in the glossary tag collection. Missing category creation needs
mod/glossary:managecategories; explicit approved needs mod/glossary:approve.
Omit approved to follow Moodle's default approval. Autolinking also requires
the glossary's own linking setting and Moodle's glossary filter.

Report every result by its zero-based index: success, entryid, actual approved
or errorcode/message. Earlier successes survive a later error; retry only
failed entries to avoid duplicates. Moodle's duplicate-entry setting applies.
This tool adds only: editing/deleting entries, comments and ratings remain
outside its scope. Existing learner content is never returned. Activity
version history covers the glossary instance only; entries and their files
cannot be restored through it. Report gap_notice rather than promising undo.

---
name: mcp-tools
description: Read this to identify the Moodle MCP tool responsible for a read or write operation.
---

# Reference: available MCP tools

Read this to select the responsible read or write tool.

## Courses, sections and activities

| Tool | Use |
|---|---|
| `coursepilot_get_sections` | Read course sections |
| `coursepilot_get_modules` | Read section activities and cmids |
| `coursepilot_get_course_catalog` | Read a compact, filterable, read-only Moodle catalog for planning |
| `coursepilot_update_section` | Set section name and an introduction only when planned |
| `coursepilot_move_section` | Move an existing section without content changes |
| `coursepilot_move_module` | Move an existing activity by cmid to a section or position within it |
| `coursepilot_create_module` | Create cataloged activities; modname selects label, page, assign, url, resource, folder, choice or forum |
| `coursepilot_update_module_settings` | Patch individual settings, with field names/values determined by modname |
| `coursepilot_set_completion` | Set completion tracking: the only path for completion* fields, blocked by generic create/update tools |
| `coursepilot_set_restriction` | Set prerequisites: another activity's completion, date or group |
| `coursepilot_ensure_question_bank` | Idempotently create/reuse a named course/project question bank |
| `coursepilot_ensure_question_category` | Idempotently find/create a subtopic/content category in a selected bank |
| `coursepilot_update_question_category` | Nondestructively rename/move categories into the correct bank/parent |
| `coursepilot_get_question_categories` | Read categories in a selected bank |
| `coursepilot_ensure_quiz_question_categories` | Initialize/list categories in one quiz; returns its default category for quiz-local questions |
| `coursepilot_plan_question_category_cleanup` | Read-only manual review plan for empty leaf categories; courseid and questionbankid (CMID). Return bank name, category ID/name/parent, Moodle link and instructions; exclude categories with questions/children and the top category |
| `coursepilot_move_question` | Move a question and all versions nondestructively into a target category |
| `coursepilot_create_quiz` | Create mod_quiz with a mode selecting a complete settings preset; see quiz-and-question-bank |
| `coursepilot_update_quiz_settings` | Apply Coursepilot settings presets to existing quizzes |
| `coursepilot_list_courses` | List courses authorized for the current teacher; entry when course ID is unknown |
| `coursepilot_ensure_section` | Idempotently create a missing sectionnum; existing sections receive only name reconciliation |
| `coursepilot_get_module_settings` | Read complete current state by cmid before every patch; report existing learner_locks |
| `coursepilot_describe_module_fields` | Read fields, meanings, blocked fields and learner_lock values; modname and optional full |
| `coursepilot_add_glossary_entries` | Add teacher-authored entries to fresh/existing glossary cmid; entries accept concept, definition/definitionformat, aliases, category names, usedynalink/casesensitive/fullmatch, optional approved, tags, attachment_files/definition_files and location (store/workbench). Explicit approval requires mod/glossary:approve; new categories require mod/glossary:managecategories. Returns per-entry index/success/entryid/approved/errorcode/message and gap_notice. Preserve successful entries on partial failure; retry failed entries only. No learner-content reads or entry history restoration. See activity-types |
| `coursepilot_clone_activity` | Duplicate by cmid/title within a course or into optional targetcourseid |

Settings writers (create/update module, completion, restrictions and quiz
create/update) reject learner locks unless confirmed through
confirm_learner_locks, or through mode selection where applicable. See
implementation-plan-workflow planning principles for when to confirm.

## Questions and quizzes

| Tool | Use |
|---|---|
| `coursepilot_create_mc_question` | Create multiple-choice questions with categoryid, name, questiontext, selectionmode and answers |
| `coursepilot_update_mc_question` | Patch questionid through fields_json; creates a new version |
| `coursepilot_get_question` | Read the current version before editing, by categoryid plus name or by questionid |
| `coursepilot_import_questions_xml` | Import Moodle XML into categoryid; supports types beyond multiple choice |
| `coursepilot_export_questions_xml` | Export questionids as Moodle XML for import templates |
| `coursepilot_plan_quiz_cleanup` | Return a manual cleanup plan for excess slots by cmid/keep_questionbankentryids; links only, no deletion |
| `coursepilot_create_activity_from_xml` | Create learned activity types from activity_xml, with courseid/modname/section and optional hidden/replaces_cmid/dry_run/files. files entries name path/filearea plus optional plain-text caption/location (store by default, or workbench); installed lightboxgallery accepts gallery_images. Copy files and set captions/native thumbnails after the round trip, before visibility; a file error discards only the new hidden activity. Create hidden internally, check round trip and remove in the same call on mismatch. A same-course/type replaces_cmid places the new activity directly after the old and hides the old without renaming/deleting it; omit section. dry_run requires replaces_cmid and returns preview notices without writes or material reads. Return cmid, Moodle defaults, references still pointing to the old activity (report them), successor_cmid if already superseded (ask whether to supersede that successor; does not block), and hidden_predecessors with cleanup guidance. Creation only, no editing; use create_module for cataloged types |
| `coursepilot_export_activity_backup` | Read an existing activity's backup <module>.xml without user data, by cmid |
| `coursepilot_export_default_activity` | Export default XML for a learned modname/courseid. Requires prior plan approval because it creates hidden then removes the activity, leaving nothing in the course. Cataloged types use create_module |
| `coursepilot_report_clone_lineage` | Read-only report of whether each cloned quiz question is an independent copy or shared source-course reference, by cmid |

## Activity version history

| Tool | Use |
|---|---|
| `coursepilot_list_activity_versions` | List captured versions by cmid, one summary line per change from its predecessor |
| `coursepilot_compare_activity_versions` | Compare arbitrary from_version/to_version for cmid, including field/file changes |
| `coursepilot_restore_activity_version` | Apply target_version as a new latest version, keeping cmid stable rather than rewinding. If learner completion data would be deleted, the first call warns; a second confirmed:true call writes completion fields |

## Material inventory and workbench

Teacher images/documents for activity embedding. Paths are relative to
the material root and named path, never course_id.

Readers (list/preview and crop sources) accept location: store by default
for read-only inventory, or workbench for chat attachments/crops. See
`coursepilot_get_skill("context-area")` for context_area exclusion.

| Tool | Use |
|---|---|
| `coursepilot_list_material_files` | List optional path, empty for root, and optional location; sizes, contenthash and remaining quota |
| `coursepilot_upload_material_file` | Create/replace path with content_base64, always on workbench; no location |
| `coursepilot_preview_material_file` | View a reduced image preview by path/location to choose crops or alt text accurately |
| `coursepilot_crop_material_file` | Crop sourcepath into targetpath using relative x0/y0/x1/y1 (0–1) on the preview; target is always workbench |
| `coursepilot_compose_material_file` | Compose ordered parts with optional crops/source headers into a PNG; arrangement vertical/horizontal, targetpath; original resolution, fixed house style and workbench target |
| `coursepilot_report_loose_material_files` | Read-only report of workbench files unused in activities |
| `coursepilot_delete_material_files` | Delete exact paths only after explicit teacher confirmation; workbench only |
| `coursepilot_create_workbench_download_links` | Issue 15-minute single-use links for paths to shell-capable clients, without OAuth Bearer headers; return URL/name/size/SHA-1, not ready-made shell commands |

Choose modname according to implementation-plan-workflow.

### Source headers and composition

Each compose part has sourcepath and its own location, default store or
workbench; mixed sources are allowed. Optional crop has relative
x0/y0/x1/y1 as with crop. Optional source_header_text adds a header.
expected_contenthash protects the SHA-1 of each source; crop's corresponding
check value instead protects its target. Omit unknown optional SHA-1 for
external inventory. Missing crop uses the whole original; missing/empty
header text creates no strip. Target resolution follows crop rules;
paths must end in .png and existing targets are replaced.

One part with a header and crop needs no intermediate file:

```json
{
  "parts": [{
    "sourcepath": "ml-p36.png",
    "location": "store",
    "crop": {"x0": 0.1, "y0": 0.2, "x1": 0.9, "y1": 0.5},
    "source_header_text": "ML p. 36"
  }],
  "arrangement": "vertical",
  "targetpath": "ml-p36-task-header.png"
}
```

Return path, width, height, size, contenthash, created, message and ordered
sources containing location, resolved path, size and modification time;
no image bytes. See `coursepilot_get_skill("graphics")` for source-header
defaults, abbreviation clarification, approval and alt text.

## Context area

Working plans, status, journals, material notes and profiles.
`pending_entry=<identifier>` replays an unsaved entry through write/append.
`previous_location:true` on list/read is a read-only switch for old content,
available only while that state is open. All outage/conflict/selection
rules are defined in context-area; tools here are an index:

| Tool | Use |
|---|---|
| `coursepilot_list_context_files` | List folders, optionally at previous_location |
| `coursepilot_read_context_file` | Read files, optionally at previous_location |
| `coursepilot_write_context_file` | Create/full replacement; optional pending_entry or create_only for old-content copying |
| `coursepilot_append_context_file` | Append; optional pending_entry |
| `coursepilot_dismiss_pending_entry` | Explicitly dismiss a pending-note entry |
| `coursepilot_dismiss_previous_location` | Explicitly end old-content state |

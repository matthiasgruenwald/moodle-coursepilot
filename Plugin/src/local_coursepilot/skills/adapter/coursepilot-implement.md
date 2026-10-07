---
name: coursepilot-implement
description: Coursepilot implementation. Use this skill after approval such as "Yes, implement it this way" to write an approved Coursepilot plan into an existing Moodle course.
---

# coursepilot-implement

First read `coursepilot_get_skill("coursepilot-core")` and
`coursepilot_get_skill("context-area")` for tools, the write offer,
journal append under session context authorization, manual-edit handling
and rotation. Respond in the teacher's language.

Follow the core's status check before writing: read `status.md` through
`coursepilot_read_context_file`, including the manual-edit check. Before
every Moodle write, also read
`coursepilot_get_skill("implementation-plan-workflow")`. Select further
activity references from the overview in `coursepilot-core`.

After Moodle writes, update `status.md` through
`coursepilot_write_context_file` after the write offer. Append the
implementation report to the journal through
`coursepilot_append_context_file`, automatically under session context
authorization without individual confirmation. If appending fails due to
a pending write or context gap, Moodle implementation still counts as
complete. Replay only the report, never the implementation;
see `coursepilot_get_skill("context-area")`.

For creating or changing an unfamiliar question type, read
`coursepilot_get_skill("question-types")` (type learning files, learning
loop and contradiction checks).

For creating or superseding an activity without a field catalog, such as
book, checklist or glossary, read `coursepilot_get_skill("activity-types")`
(activity-type learning files, learning loop and superseding).

At the end of a completed build, apply the cleanup question from
`coursepilot_get_skill("context-area")`, under "Cleanup question after a build".

Follow the core's plan discipline.

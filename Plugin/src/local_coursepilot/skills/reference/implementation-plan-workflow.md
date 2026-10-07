---
name: implementation-plan-workflow
description: Read this before every Moodle write and when building or executing an implementation plan.
---

# Reference: implementation-plan workflow

Read before every Moodle write, including `coursepilot_create_module`,
`coursepilot_update_module_settings`, `coursepilot_move_section`,
`coursepilot_move_module`, `coursepilot_set_completion` and
`coursepilot_set_restriction`, while building and executing plans in
coursepilot-plan and coursepilot-implement.

## Principles for phase analysis

### Flexible phase count

There is no fixed count. Analyze the supplied unit/subtopic and create as
many phases as it describes, typically 3–6 but possibly 2 or 8.

### Free phase design

No rigid model is required. Examples:

- Action-oriented: inform, plan, execute, check, reflect.
- Project-based: analyze, design, implement, test, accept.
- Problem-based: problem, hypothesis, experiment, evaluation.
- A structure derived from the supplied unit/subtopic.

### Derive content from the teaching unit

Derive all texts, tasks and materials from the supplied unit or subtopic.
Do not invent them or copy example content. Apply plan discipline throughout
planning and implementation; see `coursepilot_get_skill("coursepilot-core")`.

## Mandatory workflow before every write

Before any writing MCP tool, create an implementation plan and show a
staged preview. Write Moodle changes only after explicit approval such
as "Yes, implement it this way", "The plan looks good, go ahead" or
"Approved", including equivalents in the teacher's language.

The plan is plan.md in the teacher's context area, not a local data object;
see `coursepilot_get_skill("context-area")`. Record sections, activities,
gate status and deviations there. Update after each plan change through
the write offer.

### Natural opening requests

These start planning rather than direct tool calls:

- "Plan the section for ..."
- "Create an implementation plan for ..."
- "How would you populate the course? Show me the plan first."
- "Before you start, what is the plan?"

### Procedure

Four numbered steps, each with a verifiable completion criterion. The
overall criterion is exhaustive: every part of the teacher's request must
appear in plan.md or be explicitly named as a tool gap under the core's
Activity tool gaps rule. No request item silently disappears.

#### Step 1: build the plan

First establish the named course/project question bank as a distinct
planning decision; see `coursepilot_get_skill("quiz-and-question-bank")`.
For every activity record type, name, content/description, whether it is
a learning-path gate and whether digital submission is planned. Derive
completion configuration from the planning principles below. Save plan.md.

Completion criterion: plan.md meets the overall criterion before step 2.

#### Step 2: show a compact overview

Show sections, ordered activities, types, gate status, completion and
restrictions, the named bank with structure, and planning principles and
deviations. Omit full content such as entire information pages.

Completion criterion: the teacher has seen the overview and all tool gaps.

#### Step 3: show full content on request

When asked, such as "Show me the whole information page", show complete
content for that individual activity.

Completion criterion: every requested activity has been shown in full
before further planning or approval.

#### Step 4: wait for approval

Write only after explicit teacher confirmation. Without it, call no
writing tool. Follow the one-plan rule and status-controlled approval in
CONTEXT.md: exactly one active plan.md per project, approval recorded in
status.md rather than only chat.

Completion criterion: status.md has the established freigegeben (approved)
state before coursepilot-implement performs a Moodle write.

### Section and activity moves

For section moves, first update and confirm the new order in plan.md,
then call `coursepilot_move_section`. A journal-only exception requires
explicit teacher confirmation that the approved subject plan is unchanged
and only the existing course is being reorganized. Record a journal entry
before writing; see `coursepilot_get_skill("journal")`. Change no other
section content or visibility.

The same binding applies to `coursepilot_move_module`: move an existing
activity by cmid into another section or a chosen position. Preserve
content, visibility, completion, prerequisites, quiz settings, question
references and question data.

### Planning principles: do not repeat per activity

- Assignment without submission used as a gate: manual learner completion
  (`completion=1`).
- Assignment with digital submission used as a gate: submission completion
  (`completion=2`, `completionsubmit=1`).
- Pages have no gate by default; manual completion only for explicitly
  planned required reading.
- Set restrictions only when explicitly planned and justified.
- Open submission is the default: `submissiondrafts=0`. Learners revise
  until grading or the submission deadline. For multiple attempts
  (`maxattempts` != 1), use attemptreopenmethod=untilpass or automatic.
- Learner locks are settings requiring teacher action before learners can
  continue or revise. Field-catalog learner_lock describes them;
  `coursepilot_get_module_settings` reports existing locks. Plan a lock
  only when explicitly requested or specified in context, and show it as
  a justified deviation. Choosing a mode such as final-test chooses its
  settings and locks too: list them in the plan; the tool confirms them
  through mode selection itself.
- If a write rejects with learnerlocksunconfirmed, nothing was written.
  If the teacher explicitly requested that exact lock, retry with
  confirm_learner_locks and the reported identifiers. Otherwise omit the
  field or choose its open value as explained in the error, then retry.

### Plan deviations

Every activity deviating from a principle, such as required-reading pages
with gates or additional restrictions, needs a short justification when
added. Without justification, exclude it. Show it as a separate plan.md
item and in the overview, visibly explained rather than hidden in a list.

## Execution: Moodle steps

### Step 1: analyze the unit or subtopic

Before the first API call, read the unit and record:

- Phase count and names.
- Phase colors, freely chosen but consistent.
- Activities belonging to each phase.
- What learners only read and what they submit.

### Step 2: check course structure

```
coursepilot_get_sections(courseid=COURSE_ID)
```

Match the target section to the approved plan. Section 0/General is a
normal subject section, not technical storage for versions, status,
debug hints or process data. Never populate an arbitrary free section
without an approved plan.

### Step 3: name the section and add an introduction only if planned

```
coursepilot_update_section(courseid, sectionnum, fields_json='{"name": ..., "summary": ...}')
```

A summary introduction is not automatic. Use it only when the approved
plan specifies a visible introduction for that exact section.

### Step 4: create planned elements per phase

Use `coursepilot_create_module(courseid, sectionnum, modname, fields_json)`:

1. label only when a visible phase separator is planned.
2. According to content: page, url, assign, resource, folder, choice or forum.

## Choose activity types

| Situation | modname |
|---|---|
| Learners only read information, guides, instructions or code examples | page |
| Learners fill in, submit or reflect | assign |
| External documentation, GitHub, MDN or references | url |
| Phase separator visible on the course page | label |
| Downloadable PDF, worksheet or template | resource |
| File collection | folder |
| Option selection, survey or opinion poll | choice |
| Asynchronous discussion or questions/answers | forum |

resource was blocked before Spec 0018 because a missing main file produced
a broken page; folder was the temporary download alternative. Spec 0018's
material-file path now permits resource creation with its main file.

Golden rule: whenever learners fill in, enter, tick or upload anything,
use `coursepilot_create_module(modname="assign")`, never page.

### Choice: assess option count pedagogically

Moodle imposes no upper bound on option[], and Coursepilot does not
validate count (Spec 0015 §4.5). Around eight options, consider asking
whether a choice is still appropriate instead of a list, grouping or
table. This is a teaching recommendation, not validation: reject no count
and invent no upper limit.

---
name: completion-tracking
description: Read this when the teacher requests completion tracking or restriction chains between activities.
---

# Reference: optional completion tracking

When requested, first ask in the teacher's language:
"Shall I enable completion tracking? Learners will need to submit each
assignment before the next one becomes available."

If yes, run the workflow after creating all activities.

## Activities with completion

| modname | Configuration | Meaning |
|---|---|---|
| assign | completion=2, completionsubmit=1 | Automatic on submission |
| choice | completion=2, completionsubmit=1 | Automatic on voting |
| page | completion=1 | Learners manually mark complete |
| url | None | Skip links |
| label | None | Skip headers |

completionsubmit exists only for assign/choice; other types are rejected.
All completion* fields use coursepilot_set_completion exclusively.
Generic create/update tools block them because Moodle silently discards
them without completionunlocked, while setting it deletes learner
completion records.

## Required setup order

1. Create all activities with coursepilot_create_module and record cmids.
2. Configure all tracked activities with coursepilot_set_completion.
3. Only after every completion call succeeds, set restrictions pointing
   to preceding activities through coursepilot_set_restriction.

Never reverse this order.

## Example: three consecutive assignments

```
// Create activities and record their cmids.
cmid_A = coursepilot_create_module(courseid, sectionnum, modname="assign",
   fields_json='{"name": "Phase 1 worksheet", ...}')       // e.g. 1001
cmid_B = coursepilot_create_module(courseid, sectionnum, modname="assign",
   fields_json='{"name": "Phase 2 task", ...}')            // e.g. 1002
cmid_C = coursepilot_create_module(courseid, sectionnum, modname="assign",
   fields_json='{"name": "Phase 3 implementation", ...}')  // e.g. 1003

// Enable completion for all three.
coursepilot_set_completion(cmid=1001, fields_json='{"completion": 2, "completionsubmit": 1}')
coursepilot_set_completion(cmid=1002, fields_json='{"completion": 2, "completionsubmit": 1}')
coursepilot_set_completion(cmid=1003, fields_json='{"completion": 2, "completionsubmit": 1}')

// B requires completion of A; C requires completion of B.
coursepilot_set_restriction(cmid=1002,
  conditions_json='[{"type": "completion", "activity_cmid": 1001, "status": "complete"}]')
coursepilot_set_restriction(cmid=1003,
  conditions_json='[{"type": "completion", "activity_cmid": 1002, "status": "complete"}]')
```

If the first completion call warns of deleting existing learner records,
do not blindly retry with confirmed:true. Ask first; see mcp-tools.

## Include required-reading pages

```
cmid_info = coursepilot_create_module(courseid, sectionnum, modname="page",
   fields_json='{"name": "Information sheet", ...}')  // e.g. 1000
cmid_task = coursepilot_create_module(courseid, sectionnum, modname="assign",
   fields_json='{"name": "Task", ...}')               // e.g. 1001

// Manual completion for reading; automatic completion for submission.
coursepilot_set_completion(cmid=1000, fields_json='{"completion": 1}')
coursepilot_set_completion(cmid=1001, fields_json='{"completion": 2, "completionsubmit": 1}')

// Unlock the task after reading is manually marked complete.
coursepilot_set_restriction(cmid=1001,
  conditions_json='[{"type": "completion", "activity_cmid": 1000, "status": "complete"}]')
```

## Keep labels and URLs outside the chain

Phase headers and external links receive neither completion nor
restrictions and stay visible. The chain uses assignments and, when
planned, pages.

## Avoid errors

- Configure prerequisite completion before restrictions.
- Never require an activity with completion=0.
- For multiple conditions, every activity_cmid must already have completion
  configured through coursepilot_set_completion.
- label blocks name; see `coursepilot_get_skill("technical-notes")`.

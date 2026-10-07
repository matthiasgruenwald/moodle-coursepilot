---
name: coursepilot-plan
description: Coursepilot planning. Use this skill for requests such as "Plan the section for ..." (planned), "Show me the complete information page" (checked) or "The plan looks good, go ahead" (approved), when a teaching unit needs planning, review or approval before Moodle writes.
---

# coursepilot-plan

First read `coursepilot_get_skill("coursepilot-core")` and
`coursepilot_get_skill("context-area")` for tools, the write offer and
manual-edit handling. Respond in the teacher's language.

Read further references as the situation requires:

- Building or previewing a plan: `coursepilot_get_skill("implementation-plan-workflow")`.
- Planning quizzes or question banks: `coursepilot_get_skill("quiz-and-question-bank")`.
- An unfamiliar question type: `coursepilot_get_skill("question-types")`.
- An activity without a field catalog, such as book, checklist or glossary:
  `coursepilot_get_skill("activity-types")`.
- Recording a planning decision: `coursepilot_get_skill("journal")`.
- An inventory change that cannot currently be performed:
  `coursepilot_get_skill("notepad")`.

Write `plan.md`, `status.md` and templates through
`coursepilot_write_context_file` only after the write offer, never silently.

Follow the core's one-plan rule and plan discipline.

For subject illustrations from textbooks or material inventory, and crops
to be viewed together, read `coursepilot_get_skill("graphics")` before
previewing the plan (source header and composition).

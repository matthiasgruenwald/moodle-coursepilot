---
name: coursepilot
description: Coursepilot entry. Use this skill for requests such as "Continue with biology" when a teacher wants to work on existing Moodle courses without clearly selecting a specialist mode.
---

# coursepilot

First read `coursepilot_get_skill("coursepilot-core")`. If the class, subject
or topic is ambiguous, also read `coursepilot_get_skill("context-onboarding")`.

State the appropriate mode (`coursepilot-plan` or `coursepilot-implement`)
and explain the switch briefly. Follow the core's plan discipline and
respond in the teacher's language.

## Server mode

Use only skills returned by `coursepilot_list_skills`. If locally installed
Coursepilot skills are also present, tell the teacher and continue with
server skills rather than mixing both sources.

Report nonempty `pending_entries` and `notices` from that same response
at session start; see `coursepilot_get_skill("context-area")`.

Read `coursepilot_get_skill("notepad")` when an inventory change cannot
currently be performed, or at session start for clients with local file tools.

---
name: journal
description: Read this when recording a reusable decision or resuming a session with a request such as "Continue my planning for ...".
---

# Reference: journal and resuming work

Read this for decisions worth documenting or requests to resume planning.
The journal (`CONTEXT.md`) records planning, approvals, Moodle changes and
context changes in dated Markdown files that are never overwritten:
memory without Git. Use only the context-area tools.

## Record decisions during work

As in grill-with-docs, record clarified concepts and decisions immediately
when reusable for later teaching plans, not only at session end. Chat
history is not reliable memory.

Decisions worth recording include:

- Learning groups: attainment, group dynamics, differentiation, language
  level, technical conditions and observations about a class or subgroup.
- Subject/teaching: competency, focus, material selection/renaming,
  OCR/image choices, quiz mode, passing thresholds, learning-path gates
  and deferred material gaps.
- Moodle plans: sections, phase models, plan deviations, restrictions,
  digital submissions and deliberately rejected alternatives.
- Context: applicable class, subgroup, subject profile or teaching folder.

Procedure:

1. Once a decision is settled, select its journal location below.
2. If necessary context is missing, clarify required context and offer a
   low-friction explanatory setup with preview;
   see `coursepilot_get_skill("context-onboarding")`. After confirmation,
   create appropriate CONTEXT.md files and record the note immediately.
3. Append a separate entry through `coursepilot_append_context_file`.
   Group decisions go to the class journal; teaching/material/quiz/Moodle
   plans to the teaching-folder journal; context follows existing subject
   assignment. Never directly overwrite existing journals or context files.
4. If the decision clarifies a canonical Coursepilot product/domain term,
   update repository CONTEXT.md instead or additionally. Use ADRs sparingly
   for decisions hard to reverse, surprising without context and involving
   a real tradeoff.

Keep entries concise but reusable: what, why, which group/subtopic and
what remains open.

## Journal location

Daily `journal-YYYY-MM-DD.md` files are relative to the context root;
see context-area storage layout.

| Scope | Relative location |
|---|---|
| Class | `<school-year>/<class>/journal-<date>.md` for cross-subject group development |
| Teaching folder | `<school-year>/<class>/<teaching-folder>/journal-<date>.md` for subject plans, Moodle implementation, materials and questions |

Follow the changed context's location automatically. Ask only for real
ambiguity, such as whether a note concerns the whole class or one subject.
A school-year journal is not the default.

## When to create entries

Throughout the workflow, not only after Moodle writes:

- Immediately after reusable group, subject, material, quiz or planning decisions.
- After context onboarding or deliberate profile additions.
- After material ingestion, renaming, OCR checks or cropping.
- After each approved and executed implementation plan.

After each such plan (`implementation-plan-workflow`), automatically append
an implementation report:

1. Use Markdown sections Successes, Errors and Remaining work, translated
   into the teacher's language. Name activity type and title first, then
   IDs/links as technical references. Omit internal tool/MCP corrections
   unless they affect results, uncertainty or remaining work. Summarize
   readback by subject effect: "New page visible, old note box hidden",
   rather than raw IDs and flags.
2. Append to the day's journal through `coursepilot_append_context_file`.
   Create it if missing. Never overwrite entries, including multiple
   entries on the same day.

Every journal note uses append, not direct file replacement.

## Resuming work at session start

For requests such as "Continue planning for 7a science" or "Where were we
with 7a?":

1. Report `pending_entries` from `coursepilot_list_skills` first and in a
   separate paragraph from remaining work; see `context-area`.
2. Load the applicable learning-group/subject profiles; see
   `coursepilot_get_skill("context-onboarding")`.
3. Read recent class and/or teaching-folder journals through
   `coursepilot_read_context_file`.
4. Find entries in Remaining work, including existing headings in the
   teacher's language.
5. Offer a follow-up proposal, e.g. "The last entry on 2026-06-10 left ...
   open. Shall we tackle that now?" Use the teacher's language.

Do not automatically execute unfinished work. The teacher chooses whether
and what to resume.

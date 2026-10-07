---
name: context-onboarding
description: Read this for explicitly requested Coursepilot context setup or when an ambiguous opening request needs clarification about class or subject.
---

# Reference: context onboarding

Use for deliberate setup or ambiguous class/subject selection. Before
building learning situations, use applicable course context from the
teacher's area: learning-group and subject profiles. These may contain
real student names under ADR 0003 and the marking rules in
`coursepilot_get_skill("context-area")`. Read/write only through its tools.
Before planning, implementation or other writes, read existing context
in the agreed order.

## Brief clarification for ambiguity

Offer a few suitable candidates rather than assuming a wrong context or
asking a long questionnaire. Example, in the teacher's language:

> Teacher: "Continue with biology."
> Coursepilot: "I found two open plans: 7a (photosynthesis) and 7c (cell
> structure). Which do you mean?"

## When setup starts

Only as an explicitly selected setup option, never automatically.
Examples: "Set up context for 7a science", "Create a learning-group profile
for 7a" or "Set up my class/group".

If `<school-year>/<class>/CONTEXT.md` already exists according to
`coursepilot_list_context_files`, point to it rather than offering setup again.

## Required context

Only these details are mandatory:

1. School year, e.g. 2025-26.
2. Class or learning group, e.g. 7a. Split/mixed groups have independent
   names, such as 7a-science-advanced, directly under the school year,
   not nested inside 7a.
3. Subject/teaching folder, e.g. science, only when creating a subject profile.

Names use letters, digits, hyphens and underscores, no path separators or .. .

## File layout

Paths are relative to the context root; see context-area storage layout.

| File | Location |
|---|---|
| Learning-group profile | `<school-year>/<class>/CONTEXT.md` |
| Subject profile | `<school-year>/<class>/<subject>/CONTEXT.md` |

Independent subgroups use their own class value and folder under the year.

## Explanatory setup

Six steps with verifiable completion criteria. Setup ends only after
step 6's next-step choice has been offered.

### Step 1: request required context

Ask for year, class/group and subject/folder when needed.

Completion: all required details, at least year and class/group, are available.

### Step 2: explain creation

Briefly name files, location and purpose before requesting content.
Example: "I will create 2025-26/7a/CONTEXT.md in your context area. It records
cross-subject information about the group." Use the teacher's language.

Completion: the teacher knows targets and purposes before content questions.

### Step 3: offer optional planning context

Offer, without requiring: attainment, learning needs, dynamics, language
and technical conditions for group profiles; competency, working methods,
current topics and assessment state for subject profiles. On later/unknown,
leave the not-yet-recorded placeholder, translated into the teacher's language.

Completion: every optional field is filled or deliberately skipped;
no question silently disappears.

### Step 4: ask about related context

Ask lightly whether this is a subgroup or has a related learning group.
Store a reference only, never automatically copy related-profile content.

Completion: the question is answered or explicitly skipped.

### Step 5: preview and create after confirmation

Show CONTEXT.md previews before `coursepilot_write_context_file`.
Do not overwrite existing files or create without confirmed preview.

Completion: confirmed files exactly match their previews, or creation
was deliberately withheld because confirmation was absent.

### Step 6: offer the next-step choice

Offer planning now (coursepilot-plan), implementing an already approved
plan (coursepilot-implement), or continuing later.

Completion: the choice was offered, regardless of the selected option.

## Frontmatter and index (OKF, Specs 0010/0011)

Created CONTEXT.md profiles/projects use only Spec 0010's limited YAML:
type, title, tags, status, created, updated, about, gradeLevel,
coursepilot.personenbezug and coursepilot.weitergabe. Formulate it and
show it in preview; invent no new frontmatter syntax.

When creating a teaching project, best-effort add its folder to root
index.md with subject, year level, tags, summary and status. If index.md
is unreadable or has broken/duplicate markers, warn and preserve it.
This must not block creation of the project itself.

Personal observations about individual students go in a separate
CONTEXT-people.md sidecar created with `coursepilot_write_context_file`,
not the shareable subject file. Always mark the sidecar with
coursepilot.personenbezug:true and visibly link it from the subject file;
see the context-area real-name rule.

## Clone-source templates (KP-010)

Teachers may store frequent `coursepilot_clone_activity` sources (#328,
Spec 0013) in root templates.md beside school-year folders. No plugin
registry or database: a template is a regular course activity addressed
by cmid.

### Format

A free Markdown list with one item per template. Recommended fields:
activity type, course name/ID, cmid, a short explanation of distinctive
settings and optional supplementary-document links.

```markdown
- **Assignment** — Biology 7a (course ID 42), cmid 318: file submission
  with rubric grading and a peer-feedback window. Template for
  presentation submissions.
```

### Read triggers

Read only when:

1. The teacher requests settings MCP cannot set, such as submission-plugin configuration.
2. They refer to a previous solution, such as the last assignment or biology course.
3. Immediately before cloning without a supplied cmid.

### Write only after confirmation

Teachers own template maintenance. After successful cloning, propose an
entry if helpful, but write templates.md only after explicit confirmation,
following the same preview/confirmation rule as profile setup.

---
name: question-types
description: Read this when Coursepilot must create or change a question type it has not learned yet.
---

# Reference: question-type files and learning loop

Basis: Spec 0017 §3/§5 (`docs/specs/0017-fragenbank-import-klonen.md`).
Use context-area tools, write offers and manual-edit checks; this reference
adds only question-specific rules.

## Question-type learning files

Learn unfamiliar types without plugin/skill maintenance. Server-side
round-trip checks (Spec 0017 §2) roll failed attempts back without writes,
allowing experimentation under normal plan approval.

Store knowledge as ordinary teacher context files at the fixed relative path:

```
question-types/<type>.md
```

Add no root prefix; the plugin resolves the root. One file per type,
without a plugin catalog, registry or curation service: maintenance cost
is an explicit boundary.

### Required structure

| Section | Content |
|---|---|
| Header | Type, Moodle version, plugin version and last verification date |
| Minimal example | Exact XML from a successful round trip |
| Required structure | What can be omitted and what cannot |
| Pitfalls | Symptom → cause → remedy per item |
| Extensions | Optional features, such as complex grading trees, in separate subsections |

The header detects stale knowledge. Before writing it, retrieve versions
through `coursepilot_get_version_info`: Moodle release, plugin_version and
plugin_release in plain text. Unknown versions are not valid placeholders;
without them, contradiction checks cannot identify outdated files.

### Minimal examples are evidence

Store exactly the imported XML, including its XML declaration and quiz
wrapper. import_questions_xml uses qformat_xml and requires a quiz root.
Do not shorten, reconstruct or tidy the example after verification.
To store a shorter example, import that exact shortened version first
and verify it. Otherwise the learning file introduces an untested error
into the next loop; false retained knowledge is worse than no file.

### Write rule

Use `coursepilot_write_context_file` with expected_contenthash for full
replacement and conflict protection, not append. Integrate each pitfall
or extension into its proper section rather than adding it blindly at
file end. After the first successful round trip of a new type, offer to
save the learning and write only after teacher confirmation.

### Sharing

Use Moodle's existing My files ZIP download or Server files filepicker.
There is no Coursepilot endpoint, registry or guarantee for sharing.

## Learning loop

1. Read question-types/<type>.md, when present, through
   coursepilot_read_context_file with manual-edit checking. Before building,
   compare its header with coursepilot_get_version_info. Then use its rules.
2. Without a file, search working examples in the teacher's own Moodle
   inventory through `coursepilot_export_questions_xml`.
3. Build and import. Round-trip checks verify or roll everything back;
   deviations inform the next attempt.
4. Make at most three attempts, then stop experimenting.
5. After the third failure, explicitly ask the teacher to create, check
   and export one example in this Moodle instance. Learn from that actual
   version-specific template.
6. After the first successful new-type round trip, offer to save the
   learning file under the write rule above.

### Transparency

Announce unfamiliar types, e.g. "This question type is new to me; I am
trying it out," in the teacher's language. Report each attempt's failure
and correction. Explicitly request the template after three failures;
no silent experimentation or waiting loop.

### Contradiction checks

Read the file before every build. Explicitly report either contradiction:

- Version mismatch: compare header and get_version_info as a distinct,
  mandatory yes/no substep before building, never merely implied by use.
- Behavior mismatch: a documented rule fails or an excluded error occurs.

Offer to revise the affected section with a likely cause, such as a new
Moodle/plugin release, and current header versions. Do not silently keep
working against invalid knowledge.

## Scope

templates.md stores activity templates (Spec 0013), a separate concept.
Other context rules still come from `coursepilot_get_skill("context-area")`:
plan/status write offers, manual edits, rotation and real-name marking.

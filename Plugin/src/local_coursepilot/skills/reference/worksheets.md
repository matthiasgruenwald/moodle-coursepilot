---
name: worksheets
description: Read this to create a fillable Word worksheet (.docx) for a phase and attach it to an assignment.
---

# Reference: worksheets for Moodle assignments

## No grading grid

Worksheets contain no grading rubric or points table. Moodle handles
grading; embedding another grid duplicates that function.

## No metadata fields

Omit name, class and date fields. Moodle records them on submission.

## Subject-specific design

Follow the unit's subject rather than a generic school layout:

- Dark header, subject accent color and thematic icon.
- A distinct accent color appropriate to each phase.
- IoT/ESP32 example: cyan #06B6D4, dark slate #0F172A, monospace code,
  icons such as >>--[GPIO]-->> or f=1/T.

## Required structure

1. Thematic single-column header with dark background, accent, phase name and icon.
2. A short introductory task sentence.
3. Numbered questions with badge numbers.
4. Fillable answer fields: gray table cells (#F8FAFC) with dashed underlines.
5. Footer: italic gray submission hint.

## Upload

Upload the generated .docx through coursepilot_upload_material_file with
Base64 content into the teacher's workbench, then attach it to the
assignment from there.

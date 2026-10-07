---
name: html-templates
description: Read this when creating pages, phase headers or assignment descriptions with HTML content.
---

# Reference: HTML templates

Use for HTML in page, label and assign activities created through
coursepilot_create_module.

Templates are optional. Use only visible elements justified by the request,
material or approved plan; follow core plan discipline. Prefer a simpler
presentation when it serves the same purpose. Translate example labels
into the course language.

## Planned section introduction: optional summary for coursepilot_update_section

Use only when the approved plan explicitly includes a visible introduction
for this section, including section 0/General. It holds subject content,
never an automatic Coursepilot process container.

Replace all placeholders with actual unit/subtopic content:

```html
<div style="background:linear-gradient(135deg,#1a237e,#283593);border-radius:12px;padding:0;margin-bottom:20px;overflow:hidden;box-shadow:0 4px 15px rgba(0,0,0,0.2);">
  <div style="background:rgba(255,255,255,0.1);padding:12px 20px;display:flex;align-items:center;gap:10px;">
    <span style="font-size:1.4em;">&#127919;</span>
    <div>
      <div style="color:rgba(255,255,255,0.7);font-size:0.75em;font-weight:600;letter-spacing:2px;text-transform:uppercase;">SUBTOPIC [NUMBER] — [TITLE]</div>
      <div style="color:#fff;font-size:1.1em;font-weight:700;">Initial situation</div>
    </div>
  </div>
  <div style="background:#fff;margin:0 16px 16px;border-radius:8px;padding:20px;">
    <p style="color:#333;line-height:1.7;margin-bottom:16px;">[SITUATION_FROM_UNIT_OR_SUBTOPIC]</p>
    <div style="border-top:2px solid #e8eaf6;padding-top:14px;">
      <div style="color:#1a237e;font-size:0.75em;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;margin-bottom:10px;">&#127919; LEARNING_OUTCOMES</div>
      <ul style="margin:0;padding-left:20px;color:#444;line-height:2;">
        <li>[OUTCOME_1_FROM_UNIT_OR_SUBTOPIC]</li>
        <li>[OUTCOME_2_FROM_UNIT_OR_SUBTOPIC]</li>
      </ul>
    </div>
  </div>
</div>
```

## Phase headers: label

Set intro; Moodle derives the display name from it. label blocks name.

```html
<div style="background:linear-gradient(135deg,[COLOR]dd,[COLOR]);border-radius:10px;padding:16px 20px;margin:10px 0;box-shadow:0 3px 10px rgba(0,0,0,0.15);">
  <div style="display:flex;align-items:center;gap:14px;">
    <span style="font-size:2em;">[ICON]</span>
    <div>
      <div style="color:rgba(255,255,255,0.8);font-size:0.7em;font-weight:700;letter-spacing:2px;text-transform:uppercase;">PHASE [NUMBER]</div>
      <div style="color:#fff;font-size:1.25em;font-weight:700;">[PHASE_NAME]</div>
      <div style="color:rgba(255,255,255,0.85);font-size:0.82em;margin-top:3px;">&#9203; approx. [TIME] minutes &nbsp;•&nbsp; [GROUPING]</div>
    </div>
  </div>
</div>
```

Choose colors/icons freely but consistently within a course. Suggestions,
not requirements:

| Type | Color | Icon |
|---|---|---|
| Analysis / research | #1565C0 (blue) | &#128269; |
| Planning / design | #6A1B9A (purple) | &#128203; |
| Execution / implementation | #E65100 (orange) | &#9881;&#65039; |
| Testing / checking | #2E7D32 (green) | &#9989; |
| Reflection / presentation | #00695C (teal) | &#128172; |
| Analysis / problem | #B71C1C (red) | &#128270; |
| Documentation | #37474F (gray) | &#128196; |

## Pages with syntax highlighting

Set the page pseudofield {text, format:1, itemid:0}, not content directly.
Without page, Moodle silently writes null content and the page stays empty.

Include highlighting only when the page contains code:

```html
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/atom-one-dark.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/highlight.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/languages/[LANGUAGE].min.js"></script>
<script>document.addEventListener('DOMContentLoaded', function(){ hljs.highlightAll(); });</script>

<div style="font-family:Arial,sans-serif;max-width:900px;margin:0 auto;padding:20px;">
  <h2 style="color:[PHASE_COLOR];border-bottom:3px solid [PHASE_COLOR];padding-bottom:8px;">[TITLE]</h2>
  <div style="background:[PHASE_COLOR_LIGHT];border-left:4px solid [PHASE_COLOR];padding:16px;border-radius:4px;margin-bottom:24px;">
    <strong>Learning objective:</strong> [OBJECTIVE_FROM_UNIT_OR_SUBTOPIC]
  </div>

  <h3 style="color:[PHASE_COLOR];">[SECTION_TITLE]</h3>
  <p>[EXPLANATION]</p>
  <pre><code class="language-[LANGUAGE]">// Code here
  </code></pre>
</div>
```

Available languages: cpp, python, javascript, java, bash, ini, json, html, css, sql

For pages without code, omit highlight.js and use only the div container.

## Assignments: assign intro

Include only visible elements needed for the task. Submission/print/PDF
hints, banners or extra buttons require supplied material, a justified
plan entry or explicit teacher approval.

```html
<div style="font-family:Arial,sans-serif;padding:20px;">

  <div style="background:[PHASE_COLOR_LIGHT];border-left:4px solid [PHASE_COLOR];padding:16px;border-radius:4px;margin-bottom:24px;">
    <strong>Task:</strong> [TASK_FROM_UNIT_OR_SUBTOPIC]
  </div>

  [TASK_CONTENT]

  [OPTIONAL_SUBMISSION_HINT_ONLY_IF_PLANNED]

</div>
```

For input fields, checkboxes and tables, see interactive-elements.
For drawing tasks, see drawing-canvas:
`coursepilot_get_skill("interactive-elements")`,
`coursepilot_get_skill("drawing-canvas")`.

---
name: technical-notes
description: Read this for activity names, formulas or a final quality check before creating an activity.
---

# Reference: technical notes, formulas, naming and quality

## Technical rules

- Avoid emoji in activity name fields for database compatibility. Use HTML
  entities in content, e.g. &#127919; instead of an emoji character.
- Section numbers are zero-based; section 1 has sectionnum:1.
- Briefly report progress after tool calls.
- Code pages use highlight.js with pre/code class language-XY.
- Drawing tasks use a canvas, never an empty div; see drawing-canvas.

## Mathematical formulas: LaTeX/MathJax

Moodle renders LaTeX through MathJax. Always use LaTeX notation:

| Display | LaTeX |
|---|---|
| Inline | `\( f = \frac{1}{T} \)` |
| Block | `\[ f = \frac{1}{T} \]` |
| Fraction | `\frac{numerator}{denominator}` |
| Subscript | `U_{GPIO}` |
| Superscript | `cm^2` |
| Multiplication | `\times` |
| Omega | `\Omega` |
| Spaced unit | `220\,\Omega` or `1\,\text{Hz}` |

ESP32 unit examples:

```
\[ f = \frac{1}{T} \qquad T = 2 \times BLINK\_INTERVAL \qquad R = \frac{U_{GPIO} - U_{LED}}{I_{LED}} \]
```

```
The period is \( T = 100\,\text{ms} \), so \( f = 10\,\text{Hz} \).
```

Do not use plain-text formulas such as f = 1/T or U_GPIO.

## Critical naming rules

For phase labels, name is blocked. Moodle derives the navigation name
from intro through get_label_name(). Put the phase name in intro HTML,
never name in fields_json:

```
coursepilot_create_module(courseid, sectionnum, modname="label",
   fields_json='{"intro": "<h3>Phase 1 – Inform &amp; analyze</h3>"}')
```

For assignments, pages and links, omit Phase x prefixes because the
preceding label already supplies that context. Use short descriptive names:

```
Correct: name="Analysis sheet: ESP32 and client request"
Wrong:   name="Phase 1 – Analysis sheet: ESP32 and client request"
Correct: name="Frequency calculation and circuit"
Wrong:   name="Phase 2 – Frequency calculation and circuit"
```

Translate content and names into the teacher's requested course language.

## Before creation

1. Page or assignment? Reading-only uses page; submissions use assign.
2. Naming? label uses intro for its phase name and no name parameter.
   Remove Phase x prefixes from assignments/pages/links.
3. Placeholders? Follow `coursepilot_get_skill("interactive-elements")`.
   Remove answers and overly concrete hints, such as esp32dev examples.
4. Drawing? Include a canvas; see `coursepilot_get_skill("drawing-canvas")`.
5. Input tables? Clear prefilled answers and verify neutral placeholders.

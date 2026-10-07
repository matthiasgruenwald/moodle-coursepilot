---
name: interactive-elements
description: Read this for assignment input fields, checkboxes, rating scales or tables with inputs.
---

# Reference: interactive assignment elements

Use for assign activities containing inputs, checkboxes, rating scales
or tables with inputs. Translate prompts into the course language.

## Text input

```html
<!-- Short answer -->
<input type="text" style="width:90%;padding:6px;border:1px solid #bbb;border-radius:4px;"
  placeholder="[OPEN PROMPT FOR WHAT TO ENTER, NOT THE ANSWER]"/>

<!-- Long answer -->
<textarea style="width:100%;border:1px solid #bbb;border-radius:4px;padding:8px;font-family:Arial;font-size:14px;" rows="3"
  placeholder="[OPEN PROMPT, e.g. 'Describe in your own words...', NOT THE ANSWER]"></textarea>
```

## Checkbox and radio

```html
<!-- Interactive checkbox; never use the static &#9744; symbol -->
<input type="checkbox" style="width:20px;height:20px;cursor:pointer;accent-color:#2E7D32;"/>

<!-- Rating scale: a distinct name for each row -->
<input type="radio" name="rating_row1" value="1"/> 1 &nbsp;
<input type="radio" name="rating_row1" value="2"/> 2 &nbsp;
<input type="radio" name="rating_row1" value="3"/> 3
```

## Mandatory placeholder rules

**Incorrect: reveals the answer:**
```html
placeholder="T = 0.2 seconds"
placeholder="delay = 100ms"
placeholder="board = esp32dev"
placeholder="e.g. GET"
placeholder="e.g. arduino"
```

**Correct: hints only at format or thinking direction:**
```html
placeholder="Calculate T from the frequency..."
placeholder="T/2 gives the delay value"
placeholder="Which board is used?"
placeholder="Which HTTP method reads data?"
placeholder="Which framework does PlatformIO use?"
```

**Golden rule:** placeholders describe what to enter without containing
or directly pointing to the answer. When unsure, use a generic prompt
such as "Your answer..." rather than a revealing one.

## Tables with input fields

```html
<!-- Provide anchors only; learners work out the content -->
<table style="width:100%;border-collapse:collapse;margin-bottom:20px;">
  <thead style="background:[PHASE_COLOR];color:white;">
    <tr>
      <th style="padding:10px;">[KNOWN_COLUMN]</th>
      <th style="padding:10px;">[COLUMN_TO_COMPLETE_1]</th>
      <th style="padding:10px;">[COLUMN_TO_COMPLETE_2]</th>
    </tr>
  </thead>
  <tbody>
    <tr>
      <td style="padding:10px;border:1px solid #ddd;">[GIVEN_VALUE]</td>
      <td style="padding:10px;border:1px solid #ddd;">
        <input type="text" style="width:90%;padding:4px;border:1px solid #bbb;border-radius:4px;"
          placeholder="[PROMPT_FOR_CALCULATION_OR_RESEARCH]"/>
      </td>
      <td style="padding:10px;border:1px solid #ddd;">
        <input type="text" style="width:90%;padding:4px;border:1px solid #bbb;border-radius:4px;"
          placeholder="[PROMPT_FOR_CALCULATION_OR_RESEARCH]"/>
      </td>
    </tr>
  </tbody>
</table>
```

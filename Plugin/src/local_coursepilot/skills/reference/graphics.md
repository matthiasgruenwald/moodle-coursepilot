---
name: graphics
description: Read this when planning or implementing subject illustrations from textbooks or inventory, composing crops or embedding graphics.
---

# Reference: graphics in pages and assignments

Read for subject illustrations, crop composition and embedding. Before
sending any SVG, follow `coursepilot_get_skill("svg-quality-assurance")`.
Embed existing illustrations server-side through material paths; use SVG
or Base64 for generated graphics. Avoid external image URLs.

## Subject illustrations from textbooks or inventory

- Add a source header by default for textbook illustrations, such as
  ML p. 36. The teacher can opt out of the source header. It complements
  the source reference in Moodle text.
- If the abbreviation is unambiguous from the filename or context, use it.
  Otherwise ask once for the abbreviation and remember it in the relevant
  subject or learning-group context in the context area, under context
  authorization or the write offer in `coursepilot_get_skill("context-area")`.
  Take the page number from the preview or filename; clarify ambiguity
  instead of inventing it.
- The header text appears in the plan with source, crop, target path and
  alt text. Normal plan approval approves it; no extra question about
  the header is needed before implementation.
- Offer composition when crops should be viewed together, such as a task
  and illustration or a comparison. Plan their order and arrangement.
  Supply one alt text for the whole composition. If all parts come from
  the same page, a header on the first part is enough.
- After approval, use `coursepilot_compose_material_file`. One part with
  optional crop and header needs no intermediate files. Mix inventory
  and workbench sources if needed. Image bytes stay on the server;
  send only paths, relative coordinates and header text. Use previews
  to choose crops rather than passing original image bytes through AI context.
- Fixed house style: white header strip above each labeled part; blue,
  bold, left-aligned text; white background and fixed spacing. No scaling.
  The result is a workbench PNG, not yet embedded in a course.
- Embed the result through create/update module tools with
  `location: workbench` and the single alt text. Inventory originals stay
  unchanged. See `coursepilot_get_skill("mcp-tools")` for parameters/examples.

## When graphics help

- Reference circuits, not fill-in circuits: use drawing-canvas for drawing tasks.
- Hardware assemblies and wiring.
- Architecture and system overviews.
- Flowcharts and procedures.
- Protocol sequences, such as HTTP requests/responses.
- Network topologies.
- UML reference diagrams.
- Expected/current-state comparisons.
- Microcontroller pinouts.

## Method 1: SVG (preferred)

Embed SVG directly in HTML: scalable vectors without quality loss.
Use it for technical diagrams, circuits and flowcharts.

Basic structure:
```html
<div style="margin:20px 0;text-align:center;">
  <svg viewBox="0 0 [WIDTH] [HEIGHT]" xmlns="http://www.w3.org/2000/svg"
    style="max-width:100%;height:auto;border:1px solid #e0e0e0;border-radius:8px;background:#fff;">

    <!-- Title -->
    <text x="[CENTER]" y="24" text-anchor="middle"
      font-family="Arial" font-size="14" font-weight="bold" fill="#333">
      [DIAGRAM_TITLE]
    </text>

    <!-- Content here -->

  </svg>
  <p style="font-size:0.85em;color:#666;margin-top:6px;font-style:italic;">[CAPTION]</p>
</div>
```

Common SVG elements:

```svg
<!-- Rectangle: component or block -->
<rect x="50" y="50" width="120" height="60" rx="6"
  fill="#E3F2FD" stroke="#1565C0" stroke-width="2"/>
<text x="110" y="85" text-anchor="middle" font-family="Arial" font-size="13" fill="#1565C0">
  ESP32
</text>

<!-- Line: connection or cable -->
<line x1="170" y1="80" x2="250" y2="80" stroke="#333" stroke-width="2"/>

<!-- Arrow: data flow -->
<defs>
  <marker id="arrow" markerWidth="10" markerHeight="7" refX="10" refY="3.5" orient="auto">
    <polygon points="0 0, 10 3.5, 0 7" fill="#333"/>
  </marker>
</defs>
<line x1="170" y1="80" x2="248" y2="80" stroke="#333" stroke-width="2" marker-end="url(#arrow)"/>

<!-- Circle: node or LED -->
<circle cx="100" cy="100" r="20" fill="#FFF9C4" stroke="#F57F17" stroke-width="2"/>

<!-- Dashed line -->
<line x1="50" y1="50" x2="200" y2="50" stroke="#999" stroke-width="1.5" stroke-dasharray="6,3"/>

<!-- Text with background -->
<rect x="45" y="28" width="70" height="22" rx="3" fill="#1565C0"/>
<text x="80" y="43" text-anchor="middle" font-family="Arial" font-size="11" fill="white">
  Label
</text>
```

Example: simple circuit as an SVG reference graphic

```html
<div style="margin:20px 0;text-align:center;">
  <svg viewBox="0 0 500 200" xmlns="http://www.w3.org/2000/svg"
    style="max-width:100%;height:auto;border:1px solid #e0e0e0;border-radius:8px;background:#fafafa;">

    <defs>
      <marker id="arr" markerWidth="8" markerHeight="6" refX="8" refY="3" orient="auto">
        <polygon points="0 0, 8 3, 0 6" fill="#555"/>
      </marker>
    </defs>

    <!-- ESP32 -->
    <rect x="30" y="70" width="100" height="60" rx="6" fill="#E3F2FD" stroke="#1565C0" stroke-width="2"/>
    <text x="80" y="96" text-anchor="middle" font-family="Arial" font-size="12" font-weight="bold" fill="#1565C0">ESP32</text>
    <text x="80" y="113" text-anchor="middle" font-family="Arial" font-size="10" fill="#555">GPIO2</text>

    <!-- Resistor -->
    <line x1="130" y1="100" x2="200" y2="100" stroke="#555" stroke-width="2"/>
    <rect x="200" y="88" width="60" height="24" rx="3" fill="#FFF9C4" stroke="#F57F17" stroke-width="2"/>
    <text x="230" y="104" text-anchor="middle" font-family="Arial" font-size="11" fill="#333">220 &#8486;</text>
    <line x1="260" y1="100" x2="320" y2="100" stroke="#555" stroke-width="2"/>

    <!-- LED -->
    <polygon points="320,82 320,118 355,100" fill="#A5D6A7" stroke="#2E7D32" stroke-width="2"/>
    <line x1="355" y1="82" x2="355" y2="118" stroke="#2E7D32" stroke-width="2.5"/>
    <text x="337" y="140" text-anchor="middle" font-family="Arial" font-size="11" fill="#2E7D32">LED</text>

    <!-- GND -->
    <line x1="355" y1="100" x2="430" y2="100" stroke="#555" stroke-width="2"/>
    <line x1="420" y1="88" x2="420" y2="112" stroke="#333" stroke-width="2.5"/>
    <line x1="425" y1="94" x2="425" y2="106" stroke="#333" stroke-width="2"/>
    <line x1="430" y1="99" x2="430" y2="101" stroke="#333" stroke-width="2"/>
    <text x="420" y="130" text-anchor="middle" font-family="Arial" font-size="11" fill="#333">GND</text>

    <!-- Top label -->
    <text x="250" y="35" text-anchor="middle" font-family="Arial" font-size="13" font-weight="bold" fill="#333">
      ESP32 GPIO2 LED circuit
    </text>

  </svg>
  <p style="font-size:0.85em;color:#666;margin-top:6px;font-style:italic;">
    Fig. 1: LED circuit with a 220-ohm series resistor
  </p>
</div>
```

## Method 2: Base64 image for complex graphics

For graphics too complex for SVG, such as photos, screenshots or detailed
assemblies, embed a Base64 PNG/JPG:

```html
<div style="margin:20px 0;text-align:center;">
  <img src="data:image/png;base64,[BASE64_DATA_HERE]"
    alt="[DESCRIPTION]"
    style="max-width:100%;height:auto;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,0.1);">
  <p style="font-size:0.85em;color:#666;margin-top:6px;font-style:italic;">[CAPTION]</p>
</div>
```

Use Base64 for:
- Photos or screenshots, such as PlatformIO or physical hardware.
- Highly detailed graphics.
- Existing image files.

Prefer SVG for:
- Technical diagrams: circuits, UML, flowcharts and topologies.
- Illustrations and infographics.
- Graphics made of simple geometric shapes.

## Mandatory graphic rules

- Always add a caption (Fig. X: ...), in the course language.
- Always set alt text for Base64 images.
- Always use viewBox and style="max-width:100%;height:auto;" for responsive SVG.
- Use no external http/https image URLs.
- Match graphic colors to phase colors when possible.
- Include graphics only when they improve understanding.

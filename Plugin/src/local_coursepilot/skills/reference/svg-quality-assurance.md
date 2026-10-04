---
name: svg-quality-assurance
description: Read this immediately before sending any SVG for mandatory overlap and positioning checks.
---

# Reference: SVG quality assurance

Before sending SVG from `coursepilot_get_skill("graphics")`, perform these
mandatory collision and positioning checks.

## Bounding-box overlap

Compute every element's bounding box and compare it with all others:

| Element | Bounds |
|---|---|
| rect x,y,w,h | x to x+w; y to y+h |
| circle cx,cy,r | cx-r to cx+r; cy-r to cy+r |
| ellipse cx,cy,rx,ry | cx-rx to cx+rx; cy-ry to cy+ry |
| text x,y | x to x+estimated width; y-fontsize to y |
| line x1,y1,x2,y2 | min(x1,x2) to max(x1,x2); min(y1,y2) to max(y1,y2) |

Overlap occurs when A.right > B.left and A.left < B.right and
A.bottom > B.top and A.top < B.bottom.

## Minimum spacing

- Between boxes (rect/ellipse): 20px.
- Arrow tip to target edge: 2px; account for marker refX.
- Text label to arrow: 8px vertically.
- Text label to box: 6px.

## Arrows through intermediate objects

Incorrect: a long arrow ends inside a Wi-Fi cloud:

```svg
<!-- Arrow ends in the middle; the cloud at cx=310, rx=30 hides its tip. -->
<line x1="160" x2="310" y1="80" y2="80" marker-end="url(#arrow)"/>
```

Correct: split before and after the intermediate object:

```svg
<!-- Box edge to left cloud edge. -->
<line x1="160" x2="[cx-rx-2]" y1="80" y2="80" marker-end="url(#arrow)"/>
<!-- Right cloud edge to next box. -->
<line x1="[cx+rx+2]" x2="538" y1="80" y2="80" marker-end="url(#arrow)"/>
```

## Position labels

Keep enough space from the line:

```svg
<!-- Arrow at y=82. -->
<line x1="160" x2="308" y1="82" y2="82" .../>
<!-- Above: arrow y minus 12. -->
<text x="234" y="70" text-anchor="middle" ...>GET / HTTP/1.1</text>
<!-- Below: arrow y plus 18. -->
<text x="234" y="100" text-anchor="middle" ...>200 OK</text>
```

Never use text-anchor=middle with an x coordinate on another element.

## Generous viewBox

Allow at least 20px on each side. Leftmost x=20 implies viewBox begins
at 0; bottommost y=180 requires height at least 200. Place the title
20px below the lowest graphical element.

## Before sending

- [ ] Every bounding box computed and checked for collisions?
- [ ] Arrows start/end at edges, not inside objects?
- [ ] Intermediate-object arrows split into segments?
- [ ] Labels at least 8px away from arrows?
- [ ] Labels overlap neither boxes nor other text?
- [ ] Title outside all other elements?
- [ ] viewBox margins at least 20px?

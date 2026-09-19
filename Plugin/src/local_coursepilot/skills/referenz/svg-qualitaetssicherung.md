---
name: svg-qualitaetssicherung
description: Lies diese Datei unmittelbar bevor eine SVG-Grafik abgesendet wird - Pflichtpruefung gegen Ueberschneidungen und Positionierungsfehler.
---

# Referenz: Qualitaetssicherung fuer SVG-Grafiken

Lies diese Datei unmittelbar bevor eine SVG-Grafik (siehe
`coursepilot_get_skill("grafiken")`) abgesendet wird – als Pflichtpruefung gegen
Ueberschneidungen und Positionierungsfehler.

## Pflichtpruefung: Ueberschneidungscheck

Fuer jedes Element die Bounding Box berechnen und gegen alle anderen pruefen:

| Element | Bounding Box |
|---|---|
| rect x,y,w,h | x bis x+w, y bis y+h |
| circle cx,cy,r | cx-r bis cx+r, cy-r bis cy+r |
| ellipse cx,cy,rx,ry | cx-rx bis cx+rx, cy-ry bis cy+ry |
| text x,y | x bis x+geschaetzte_breite, y-fontsize bis y |
| line x1,y1,x2,y2 | min(x1,x2) bis max(x1,x2) |

Zwei Elemente ueberschneiden sich wenn:
- A.rechts > B.links UND A.links < B.rechts UND A.unten > B.oben UND A.oben < B.unten

## Mindestabstaende einhalten

- Zwischen zwei Boxen (rect/ellipse): mindestens 20px Abstand
- Zwischen Pfeilspitze und Zielobjektrand: mindestens 2px (marker refX beachten!)
- Zwischen Textlabel und Pfeil: mindestens 8px vertikal
- Zwischen Textlabel und Box: mindestens 6px

## Pfeile durch Zwischenobjekte (z.B. WLAN-Wolke)

FALSCH: Ein langer Pfeil der durch eine Wolke hindurchgeht:
```svg
<!-- Pfeil endet MITTEN in der Wolke - sieht falsch aus -->
<line x1="160" x2="310" y1="80" y2="80" marker-end="url(#arrow)"/>
<!-- Wolke bei cx=310 rx=30 ueberdeckt die Pfeilspitze! -->
```

RICHTIG: Pfeil in zwei Segmente aufteilen - vor und nach dem Zwischenobjekt:
```svg
<!-- Segment 1: Box-Rand bis linker Wolken-Rand -->
<line x1="160" x2="[cx-rx-2]" y1="80" y2="80" marker-end="url(#arrow)"/>
<!-- Segment 2: rechter Wolken-Rand bis naechste Box -->
<line x1="[cx+rx+2]" x2="538" y1="80" y2="80" marker-end="url(#arrow)"/>
```

## Textlabels positionieren

Textlabels fuer Pfeile IMMER mit ausreichend Abstand zur Linie:
```svg
<!-- Pfeil bei y=82 -->
<line x1="160" x2="308" y1="82" y2="82" .../>

<!-- Label OBERHALB (y = Pfeil-y - 12) -->
<text x="234" y="70" text-anchor="middle" ...>GET / HTTP/1.1</text>

<!-- Label UNTERHALB (y = Pfeil-y + 18) -->
<text x="234" y="100" text-anchor="middle" ...>200 OK</text>
```

NIEMALS text-anchor="middle" mit x-Wert verwenden der auf einem anderen Element liegt.

## viewBox grosszuegig waehlen

Faustregel: viewBox mindestens 20px Rand auf jeder Seite:
- Linkstes Element bei x=20 → viewBox beginnt bei 0
- Unterstes Element bei y=180 → viewBox-Hoehe mindestens 200
- Titel immer 20px unterhalb des untersten Grafikelements

## Checkliste vor dem Absenden

- [ ] Alle Bounding Boxes berechnet und auf Kollision geprueft?
- [ ] Pfeile enden/starten am Rand von Objekten, nicht im Inneren?
- [ ] Pfeile durch Zwischenobjekte in Segmente aufgeteilt?
- [ ] Textlabels mindestens 8px von Pfeilen entfernt?
- [ ] Textlabels ueberschneiden keine Boxen oder andere Texte?
- [ ] Titel ausserhalb aller anderen Elemente?
- [ ] viewBox hat ausreichend Rand (mind. 20px)?

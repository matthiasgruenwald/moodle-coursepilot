---
name: technische-hinweise
description: Lies diese Datei bei technischen Details rund um Aktivitaetsnamen, Formeln oder als Checkliste kurz vor dem Anlegen einer Aktivitaet.
---

# Referenz: Technische Hinweise, Formeln, Benennung, Qualitaetspruefung

Lies diese Datei bei technischen Details rund um Aktivitaetsnamen, Formeln
oder als Checkliste kurz vor dem Anlegen einer Aktivitaet.

## Wichtige technische Hinweise

- KEINE Emojis in Aktivitaetstiteln (name-Feld) – Moodle-DB kein UTF8MB4
  Im HTML-Content HTML-Entities verwenden: &#127919; statt 🎯
- Abschnittsnummer ist 0-basiert: Abschnitt 1 = sectionnum: 1
- Nach jedem Tool-Aufruf kurz den Fortschritt berichten
- Codeseiten IMMER mit highlight.js: <pre><code class="language-XY">
- Zeichenaufgaben IMMER mit Canvas (siehe `coursepilot_get_skill("zeichen-canvas")`), NIEMALS mit leerem Div

## Mathematische Formeln (LaTeX / MathJax)

Moodle rendert LaTeX-Formeln automatisch via MathJax. Formeln IMMER in LaTeX-Notation schreiben:

| Darstellung | LaTeX |
|---|---|
| Inline-Formel | `\( f = \frac{1}{T} \)` |
| Block-Formel (eigene Zeile) | `\[ f = \frac{1}{T} \]` |
| Bruch | `\frac{Zaehler}{Nenner}` |
| Index unten | `U_{GPIO}` |
| Index oben | `cm^2` |
| Multiplikationszeichen | `\times` |
| Omega | `\Omega` |
| Einheit mit Abstand | `220\,\Omega` oder `1\,\text{Hz}` |

Beispiele aus der ESP32-Unterrichtseinheit:
```
\[ f = \frac{1}{T} \qquad T = 2 \times BLINK\_INTERVAL \qquad R = \frac{U_{GPIO} - U_{LED}}{I_{LED}} \]
```
```
Die Periodendauer betr&auml;gt \( T = 100\,\text{ms} \), also gilt \( f = 10\,\text{Hz} \).
```

NIEMALS Formeln als Plain-Text schreiben (z.B. `f = 1/T` oder `U_GPIO`).

## Benennung von Labels und Aktivitaeten (KRITISCH)

**Labels (Phasen-Header):** `name` ist fuer `label` gesperrt – Moodle leitet den in der
Kursnavigation sichtbaren Namen selbst aus `intro` ab (`get_label_name()`). IMMER den
Phasennamen als HTML in `intro` schreiben, NIEMALS `name` in `felder_json` mitgeben:
```
coursepilot_create_module(courseid, sectionnum, modname="label",
  felder_json='{"intro": "<h3>Phase 1 – Informieren &amp; Analysieren</h3>"}')
```

**Aufgaben, Seiten und Links:** NIEMALS einen "Phase x –" Prefix im `name`-Feld verwenden.
Der Phasenkontext ergibt sich bereits aus dem Label darueber. Kurze, beschreibende Namen:
```
RICHTIG: name="Analysebogen: ESP32 und Kundenauftrag"
FALSCH:  name="Phase 1 – Analysebogen: ESP32 und Kundenauftrag"

RICHTIG: name="Frequenzberechnung und Schaltplan"
FALSCH:  name="Phase 2 – Frequenzberechnung und Schaltplan"
```

## Qualitaetspruefung vor dem Erstellen

Fuer jede Aktivitaet pruefen:

1. Textseite oder Aufgabe?
   - SuS liest nur → `coursepilot_create_module(modname="page", ...)`
   - SuS gibt etwas ab → `coursepilot_create_module(modname="assign", ...)`

2. Name korrekt?
   - Label: Hat es einen `name`-Parameter mit dem Phasennamen? → Pflicht!
   - Aufgabe/Seite/Link: Enthält der Name einen "Phase x –" Prefix? → Entfernen!

3. Placeholder-Texte korrekt? (siehe `coursepilot_get_skill("interaktive-elemente")`)
   - Verrät der Placeholder die Antwort? → Anpassen!
   - Ist der Placeholder zu konkret (z.B. "z.B. esp32dev")? → Generischer formulieren!

4. Zeichenaufgaben?
   - Ist ein Canvas eingebaut? → Pflicht!

5. Tabellen mit Eingabefeldern?
   - Stehen in den Eingabefeldern schon die Antworten? → Leeren!
   - Sind die Placeholder neutral formuliert? → Pruefen!

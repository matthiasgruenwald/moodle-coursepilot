---
name: html-vorlagen
description: Lies diese Datei beim Erstellen von Textseiten, Phasen-Headern oder Aufgabenbeschreibungen mit HTML-Inhalt.
---

# Referenz: HTML-Vorlagen

Lies diese Datei beim Erstellen von Textseiten (`kurspilot_create_module` mit
`modname="page"`), Phasen-Headern (`modname="label"`) oder Aufgabenbeschreibungen
(`modname="assign"`) mit HTML-Inhalt.

Keine dieser Vorlagen ist Pflicht. Nutze nur die sichtbaren Elemente, die im
Auftrag, Material oder freigegebenen Implementierungsplan fachlich begruendet
sind (siehe Planstrenge in `kurspilot_get_skill("kurspilot-core")`). Wenn eine
schlichtere Darstellung denselben Zweck erfuellt, ist sie die richtige Wahl.

## Geplanter Abschnittseinstieg (optional fuer kurspilot_update_section, Feld "summary")

Nur verwenden, wenn ein freigegebener Plan fuer diesen Abschnitt ausdruecklich
einen sichtbaren Einstieg vorsieht. Das gilt auch fuer Abschnitt 0
("Allgemeines"): fachlicher Inhalt ja, automatischer Kurspilot-Prozesscontainer
nein.

Ersetze alle [PLATZHALTER] mit echten Inhalten aus der Unterrichtseinheit bzw. dem Unterthema:

```html
<div style="background:linear-gradient(135deg,#1a237e,#283593);border-radius:12px;padding:0;margin-bottom:20px;overflow:hidden;box-shadow:0 4px 15px rgba(0,0,0,0.2);">
  <div style="background:rgba(255,255,255,0.1);padding:12px 20px;display:flex;align-items:center;gap:10px;">
    <span style="font-size:1.4em;">&#127919;</span>
    <div>
      <div style="color:rgba(255,255,255,0.7);font-size:0.75em;font-weight:600;letter-spacing:2px;text-transform:uppercase;">UNTERTHEMA [NR] — [TITEL]</div>
      <div style="color:#fff;font-size:1.1em;font-weight:700;">Ausgangssituation</div>
    </div>
  </div>
  <div style="background:#fff;margin:0 16px 16px;border-radius:8px;padding:20px;">
    <p style="color:#333;line-height:1.7;margin-bottom:16px;">[SITUATIONSBESCHREIBUNG AUS DER UNTERRICHTSEINHEIT/DEM UNTERTHEMA]</p>
    <div style="border-top:2px solid #e8eaf6;padding-top:14px;">
      <div style="color:#1a237e;font-size:0.75em;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;margin-bottom:10px;">&#127919; LERNERGEBNISSE</div>
      <ul style="margin:0;padding-left:20px;color:#444;line-height:2;">
        <li>[ERGEBNIS 1 AUS DER UNTERRICHTSEINHEIT/DEM UNTERTHEMA]</li>
        <li>[ERGEBNIS 2 AUS DER UNTERRICHTSEINHEIT/DEM UNTERTHEMA]</li>
      </ul>
    </div>
  </div>
</div>
```

## Phasen-Header (fuer kurspilot_create_module mit modname="label")

Feld `"intro"` setzen - Moodle leitet den Anzeigenamen selbst aus `intro` ab,
`"name"` ist fuer `label` gesperrt.

```html
<div style="background:linear-gradient(135deg,[FARBE]dd,[FARBE]);border-radius:10px;padding:16px 20px;margin:10px 0;box-shadow:0 3px 10px rgba(0,0,0,0.15);">
  <div style="display:flex;align-items:center;gap:14px;">
    <span style="font-size:2em;">[ICON]</span>
    <div>
      <div style="color:rgba(255,255,255,0.8);font-size:0.7em;font-weight:700;letter-spacing:2px;text-transform:uppercase;">PHASE [NR]</div>
      <div style="color:#fff;font-size:1.25em;font-weight:700;">[PHASENNAME]</div>
      <div style="color:rgba(255,255,255,0.85);font-size:0.82em;margin-top:3px;">&#9203; ca. [ZEIT] Minuten &nbsp;•&nbsp; [SOZIALFORM]</div>
    </div>
  </div>
</div>
```

Farben und Icons frei waehlen, aber pro Kurs konsistent halten.
Empfehlungen (nicht verpflichtend):

| Typ | Farbe | Icon |
|---|---|---|
| Analyse / Recherche | #1565C0 (Blau) | &#128269; |
| Planung / Konzept | #6A1B9A (Lila) | &#128203; |
| Umsetzung / Implementierung | #E65100 (Orange) | &#9881;&#65039; |
| Test / Kontrolle | #2E7D32 (Gruen) | &#9989; |
| Reflexion / Praesentation | #00695C (Teal) | &#128172; |
| Analyse / Problem | #B71C1C (Rot) | &#128270; |
| Dokumentation | #37474F (Grau) | &#128196; |

## Textseite mit Syntax-Highlighting (fuer kurspilot_create_module mit modname="page")

Pseudofeld `"page": {"text": ..., "format": 1, "itemid": 0}` setzen - NICHT das Feld
`"content"` direkt, sonst bleibt der Seiteninhalt leer (ohne das Pseudofeld setzt Moodle
`content` beim Schreiben still auf null).

Nur einbinden wenn die Seite tatsaechlich Code enthaelt:

```html
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/atom-one-dark.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/highlight.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/languages/[SPRACHE].min.js"></script>
<script>document.addEventListener('DOMContentLoaded', function(){ hljs.highlightAll(); });</script>

<div style="font-family:Arial,sans-serif;max-width:900px;margin:0 auto;padding:20px;">
  <h2 style="color:[PHASENFARBE];border-bottom:3px solid [PHASENFARBE];padding-bottom:8px;">[TITEL]</h2>
  <div style="background:[PHASENFARBE_HELL];border-left:4px solid [PHASENFARBE];padding:16px;border-radius:4px;margin-bottom:24px;">
    <strong>Lernziel:</strong> [LERNZIEL AUS DER UNTERRICHTSEINHEIT/DEM UNTERTHEMA]
  </div>

  <h3 style="color:[PHASENFARBE];">[ABSCHNITTSTITEL]</h3>
  <p>[ERKLAERUNGSTEXT]</p>
  <pre><code class="language-[SPRACHE]">// Code hier
  </code></pre>
</div>
```

Verfuegbare Sprachen: cpp, python, javascript, java, bash, ini, json, html, css, sql

Fuer Seiten OHNE Code: highlight.js weglassen, nur den div-Container verwenden.

## Aufgabe (fuer kurspilot_create_module mit modname="assign", Feld "intro")

Grundsatz: Aufgaben enthalten nur die fuer den Arbeitsauftrag noetigen
sichtbaren Elemente. Abgabehinweise, Print-/PDF-Hinweise, Banner oder
Zusatzbuttons nur verwenden, wenn sie im Material stehen, im Plan begruendet
sind oder von der Lehrkraft ausdruecklich freigegeben wurden.

```html
<div style="font-family:Arial,sans-serif;padding:20px;">

  <div style="background:[PHASENFARBE_HELL];border-left:4px solid [PHASENFARBE];padding:16px;border-radius:4px;margin-bottom:24px;">
    <strong>Arbeitsauftrag:</strong> [AUFGABENSTELLUNG AUS DER UNTERRICHTSEINHEIT/DEM UNTERTHEMA]
  </div>

  [AUFGABEN_INHALT]

  [OPTIONALER_ABGABEHINWEIS_NUR_WENN_GEPLANT]

</div>
```

Fuer ausfuellbare Eingabefelder, Checkboxen und Tabellen in Aufgaben siehe
`kurspilot_get_skill("interaktive-elemente")`. Fuer Zeichenaufgaben siehe
`kurspilot_get_skill("zeichen-canvas")`.

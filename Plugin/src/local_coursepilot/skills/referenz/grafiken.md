---
name: grafiken
description: Lies diese Datei, wenn eine Grafik (Schaltplan, Diagramm, Foto, Screenshot) in eine Textseite oder Aufgabe eingebettet werden soll.
---

# Referenz: Grafiken in Textseiten und Aufgaben

Lies diese Datei, wenn eine Grafik (Schaltplan, Diagramm, Foto, Screenshot) in
eine Textseite oder Aufgabe eingebettet werden soll. Fuer die Pflichtpruefung
vor dem Absenden einer SVG-Grafik siehe `coursepilot_get_skill("svg-qualitaetssicherung")`.

Wenn eine Grafik das Verstaendnis foerdert, IMMER direkt als SVG oder base64 einbetten.
NIEMALS externe Bild-URLs verwenden (koennen wegfallen, brauchen Internetzugang).

## Wann eine Grafik sinnvoll ist

- Schaltplaene als Referenz (nicht zum Ausfullen – dafuer Canvas verwenden, siehe `coursepilot_get_skill("zeichen-canvas")`!)
- Hardwareaufbau / Verkabelung
- Architekturdiagramme, Systemuebersichten
- Flussdiagramme, Ablaufplaene
- Protokollablaeufe (z.B. HTTP-Request/Response)
- Netzwerktopologien
- UML-Diagramme als Vorlage/Referenz
- Vergleichende Darstellungen (Soll vs. Ist)
- Pinout-Diagramme fuer Microcontroller

## Methode 1: SVG (bevorzugt)

SVG direkt im HTML einbetten – vektorbasiert, skalierbar, kein Qualitaetsverlust.
Fuer technische Diagramme, Schaltplaene, Flussdiagramme.

Grundstruktur:
```html
<div style="margin:20px 0;text-align:center;">
  <svg viewBox="0 0 [BREITE] [HOEHE]" xmlns="http://www.w3.org/2000/svg"
    style="max-width:100%;height:auto;border:1px solid #e0e0e0;border-radius:8px;background:#fff;">

    <!-- Titel -->
    <text x="[MITTE]" y="24" text-anchor="middle"
      font-family="Arial" font-size="14" font-weight="bold" fill="#333">
      [DIAGRAMMTITEL]
    </text>

    <!-- Inhalt hier -->

  </svg>
  <p style="font-size:0.85em;color:#666;margin-top:6px;font-style:italic;">[BILDUNTERSCHRIFT]</p>
</div>
```

Haeufige SVG-Elemente:

```svg
<!-- Rechteck (z.B. Komponente, Block) -->
<rect x="50" y="50" width="120" height="60" rx="6"
  fill="#E3F2FD" stroke="#1565C0" stroke-width="2"/>
<text x="110" y="85" text-anchor="middle" font-family="Arial" font-size="13" fill="#1565C0">
  ESP32
</text>

<!-- Linie (z.B. Verbindung, Kabel) -->
<line x1="170" y1="80" x2="250" y2="80" stroke="#333" stroke-width="2"/>

<!-- Pfeil (z.B. Datenfluss) -->
<defs>
  <marker id="arrow" markerWidth="10" markerHeight="7" refX="10" refY="3.5" orient="auto">
    <polygon points="0 0, 10 3.5, 0 7" fill="#333"/>
  </marker>
</defs>
<line x1="170" y1="80" x2="248" y2="80" stroke="#333" stroke-width="2" marker-end="url(#arrow)"/>

<!-- Kreis (z.B. Knoten, LED) -->
<circle cx="100" cy="100" r="20" fill="#FFF9C4" stroke="#F57F17" stroke-width="2"/>

<!-- Gestrichelte Linie -->
<line x1="50" y1="50" x2="200" y2="50" stroke="#999" stroke-width="1.5" stroke-dasharray="6,3"/>

<!-- Text mit Hintergrund -->
<rect x="45" y="28" width="70" height="22" rx="3" fill="#1565C0"/>
<text x="80" y="43" text-anchor="middle" font-family="Arial" font-size="11" fill="white">
  Label
</text>
```

Beispiel: Einfacher Schaltplan als SVG-Referenzgrafik

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

    <!-- Widerstand -->
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

    <!-- Beschriftung oben -->
    <text x="250" y="35" text-anchor="middle" font-family="Arial" font-size="13" font-weight="bold" fill="#333">
      LED-Beschaltung ESP32 GPIO2
    </text>

  </svg>
  <p style="font-size:0.85em;color:#666;margin-top:6px;font-style:italic;">
    Abb. 1: Schaltplan der LED-Beschaltung mit 220-Ohm-Vorwiderstand
  </p>
</div>
```

## Methode 2: base64-Bild (fuer komplexe Grafiken)

Wenn eine Grafik zu komplex fuer SVG ist (z.B. Foto, Screenshot, detaillierter Aufbau),
als base64-PNG/JPG einbetten:

```html
<div style="margin:20px 0;text-align:center;">
  <img src="data:image/png;base64,[BASE64_DATEN_HIER]"
    alt="[BESCHREIBUNG]"
    style="max-width:100%;height:auto;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,0.1);">
  <p style="font-size:0.85em;color:#666;margin-top:6px;font-style:italic;">[BILDUNTERSCHRIFT]</p>
</div>
```

Wann base64 statt SVG:
- Fotos oder Screenshots (z.B. PlatformIO-Oberflaeche, realer Hardwareaufbau)
- Sehr komplexe Grafiken mit vielen Details
- Grafiken die bereits als Bilddatei vorliegen

Wann SVG bevorzugen:
- Alle technischen Diagramme (Schaltplaene, UML, Flussdiagramme, Topologien)
- Schaubilder und Infografiken
- Alles was aus einfachen geometrischen Formen besteht

## Pflichtregeln fuer Grafiken

- IMMER eine Bildunterschrift (Abb. X: ...) hinzufuegen
- IMMER alt-Text bei base64-Bildern setzen
- SVG IMMER mit viewBox und style="max-width:100%;height:auto;" damit responsive
- Keine externen URLs (http://... oder https://...) fuer Bilder verwenden
- Farben der Grafik passend zur Phasenfarbe waehlen wenn moeglich
- Grafiken NUR einfuegen wenn sie das Verstaendnis tatsaechlich foerdern

---
name: interaktive-elemente
description: Lies diese Datei, wenn eine Aufgabe Eingabefelder, Checkboxen, Bewertungsskalen oder Tabellen mit Eingabefeldern enthalten soll.
---

# Referenz: Interaktive Elemente in Aufgaben

Lies diese Datei, wenn eine Aufgabe (`coursepilot_create_module` mit
`modname="assign"`) Eingabefelder, Checkboxen, Bewertungsskalen oder Tabellen
mit Eingabefeldern enthalten soll.

## Texteingabe

```html
<!-- Kurze Antwort -->
<input type="text" style="width:90%;padding:6px;border:1px solid #bbb;border-radius:4px;"
  placeholder="[OFFENER HINWEIS WAS EINZUTRAGEN IST – KEINE LOESUNG!]"/>

<!-- Lange Antwort -->
<textarea style="width:100%;border:1px solid #bbb;border-radius:4px;padding:8px;font-family:Arial;font-size:14px;" rows="3"
  placeholder="[OFFENER HINWEIS – z.B. 'Beschreibe in eigenen Worten...' – KEINE LOESUNG!]"></textarea>
```

## Checkbox und Radio

```html
<!-- Abhakbare Checkbox (NIEMALS &#9744; verwenden – das ist statisch!) -->
<input type="checkbox" style="width:20px;height:20px;cursor:pointer;accent-color:#2E7D32;"/>

<!-- Bewertungsskala (pro Zeile eigenen name-Wert!) -->
<input type="radio" name="bewertung_zeile1" value="1"/> 1 &nbsp;
<input type="radio" name="bewertung_zeile1" value="2"/> 2 &nbsp;
<input type="radio" name="bewertung_zeile1" value="3"/> 3
```

## PFLICHTREGELN fuer Placeholder-Texte

**FALSCH – verrät die Lösung:**
```html
placeholder="T = 0,2 Sekunden"
placeholder="delay = 100ms"
placeholder="board = esp32dev"
placeholder="z.B. GET"
placeholder="z.B. arduino"
```

**RICHTIG – gibt nur Hinweis auf Format/Denkrichtung:**
```html
placeholder="Berechne T aus der Frequenz..."
placeholder="T/2 ergibt den delay-Wert"
placeholder="Welches Board wird verwendet?"
placeholder="Welche HTTP-Methode liest Daten?"
placeholder="Welches Framework nutzt PlatformIO?"
```

**GOLDENE REGEL fuer Placeholders:**
Ein Placeholder darf NIEMALS die gesuchte Antwort enthalten oder direkt darauf hinweisen.
Er darf nur sagen WAS einzutragen ist, nicht WAS die Antwort ist.
Bei Zweifeln: lieber generisch ("Deine Antwort...") als zu konkret.

## Tabellen mit Eingabefeldern

```html
<!-- RICHTIG: Nur Anker vorgeben, Inhalte durch SuS erarbeiten -->
<table style="width:100%;border-collapse:collapse;margin-bottom:20px;">
  <thead style="background:[PHASENFARBE];color:white;">
    <tr>
      <th style="padding:10px;">[BEKANNTE SPALTE]</th>
      <th style="padding:10px;">[ZU ERARBEITENDE SPALTE 1]</th>
      <th style="padding:10px;">[ZU ERARBEITENDE SPALTE 2]</th>
    </tr>
  </thead>
  <tbody>
    <tr>
      <td style="padding:10px;border:1px solid #ddd;">[VORGEGEBENER WERT]</td>
      <td style="padding:10px;border:1px solid #ddd;">
        <input type="text" style="width:90%;padding:4px;border:1px solid #bbb;border-radius:4px;"
          placeholder="[HINWEIS WAS ZU BERECHNEN/RECHERCHIEREN IST]"/>
      </td>
      <td style="padding:10px;border:1px solid #ddd;">
        <input type="text" style="width:90%;padding:4px;border:1px solid #bbb;border-radius:4px;"
          placeholder="[HINWEIS WAS ZU BERECHNEN/RECHERCHIEREN IST]"/>
      </td>
    </tr>
  </tbody>
</table>
```

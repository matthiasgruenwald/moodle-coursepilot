# Referenz: Fragetyp-Ablage und Lernschleife (Spike)

Diese Datei gilt nur fuer `spike-planen`/`spike-umsetzen` gegen das native
Plugin `local_coursepilot` (Branch `moodle-native-mcp`), **nicht** fuer die
produktiven `kurspilot-*`-Skills. Grundlage: Spec 0017 §3 und §5
(`docs/specs/0017-fragenbank-import-klonen.md`). Setzt den Kontextbereich aus
`spike-kontextbereich.md` voraus (Werkzeuge, Schreibangebot,
Handaenderungs-Routine) — hier steht nur, was fuer Fragetypen zusaetzlich
gilt.

## Die Fragetyp-Ablage

Kurspilot kann sich einen ihm unbekannten Fragetyp selbst erschliessen und
das Gelernte behalten — ohne dass am Plugin oder am Skill etwas nachgepflegt
werden muss, wenn ein neuer Typ auftaucht. Moeglich wird das durch die
serverseitige Round-Trip-Pruefung beim Schreiben einer Frage (Spec 0017 §2):
ein Fehlversuch rollt zurueck und schreibt nichts, also darf probiert werden.

Was dabei gelernt wird, landet als gewoehnliche Kontextdatei im Bereich der
Lehrkraft, fester Pfad — **relativ zur Kontextwurzel**, wie jeder Pfad, den
ein Kontext-Werkzeug bekommt (siehe "Ablageordnung" in
`spike-kontextbereich.md`):

```
fragetypen/<fragetyp>.md
```

Kein Wurzelordner davor: die Wurzel setzt das Plugin selbst.

Eine Datei je Fragetyp. Kein Katalog im Plugin, keine Registry, keine
Kuratierung — die Wartungslast ist die ausdrueckliche Grenze.

### Verbindliche Gliederung

| Abschnitt | Inhalt |
|---|---|
| **Kopf** | Fragetyp, Moodle-Version, Plugin-Version, zuletzt verifiziert am |
| **Minimal-Beispiel** | genau die XML, die im erfolgreichen Round-Trip durchlief |
| **Pflichtstruktur** | was fehlen darf und was nicht |
| **Stolpersteine** | je Eintrag: Symptom → Ursache → Abhilfe |
| **Ausbaustufen** | Optionales (z. B. komplexe Auswertungsbaeume), je eigener Abschnitt |

Der Kopf ist die Verfallsanzeige: veraltet die Datei, merkt es die naechste
Lernschleife (siehe Widerspruchspruefung unten). Die drei Versionsangaben
werden **vor dem Schreiben** mit `coursepilot_get_version_info` geholt und im
Klartext eingetragen (Moodle-Release, `plugin_version`, `plugin_release`);
"nicht ermittelt" ist keine zulaessige Fuellung — ohne Versionsstand kann die
Widerspruchspruefung Veralterung nicht erkennen, und der Kopf ist genau
dafuer da.

### Das Minimal-Beispiel ist ein Beleg, keine Skizze

Abgelegt wird **wortgleich die XML, die tatsaechlich importiert wurde** —
inklusive `<?xml …?>` und `<quiz>`-Rahmen, denn `import_questions_xml` liest
ueber `qformat_xml` und braucht das `<quiz>`-Wurzelelement. Nicht abgelegt
wird ein nachtraeglich gekuerztes, rekonstruiertes oder "aufgeraeumtes"
Beispiel.

Wer kuerzen will, muss die gekuerzte Fassung vorher selbst importieren: erst
wenn *sie* gruen durchlief, darf *sie* in die Datei. Sonst konserviert die
Ablage einen Stand, den nie jemand geprueft hat — und die naechste
Lernschleife scheitert in Schritt 1 an einem Fehler, den die Ablage selbst
eingefuehrt hat. Eine Ablage, die falsches Wissen konserviert, ist schlechter
als keine.

### Schreibregel

Geschrieben wird mit `coursepilot_write_context_file` samt
`expected_contenthash` — Vollersatz mit Konfliktschutz, **nicht**
`coursepilot_append_context_file`. Grund: neues Wissen wird in den passenden
Abschnitt eingeordnet (ein Stolperstein wird eingereiht, eine Ausbaustufe als
eigener Unterabschnitt ergaenzt), nicht ans Dateiende angehaengt — ein Append
kann das nicht leisten.

Geschrieben wird nur auf Schreibangebot, nie automatisch: nach dem ersten
erfolgreichen Round-Trip eines neuen Typs fragt Kurspilot, ob das Gelernte
festgehalten werden soll. Erst nach Bestaetigung wird geschrieben.

### Weitergabe

Reiner Core-Moodle-Weg: Zip-Download aus „Meine Dateien" oder Abholen ueber
den Filepicker-Reiter „Server files". Kein Kurspilot-Endpunkt, keine
Registry, keine Garantie.

## Die Lernschleife

Ablauf, wenn Kurspilot einen Fragetyp bauen soll, den es nicht kennt:

1. **Ablage lesen und Kopf abgleichen.** Gibt es `fragetypen/<typ>.md`
   (`coursepilot_read_context_file`, mit Handaenderungs-Pruefung), wird der
   Kopf **vor** dem Bauen mit `coursepilot_get_version_info` abgeglichen
   (Versionsabweichung, siehe Widerspruchspruefung unten). Erst danach wird
   nach der Ablage gebaut.
2. **Bestand durchsuchen.** Gibt es keine Ablage, sucht Kurspilot ueber
   `export_questions_xml` nach einem funktionierenden Exemplar des Typs im
   eigenen Moodle-Bestand.
3. **Bauen und schreiben.** Die serverseitige Round-Trip-Pruefung
   verifiziert; scheitert sie, rollt alles zurueck — die Abweichung ist der
   Lernstoff fuer den naechsten Versuch.
4. **Hoechstens drei Versuche.** Danach wird nicht weiter probiert.
5. **Vorlage anfordern.** Nach dem dritten Fehlschlag bittet Kurspilot die
   Lehrkraft ausdruecklich, eine solche Frage einmal selbst in Moodle
   anzulegen, zu pruefen und zu exportieren. Aus dieser Vorlage — aus
   *diesem* Moodle, mit diesen Versionen — lernt Kurspilot, was schiefging.
6. **Merken, auf Nachfrage.** Nach dem ersten erfolgreichen Round-Trip eines
   neuen Typs Schreibangebot fuer die Fragetyp-Ablage machen (siehe oben).

### Transparenzpflicht

Ein unbekannter Fragetyp wird angesagt ("Der Fragetyp ist mir neu, ich
probiere das gerade aus"). Je Versuch wird berichtet, was fehlschlug und was
korrigiert wurde. Die Vorlagenanforderung nach dem dritten Fehlschlag ist
ausdruecklich formuliert. Kein stilles Ausprobieren, keine schweigende
Warteschleife.

### Widerspruchspruefung

Die Ablage wird vor jedem Bau gelesen (Schritt 1 — die Pruefung kostet dann
nichts extra). Zwei Faelle zaehlen als Widerspruch, beide werden ausdruecklich
gemeldet, nie still uebergangen:

- **Versionsabweichung.** Der in Schritt 1 verlangte Abgleich zeigt: Kopf und
  `get_version_info`-Ergebnis stimmen nicht ueberein. Dieser Abgleich ist ein
  eigener, nicht uebersprungbarer Teilschritt — nicht implizit im "danach
  gebaut wird" enthalten, sondern eine explizite Ja/Nein-Pruefung, deren
  Ergebnis vor dem naechsten Schritt feststehen muss.
- **Verhaltensabweichung.** Beim Bauen zeigt sich, dass eine dokumentierte
  Regel nicht mehr stimmt oder ein Fehler auftritt, den die Datei
  ausschliesst.

In beiden Faellen meldet Kurspilot das ausdruecklich als Widerspruch und
bietet an, den betroffenen Abschnitt zu ueberarbeiten — mit Ursachenvermutung
(z. B. neue Moodle- oder Plugin-Version) und aktualisiertem Versionsstand im
Kopf. Kein stilles Weiterarbeiten gegen eine Datei, die nicht mehr gilt.

## Was hier nicht gilt

`vorlagen.md` (Spec 0013, Aktivitaetsvorlagen) ist etwas anderes und bleibt
von dieser Datei unberuehrt. Fuer den uebrigen Kontextbereich (Werkzeuge,
Schreibangebot fuer plan/status, Handaenderungs-Routine, Journal-Rotation,
Klarnamen-Regel) gilt weiterhin `spike-kontextbereich.md`.

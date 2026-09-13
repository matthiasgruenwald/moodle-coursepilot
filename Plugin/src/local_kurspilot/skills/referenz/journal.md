---
name: journal
description: Lies diese Datei, wenn eine dokumentationswuerdige Entscheidung festgehalten werden soll oder eine Sitzung mit "Setze meine Planung für ... fort" weiterarbeitet.
---

# Referenz: Journal und Weiterarbeiten

Lies diese Datei, wenn eine dokumentationswuerdige Entscheidung festgehalten
werden soll oder eine Sitzung mit "Setze meine Planung für ... fort"
weiterarbeitet.

Das **Journal** (siehe CONTEXT.md) hält Planungen, Freigaben,
Moodle-Aenderungen und Kontextaenderungen in datierten, nie ueberschriebenen
Markdown-Dateien im Kontextbereich fest – als Gedaechtnis ohne Git. Gelesen
und geschrieben wird ausschliesslich über die Werkzeuge aus
`kurspilot_get_skill("kontextbereich")`.

## Dokumentationsroutine waehrend der Arbeit

Wie beim `grill-with-docs`-Skill werden geklaerte Begriffe und Entscheidungen
nicht erst am Sitzungsende gesammelt, sondern sofort dokumentiert, sobald sie
für spaetere Unterrichtsplanung wiederverwendbar sind. Der Chatverlauf ist
kein verlaessliches Gedaechtnis.

Als dokumentationswuerdig gelten insbesondere:

- Lerngruppenentscheidungen: Leistungsstand, Gruppendynamik,
  Differenzierungsbedarf, Sprachstand, technische Rahmenbedingungen,
  besondere Beobachtungen zur Klasse oder Teilgruppe.
- Fach- und Unterrichtsentscheidungen: Kompetenzstand, fachliche
  Schwerpunkte, Materialauswahl, Materialumbenennungen, OCR-/Bildentscheidungen,
  Testmodus, Bestehensgrenzen, Lernpfad-Gates, vertagte Materialluecken.
- Moodle-Planungsentscheidungen: Abschnittsentscheidung, Phasenmodell,
  Planabweichungen, Freigabe-Voraussetzungen, digitale Abgaben,
  bewusst verworfene Alternativen.
- Kontextentscheidungen: welche Klasse, Teilgruppe, Fachprofil oder welcher
  Unterrichtsordner für eine Planung gilt.

Vorgehen:

1. Sobald eine solche Entscheidung geklaert ist, den passenden Speicherort
   bestimmen (siehe Journal-Ablage unten).
2. Fehlt der noetige Kontext, nicht still ohne Gedaechtnis weiterarbeiten:
   kurz den **Pflichtkontext** klaeren und ein niedrigschwelliges
   **Erklaerendes Setup** mit Vorschau anbieten (siehe
   `kurspilot_get_skill("kontext-onboarding")`). Nach Bestätigung werden die
   passenden `CONTEXT.md`-Dateien angelegt und die Notiz direkt ins Journal
   geschrieben.
3. Die Notiz als eigenen Journal-Eintrag per `kurspilot_append_context_file`
   an die passende Journal-Datei anhaengen (siehe Journal-Ablage unten für
   den Scope: Lerngruppe -> Klassenjournal; Unterricht, Material, Test,
   Moodle-Planung -> Unterrichtsordner-Journal; Kontext je nach vorhandener
   Fachzuordnung). Bestehende Journal- oder Kontextdateien werden nie direkt
   überschrieben.
4. Wenn die Entscheidung einen kanonischen Produkt-/Domainbegriff für
   Kurspilot selbst klaert, stattdessen oder zusätzlich `CONTEXT.md` im Repo
   aktualisieren. ADRs nur sparsam nutzen, wenn die Entscheidung schwer
   rueckgaengig, ohne Kontext ueberraschend und das Ergebnis eines echten
   Trade-offs ist.

Einträge knapp, aber später nutzbar formulieren: Was wurde entschieden,
warum, für welche Lerngruppe oder welches Unterthema, und was bleibt offen?

## Journal-Ablage

Die Journal-Datei des Tages (`journal-YYYY-MM-DD.md`) liegt relativ zur
Kontextwurzel (siehe "Ablageordnung" in `kurspilot_get_skill("kontextbereich")`):

| scope | Ablage (relativ zur Kontextwurzel) |
|---|---|
| `'klasse'` | `<schuljahr>/<klasse>/journal-<datum>.md` – allgemeine Lerngruppenentwicklung (faecheruebergreifend) |
| `'unterrichtsordner'` | `<schuljahr>/<klasse>/<unterrichtsordner>/journal-<datum>.md` – fachliche Planung, Moodle-Umsetzung, Material, Testfragen |

Die **Journal-Ablage** folgt automatisch dem Kontextort der Aenderung. Nur bei
echter Mehrdeutigkeit (z.B. unklar, ob eine Notiz die ganze Klasse oder nur
ein Fach betrifft) kurz nachfragen – sonst automatisch entscheiden. Ein
Schuljahresjournal ist kein Standard.

## Wann entstehen Journal-Einträge?

Journal-Einträge entstehen waehrend des gesamten Workflows, nicht nur nach
Moodle-Schreibzugriff:

- direkt nach jeder dokumentationswuerdigen Lerngruppen-, Fach-, Material-,
  Test- oder Moodle-Planungsentscheidung (siehe Dokumentationsroutine oben),
- nach Kontext-Onboarding oder bewusster Ergaenzung eines Profils,
- nach Material-Ingestion, Umbenennung, OCR-Kontrolle oder Bildausschnitt,
- nach jedem freigegebenen und ausgefuehrten Implementierungsplan.

Nach jedem freigegebenen und ausgefuehrten Implementierungsplan (siehe
`kurspilot_get_skill("implementierungsplan-workflow")`) wird automatisch ein
**Umsetzungsbericht** als neuer Journal-Eintrag angehängt:

1. Der Bericht wird als Markdown mit den Abschnitten "Erfolge", "Fehler" und
   "Offene Nacharbeit" formatiert. Erfolge nennen Aktivitätstyp und
   Aktivitaetsname zuerst; Moodle-IDs/Links stehen nur als technische
   Referenz dahinter. Interne Tool- oder MCP-Korrekturen gehoeren nicht in
   den Bericht, solange sie keine Auswirkung auf Ergebnis, Unsicherheit oder
   offene Nacharbeit haben. Ruecklesechecks werden als fachliche Wirkung
   formuliert, nicht als technische Rohdatenliste: zum Beispiel "Neue
   Textseite ist sichtbar, alter Merkkasten ist verborgen" statt "847
   sichtbar, 362 verborgen".
2. Der Bericht wird per `kurspilot_append_context_file` an die Journal-Datei
   des Tages angehängt. Existiert die Datei noch nicht, wird sie neu
   angelegt. Bestehende Einträge werden **nie** überschrieben, auch nicht
   bei mehreren Einträgen am selben Tag.

Auch ausserhalb von Umsetzungsberichten gilt: jede Journal-Notiz läuft über
`kurspilot_append_context_file`, nie durch direktes Ueberschreiben der Datei.

## Weiterarbeiten-Routine (Sitzungsstart)

Bei natuerlichen Startformulierungen wie "Setze meine Planung für 7a Nawi
fort" oder "Wo standen wir bei 7a?":

1. Offene `ausstände` aus `kurspilot_list_skills` melden (siehe
   `kurspilot_get_skill("kontextbereich")`) — vor und getrennt von der
   Offenen Nacharbeit unten, kein gemeinsamer Absatz.
2. Passenden Kontext laden (Lerngruppenprofil/Fachprofil, siehe
   `kurspilot_get_skill("kontext-onboarding")`).
3. Relevante Journal-Dateien sammeln (Klassen- und/oder
   Unterrichtsordner-Journal der letzten Einträge, per
   `kurspilot_read_context_file`).
4. Diese Dateien nach Einträgen im Abschnitt "Offene Nacharbeit" durchsuchen.
5. Gefundene Punkte werden der Lehrkraft als **Nacharbeitsvorschlag**
   zusammengefasst angeboten – z.B. "Aus dem letzten Eintrag (2026-06-10) ist
   noch offen: ... Soll das jetzt angegangen werden?"

**Wichtig:** Die Weiterarbeiten-Routine arbeitet offene Punkte NICHT automatisch
ab. Sie macht nur einen Vorschlag; die Lehrkraft entscheidet, ob und womit
weitergearbeitet wird.

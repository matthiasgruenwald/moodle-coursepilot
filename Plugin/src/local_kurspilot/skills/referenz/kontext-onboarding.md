---
name: kontext-onboarding
description: Lies diese Datei beim bewusst gestarteten Einrichten des Kurspilot-Kontexts oder wenn eine Startformulierung mehrdeutig ist und geklaert werden muss, welche Klasse/welches Fach gemeint ist.
---

# Referenz: Kontext-Onboarding

Lies diese Datei beim bewusst gestarteten Einrichten des Kurspilot-Kontexts
(Referenzteil des Einstiegs-Skills `kurspilot`) oder wenn eine
Startformulierung mehrdeutig ist und geklaert werden muss, welche
Klasse/welches Fach gemeint ist.

Bevor eine Lernsituation in Moodle aufgebaut wird, kann passender **Kurskontext**
aus dem Kontextbereich genutzt werden (Lerngruppenprofil + Fachprofil). Diese
Dateien duerfen echte Schuelernamen enthalten (siehe
`docs/adr/0003-allow-local-student-names-in-teacher-context.md`) und werden
ausschliesslich ueber die Werkzeuge aus
`kurspilot_get_skill("kontextbereich")` gelesen und geschrieben.
Vor Planung, Umsetzung oder anderen Schreibschritten liest Kurspilot zuerst
den bestehenden Kontext in der vereinbarten Reihenfolge.

## Kurze Kontextklaerung bei Mehrdeutigkeit

Wenn eine Startformulierung mehrere Klassen, Faecher oder Themen meinen koennte,
stellt Kurspilot eine kurze Rueckfrage mit wenigen passenden Kandidaten – statt
den falschen Kontext stillschweigend anzunehmen oder lange Rueckfragen zu stellen.

Beispiel:
> **Lehrkraft:** "Mach mit Bio weiter."
> **Kurspilot:** "Ich habe zwei offene Planungen fuer Bio gefunden: 7a
> (Photosynthese) und 7c (Zellaufbau). Welche meinst du?"

## Wann startet das Setup?

Nur als bewusst gestartete **Setup-Option** – nicht automatisch. Typische
Ausloeser sind natuerliche Formulierungen wie:

- "Richte den Kontext fuer 7a Nawi ein"
- "Lege ein Lerngruppenprofil fuer die 7a an"
- "Setup fuer meine Klasse/Lerngruppe"

Existiert `<schuljahr>/<klasse>/CONTEXT.md` im Kontextbereich bereits
(`kurspilot_list_context_files`), das Setup nicht erneut anbieten, sondern
auf den vorhandenen Kontext hinweisen.

## Pflichtkontext (immer abfragen)

Nur diese drei Angaben sind zwingend:

1. **Schuljahr** (z.B. `2025-26`)
2. **Klasse oder Lerngruppe** (z.B. `7a`; bei geteilten/gemischten Gruppen ein
   eigener Lerngruppenname als **eigenstaendige Teilgruppe**, z.B.
   `7a-e-kurs-nawi` – liegt als eigener Ordner direkt unter dem Schuljahr,
   NICHT verschachtelt unter `7a`)
3. **Fach/Unterrichtsordner** (z.B. `naturwissenschaften`) – nur wenn ein
   Fachprofil angelegt werden soll

Erlaubt sind Buchstaben, Ziffern, `-` und `_`. Keine Pfadtrenner oder `..`.

## Ablage der Kontextdateien

Alle Pfade sind relativ zur Kontextwurzel (siehe "Ablageordnung" in
`kurspilot_get_skill("kontextbereich")`):

| Datei | Ablage |
|---|---|
| Lerngruppenprofil | `<schuljahr>/<klasse>/CONTEXT.md` |
| Fachprofil | `<schuljahr>/<klasse>/<fach>/CONTEXT.md` |

Teilgruppen (z.B. `7a-e-kurs-nawi`) sind eigene `<klasse>`-Werte und liegen
dadurch automatisch als eigenstaendiger Ordner direkt unter dem Schuljahr.

## Setup-Ablauf (Erklaerendes Setup)

Sechs nummerierte Schritte, jeder mit einem pruefbaren Abschlusskriterium.
Einrichten ist erst fertig, wenn Schritt 6 sein Abschlusskriterium erfuellt –
also wenn die Setup-Abschlussweiche angeboten wurde.

### Schritt 1: Pflichtkontext erfragen

Schuljahr, Klasse/Lerngruppe und ggf. Fach/Unterrichtsordner abfragen (siehe
Pflichtkontext oben).

**Abschlusskriterium:** Alle noetigen Pflichtangaben (mindestens Schuljahr und
Klasse/Lerngruppe) liegen vor.

### Schritt 2: Anlage erklaeren

Kurz erklaeren, was angelegt wird und warum (z.B. "Ich lege
`2025-26/7a/CONTEXT.md` in deinem Kontextbereich an – das Lerngruppenprofil
haelt faecheruebergreifende Infos zur Klasse fest.").

**Abschlusskriterium:** Die Lehrkraft kennt Zielort und Zweck der anzulegenden
Datei(en), bevor Inhalte erfragt werden.

### Schritt 3: Optionalen Planungskontext anbieten

Anbieten, nicht erzwingen: Leistungsstand, besondere Lernbedarfe,
Gruppendynamik, Sprachstand, technische Rahmenbedingungen
(Lerngruppenprofil) bzw. Kompetenzstand, Arbeitsweisen, laufende Themen,
Teststand (Fachprofil). Bei "spaeter"/"weiss ich noch nicht" einfach leer
lassen (Platzhalter `_(noch nicht erfasst)_` bleibt stehen).

**Abschlusskriterium:** Jedes optionale Feld wurde entweder befuellt oder
bewusst mit Platzhalter uebersprungen – keine stillschweigend ausgelassene
Frage.

### Schritt 4: Verwandten Kontext abfragen

Nur als leichte Referenz abfragen (z.B. "Ist das eine Teilgruppe einer
Stammklasse, oder gibt es eine verwandte Lerngruppe?"). Es wird nur ein
Verweistext gespeichert – KEINE automatische Uebernahme von Inhalten aus dem
verwandten Profil.

**Abschlusskriterium:** Die Frage nach verwandtem Kontext wurde gestellt und
beantwortet oder ausdruecklich uebersprungen.

### Schritt 5: Vorschau zeigen und nach Bestaetigung anlegen

Vorschau der zu erstellenden CONTEXT.md(s) zeigen, dann erst auf Bestaetigung
per `kurspilot_write_context_file` anlegen. Bestehende Dateien werden nicht
ueberschrieben. Ohne bestaetigte Vorschau wird keine Datei angelegt.

**Abschlusskriterium:** Die Lehrkraft hat die Vorschau bestaetigt, und die
Datei(en) existieren danach exakt wie in der Vorschau gezeigt (oder das
Anlegen wurde mangels Bestaetigung bewusst nicht ausgefuehrt).

### Schritt 6: Setup-Abschlussweiche anbieten

Kurz anbieten, wie es weitergeht: jetzt planen (`kurspilot-planen`), einen
bereits freigegebenen Plan umsetzen (`kurspilot-umsetzen`) oder spaeter
weiterarbeiten.

**Abschlusskriterium:** Einrichten ist fertig, wenn die Setup-Abschlussweiche
angeboten wurde – unabhaengig davon, welche Option die Lehrkraft waehlt.

## Frontmatter und Index (OKF, Spezifikation 0010/0011)

Jede angelegte `CONTEXT.md` (Lerngruppenprofil, Fachprofil, Unterrichtsvorhaben)
bekommt beim Anlegen das begrenzte YAML-Frontmatter aus Spezifikation 0010
(`type`, `title`, `tags`, `status`, `created`, `updated`, `about`,
`gradeLevel`, `kurspilot.personenbezug`, `kurspilot.weitergabe`), von
Kurspilot selbst formuliert und im Vorschauschritt gezeigt. Kurspilot erfindet
keine eigene Frontmatter-Syntax im Chat.

Beim Anlegen eines Unterrichtsvorhabens traegt Kurspilot den Vorhabenordner
automatisch best-effort in `index.md` an der Kontextwurzel ein (Fach,
Jahrgangsstufe, Tags, Kurzbeschreibung, Status). Ist `index.md` nicht lesbar
oder widerspruechlich (kaputte/doppelte Marker), warnt Kurspilot sichtbar und
laesst die Datei unveraendert statt sie zu ueberschreiben; das Anlegen des
Vorhabens selbst wird dadurch nicht blockiert.

Personenbezogene Beobachtungen (z.B. zu einzelnen Schuelerinnen und Schuelern)
gehoeren nicht in die teilbare Sachdatei, sondern in ein eigenes Sidecar
(`CONTEXT.personen.md`), per `kurspilot_write_context_file` angelegt. Ein
Sidecar traegt immer `kurspilot.personenbezug: true` (siehe Klarnamen-Regel in
`kurspilot_get_skill("kontextbereich")`) und wird von der Sachdatei aus
sichtbar verlinkt.

## Vorlagen-Ablage für Klon-Quellen (KP-010)

Häufig genutzte Klon-Quellen für `kurspilot_clone_activity` (Issue #328,
Spezifikation 0013) können Lehrkräfte in einer einfachen Textdatei
`vorlagen.md` an der Kontextwurzel festhalten (Geschwisterebene zu den
Schuljahresordnern). Keine Registry im Plugin, keine Datenbank — eine
„Vorlage" ist eine normale Aktivität im Kurs, adressiert per `cmid`.

### Format

Freie Markdown-Liste, ein Eintrag pro Punkt. Empfohlene Eintragsstruktur:

- Aktivitätstyp
- Kursname + Kurs-ID
- `cmid`
- kurze Beschreibung, was die Aktivität besonders macht
- optional: Verweis auf ergänzende Unterlagen

Beispiel:

```markdown
- **Aufgabe** – Bio 7a (Kurs-ID 42), cmid 318: Dateiabgabe mit
  Rubrik-Bewertung und Peer-Feedback-Fenster. Vorlage für alle
  Präsentationsabgaben.
```

### Wann liest der Agent die Datei?

Nur bei einem der drei Trigger, nicht präventiv bei jeder Sitzung:

1. Die Lehrkraft verlangt eine Einstellung, die MCP nicht setzen kann (z.B.
   eine Plugin-Konfiguration eines Abgabetyps).
2. Sie verweist auf eine frühere Lösung ("wie bei der letzten Aufgabe", "so
   wie im Bio-Kurs").
3. Unmittelbar vor einem `kurspilot_clone_activity`-Aufruf, wenn keine `cmid`
   genannt wurde.

### Schreiben nur nach Bestätigung

Anlegen und Pflegen der Datei obliegt der Lehrkraft. Kurspilot kann nach
einem erfolgreichen Klon einen Eintrag vorschlagen, schreibt `vorlagen.md`
aber nie still — nur nach ausdrücklicher Bestätigung durch die Lehrkraft
(`kurspilot_write_context_file`), analog zur Vorschau/Bestätigung-Regel bei
Kontextprofilen (Schritt 5 oben).

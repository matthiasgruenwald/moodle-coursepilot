# Spec 0026 — Erschlossene Aktivitätsarten anlegen

*Ergebnis des Grillings zu [#585](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/585) (01.10.2026). Entscheidung: ADR 0028.*

> **Umgesetzt wird gegen die Issues, nicht gegen dieses Dokument.** Die Issues tragen die
> verbindliche Form — User Stories und Abnahmekriterien als Haken. Dieses Dokument
> beantwortet das *Warum* und ist Nachschlagewerk, keine zweite Anforderungsquelle.

## Problem Statement

Coursepilot schreibt nur Aktivitätsarten mit geprüftem Feldkatalog. Will eine Lehrkraft ein
Buch, eine Checkliste oder ein Glossar, muss sie es selbst anlegen oder über den
Moodle-Import gehen. Für jede dieser Arten einen Feldkatalog zu pflegen, lohnt sich nicht.

## Solution

Coursepilot erschließt sich solche Arten selbst, wie bei der Fragetyp-Ablage: Die KI baut
eine **Aktivitäts-XML** (`<mod>.xml`), das Plugin ergänzt das Backup-Gerüst, legt an und
prüft per Round-Trip. Was dabei gelernt wird, steht in der **Aktivitätsart-Ablage**
(`aktivitaetsarten/<modname>.md`) im Kontextbereich der Lehrkraft.

Begriffe: `CONTEXT.md` — Katalogisierte/Erschlossene Aktivitätsart, Aktivitätsart-Ablage,
Anlegen aus XML, Aktivitäts-XML, Ablösen.

## Implementation Decisions

- **Drei Werkzeuge** (englisch, ADR 0024):
  - `export_activity_backup(cmid)` — liefert die Aktivitäts-XML einer bestehenden
    Aktivität. Dient dem Bestand („wie ist das gebaut?“) und der Round-Trip-Prüfung.
  - `export_default_activity(courseid, modname)` — legt in einer Transaktion eine Aktivität
    mit Moodle-Standardwerten an (Formular-Vorbelegung → `add_moduleinfo`), exportiert sie
    und rollt zurück. Es bleibt nichts im Kurs. Ersetzt in der Lernschleife den Schritt
    „Lehrkraft legt selbst an und exportiert“.
  - `create_activity_from_xml(courseid, modname, section, activity_xml, hidden?)` — legt an.
    Optional `replaces_cmid` fürs Ablösen (siehe unten).
- **Zulässige Arten:** jede installierte Art ohne Feldkatalog. Abgelehnt mit Klartext:
  katalogisierte Arten (Verweis auf `create_module`), lesson, quiz, Arten mit Dateien im
  Inhalt. Keine Positivliste.
- **Ablauf beim Anlegen:** XML parsen (vorher, Restore ist nicht atomar) → Gerüst bauen →
  Restore versteckt → exportieren → Eingabe ⊆ Ausgabe prüfen (ignoriert: ids, `time*`,
  contextid, Datei-Verweise) → bei Abweichung im selben Aufruf löschen und die Abweichung
  melden → sonst sichtbar schalten, außer `hidden` → ersten Stand im Änderungsverlauf
  schreiben (wie `clone_activity`, Quelle „aus XML angelegt“).
- **Antwort:** cmid, Abweichungen bzw. Moodle-Vorbelegungen als Hinweis (Felder, die Moodle
  ergänzt hat, ohne dass sie in der Eingabe standen).
- **Ablösen** (`replaces_cmid`): neue Aktivität direkt hinter der alten, alte nur verstecken
  (Titel bleibt), Verlauf der alten cmid „abgelöst durch cmid X“. Die Planvorschau nennt
  Verweise auf die alte cmid (Voraussetzungen, Textlinks soweit lesbar); aufgelöst werden
  sie nicht.
- **Nutzerdaten-Inhalte** gehen nicht mit. Ein Glossar wird leer angelegt; das steht als
  Stolperstein in der Ablage.
- **Rechte:** Restore- bzw. Backup-Capabilities im Kurs, praktisch `editingteacher`.
  Je Werkzeug eine Deklaration (Spec 0022), danach `upgrade.php`.
- **Skill:** `Plugin/src/local_coursepilot/skills/referenz/aktivitaetsarten.md` nach dem
  Vorbild `referenz/fragetypen.md`:
  Ablage-Gliederung, Lernschleife (Ablage lesen → Bestand oder `export_default_activity` →
  bauen → Round-Trip → höchstens dreimal korrigieren), Schreibangebot fürs Gelernte. Zur
  Lehrkraft heißt der Vorgang nur „anlegen“; erschlossene Arten heißen nie „unterstützt“.

## Testing Decisions

- **PHPUnit im Spike-Container** je Werkzeug: Anlegen mit gültiger XML, Fehlanlage wird
  gelöscht und gemeldet, katalogisierte Art abgelehnt, `hidden` wirkt, Verlauf hat genau
  einen Stand, `export_default_activity` hinterlässt nichts.
- **Abnahme an book, checklist, glossary** auf der Spike-Instanz, jeweils mit verifiziertem
  Minimal-Beispiel in der Ablage.

## Out of Scope

- Bearbeiten über XML (ADR 0016, 0028).
- „Ersetzen“ (Überschreiben mit Sicherung und Verweisauflösung).
- Nutzerdaten-Inhalte, etwa Glossar-Einträge — eigenes Ticket.
- Arten mit Dateien im Inhalt sowie lesson und quiz.

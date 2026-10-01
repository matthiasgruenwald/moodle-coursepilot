# ADR 0028 — Aktivitäts-XML als Anlegeweg für erschlossene Aktivitätsarten

Status: angenommen (01.10.2026)

## Context

Coursepilot schreibt Aktivitäten über den Formularweg und einen geprüften Feldkatalog je
Aktivitätsart (ADR 0016, ADR 0017). Das deckt neun Arten ab. Lehrkräfte wollen weitere Arten
nutzen (etwa book, checklist, glossary), ohne den Moodle-Import selbst zu bedienen. Einen
Feldkatalog für jede installierte Art zu pflegen, ist nicht leistbar.

ADR 0016 hat Backup/Restore als **Schreibweg** verworfen, weil Restore ausnahmslos neu
anlegt und damit nichts ändern kann. Das Anlegen war nie verworfen. Der Prototyp
`prototype/mbz-restore` (Issue #585) hat gezeigt:

- Eine von der KI gebaute `<mod>.xml` lässt sich wiederherstellen. Das Gerüst des Backups
  (16 Dateien) erzeugt das Plugin.
- Restore ist nicht atomar und schluckt Feldfehler still.
- Nutzerdaten-Inhalte (z. B. Glossar-Einträge) gehen über diesen Weg nicht.
- Eine Aktivität jeder Art lässt sich mit Moodle-Standardwerten anlegen, ohne Code je Art.

## Decision

**Aktivitäts-XML ist ein zugelassener Anlegeweg, nie ein Bearbeitungsweg.** Er gilt nur für
*erschlossene* Aktivitätsarten (ohne Feldkatalog). Für *katalogisierte* Arten bleibt er
gesperrt; dort gilt weiter ADR 0016.

- **Keine Positivliste.** Zugelassen ist jede installierte Art ohne Feldkatalog, außer Arten
  mit Fragen (lesson, quiz) und Arten mit Dateien im Inhalt.
- **Round-Trip-Prüfung statt Zusage.** Nach dem Anlegen exportiert das Plugin die Aktivität
  und prüft Eingabe ⊆ Ausgabe. Ids, `time*`, contextid und Datei-Verweise bleiben
  unberücksichtigt. Moodle-Vorbelegungen gehen als Hinweis zurück.
- **Fehlanlagen werden gelöscht.** Das Plugin legt intern versteckt an. Besteht die Prüfung
  nicht, löscht es die Aktivität im selben Aufruf. Besteht sie, schaltet es sichtbar, außer
  die Lehrkraft verlangt ausdrücklich „versteckt“. Das Verbot, zu löschen, schützt nur den
  Bestand der Lehrkraft, nicht eine eigene, nie sichtbare Fehlanlage.
- **Ablösen statt Ändern.** Eine Aktivität mit Nutzerdaten wird nicht überschrieben. Die neue
  kommt direkt hinter die alte, die alte wird nur versteckt. Überschreiben mit Sicherung
  („Ersetzen“) ist eine spätere Entscheidung.
- **Änderungsverlauf.** Restore löst kein `course_module_created` aus. Das Plugin schreibt den
  ersten Stand selbst, wie `clone_activity` (ADR 0018). Beim Ablösen vermerkt der Verlauf der
  alten Kursmodul-ID „abgelöst durch cmid X“.
- **Wissen bei der Lehrkraft.** Was über eine Art gelernt wird, steht in der
  Aktivitätsart-Ablage im Kontextbereich, nicht im Plugin.

## Considered Options

- **Feldkatalog für jede gewünschte Art:** verworfen. Jede Art kostet Katalog, Driftprüfung
  und Pflege je Moodle-Version (ADR 0017), auch für Arten, die nur wenige Lehrkräfte nutzen.
- **Lehrkraft importiert selbst über Moodle:** verworfen. Genau diesen Umweg soll Coursepilot
  ersparen.
- **Backup-XML auch zum Bearbeiten:** bleibt verworfen (ADR 0016). Restore legt immer neu an.

## Consequences

- Coursepilot kann Arten anlegen, für die es nichts zusagt. Die Grenze „unterstützt“ bleibt
  der Feldkatalog. Erschlossene Arten heißen nie „unterstützt“.
- Neue Werkzeuge: `create_activity_from_xml`, `export_activity_backup`,
  `export_default_activity`. Sie brauchen die Restore- bzw. Backup-Capabilities, praktisch
  also `editingteacher`.
- Der Formularweg bleibt für katalogisierte Arten unverändert. Der Verlauf kennt einen
  weiteren Schreibweg und schreibt dort ausdrücklich mit.
- Ergänzt ADR 0016, ohne sie aufzuheben.

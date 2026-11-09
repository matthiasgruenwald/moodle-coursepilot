# ADR 0032 — Vorab-Lese-Ausfall vor dem Schreibversuch abfangen

Status: angenommen (nachträglich dokumentiert, Fix aus #505 Befund 10,
Commit `b987322`). Aus dem Worktree `t3/context-request` übernommen am 09.11.2026;
die Verweise auf die Implementierung entsprechen dem heutigen Deepening-Stand.

## Kontext

Vor dem Schreiben in den Kontextbereich muss die Personenbezugs-Vorprüfung den
bisherigen Inhalt kennen. Ist eine vorhandene Datei personenbezogen markiert,
verhindert die Schutzregel ein ungeprüftes Überschreiben.

Schlägt das Vorab-Lesen fehl, darf der Schreibpfad nicht einfach fortfahren.
Ein nur beim GET auftretender Ausfall würde sonst einen später erfolgreichen PUT
auf eine ungeprüfte Datei ermöglichen.

## Entscheidung

Ein Vorab-Lese-Ausfall wird vor dem echten Schreibversuch abgefangen. Er läuft
über denselben Übersetzungsweg wie ein Ausfall beim eigentlichen Schreiben und
erzeugt einen Ausstand-Eintrag. Die Schutzprüfung darf nicht übersprungen werden.

Heute liest `storage_write::read_target()` über den Ablage-Port. Die Fehlerpfade
rufen `pointer_writer::record_preread_failure()`, `record_location_failure()` oder
`record_storage_failure()` auf und brechen vor `storage_write::persist()` ab.
`pointer_writer` stellt die Operationskonstanten für die Fehlerübersetzung bereit.

## Konsequenzen

- Der Test `test_peek_read_failure_aborts_the_write_instead_of_bypassing_the_lock`
  in `write_context_file_test.php` schützt diese Entscheidung. Er wies die erste
  naive Fix-Fassung zurück.
- Vor einem Deploy ist die volle PHPUnit-Suite aus `docs/agents/testing.md`
  erforderlich; neue Einzeltests allein sichern die bestehenden Pfade nicht.
- Jeder neue Schreibpfad behandelt einen Lesefehler vor dem Schreiben als
  Abbruch mit Ausstand, niemals als Aufforderung zum ungeprüften Weiterschreiben.

Verwandt: `CODING_STANDARDS.md` S1 (Fail loud; Fallback nur per ADR), ADR 0023.

# ADR 0032 — Vorab-Lese-Ausfall vor dem Schreibversuch abfangen

Status: angenommen (nachträglich dokumentiert, Fix aus #505 Befund 10, Commit `b987322`).

## Kontext

Beim Schreiben in den Kontextbereich (`write_context_file`, `append_context_file`)
prüft die Personenbezugs-Vorprüfung vorab die Zieldatei über
`pointer_reader::peek_external_content()`. Ist die Datei bereits als personenbezogen
markiert, wird der Schreibvorgang gesperrt (#515).

Schlägt dieses Vorab-Lesen fehl (abgelehnte Anmeldung, Netzfehler), wäre die naheliegende
Reparatur, den Fehler zu ignorieren und zum eigentlichen Schreibversuch durchzureichen.
Das umgeht die Sperre: Trifft der Ausfall nur das Vorab-GET, nicht aber den anschließenden
PUT bzw. das Read-modify-write, wird eine personenbezogen markierte Datei ungeprüft
überschrieben.

## Entscheidung

Ein Vorab-Lese-Ausfall wird **vor** dem echten Schreibversuch abgefangen, nie
durchgereicht. Er läuft über denselben Übersetzungsweg wie ein Ausfall beim echten
Schreiben:

- `pointer_writer::record_preread_failure()` erzeugt den Ausstand-Eintrag mit Kennung,
- die dafür nötigen `OP_*`-Konstanten sind öffentlich (`OP_UNKNOWN`, `OP_APPEND`, …),
- die Sperre bleibt erhalten.

Umgesetzt in `classes/context_area.php` (Aufrufe von `record_preread_failure()`) und
`classes/pointer_writer.php`.

## Konsequenzen

- Der Test `test_peek_read_failure_aborts_the_write_instead_of_bypassing_the_lock`
  (`write_context_file_test.php`) schützt die Entscheidung. Er hat die naive erste
  Fassung des Fixes in der Suite zurückgewiesen. Vor jedem Deploy deshalb die **volle**
  PHPUnit-Suite laufen lassen (`docs/agents/testing.md`), nicht nur die neuen Tests.
- Die Regel gilt für jeden neuen Schreibpfad: Ein Lesefehler vor dem Schreiben ist ein
  Abbruch mit Ausstand, kein Fallback.

Verwandt: C5 in `CODING_STANDARDS.md` (Fail loud; Fallback nur per ADR).

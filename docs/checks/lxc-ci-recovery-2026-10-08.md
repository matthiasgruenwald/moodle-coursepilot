# LXC- und CI-Prüfung vom 08.10.2026

## Beobachtungen

- Root-Dateisystem: 44 GB, zunächst 95 % belegt, 2,5 GB frei.
- Tatsächliches LXC: 10 GB RAM, 6 GB Swap; zunächst etwa 5 GB Swap belegt.
  Die isolierte Shell zeigte abweichende Hostwerte, daher erfolgte die
  maßgebliche Prüfung außerhalb der Sandbox.
- Hohe Last ging mit starkem Speicher- und I/O-Druck einher. Die CPU war
  bei Stichproben überwiegend frei; reine CPU-Überlastung war nicht belegt.
- Der cgroup-Zähler zeigte elf OOM-Abschüsse; während der Prüfung stieg er
  nicht weiter. Der Zähler allein datiert die Abschüsse nicht.
- `/tmp` war ein tmpfs mit 7,4 GB Belegung. Mehrere alte synthetische
  Testumgebungen und die Kompatibilitätsmatrix lagen dort.

## Durchgeführte Maßnahmen

- Neun ungenutzte Container der aktuellen Kompatibilitäts-/Releaseprüfung
  reversibel gestoppt; laufende Moodle-Instanzen nicht gestoppt.
- Nach ausdrücklicher Freigabe alte Journaldateien bereinigt: 3 GB frei,
  Journal anschließend 955 MB, Root-Dateisystem 88 % / 5,5 GB frei.
- Dauerhaftes Journal-Limit: `SystemMaxUse=1G` in
  `/etc/systemd/journald.conf.d/60-coursepilot-size.conf`.
- Während der Unterbrechung wurde der LXC neu gestartet. `/tmp` und damit
  uncommittete Arbeit, Testdaten und Volltestprotokolle waren danach weg.
  Die Ursache des Neustarts wurde hier nicht festgestellt.
- Worktree auf persistentem Dateisystem wiederhergestellt. Neue isolierte
  Diagnoseinstanz: Datenbank 512 MB, PHP 1 GB, je eine CPU; keine
  veröffentlichten Ports und ausschließlich synthetische Daten.

## CI-Korrekturen

PostgreSQL-Vertragstests für Rollbacks liefen innerhalb von Moodles zusätzlicher
PHPUnit-Transaktion. Delegierte Transaktionen rollen erst an der äußersten
Grenze zurück. `preventResetByRollback()` lässt die betroffenen Tests die
Transaktionsgrenzen eines echten Werkzeugaufrufs prüfen. Vier gezielte Tests
bestanden mit 19 Assertions; die Backup-Klasse bestand mit 17 Assertions und
zwei datenbankspezifischen Skips. Vor dem Neustart zeigte der volle Lauf weitere
Fehler in der Testreihenfolge; die Einzelprüfungen ersetzen deshalb nicht das
vollständige CI-Gate.

Rekonstruierter Stand: Commit `82191b3`, PR #688. Native JS-Suite: 39 Tests grün.
Die vollständige lokale Suite auf Moodle 5.2.4 / PHP 8.4 / PostgreSQL 17
besteht nach der zusätzlichen Cleanup-Testkorrektur: 1.548 Tests,
130.731 Assertions, keine Fehler/Failures, zwei Notices und 30 Skips.
Der Restore-Test mit zwei Korrektoren besteht separat mit 14 Assertions.
Die GitHub-Matrix weist auf `82191b3` bereits beide neueren Moodle-Linien
mit MariaDB sowie alle drei frischen ZIP-Installationen nach. Die dortigen
PostgreSQL-Folgefehler beginnen an exakt der zusätzlich korrigierten
Cleanup-Testgrenze. Die vollständige Matrix bleibt maßgeblich für eine
Kompatibilitätszusage.

Die zwei Notices wurden auf veraltete `course_delete_module()`-Aufrufe in
Test-Fixtures eingegrenzt. Die Fixtures verwenden jetzt wie der Produktcode
`course_get_format(...)->delete_module(...)`. Die beiden gemeldeten Fälle
bestehen gezielt ohne Notices (zwei Tests, fünf Assertions); die weiteren
beiden MariaDB-Trigger-Fixtures werden durch die Pflichtmatrix geprüft.

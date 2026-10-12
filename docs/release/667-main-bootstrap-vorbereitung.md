# Main-Gate-Bootstrap: Vorbereitung für #667

Gemessen am 11.10.2026, ausschließlich im isolierten Gate-Container. Release-Aktion
und verbindliche Main-CI-Abnahme bleiben beim Maintainer in
[#715](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/715).

## Tatsächlich gemessener Stand

- Plugin: `main@0f05fe6da8b8de47add061bf35f6bf45f5869ee2`, per Git-Archiv extrahiert;
  keine Änderung an `main`, kein neuer Worktree, keine Übernahme der dev-Coverage.
- Moodle: 5.1.7+ (Build 20260928), Core `71a4dca64fa9863751c379eabd344f09e0e8d15b`.
- PHP 8.4.25, PCOV 1.0.12, MariaDB 11.4.12, PHPUnit 11.5.55.
- Vollsuite des tatsächlichen Main-Quellstands: Exit 0, 1558 Tests,
  130959 Assertions, 14 bestehende bedingte Skips; 15:39 Minuten.
- Coverage: **81,26 % (10999/13536)**, 184 Dateien, 47 unter 50 %;
  1104 Methoden, 90 über CRAP 8, 7 über CRAP 30.
- Ausschlussumfang: unveränderte begründete Datentabellen aus `baseline.json`
  (`lang/`, `db/access.php`, `db/events.php`, `db/services.php`, `db/tasks.php`,
  `version.php`). Einstiegsskripte und Upgrade bleiben im Nenner. Kein Umbau aus #668.

Der Main-Nenner ist nicht der dev-Nenner. Der Vorschlag für eine initiale Main-Baseline
enthält die **gemessenen** 10999/13536 und als historischen Messpunkt diesen Main-Commit.
Er liegt nur als Prüfartefakt vor; die versionierte dev-Baseline und ihr `armedAt`
wurden nicht verändert.

## Passende Statikmessung

PHPStan 2.3.1, Level 6, mit derselben Gate-Konfiguration und **ohne** bestehende
PHPStan-Baseline: Exit 1, vollständiges JSON mit 1009 Dateibefunden, 0 internen Fehlern.
Die Baseline-Erzeugung endet mit Exit 0 und 1006 baseline-fähigen Befunden. Drei
`return.missing`-Befunde lassen sich nicht baselinen:

- `glossary_entry_writer::add()` (Main-Zeile 40),
- `history/version_writer::capture_on_update()` (Main-Zeile 102),
- `history/version_writer::capture()` (Main-Zeile 135).

Die generierte Datei ist rohe Messausgabe, keine genehmigte Ausnahme. Gründe,
Quellbelege und Auflösungstickets sind vor Veröffentlichung einzeln zu prüfen.
Die drei nicht baseline-fähigen Befunde verhindern eine grüne Bootstrap-Abnahme
dieses Main-Standes; sie dürfen nicht durch einen Waiver verschwinden.

deptrac 2.0.5: Exit 0, 0 neue Verstöße, 22 gemeldete bestehende Aufrufe
(12 unterschiedliche Quelle→Ziel-Paare). `debug:unassigned`: Exit 0,
keine unzugeordneten Klassen. Die gemessenen Paare sind als Kandidat gesichert.

## Artefakte und offene Abnahme

Lokale, nicht ausgelieferte Nachweise für die unabhängige Prüfung:

- `/tmp/coursepilot-667-main-measure.log` und `.exit`,
  `/tmp/coursepilot-667-main-clover.xml`, `/tmp/coursepilot-667-main-report.json`.
- `/tmp/coursepilot-667-main-baseline-candidate.json`.
- `/tmp/coursepilot-667-main-phpstan.json`, `.stderr`, `.exit` und
  `/tmp/coursepilot-667-main-phpstan-baseline.neon`; Generator-Exit in
  `/tmp/coursepilot-667-main-phpstan-baseline.exit`.
- `/tmp/coursepilot-667-main-deptrac.json`, `.stderr`, `.exit`,
  `/tmp/coursepilot-667-main-deptrac-baseline-candidate.yaml` und
  `/tmp/coursepilot-667-main-deptrac-unassigned.log`/`.exit`.
- Serieller Statikprozess: `/tmp/coursepilot-667-main-static.log`/`.exit`.

Damit ist die Vorbereitungsmessung vollständig, **nicht die Main-Gate-Abnahme**.
Auf `main` fehlen weiterhin die versionierten Gate-Baselines; ein Vergleich dagegen
bleibt bewusst rot. #715 muss den tatsächlichen Release-Kandidaten neu messen,
Statikbefunde beheben bzw. begründete Baselines prüfen und den Main-Rollout samt
echter grüner CI und normalem Hotfix-Vergleich abnehmen. Ändert sich der Kandidat,
sind diese Messwerte keine Ersatzmessung. Kein automatischer Merge durch #667.

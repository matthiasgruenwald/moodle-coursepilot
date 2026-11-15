# Brief: überlebende Mutanten töten

Ziel: offene Infection-Mutanten (Überlebende) einer Produktionsdatei töten, also mit Tests, die den Mutanten
erkennen, oder mit einem begründeten Ignore-Eintrag, wenn der Mutant nachweislich äquivalent ist.
Modellwahl und Ablauf: [`models.md`](models.md).

## Vorab

- Lies `CODING_STANDARDS.md` (Test- und Ignore-Regeln, Moodle-Code-Style) und
  `docs/checks/672-infection-equivalence.md` (was als äquivalent gilt).
- Ein Arbeitspaket bekommt einen eigenen Worktree und Branch von `origin/dev`. Andere Worktrees nicht anfassen.
- Produktionscode unter `Plugin/src/local_coursepilot/` bleibt unverändert, außer `tests/`. Ein echter
  Produktionsfehler wird nicht behoben, sondern im Bericht gemeldet.

## Eingabe

Offene Überlebende aus einem Volllauf-Bericht (Infection-Log der Instanz, z. B. `<GATE_DIR>/reports/infection.json`):

```bash
node scripts/gate/mutation-classify.js <GATE_DIR>/reports/infection.json <ziel>.json
```

Die Datei listet je offenem Mutanten `file`, `line`, `mutator`, `hash`. Die Ausgabe zeigt die Anzahl je Datei.

## Ziel je Mutant

1. **Töten:** Test mit echter Assertion schreiben, der mit dem Mutanten rot und ohne ihn grün ist.
   Neue Tests unter `tests/`, Dateiname `<thema>_<issue>_contract_test.php`, Klasse mit `#[CoversClass]`,
   Moodle-Docblocks an jeder Methode, englische Kommentare. Bestehende Tests nicht abschwächen.
2. **Äquivalent:** nur, wenn der Unterschied nachweislich nicht beobachtbar ist, geprüft gegen Quelltext
   UND Moodle-Core. Eintrag nicht direkt in `scripts/gate/mutation-ignore.json`, sondern in eine Datei
   `docs/checks/<thema>-ignores.json` (Format wie der Ignore-Eintrag unten). Der Reviewer übernimmt ihn.
   Kein Sammel-Ignore ohne Einzelprüfung. Kein Ignore, nur weil ein Test schwierig ist.
3. **Fehler-Mutanten** (Status `error`): zuerst die Ursache klären (Timeout, Fatal), dann wie oben.

## Reihenfolge

Erst `npm run gate -- fast` grün, dann `verify`, dann committen; nach jeder weiteren Änderung `verify` wiederholen.

## Messen

Eigene Instanz verwenden, nie die Haupt-Instanz `/opt/kurspilot-gate` (Gate-Lock, siehe
[`testing.md`](testing.md), Abschnitt Lange Läufe). Instanz anlegen und messen:

```bash
bash scripts/gate/mutation-full-instance.sh up <id>
FULL_MUTATION_INSTANCE=<id> GATE_DIR=/opt/kurspilot-gate-<id> GATE_CONTAINER=kurspilot-gate-<id>-webserver-1 \
  node scripts/gate/mutation-full.js verify <classes/datei.php> '<phpunit-filter-regex>'
```

- Filter: Regex der Testklassen und -methoden, die die Datei prüfen (bestehende und neue), z. B.
  `location_selection|mein_neuer_test`.
- Ausgabe: `total`, `killed`, `escaped`, `uncovered`, `open` und die Liste offener Mutanten. Ziel: `open=0`.
- Dauer: Minuten je Datei. Läuft der Befehl länger als 120 s, abgekoppelt starten und die Ausgabe in eine
  Datei schreiben (siehe [`testing.md`](testing.md), Lange Läufe).
- Einzelne Tests schneller: `GATE_DIR=<instanz> GATE_REPO=<worktree> bash scripts/gate/sync-plugin.sh`, danach
  PHPUnit im Container `kurspilot-gate-<id>-webserver-1` mit `--filter`.
- PHP gibt es nur im Container, nicht auf dem Host.

## Ignore-Regeln

Format wie in `scripts/gate/mutation-ignore.json`: `file`, `line`, `mutator`, `hash`, `reason`.

- `reason` auf Englisch, konkret, mindestens 40 Zeichen. Er nennt die Datei mit Zeilenangabe
  (`klasse.php:123`) und die Begründung, warum kein Test den Unterschied sehen kann. Bei Äquivalenz steht
  der Wortlaut „Not an equivalence“ oder „Probabilistic equivalence“ mit Begründung.
- Der Reviewer liest zu jedem Ignore die Quelle und prüft den Grund.

## Abschluss

- `npm test` grün; Moodle-Code-Style der neuen Testdateien (4 Leerzeichen, Docblocks, keine anonymen Klassen in
  Datenprovidern).
- Bericht knapp auf Deutsch: je Datei Mutanten vorher und nachher, getötet und ignoriert, Pfade der neuen
  Testdateien und der Ignore-Datei, Auffälligkeiten (Produktionsfehler!).
- Briefe verweisen mit „lies CODING_STANDARDS.md“, nie mit Regelnummern; der Reviewer prüft gegen den Standard selbst.

# Gate-Container und Gate-Kommando

Stand: Spec 0029, Tracer Bullet. Das Gate **misst nur**: Es zeigt
Coverage und CRAP, blockiert aber noch nichts (Ratsche und Schwellen folgen in
den nächsten Tickets, ADR 0029).

## Gate-Container

Dauerhafter Docker-Stack `kurspilot-gate`, getrennt von Spike, Devstack-Instanzen
und `coursepilot-afk-*`:

| Teil | Wert |
|---|---|
| Moodle | 5.1 (`origin/MOODLE_501_STABLE`, fester Worktree-Stand) |
| PHP | 8.4 mit pcov (Coverage-Treiber, nur per `-d pcov.enabled=1` aktiv) |
| Datenbank | eigene MariaDB 11.4, Datenbank `moodle_gate`, PHPUnit-Präfix `t_` |
| Ablage | `/opt/kurspilot-gate` (nicht `/tmp`) |

Einrichtung, wiederholbar (jeder Schritt überspringt, was schon steht):

```bash
bash scripts/gate/setup-container.sh
```

Das Skript legt `/opt/kurspilot-gate` an, erzeugt das DB-Passwort einmalig in
`/opt/kurspilot-gate/.env` (0600, wird nie ausgegeben), legt Moodle 5.1 als
Worktree aus `/opt/moodle` an, schreibt `config.php`, installiert die
Core-Abhängigkeiten inkl. PHPUnit per `composer:2` (Wegwerf-Container), startet
den Stack und initialisiert die PHPUnit-Umgebung. Abweichungen über
`GATE_DIR`, `GATE_MOODLE_REPO`, `GATE_MOODLE_REF`.

Nach einem Moodle-Upgrade des Worktrees die Initialisierung neu auslösen:
`rm /opt/kurspilot-gate/phpunitdata/phpunit/.gate-initialised` und das Skript
erneut starten. Komplett neu: `docker compose -f scripts/gate/compose.yml down`,
`git -C /opt/moodle worktree remove --force /opt/kurspilot-gate/moodle`,
`/opt/kurspilot-gate` löschen, Skript starten.

## Gate-Kommando

Node-Skript ohne Laufzeit-Abhängigkeiten, ruft PHP per `docker exec` im
Container auf:

```bash
npm run gate -- fast               # Node-Tests + PHPUnit der Tests zu geänderten Klassen
npm run gate -- full               # volle Suite mit pcov, danach Bericht (rund 15 Minuten)
npm run gate -- report <clover.xml> # nur Bericht aus vorhandenem Clover-Bericht
```

`full` spiegelt `Plugin/src/local_coursepilot` in den Container, baut die
PHPUnit-Konfiguration, schreibt den Clover-Bericht nach
`/opt/kurspilot-gate/reports/clover.xml` und wertet ihn aus. Der volle Lauf
braucht einen abgekoppelten Prozess, siehe [`agents/testing.md`](agents/testing.md).

`fast` ordnet geänderte Dateien (`git diff HEAD` plus neue Dateien) Tests zu:
Produktionsklasse `classes/…/foo.php` gehört zu Tests mit
`#[CoversClass(…foo::class)]`, eine geänderte `*_test.php` steht für sich.
Ohne Zuordnung meldet das Gate `no-test-mapped`.

### Ausgabe

Eine Zeile pro Befund, `datei:zeile: regel: text`, zuletzt `summary:`:

```text
Plugin/src/local_coursepilot/classes/x.php:0: coverage-file: 40.0% (4/10) unter 90%
Plugin/src/local_coursepilot/classes/x.php:30: crap-method: hard crap=462 complexity=21
summary: coverage=82.3% lines=… files=… files_below_50=… methods=… crap_over_8=… crap_over_30=…
```

Regeln heute nur Messwerte: `coverage-file` (Datei unter 90 %), `crap-method`
(CRAP über 8), `no-test-mapped`, `gate-error`. Ein fehlender, leerer oder
ungültiger Clover-Bericht, 0 ausführbare Zeilen, Methoden ohne CRAP-Wert,
fehlgeschlagene Tests oder ein nicht laufender Container enden mit Exitcode 1,
nie grün. Tests: `test/gate.test.js` mit Fixtures unter `test/fixtures/gate/`.

## Messwerte im Vergleich zur Spec-Baseline

Erster `full`-Lauf im Gate-Container (Moodle 5.1.7+, PHP 8.4, pcov), 1548 Tests,
rund 15 Minuten:

| Wert | Spec 0029 (CI, Moodle 5.0, dev @ 05fb815) | Gate-Container (5.1) |
|---|---|---|
| Line-Coverage | 82,3 % | 82,33 % (11967/14536) |
| Dateien unter 50 % | 45 von 183 | 50 von 190 |
| Methoden | 1088 | 1101 |
| CRAP über 8 / über 30 | 88 / 5 | 90 / 7 |

Die Gesamt-Coverage stimmt überein. Die übrigen Zahlen liegen leicht höher,
weil der Gate-Stand seit `05fb815` gewachsen ist (+695 Zeilen Plugin-Code,
u. a. `glossary_entry_writer`, `add_glossary_entries`,
`activity_file_supplement`, `activity_files/lightboxgallery`) und auf 5.1
statt 5.0 gemessen wird; neue Dateien und Methoden erhöhen Nenner und Zähler.

## Dev-Werkzeuge

`composer.json` und `composer.lock` im Repo-Root (PHPStan, deptrac, Infection)
sind dev-only. Das Release-ZIP entsteht ausschließlich aus
`Plugin/src/local_coursepilot/`; `test/native-release-artifact.test.js` prüft,
dass kein Dev-Tool darin landet. `vendor/` ist gitignoriert.

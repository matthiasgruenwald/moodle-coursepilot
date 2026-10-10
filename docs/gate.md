# Gate-Container und Gate-Kommando

Stand: Spec 0029, Gate scharf. Das Gate misst Coverage und CRAP, führt die
statischen Prüfungen aus und **blockiert** nach der Ratschenregel aus ADR 0029
(Abschnitt [Ratsche](#ratsche)). Git-Hooks und Edit-Hooks rufen dasselbe
Kommando auf (Abschnitt [Hooks](#hooks)).

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
npm run gate -- ratchet [clover.xml] # Ratsche; ohne Clover nur die Baselines
npm run gate -- baseline [clover.xml] # Coverage-Baseline anheben (nie senken)
npm run gate -- edit               # Edit-Hook: Hook-JSON von stdin, ruft fast
npm run gate -- static             # alle statischen Prüfungen (rund 1 Minute)
npm run gate -- phpstan-baseline   # PHPStan-Baseline neu erzeugen
npm run gate -- ranking [n]        # Fehlschlags-Rangliste nach Datei und Prüfung
```

`full` spiegelt `Plugin/src/local_coursepilot` in den Container, baut die
PHPUnit-Konfiguration, schreibt den Clover-Bericht nach
`/opt/kurspilot-gate/reports/clover.xml` und wertet ihn aus. Der volle Lauf
braucht einen abgekoppelten Prozess, siehe [`agents/testing.md`](agents/testing.md).

`fast` führt zusätzlich die Baseline-Ratsche aus (ohne Messwert). `full` wertet nach der
Suite die Ratsche mit dem Clover-Bericht aus. Exitcode 1 bei jedem blockierenden Befund.

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

Messwerte im Bericht (blockieren nicht, die Ratsche blockiert nur Geändertes):
`coverage-file` (Datei unter 90 %), `crap-method` (CRAP über 8), `no-test-mapped`
(Hinweis). `gate-error` ist immer rot. Ein fehlender, leerer oder
ungültiger Clover-Bericht, 0 ausführbare Zeilen, Methoden ohne CRAP-Wert,
fehlgeschlagene Tests oder ein nicht laufender Container enden mit Exitcode 1,
nie grün. Tests: `test/gate.test.js` mit Fixtures unter `test/fixtures/gate/`.

## Fehlschlagslog und Rangliste

Jeder Lauf hängt pro Prüfung Zeilen an `.gate-failures.log` im Repo-Wurzelverzeichnis
(gitignoriert, überschreibbar über `GATE_FAILURE_LOG`). Format, tab-getrennt:
`Datum` (ISO), `Prüfung` (bei Befunden die Regel), `Datei` (`-` ohne Dateibezug), `Ergebnis`
(`ok`, `fail`, `abort`). Eine Prüfung schreibt sofort nach ihrem Ende; wirft sie oder
endet ein Werkzeug ohne auswertbaren Befund, steht `abort`, und frühere Prüfungen des
Laufs bleiben im Log. `npm run gate -- ranking` zeigt die Fehlschläge (`fail`, `abort`)
als Rangliste nach Datei und nach Prüfung. Das ist Trend, keine Schwelle: Eine Datei
oben in der Liste ist Kandidat für größeres Aufräumen (ADR 0029).

## Statische Prüfungen

Laufen im Gate-Container. **Jeder Befund blockiert**, ausgenommen `deptrac-baselined`
(bekannter Verstoß aus der deptrac-Baseline, nur Bericht). Der Bestand ist ohne Befund
(PHPStan über seine Baseline). Ein Werkzeug, das ohne auswertbaren Befund mit Fehlercode endet, ist
rot (`gate-error`). `fast` führt (jetzt immer mit laufendem Container) moodle-cs, phpdoc, PHPStan, die Covers-Prüfung, die Kommentarsprache und die Kontext-/Capability-Prüfung externer Funktionen aus,
`full` und `static` zusätzlich savepoints, Mustache und ESLint.

| Prüfung | Regel im Bericht | Werkzeug |
|---|---|---|
| moodle-cs | `moodle-cs-error`, `moodle-cs-warning` | `moodle-plugin-ci phpcs` (TODO-Kommentare nur mit Link `https://github.com/matthiasgruenwald/moodle-coursepilot/issues/<nr>`; ohne den Sniff `moodle.Files.BoilerplateComment`, weil er die GPL-Kopfzeile verlangt und das Projekt nach ADR 0025 unter AGPL steht) |
| phpdoc | `phpdoc` | `moodle-plugin-ci phpdoc` |
| savepoints | `savepoints` | `moodle-plugin-ci savepoints` |
| Mustache | `mustache` | `moodle-plugin-ci mustache` (vnu-jar, Java 11) |
| ESLint, AMD-Build | `eslint`, `grunt-stale` | `moodle-plugin-ci grunt` |
| PHPStan Level 6 | `phpstan` | `scripts/gate/phpstan/phpstan.neon` mit Baseline |
| Kommentarsprache | `english-comment`, `english-ignore-invalid` | Node (`scripts/gate/english.js`): Umlaut/ß oder deutsches Funktionswort in einem PHP-Kommentar oder Docblock; ausgenommen `lang/de/`, `tests/fixtures/`, String-Literale. Fehlalarme nur in `scripts/gate/english-ignore.json` (`file`, `match`, `reason`; ohne Begründung rot) |
| Externe Funktionen | `external-check-missing`, `external-ignore-invalid` (auch für veraltete Ausnahmen) | Node (`scripts/gate/capability.js`): jede Klasse in `classes/external/` ruft in `execute()` (auch über Methoden derselben Klasse, die es per `self::`/`static::`/`$this->` aufruft) `validate_context()` und `require_capability()`/`has_capability()` auf, oder einen Resolver aus `scripts/gate/external-resolvers.json` (feste, versionierte Liste; `call` als `klasse::methode`, `validates`/`requires`, `reason`). Ausnahmen nur in `scripts/gate/external-ignore.json` (`file`, `missing` = `context` oder `capability`, `reason`; ohne Begründung rot). Ausgabe: Klasse und fehlender Aufruf |
| Covers-Pflicht | `covers-missing` | Node, listet nicht abstrakte `*_test`-Klassen ohne `#[Covers…]` |
| Schichtregeln | `deptrac-violation`, `deptrac-unassigned`, `deptrac-baseline-stale`, `deptrac-baseline-ticket` (blockieren), `deptrac-baselined` (nur Bericht) | deptrac, siehe Abschnitt unten (`scripts/gate/deptrac.js`) |

**Schichtregeln (ADR 0030)** prüft deptrac mit `scripts/gate/deptrac/deptrac.yaml`. Die Datei
enthält die Klassenliste für den Wurzel-Namespace und die Regeln: Einstieg darf alles nutzen,
Werkzeuge nur Fachmodule, Ports und Adapter, Fachmodule nie Werkzeuge, Adapter nur Ports. „Ports“ ist der
Ablageort-Kern (Port, Anker, Pointer, Zugriffsprotokoll, Ereignisse) und gehört fachlich zu den Fachmodulen;
er ist eine eigene Schicht, damit ein Adapter nur ihn und nicht die übrige Fachlogik nutzen darf. Jedes
Werkzeug ist eine eigene Schicht, weil deptrac Abhängigkeiten innerhalb einer Schicht immer erlaubt und
Werkzeug → Werkzeug sonst unsichtbar bliebe. Das Gate ist rot bei:

- einem Verstoß, der nicht in der Baseline steht (`deptrac-violation`),
- einer Klasse ohne Schichtzuordnung, etwa einer neuen Wurzel-Klasse oder einem neuen Werkzeug, das nicht in der Liste steht (`deptrac-unassigned`),
- einem Baseline-Eintrag, den der Code nicht mehr verletzt (`deptrac-baseline-stale`),
- einem Baseline-Eintrag ohne Ticketnummer (`deptrac-baseline-ticket`).

Neue Wurzel-Klasse oder neues Werkzeug: Eintrag in `deptrac.yaml` ergänzen (Regex der passenden Schicht
bzw. eine neue `Werkzeug_…`-Schicht samt Ruleset-Zeile). Die Baseline `scripts/gate/deptrac/deptrac-baseline.yaml`
ist versioniert und darf nur schrumpfen; jeder Eintrag nennt das auflösende Ticket (#693, #696, #697, #700) und
verschwindet mit dessen Umbau. Bekannte Verstöße erscheinen als `deptrac-baselined` im Bericht. Der Lauf nutzt
die Konfiguration aus `/var/www/deptrac-config` im Container, die `sync-plugin.sh` spiegelt.

deptrac wertet Docblocks nicht aus: Die Analyse läuft über `class`, `use` und `function`, nicht über
Kommentare. Ein `{@see \local_coursepilot\external\…}` erzeugt deshalb keine Kante, ein echter `use`-Import oder
Aufruf eines Werkzeugs aus `catalog/` oder `webdav/` dagegen einen Verstoß. Namespaces des Moodle-Kerns
(`core_external\…`) liegen in keiner Schicht und bleiben ohne Befund.

`full` und `static` schreiben zusätzlich `/opt/kurspilot-gate/reports/gate-static.json`.

`moodle-plugin-ci` (symfony ^5.4) lässt sich nicht im Root-`composer.json` neben
deptrac und Infection auflösen und liegt deshalb als eigenes dev-only Projekt unter
`scripts/gate/plugin-ci/` (mit Lockfile). Das Setup-Skript installiert es zusammen mit
den Root-Werkzeugen, Node 22, den Moodle-npm-Paketen und Java 11 in den Gate-Container.

**PHPStan-Baseline** (`scripts/gate/phpstan/phpstan-baseline.neon`) ist versioniert und darf
nur schrumpfen (ADR 0029). Pfade darin gelten relativ zum Container. Neu erzeugen mit
`npm run gate -- phpstan-baseline`; das Kommando analysiert ohne die bestehende Baseline und
schreibt deshalb immer den vollständigen Bestand. Nicht baseline-fähige Befunde
(`return.missing`) bleiben im Bericht sichtbar. Die Tests unter `tests/` sind im Lauf enthalten.

Jede Befundart (`identifier`), die in der Baseline bleibt, steht mit einer Begründung in
`scripts/gate/phpstan/baseline-reasons.json`; `test/gate-phpstan-baseline.test.js` verlangt, dass
beide Listen übereinstimmen. Eine neue Befundart in der Baseline braucht also eine Begründung,
und eine nicht mehr vorkommende muss aus der Datei verschwinden.

## Ratsche

ADR 0029: Das Gate blockiert, was schlechter wird, und prüft, was geändert wird. Der
Bestand über der Regel blockiert erst, wenn jemand ihn anfasst.

| Regel im Bericht | Blockiert, wenn |
|---|---|
| `ratchet-coverage` | Gesamt-Coverage unter dem Wert in `scripts/gate/baseline.json` (nach Ausschlussliste) |
| `ratchet-baseline-lowered` | `baseline.json` niedriger als im Vergleichsstand |
| `ratchet-file-coverage` | eine geänderte Produktionsdatei unter 90 % Line-Coverage |
| `ratchet-method-crap` | eine geänderte Methode mit CRAP über 8 |
| `ratchet-phpstan-grown` | die PHPStan-Baseline hat je Datei und Befundart mehr Einträge als im Vergleichsstand |
| `ratchet-deptrac-grown` | die deptrac-Baseline hat ein neues Paar Quelle → Ziel gegenüber dem Vergleichsstand |

Dazu kommen die statischen Prüfungen (siehe oben) und die Schichtregeln.

**Geänderte Datei und Methode:** Das Kommando liest `git diff -U0` des Arbeitsbaums gegen den
Vergleichsstand und nimmt neue, unversionierte Dateien komplett als geändert. Eine Methode
gilt als geändert, wenn eine geänderte Zeile zwischen ihrer Startzeile (aus dem Clover-Bericht)
und der letzten Code-Zeile vor der nächsten Methode liegt; Docblock, Attribute und Leerzeilen
der nächsten Methode gehören nicht dazu. Testdateien und ausgeschlossene Dateien zählen nicht.

**Vergleichsstand:** `GATE_BASE_REF`, sonst der jüngere von Merge-Base mit `origin/dev` und
`armedAt` aus der Baseline, sonst `HEAD`. `armedAt` ist der Stand der Messung beim
Scharfschalten; er verhindert, dass das einmalige Aufräumen davor als „geändert“ zählt. Nach dem
Merge nach `dev` ist die Merge-Base jünger und gewinnt. Gibt es die Baseline-Dateien im
Vergleichsstand nicht, entfällt der Vergleich.

**Baseline-Datei** `scripts/gate/baseline.json`: `coverage` (`covered`/`statements`, Wert des
vollen Laufs ohne die ausgeschlossenen Dateien), `excluded` (Pfad relativ zum Plugin, `/` am Ende
= Verzeichnis, je Eintrag `reason`) und `armedAt`. Fortschreiben nur nach oben:

```bash
npm run gate -- full        # Messung
npm run gate -- baseline    # hebt coverage an; verweigert, wenn der Messwert niedriger ist
```

**Ausschlussliste:** Sprachdateien, `db/access.php`, `db/events.php`, `db/services.php`,
`db/tasks.php` und `version.php` sind reine Datentabellen ohne Logik. Unter `#[CoversClass]`
lassen sie sich keiner Klasse zuordnen; sie tauchten nur deshalb in der Messung auf, weil
Testklassen ohne Covers-Angabe alles Ausgeführte gutschrieben. Mit der Covers-Pflicht
(`covers-missing`) fiel die Gutschrift weg: Die Messung sank von 82,33 % auf 75,48 %, davon
1005 Zeilen aus diesen Dateien, der Rest ist Zuwachs an Code. Ohne diese Dateien im Nenner liegt
der Bestand bei 81,08 % (11356/14006); das ist die Baseline. Seiten-Einstiegsskripte
(`mcp.php`, `settings.php` u. a.) stehen noch im Nenner und sind bei Änderung an die 90 %-Regel
gebunden, bis ihre Logik in Klassen liegt.

## Hooks

Versionierte Hooks unter `scripts/githooks/`, aktiviert über `core.hooksPath`:

```bash
npm run hooks:install
```

| Hook | Ruft auf | Dauer |
|---|---|---|
| `pre-commit` | `gate fast` (Node-Tests, PHPUnit zu geänderten Klassen, statische Prüfungen, Baseline-Ratsche) | unter einer Minute plus zugeordnete PHPUnit-Tests |
| `pre-push` | `gate full` (volle Suite, Coverage- und CRAP-Ratsche, alle statischen Prüfungen) | rund 15 Minuten |
| Edit-Hook Claude/Codex | `gate edit`, startet `fast` bei Änderung an Plugin-PHP, `test/**/*.js` oder `scripts/gate/**` | wie `fast` |

Der Edit-Hook (`.claude/settings.json`, `.codex/hooks.json`) schreibt Befunde auf stderr und
endet mit Exitcode 2, damit der Agent sie als Rückmeldung bekommt und weiterarbeitet. Ein
stiller `php -l`-Hook entfällt, `fast` enthält die Syntaxprüfung über moodle-cs und PHPStan.
Hooks lassen sich mit `git commit --no-verify` übergehen; verbindlich bleibt CI.
Ohne laufenden Gate-Container ist `fast` rot (`gate-error`).

## Statische Prüfungen ohne Befund

moodle-cs (Errors und Warnings), phpdoc, savepoints, Mustache, ESLint, AMD-Build, PHPStan nach
Baseline und die Covers-Pflicht melden null. Wo eine Regel nicht zum Projekt passt, steht die
Ausnahme versioniert und begründet im Code oder im Gate, nie stillschweigend:

- **Dateikopf:** moodle-cs verlangt die GPL-Boilerplate, das Projekt steht nach ADR 0025 unter
  AGPL-3.0-or-later. Der Sniff `moodle.Files.BoilerplateComment` ist deshalb in
  `scripts/gate/static.js` ausgenommen; `phpcbf` darf die Köpfe nie umschreiben (ohne
  `--exclude=moodle.Files.BoilerplateComment` würde es sie auf GPL setzen).
- **Zeilenkommentare:** `// phpcs:ignore <Sniff> -- <Grund>` bzw. `phpcs:disable` mit Grund, nur dort, wo
  der Sniff das Muster nicht kennt (Entry-Shims, Kindprozess-Fixtures, abstrakte Testbasis,
  Heredoc-Fixtures, Markdown-Zitate in Strings).
- **Typen in Docblocks:** `moodle-plugin-ci phpdoc` vergleicht den `@param`-Typ mit der Signatur und
  moodle-cs verbietet `@phpstan-param`. Array-Parameter sind deshalb `mixed[]` oder `T[]`, die genaue
  Form steht als `Type: …` in der Beschreibung; was sich so nicht ausdrücken lässt (nullable Arrays,
  Test-Doubles), bleibt als `missingType.iterableValue` in der PHPStan-Baseline.

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

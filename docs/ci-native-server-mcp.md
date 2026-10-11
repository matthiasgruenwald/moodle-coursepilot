# CI: nativer Server-MCP (Issue #268)

Reproduzierbare GitHub-Actions-Pflichtprüfung für `Plugin/src/local_coursepilot`
(nativer Server-MCP, `local_coursepilot`). Läuft auf jedem sauberen
GitHub-Actions-Runner — kein Zugriff auf die Spike-Instanz, keine
Produktivdaten, keine persönlichen Verbindungen nötig.

Workflow: [`.github/workflows/native-ci.yml`](../.github/workflows/native-ci.yml).

## Stabiler Gesamtcheck

Der einzige als Merge-Bedingung zu verwendende Check heißt **`Gate (required)`**
(Job `gate`). Er aggregiert `quality`, `phpunit`, `js-native` und `artifact` über
`needs.*.result` und schlägt explizit fehl, wenn einer dieser Jobs
fehlschlägt **oder übersprungen wird** — ein übersprungener Pflichtjob zählt
nicht als Erfolg. Matrix-Job-Namen (z. B. „PHPUnit (MOODLE_501_STABLE)“)
dürfen sich ändern, ohne dass die Branch-Protection-Regel neu eingerichtet
werden muss, weil diese ausschließlich auf `Gate (required)` zeigt.

## Supportmatrix

| Moodle | PHP | Datenbank | Pflichtprüfung |
|---|---|---|---|
| 5.1 (`MOODLE_501_STABLE`) | 8.4 | MariaDB 11.4 | `full`-Gate mit Coverage-/CRAP-Ratsche |
| 5.2 (`MOODLE_502_STABLE`) | 8.3 | MariaDB 11.4 | volle Suite |
| 5.2 (`MOODLE_502_STABLE`) | 8.4 | PostgreSQL 17 | volle Suite |
| 5.3 (`MOODLE_503_STABLE`) | 8.3 | MariaDB 11.4 | volle Suite |
| 5.3 (`MOODLE_503_STABLE`) | 8.4 | PostgreSQL 17 | volle Suite |

Alle fünf Kombinationen sind verpflichtend; ein Fehler sperrt den Gesamtcheck.
Die Artefaktprüfung installiert das Release-ZIP zusätzlich auf jeder der drei
Moodle-Linien mit PHP 8.4 und MariaDB 11.4. Die Mindestversion bleibt Moodle 5.1.
Eine Kompatibilitätszusage setzt ein grünes vollständiges Gate und die
Testinstanz-Abnahme voraus (ADR 0027). Andere Kombinationen sind dadurch
nicht automatisch zugesagt.

## Messender Mindestversions-Leg

`quality` richtet einen isolierten Gate-Container mit Moodle 5.1, PHP 8.4, MariaDB 11.4
und PCOV ein (`scripts/gate/setup-container.sh`, versionierte Composer-Lockfiles).
Dort läuft derselbe `npm run gate -- full`-Einstieg wie lokal: Node-Verträge,
volle PHPUnit-Suite, Coverage-/CRAP-Ratsche und alle statischen Prüfungen. Die übrigen
vier PHPUnit-Kombinationen erheben keine zweite Coverage-Regel. Das frühere feste
80-%-Skript samt Vertragstest ist entfernt.

Quellumfang und begründete Ausschlüsse folgen unverändert `tests/coverage.php` und
`scripts/gate/baseline.json`, siehe [Gate-Doku](gate.md). Einstiegsskript- und
Nennerumbau gehören zu #668. PostgreSQL 5.1 gehört zu #687.

Bei PRs lädt Checkout alle Branch-Refs und die vollständige Historie. `GATE_BASE_REF`
zeigt auf `origin/<PR-Zielbranch>`, bei Push auf den Commit vor dem Push. Manuelle
Abnahmen wählen den Zielbranch `dev` oder `main`. Baseline-Vergleich am Zielstand,
Datei-/Methodenänderungen an dessen Merge-Base; fehlender Ref oder erforderliche
Baseline und flache/getrennte Historie sind Fehler. Der lokale historische Startpunkt
`armedAt` überschreibt keinen expliziten CI-Zielref und bleibt unverändert erhalten.

Der verpflichtende Upload `gate-trend-MOODLE_501_STABLE-<SHA>` läuft auch bei Fehlern.
Er enthält den Messbericht (`gate-report.json`: Coverage, Methoden über CRAP 8),
Clover, Statik, Git-Kontext und Setup-/full-Logs samt Exitcode, soweit erzeugt.
MSI folgt erst in der Mutationsphase. Frühe Fehler liefern Diagnose, keine fingierten
Trendwerte. `Gate (required)` ist auch bei fehlendem/übersprungenem `quality` rot.

## Main-Bootstrap

`main` besitzt beim Start von #667 noch keine Gate-Baselines. Ein Hotfix-PR gegen
diesen Stand muss deshalb rot sein; weder ein stiller Vergleichsausfall noch eine
ungemessene Kopie der dev-Coverage ist zulässig.

Der erstmalige Release-Rollout braucht eine gesonderte, vom Maintainer ausgeführte
Bootstrap-Abnahme ([#715](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/715)). Vorbereitung: den tatsächlichen Main-Quellstand auf derselben
Mindestversion mit derselben Suite und demselben Ausschlussumfang messen; Coverage,
CRAP, PHPStan und deptrac mit Commit und Werkzeugversionen dokumentieren. Die Baselines
werden aus diesem gemessenen Kandidaten erzeugt, fachlich geprüft und zusammen mit
dem Gate auf `main` veröffentlicht. Änderungen des Release-Kandidaten seit der Messung
verlangen eine neue Messung. Erst danach sind normale Hotfix-Ratschenvergleiche und
eine verbindliche grüne Main-CI-Abnahme möglich. Der Main-Rollout ist keine automatische
Merge-Aktion dieses Integrationsbranches; der User übernimmt den Merge-Haltepunkt.

Die [passende Vorbereitungsmessung](release/667-main-bootstrap-vorbereitung.md)
enthält den tatsächlichen Main-Nenner, rohe Statikbefunde und die noch offenen
Bootstrap-Blocker. Sie behauptet keine grüne Main-Gate-Abnahme.

Feature-PR-Freigabe setzt die echte grüne CI-Abnahme aller Pflichtjobs voraus.
Der historische 80-%-CI-Nachweis von #268 belegt ausschließlich das frühere Verhalten.

## Node-Suite

`scripts/ci/run-js-tests.js` (`npm run test:ci`) führt dieselbe Suite wie
`npm test` aus. Seit dem Entfernen des Altstands 1.x (#587) gibt es weder
ausgeschlossene Dateien noch selbst übersprungene Tests. Kommt eine
plattformgebundene Testdatei hinzu, wird sie im Skript einzeln begründet
ausgeschlossen.

Die laufende Spike- oder Produktivinstanz wird durch diese CI nicht
automatisch verändert.

## Artefakt-Job

Baut das installierbare Release-ZIP (`npm run build:native-release`, #577),
entpackt es (derselbe Vertrag, den eine Administration tatsächlich
installiert — nicht nur der Quellbaum), installiert es auf einer **eigenen,
vom `phpunit`-Job getrennten** frischen Moodle-Instanz und prüft über
[`scripts/ci/verify-native-artifact.php`](../scripts/ci/verify-native-artifact.php):

1. Registrierung der Webservice-Funktion `local_coursepilot_get_version_info`
   nach dem Upgrade.
2. Discovery über den deklarierten Dienst `coursepilot`.
3. Einen autorisierten Aufruf mit einem eigens angelegten Testnutzer, der nur
   die Capability `local/coursepilot:useremote` besitzt (keine Admin-Rechte) —
   prüft die tatsächliche Capability-Kette, nicht nur „geht mit Admin-Rechten
   alles".
4. Denselben Aufruf als MCP-Anfrage (#578): Token über den echten
   OAuth-Weg (DCR, PKCE, Code-Einlösung), dann `server/discover`,
   `tools/list` und `tools/call` über `dispatcher::handle()` — die Seam, an
   die `mcp.php` den HTTP-Rumpf übergibt. Ohne Token 401. Handshake,
   Werkzeugantwort und installierte Version müssen `version.php` des ZIPs
   entsprechen.

## Bekannte native Baseline-Fehler (Stand Review 25.09.2026, Commit `06ded34`)

Der Review nannte zwei konkrete veraltete Testerwartungen:

1. Ein PHPUnit-Fehler zu einer veralteten Erwartung auf den Beschreibungstext
   „Issue #481" — bereits vor dieser CI-Arbeit auf diesem Branch behoben
   (Commit `7c8a176`, nach dem Review-Commit `06ded34`): `tests/umlaut_test.php`
   prüft seither `docs/admin-erstanleitung.md` statt der Issue-Nummer im Text.
   Keine weitere Änderung durch #268 nötig.
2. Ein nativer Node-Vertragstest mit überholter Erwartung an die Position der
   Quiz-Slot-SQL (`quiz_slots`/`question_references`) — zum Zeitpunkt dieser
   CI-Arbeit noch offen, jetzt behoben in
   `test/coursepilot-native-catalog-contract.test.js`: die SQL liegt seit der
   Katalogextraktion (#533/#556) in `classes/catalog/quiz.php`, nicht in
   `classes/catalog/module_state.php` (dorthin verwies der Test noch).

Außerdem meldete die Suite eine Notice: `oauth_lib::active_tokens()` las nur
Vor- und Nachname, `fullname()` in der Verbindungsübersicht löste deshalb
Moodles `debugging()` zu fehlenden Namensfeldern aus. Behoben in #578; der
Test baut seine Zeilen jetzt über `active_tokens()` und verlangt, dass kein
`debugging()` fällt. Übrig bleibt ein beabsichtigter Skip
(`webdav_storage_port_test::test_write_rejects_when_quota_is_exceeded`: WebDAV
unterliegt nicht der Moodle-Nutzerquote).

## Frühere Bugfix-Voraussetzungen #239/#243/#244

Die historische „0 Tests"-Voraussetzung dieses Issues ist überholt: die
native Suite umfasst (Stand Review 25.09.2026) 1.204 PHPUnit-Tests. Die
geschlossenen Issues #239, #243 und #244 sind in der Übernahme des
Testfundaments (#309 u. f.) als gezielte Regressionen berücksichtigt und
werden durch diese CI nicht erneut als offene Bugfix-Voraussetzung geführt.

## Administrativer Schritt: Required Status Check

Ob `Gate (required)` als verpflichtender Branch-Protection-Check auf `main`
eingerichtet werden konnte, hängt von den Repository-Rechten zum
Ausführungszeitpunkt ab und ist im PR/Issue-Verlauf von #268 dokumentiert.
Falls nicht automatisiert eingerichtet: Repository-Einstellungen → Branches →
Branch protection rule für `main` → „Require status checks to pass before
merging" → `Gate (required)` auswählen.

## Nachweis

Der Workflow ist seit Run
[36530129453](https://github.com/matthiasgruenwald/moodle-coursepilot/actions/runs/36530129453)
auf echten GitHub-Actions-Runnern grün; PHPUnit-Installation,
Coverage-Erhebung und Artefakt-Installation sind damit real nachgewiesen.
Die Vor-Merge-Abnahme mit Commit, ZIP-Prüfsumme und allen Kennzahlen steht in
[`docs/release/2.0.0-alpha-vor-merge-abnahme.md`](release/2.0.0-alpha-vor-merge-abnahme.md).

## PostgreSQL-Testtransaktionen

Rollback-Verträge und Backup-Temporärtabellen werden mit
`advanced_testcase::preventResetByRollback()` außerhalb der zusätzlichen
PostgreSQL-Testtransaktion geprüft. Moodle verschachtelt delegierte
Transaktionen ohne unabhängige Savepoints: innerhalb der Testhülle würde ein
Rollback erst beim Testende wirken. Die Assertions prüfen deshalb die realen
Transaktionsgrenzen des Werkzeugaufrufs; Moodle setzt die Testdaten anschließend
über seinen regulären Datenbank-Reset zurück.
